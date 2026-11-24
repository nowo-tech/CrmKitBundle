<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Closure;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotConnectionStatus;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotConnectionView;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotSchemaView;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Crypto\SodiumCrmSecretBox;
use Nowo\CrmKitBundle\Tests\Fixtures\HubSpot\UnavailableKeyBox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function str_repeat;
use function str_starts_with;

/**
 * The 0.10.1 follow-ups of audit ZAM: the start and mapping pages' HubSpot reads are rate-limited per tenant (ZAM-9),
 * and so is a confirm once its local checks passed (ZAW-L2);
 * a token whose key is not available is never taken for a bad value: the import refuses or pauses, the connection
 * and its sealed token are kept, and a new token resumes the import; a rotation racing a running import keeps the
 * token (ZAM-D2).
 */
final class HubSpotHardeningTest extends HubSpotTestCase
{
    public function testTheStartAndMappingReadsAreRateLimitedPerTenant(): void
    {
        $fake = $this->hubspot(['import' => ['hubspot' => ['browse_rate_limit' => ['limit' => 3, 'interval' => 60]]]]);
        $fake->seedContacts(1);
        $this->connect($fake);
        $this->hubImports()->lists();
        $id     = $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact], []));
        $schema = $this->hubImports()->schema($id);
        $calls  = $fake->requests;
        $this->expectBrowseLimited(fn (): HubSpotSchemaView => $this->hubImports()->schema($id));
        $this->expectBrowseLimited(fn (): array => $this->hubImports()->lists());
        $this->expectBrowseLimited(fn (): string => $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact], [])));
        self::assertSame($calls, $fake->requests, 'HubSpot is not asked beyond the limit');
        // The window passes (a confirm counts too); another tenant has its own window.
        $this->clock()->modify('+61 seconds');
        $map = [];
        foreach ($schema->properties as $object => $rows) {
            foreach ($rows as $row) {
                if ($row->target !== '') {
                    $map[$object][$row->name] = $row->target;
                }
            }
        }
        $this->hubImports()->confirm($id, new HubSpotMapping($map), DuplicatePolicy::Skip, $schema->version);
        self::assertSame(ImportStatus::Running, $this->imports()->preview($id)->status);
        $this->ctx()->tenant = 'globex';
        $this->actAs('ana', 'view', 'edit', 'manage');
        $this->connect($fake);
        $this->hubImports()->lists();
        $this->ctx()->tenant = 'acme';
        $this->clock()->modify('+61 seconds');
        $this->hubImports()->lists();
        self::assertSame(1, $this->int("SELECT value FROM nowo_crm_counter WHERE tenant_id = 'acme' AND resource = 'hubspot_browse'"));
    }

    public function testRepeatedStaleOrInvalidConfirmsDoNotReachHubSpotBeyondTheLimit(): void
    {
        $fake = $this->hubspot(['import' => ['hubspot' => ['browse_rate_limit' => ['limit' => 3, 'interval' => 60]]]]);
        $fake->seedContacts(1);
        $this->connect($fake);
        $id     = $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact], []));
        $schema = $this->hubImports()->schema($id);
        $calls  = $fake->requests;
        // Audit ZAW-L2: a stale version is refused before HubSpot is asked, and costs no slot.
        for ($i = 0; $i < 5; ++$i) {
            try {
                $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email']]), DuplicatePolicy::Skip, $schema->version - 1);
                self::fail('stale');
            } catch (ConflictException $e) {
                self::assertSame(ConflictException::IMPORT_RUNNING, $e->errorCode);
            }
        }
        self::assertSame($calls, $fake->requests, 'no HubSpot call for a stale confirm');
        // A property HubSpot does not offer is found by asking HubSpot: that confirm takes the last slot, the next one
        // is refused without asking.
        $unknown = new HubSpotMapping(['contacts' => ['not_offered' => 'email']]);
        $confirm = function () use ($id, $unknown, $schema): void {
            $this->hubImports()->confirm($id, $unknown, DuplicatePolicy::Skip, $schema->version);
        };
        $this->expectInput($confirm, 'mapping', 'import.error.mapping');
        $calls = $fake->requests;
        $this->expectBrowseLimited($confirm);
        self::assertSame($calls, $fake->requests, 'HubSpot is not asked beyond the limit');
        $this->clock()->modify('+61 seconds');
        // The import changes while HubSpot answers (another person's confirm): the claim under its version still loses.
        $fake->onRequest = function () use ($id): void {
            $this->connection()->executeStatement('UPDATE nowo_crm_import SET version = version + 1 WHERE id = ?', [$id]);
        };

        try {
            $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email']]), DuplicatePolicy::Skip, $schema->version);
            self::fail('lost claim');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::IMPORT_RUNNING, $e->errorCode);
        }
        $fake->onRequest = null;
        $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email']]), DuplicatePolicy::Skip, $schema->version + 1);
        self::assertSame(ImportStatus::Running, $this->imports()->preview($id)->status);
    }

    public function testAKeyThatIsNotAvailableKeepsTheTokenAndRefusesTheImport(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(2);
        $version = $this->connect($fake);
        $id      = $this->startAndConfirm();
        $sealed  = $this->text('SELECT token_sealed FROM nowo_crm_hubspot_connection');
        // The key "k1" was dropped from secrets.keys before the rotation re-sealed the token.
        $this->connection()->executeStatement("UPDATE nowo_crm_hubspot_connection SET token_key_id = 'gone'");
        $this->expectInput(fn (): array => $this->hubImports()->lists(), '_', 'error.hubspot_key_unavailable');
        self::assertFalse($this->imports()->run($id, 1));
        $view = $this->imports()->preview($id);
        self::assertSame([ImportStatus::Running, 'hubspot_key_unavailable', true], [$view->status, $view->errorCode, $view->awaitingPerson]);
        $status = $this->connections()->status();
        self::assertNotNull($status);
        self::assertSame([HubSpotConnectionStatus::Active, $version], [$status->status, $status->version], 'nothing marked invalid');
        self::assertSame(['gone', $sealed], [$this->text('SELECT token_key_id FROM nowo_crm_hubspot_connection'), $this->text('SELECT token_sealed FROM nowo_crm_hubspot_connection')]);
        // A manager pastes the token again: the paused import runs again.
        $this->connections()->connect($fake->token, null, $version);
        self::assertNull($this->imports()->preview($id)->errorCode);
        self::assertTrue($this->imports()->run($id));
        self::assertSame(ImportStatus::Done, $this->imports()->preview($id)->status);
        self::assertSame(2, $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE object_type = 'contact'"));
    }

    public function testARotationRacingARunningImportKeepsTheToken(): void
    {
        $fake = $this->hubspot(['secrets' => ['keys' => ['k0' => str_repeat("\x05", 32), 'k1' => self::KEY], 'current_key' => 'k1']]);
        $fake->seedContacts(3);
        $view = $this->connections()->connect($fake->token, null, null);
        // Sealed under the previous key "k0" (as before a rotation).
        $old = (new SodiumCrmSecretBox(['k0' => str_repeat("\x05", 32)], 'k0'))->seal($fake->token, new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('acme'), $view->id));
        $this->connection()->executeStatement("UPDATE nowo_crm_hubspot_connection SET token_key_id = 'k0', token_sealed = ?", [$old->ciphertext]);
        $id      = $this->startAndConfirm();
        $tester  = new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:secrets:rotate'));
        $rotated = false;
        // The rotation runs while the runner holds the token it opened under "k0" and HubSpot answers.
        $fake->onRequest = static function (string $path) use ($tester, &$rotated): void {
            if (!$rotated && str_starts_with($path, '/crm/v3/objects/contacts')) {
                $rotated = true;
                self::assertSame(Command::SUCCESS, $tester->execute([]));
            }
        };
        self::assertTrue($this->imports()->run($id));
        self::assertTrue($rotated);
        self::assertSame(ImportStatus::Done, $this->imports()->preview($id)->status);
        self::assertSame(3, $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE object_type = 'contact'"));
        self::assertSame('k1', $this->text('SELECT token_key_id FROM nowo_crm_hubspot_connection'));
        self::assertSame(HubSpotConnectionStatus::Active, $this->connectionStatus());
        // A late failure report of the runner names the old sealed value: it never hits the re-sealed token.
        $this->runner()->write(fn () => $this->service(HubSpotStoreInterface::class)->invalidate(new TenantRef('acme'), $view->id, $old->ciphertext, $this->clock()->now()));
        self::assertSame(HubSpotConnectionStatus::Active, $this->connectionStatus());
        $fake->onRequest = null;
        $this->hubImports()->lists();
    }

    public function testAConnectWhoseSealFailsStoresNothing(): void
    {
        $fake = $this->hubspot(['secrets' => ['box' => 'test.unavailable_box']], static function (ContainerConfigurator $container): void {
            $container->services()->set('test.unavailable_box', UnavailableKeyBox::class);
        });
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), 'token', 'error.hubspot_key_unavailable');
        self::assertNull($this->connections()->status());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_hubspot_connection'));
    }

    private function connectionStatus(): ?HubSpotConnectionStatus
    {
        return $this->connections()->status()?->status;
    }

    private function expectBrowseLimited(Closure $call): void
    {
        $this->expectInput($call, '_', 'error.hubspot_browse_limited');
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
}
