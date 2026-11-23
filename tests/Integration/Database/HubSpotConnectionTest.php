<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Closure;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotConnectionStatus;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotConnectionView;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Crypto\SodiumCrmSecretBox;
use Nowo\CrmKitBundle\Tests\Fixtures\HubSpot\FakeHubSpot;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

use function str_contains;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * The HubSpot connection (0.10.0): a service key or a legacy private app token, validated the same way (account
 * through account-info; scopes from token info when HubSpot knows the credential, else by probing), sealed with the
 * tenant-bound context, replaced under its version, disconnected; refused write scopes, missing scopes, account
 * changes, rate limits and permissions; the rotation command.
 */
final class HubSpotConnectionTest extends HubSpotTestCase
{
    public function testAPrivateAppTokenIsCheckedSealedAndShownAsItsLastFourCharacters(): void
    {
        $fake = $this->hubspot();
        self::assertNull($this->connections()->status());
        $view = $this->connections()->connect('  ' . $fake->token . ' ', 'Main portal', null);
        self::assertSame(['4242', 'Main portal', HubSpotConnectionStatus::Active, 'private_app'], [$view->accountId, $view->label, $view->status, $view->kind]);
        self::assertSame(substr($fake->token, -4), $view->tokenLast4);
        self::assertContains('crm.lists.read', $view->scopes);
        // The token is stored sealed only: no row holds it in clear.
        $dump = $this->text('SELECT token_sealed FROM nowo_crm_hubspot_connection');
        self::assertFalse(str_contains($dump, $fake->token));
        self::assertSame('k1', $this->text('SELECT token_key_id FROM nowo_crm_hubspot_connection'));
        $box = $this->service(CrmSecretBoxInterface::class);
        self::assertSame($fake->token, $box->unseal(new SealedSecret('k1', $dump), new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('acme'), $view->id)));
        // Bound to the tenant: the same value does not open for another one.
        $this->expectFailure(static fn () => $box->unseal(new SealedSecret('k1', $dump), new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('other'), $view->id)));
        self::assertContains('crm.hubspot.connected', $this->recorder()->actions());
        self::assertContains('HubSpotConnected', $this->recorder()->eventNames());
        foreach ($this->recorder()->audit as $entry) {
            self::assertFalse(str_contains(json_encode($entry->details, JSON_THROW_ON_ERROR), $fake->token));
        }
    }

    public function testAServiceKeyIsCheckedByProbingTheReadEndpoints(): void
    {
        $fake             = $this->hubspot();
        $fake->introspect = false;
        $fake->scopes     = [...FakeHubSpot::REQUIRED];
        $view             = $this->connections()->connect($fake->token, null, null);
        self::assertSame('HubSpot 4242', $view->label);
        self::assertSame(FakeHubSpot::REQUIRED, $view->scopes);
        $probed = array_map(static fn (array $r): string => (string) parse_url($r[1], PHP_URL_PATH), $fake->requests);
        self::assertContains('/crm/v3/lists/search', $probed);
        self::assertContains('/account-info/v3/details', $probed);
        // A missing read scope found by a probe (403) is refused.
        $fake->scopes = ['crm.objects.companies.read'];
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, $view->version), 'token', 'error.hubspot_scope_missing');
    }

    public function testWriteScopesMissingScopesAndRefusedTokensAreRefused(): void
    {
        $fake         = $this->hubspot(['import' => ['hubspot' => ['connect_rate_limit' => ['limit' => 20]]]]);
        $fake->scopes = [...FakeHubSpot::REQUIRED, 'crm.objects.contacts.write'];
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), 'token', 'error.hubspot_scope_not_read_only');
        $fake->scopes = ['crm.objects.contacts.read'];
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), 'token', 'error.hubspot_scope_missing');
        $fake->scopes = [...FakeHubSpot::REQUIRED, 'oauth'];
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect('wrong-key-1234567', null, null), 'token', 'error.hubspot_token_invalid');
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect('short', null, null), 'token', 'error.hubspot_token_invalid');
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, str_repeat('L', 81), null), 'label', 'error.invalid');
        $fake->failNext('/account-info', 503);
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), 'token', 'error.hubspot_unavailable');
        $fake->failNext('/oauth/v2/private-apps', 502);
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), 'token', 'error.hubspot_unavailable');
        // Token info of another account than the credential's: refused.
        $fake->failNext('/oauth/v2/private-apps', 200, [], '{"hubId": 777, "scopes": []}');
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), 'token', 'error.hubspot_token_invalid');
        self::assertNull($this->connections()->status());
        self::assertContains('crm.hubspot.token_invalid', $this->recorder()->actions());
        self::assertSame(1, $this->connections()->connect($fake->token, null, null)->version);
    }

    public function testReplaceNeedsTheVersionAndConfirmsAnAccountChange(): void
    {
        $fake    = $this->hubspot();
        $version = $this->connect($fake);
        $this->expectConflict(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), ConflictException::STALE);
        $this->expectConflict(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, $version + 5), ConflictException::STALE);
        $fake->account = '5151';
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, $version), 'token', 'error.hubspot_account_changed');
        $view = $this->connections()->connect($fake->token, 'Other', $version, true);
        self::assertSame(['5151', 'Other'], [$view->accountId, $view->label]);
        self::assertGreaterThan($version, $view->version);
        self::assertContains('crm.hubspot.replaced', $this->recorder()->actions());
        $this->connections()->disconnect($view->version);
        self::assertNull($this->connections()->status());
        $this->expectConflict(fn () => $this->connections()->disconnect($view->version), ConflictException::STALE);
        self::assertContains('crm.hubspot.disconnected', $this->recorder()->actions());
        self::assertContains('HubSpotDisconnected', $this->recorder()->eventNames());
    }

    public function testTokenChecksAreRateLimitedPerTenant(): void
    {
        $fake = $this->hubspot(['import' => ['hubspot' => ['connect_rate_limit' => ['limit' => 2, 'interval' => 60]]]]);
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect('wrong-key-1234567', null, null), 'token', 'error.hubspot_token_invalid');
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect('wrong-key-1234567', null, null), 'token', 'error.hubspot_token_invalid');
        $this->expectConflict(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), ConflictException::HUBSPOT_RATE_LIMITED);
        // Another tenant has its own window; the window passes.
        $this->ctx()->tenant = 'globex';
        $this->actAs('ana', 'view', 'edit', 'manage');
        self::assertSame(1, $this->connect($fake));
        $this->ctx()->tenant = 'acme';
        $this->clock()->modify('+61 seconds');
        self::assertSame(1, $this->connect($fake));
    }

    public function testConnectAndDisconnectNeedImportAndManage(): void
    {
        $fake                = $this->hubspot();
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        self::assertNull($this->connections()->status());
        $this->expectDenied(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null));
        $this->expectDenied(fn () => $this->connections()->disconnect(1));
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'manage');
        $this->expectDenied(fn (): ?\Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotConnectionView => $this->connections()->status());
    }

    public function testRotationReSealsUnderTheCurrentKeyAndInvalidatesWhatNoLongerOpens(): void
    {
        $old  = str_repeat("\x07", 32);
        $fake = $this->hubspot(['secrets' => ['keys' => ['old' => $old, 'k1' => self::KEY], 'current_key' => 'k1']]);
        $view = $this->connections()->connect($fake->token, null, null);
        $k1   = $this->text('SELECT token_sealed FROM nowo_crm_hubspot_connection');
        // A row sealed under the retired key "old".
        $sealed = (new SodiumCrmSecretBox(['old' => $old], 'old'))->seal($fake->token, new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('acme'), $view->id));
        $this->connection()->executeStatement("UPDATE nowo_crm_hubspot_connection SET token_key_id = 'old', token_sealed = ?", [$sealed->ciphertext]);
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:secrets:rotate'));
        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('dry run: 1 re-sealed, 0 already current, 0 invalid', $tester->getDisplay());
        self::assertSame('old', $this->text('SELECT token_key_id FROM nowo_crm_hubspot_connection'));
        $tester->execute([]);
        self::assertStringContainsString('secrets: 1 re-sealed, 0 already current, 0 invalid', $tester->getDisplay());
        self::assertSame('k1', $this->text('SELECT token_key_id FROM nowo_crm_hubspot_connection'));
        $tester->execute([]);
        self::assertStringContainsString('0 re-sealed, 1 already current', $tester->getDisplay());
        self::assertFalse(str_contains($tester->getDisplay(), $fake->token));
        // A value that does not open under its key: the connection becomes invalid (a manager pastes a new key).
        $this->connection()->executeStatement("UPDATE nowo_crm_hubspot_connection SET token_key_id = 'old', token_sealed = ?", [$k1]);
        $tester->execute(['--dry-run' => true]);
        self::assertStringContainsString('1 invalid', $tester->getDisplay());
        $before = $this->connections()->status();
        self::assertNotNull($before);
        self::assertSame(HubSpotConnectionStatus::Active, $before->status);
        $tester->execute([]);
        $after = $this->connections()->status();
        self::assertNotNull($after);
        self::assertSame(HubSpotConnectionStatus::Invalid, $after->status);
    }

    private function expectInput(Closure $call, string $field, string $key): void
    {
        try {
            $call();
            self::fail('Expected an input error.');
        } catch (InvalidInputException $e) {
            self::assertSame([$field => $key], $e->errors);
        }
    }

    private function expectConflict(Closure $call, string $code): void
    {
        try {
            $call();
            self::fail('Expected a conflict.');
        } catch (ConflictException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    private function expectDenied(Closure $call): void
    {
        try {
            $call();
            self::fail('Expected a denial.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
    }

    private function expectFailure(Closure $call): void
    {
        try {
            $call();
            self::fail('Expected a failure.');
        } catch (Throwable $e) {
            self::assertFalse(str_contains(strtolower($e->getMessage()), 'fake-key'));
        }
    }
}
