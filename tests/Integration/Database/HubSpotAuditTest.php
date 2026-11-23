<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Closure;
use Nowo\CrmKitBundle\Application\HubSpot\NeedsPersonException;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotConnectionStatus;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotConnectionView;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The fixes of audit ZAM (0.10.0): unattended runs under resource scoping write only the confirming person's own or
 * ownerless records and never move an owner (ZAM-1); the write-scope check fails closed and a service key's scopes
 * are shown as not verified (ZAM-3); an invalidation only hits the token that failed (ZAM-4); `--all` re-seals every
 * token (ZAM-5); the secret keys are no container parameters (ZAM-6).
 */
final class HubSpotAuditTest extends HubSpotTestCase
{
    /** @var array<string, mixed> */
    private const SCOPED = ['security' => ['resource_scoping' => true]];

    public function testUnattendedRunsPauseOnRecordsOwnedBySomeoneElse(): void
    {
        $fake = $this->hubspot(self::SCOPED);
        $fake->seedContacts(2);
        $this->connect($fake);
        self::assertTrue($this->imports()->run($this->startAndConfirm()));
        $leo = $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE external_id = '2001'");
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET owner_id = 'leo' WHERE id = ?", [$leo]);
        $fake->contacts[2001] = ['firstname' => 'Changed'] + $fake->contacts[2001];
        $fake->contacts[2002] = ['firstname' => 'Changed'] + $fake->contacts[2002];
        // Policy skip: a re-run updates the linked records; unattended, leo's record needs a person.
        foreach ([DuplicatePolicy::Skip, DuplicatePolicy::Update] as $policy) {
            $id = $this->startAndConfirm(policy: $policy);
            self::assertSame('ana', $this->text('SELECT confirmed_by_id FROM nowo_crm_import WHERE id = ?', [$id]));
            $this->resume($id);
            $view = $this->imports()->preview($id);
            self::assertSame([ImportStatus::Running, NeedsPersonException::CODE, true], [$view->status, $view->errorCode, $view->awaitingPerson]);
            self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE first_name = 'Changed'"), 'the page wrote nothing');
            // A person's "Continue" settles it with their own access.
            self::assertTrue($this->imports()->run($id));
            self::assertSame(2, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE first_name = 'Changed'"));
            $this->connection()->executeStatement("UPDATE nowo_crm_contact SET first_name = 'Person'");
        }
        self::assertSame('leo', $this->text('SELECT owner_id FROM nowo_crm_contact WHERE id = ?', [$leo]));
    }

    public function testUnattendedRunsWriteOwnOrOwnerlessRecordsAndNeverMoveAnOwner(): void
    {
        $fake = $this->hubspot(self::SCOPED);
        $fake->seedContacts(2);
        $fake->owners                = ['501' => 'leo@owners.example'];
        $this->ctx()->emails['acme'] = ['leo' => 'leo@owners.example'];
        $this->connect($fake);
        self::assertTrue($this->imports()->run($this->startAndConfirm()));
        $mine = $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE external_id = '2001'");
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET owner_id = 'ana' WHERE id = ?", [$mine]);
        foreach ([2001, 2002] as $hid) {
            $fake->contacts[$hid] = ['firstname' => 'Changed', 'hubspot_owner_id' => '501'] + $fake->contacts[$hid];
        }
        $id = $this->startAndConfirm();
        $this->resume($id);
        self::assertSame(ImportStatus::Done, $this->imports()->preview($id)->status);
        self::assertSame(2, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE first_name = 'Changed'"));
        self::assertSame(['ana', ''], [$this->text('SELECT owner_id FROM nowo_crm_contact WHERE id = ?', [$mine]), $this->text("SELECT COALESCE(c.owner_id, '') FROM nowo_crm_contact c JOIN nowo_crm_external_ref r ON r.target_id = c.id WHERE r.external_id = '2002'")], 'no owner moves unattended');
    }

    public function testUnattendedAffiliationsAndTagsFollowTheSameRule(): void
    {
        $fake = $this->hubspot(self::SCOPED);
        $fake->seedCompanies(1);
        $fake->seedContacts(1);
        $fake->associations = ['2001' => [['1001', true]]];
        $this->connect($fake);
        self::assertTrue($this->imports()->run($this->startAndConfirm([HubSpotObject::Company])));
        $company = $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE external_id = '1001'");
        $this->connection()->executeStatement("UPDATE nowo_crm_company SET owner_id = 'leo' WHERE id = ?", [$company]);
        $id = $this->startAndConfirm(policy: DuplicatePolicy::Skip, properties: ['contacts' => ['email' => 'email', 'lastname' => 'last_name']]);
        $this->resume($id);
        self::assertSame(NeedsPersonException::CODE, $this->imports()->preview($id)->errorCode, 'the company of the affiliation is leo\'s');
        self::assertTrue($this->imports()->run($id));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation'));
        // Lists: a member owned by someone else needs a person; a person without edit on it adds no tag.
        $contact = $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE external_id = '2001'");
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET owner_id = 'leo' WHERE id = ?", [$contact]);
        $fake->lists = ['77' => ['name' => 'Fans', 'type' => '0-1', 'members' => ['2001']]];
        $id          = $this->startAndConfirm([HubSpotObject::Contact], ['77'], ['77' => 'create'], properties: ['contacts' => ['email' => 'email']]);
        $this->resume($id);
        self::assertSame(NeedsPersonException::CODE, $this->imports()->preview($id)->errorCode);
        $this->ctx()->denied[] = 'edit:' . $contact;
        self::assertTrue($this->imports()->run($id));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_tagging'), 'no edit on the member: no tag');
    }

    public function testTheWriteScopeCheckFailsClosedAndServiceKeysAreNotVerified(): void
    {
        $fake = $this->hubspot();
        $fake->failNext('/oauth/v2/private-apps/get/access-token-info', 500);
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), 'error.hubspot_unavailable');
        $fake->failNext('/oauth/v2/private-apps/get/access-token-info', 401);
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), 'error.hubspot_token_invalid');
        self::assertSame([], array_filter($fake->requests, static fn (array $r): bool => str_contains($r[1], '/lists/search')), 'never probed after another failure');
        $fake->failNext('/oauth/v2/private-apps/get/access-token-info', 200, [], '{"hubId": 4242, "scopes": ["crm.objects.contacts.read", 7]}');
        $this->expectInput(fn (): HubSpotConnectionView => $this->connections()->connect($fake->token, null, null), 'error.hubspot_scope_not_read_only');
        self::assertTrue($this->connections()->connect($fake->token, null, null)->scopesVerified);
        $fake->introspect = false;
        $view             = $this->connections()->connect($fake->token, null, 1);
        self::assertFalse($view->scopesVerified);
        $card = $this->client->request('GET', '/crm/import/hubspot');
        self::assertStringContainsString('write scopes not verified', $card->filter('[data-testid="crm-hubspot-unverified"]')->text());
    }

    public function testAnInvalidationOnlyHitsTheTokenThatFailed(): void
    {
        $fake  = $this->hubspot();
        $first = $this->connections()->connect($fake->token, null, null);
        $old   = $this->text('SELECT token_sealed FROM nowo_crm_hubspot_connection');
        $now   = $this->connections()->connect($fake->token, null, $first->version);
        $store = $this->service(HubSpotStoreInterface::class);
        // A late 401 of a runner that still holds the old sealed token.
        $this->runner()->write(fn () => $store->invalidate(new TenantRef('acme'), $first->id, $old, $this->clock()->now()));
        $after = $this->connections()->status();
        self::assertNotNull($after);
        self::assertSame([HubSpotConnectionStatus::Active, $now->version], [$after->status, $after->version]);
        $this->runner()->write(fn () => $store->invalidate(new TenantRef('acme'), $first->id, $this->text('SELECT token_sealed FROM nowo_crm_hubspot_connection'), $this->clock()->now()));
        $after = $this->connections()->status();
        self::assertNotNull($after);
        self::assertSame([HubSpotConnectionStatus::Invalid, $now->version + 1], [$after->status, $after->version]);
    }

    public function testRotationAllReSealsEveryTokenAndKeysAreNoParameters(): void
    {
        $fake = $this->hubspot();
        $this->connect($fake);
        $before = $this->text('SELECT token_sealed FROM nowo_crm_hubspot_connection');
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:secrets:rotate'));
        $tester->execute(['--all' => true]);
        self::assertStringContainsString('secrets: 1 re-sealed', $tester->getDisplay());
        self::assertNotSame($before, $this->text('SELECT token_sealed FROM nowo_crm_hubspot_connection'));
        $container = $this->kernel->getContainer();
        self::assertFalse($container->hasParameter('nowo_crm_kit.secrets.keys.k1'));
        self::assertFalse($container->hasParameter('nowo_crm_kit.secrets.keys'));
        self::assertTrue($container->hasParameter('nowo_crm_kit.secrets.current_key'));
    }

    /**
     * Lets the resume command take the import (as after a pause whose wait passed), with nobody signed in.
     */
    private function resume(string $id): void
    {
        $this->connection()->executeStatement('UPDATE nowo_crm_import SET claim_token = NULL, resume_after = ? WHERE id = ?', [$this->clock()->now()->format('Y-m-d H:i:s'), $id]);
        (new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:import:resume')))->execute([]);
    }

    private function expectInput(Closure $call, string $key): void
    {
        try {
            $call();
            self::fail('Expected an input error.');
        } catch (InvalidInputException $e) {
            self::assertSame(['token' => $key], $e->errors);
        }
    }
}
