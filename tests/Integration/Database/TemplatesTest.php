<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Bridge\MailKit\CompanyPlaceholders;
use Nowo\CrmKitBundle\Bridge\MailKit\ContactPlaceholders;
use Nowo\CrmKitBundle\Bridge\MailKit\CrmTemplatePermission;
use Nowo\CrmKitBundle\Bridge\MailKit\DealPlaceholders;
use Nowo\CrmKitBundle\Bridge\MailKit\MePlaceholders;
use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\EmailTemplateView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplateInput;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTemplateMailStore;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;
use Nowo\MailKitBundle\Template\Value\StoredTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;

use function array_filter;
use function array_values;
use function str_repeat;

/**
 * 0.13.0 (Beacon spec 254, A-2): the templates service on MailKit's library (personal / shared, classification,
 * exceptions), the placeholder providers (only what the person may view, exactly the call's actor) and the permission
 * port.
 */
final class TemplatesTest extends TemplatesTestCase
{
    public function testPersonalAndSharedTemplatesWithTheirClassification(): void
    {
        $this->boot();
        $this->actAs('ana', 'view', 'edit');
        $mine = $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'welcome', ' Welcome ', 'en', 'Hello {{ contact.first_name }}', 'Hi {{ contact.first_name }}', 'marketing_email'));
        $snip = $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Snippet, false, 'sign', 'Signature', 'en', 'ignored', '-- {{ me.name }}', 'newsletter'));
        self::assertSame(['marketing_email'], [$this->text('SELECT purpose FROM nowo_crm_template_purpose WHERE id = ?', [$mine])]);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_template_purpose WHERE id = ?', [$snip]), 'snippets have no classification');
        self::assertSame('', $this->mailKit()->rows[$snip]->subject);
        $this->expectThrows(AccessDeniedException::class, fn (): string => $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, true, 'org', 'Org', 'en', 'S', 'T')));
        $this->expectErrors(['purpose' => 'error.consent_purpose'], fn (): string => $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'x', 'X', 'en', 'S', 'T', 'unknown')));
        $this->expectErrors(['key' => 'template_error.key_taken'], fn (): string => $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'welcome', 'Again', 'en', 'S', 'T')));
        $this->expectErrors(['key' => 'template_error.key'], fn (): string => $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'Bad Key', 'X', 'en', 'S', 'T')));
        $this->expectErrors(['text' => 'template_error.body'], fn (): string => $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'long', 'X', 'en', 'S', str_repeat('t', 5001))));
        $this->expectErrors(['shared' => 'template_error.scope'], fn (): string => $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'odd', 'raise:scope', 'en', 'S', 'T')));
        $this->expectErrors(['text' => 'template_error.body'], fn (): string => $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'odd', 'raise:strange', 'en', 'S', 'T')));
        $this->expectErrors(['subject' => 'template_error.subject'], fn (): string => $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'odd', 'raise:subject', 'en', 'S', 'T')));

        $this->actAs('ana', 'manage');
        $shared = $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, true, 'invoice', 'Invoice', 'en', 'Your invoice', 'Attached.'));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_template_purpose WHERE id = ? AND purpose IS NULL', [$shared]), 'a service template is classified');
        $legacy = $this->mailKit()->seed('acme', null, TemplateKind::Template, 'legacy', 'Old', 'Old text', '<p>Old</p>');
        $this->mailKit()->seed('globex', null, TemplateKind::Template, 'other', 'Other', 'Other tenant');
        $this->mailKit()->seed('acme', 'leo', TemplateKind::Template, 'leos', 'Leo', 'Leo only');

        $list = $this->templates()->list();
        self::assertSame(['welcome', 'invoice', 'legacy', 'sign'], array_map(static fn (EmailTemplateView $t): string => $t->key, $list));
        self::assertSame([true, true, false, true], array_map(static fn (EmailTemplateView $t): bool => $t->sendable(), $list));
        self::assertSame(['marketing_email', null, null, null], array_map(static fn (EmailTemplateView $t): ?string => $t->purpose, $list));
        self::assertSame('', $list[0]->text, 'lists carry no text');
        self::assertCount(2, $this->templates()->list(EmailTemplateKind::Template) === [] ? [] : array_filter($this->templates()->list(EmailTemplateKind::Template), static fn ($t): bool => $t->shared));
        $one = $this->templates()->get($legacy);
        self::assertSame(['Old text', true, false, true], [$one->text, $one->hasHtml, $one->classified, $one->manageable]);
        $this->expectThrows(NotAvailableException::class, fn (): object => $this->templates()->get('not-an-id'));
        $this->expectThrows(NotAvailableException::class, fn (): object => $this->templates()->get('0190a000-0000-7000-8000-00000000dead'));

        // A concurrent first classification of the same template: a conflict; the unit rolls back, so the template stays
        // unclassified (it cannot be sent) until it is saved again.
        $this->ctx()->races['TemplatePurpose'] = 1;
        $this->expectConflictCode(ConflictException::STALE, fn (): string => $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'race', 'Race', 'en', 'S', 'T', 'newsletter')));
        $race = array_values(array_filter($this->mailKit()->rows, static fn (StoredTemplate $r): bool => $r->key === 'race'))[0]->id;
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_template_purpose WHERE id = ?', [$race]));
        $this->mailKit()->delete(new TemplateActor('acme', 'ana'), $race, 1);

        // Update: kind and scope never change, versions are checked, the classification follows.
        $this->templates()->update($legacy, new EmailTemplateInput(EmailTemplateKind::Template, true, 'legacy', 'Legacy', 'en', 'New', 'New text', 'newsletter'), 1);
        self::assertSame('newsletter', $this->text('SELECT purpose FROM nowo_crm_template_purpose WHERE id = ?', [$legacy]));
        self::assertSame('', $this->mailKit()->rows[$legacy]->html, 'the CRM saves the text body only');
        $this->expectConflictCode(ConflictException::STALE, fn () => $this->templates()->update($legacy, new EmailTemplateInput(EmailTemplateKind::Template, true, 'legacy', 'Legacy', 'en', 'New', 'New text'), 1));
        $this->expectErrors(['kind' => 'template_error.kind'], fn () => $this->templates()->update($legacy, new EmailTemplateInput(EmailTemplateKind::Snippet, true, 'legacy', 'Legacy', 'en', '', 'New text'), 2));
        $this->expectErrors(['shared' => 'template_error.scope'], fn () => $this->templates()->update($legacy, new EmailTemplateInput(EmailTemplateKind::Template, false, 'legacy', 'Legacy', 'en', 'New', 'New text'), 2));
        $this->templates()->update($legacy, new EmailTemplateInput(EmailTemplateKind::Template, true, 'legacy', 'Legacy', 'en', 'New', 'New text'), 2);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_template_purpose WHERE id = ? AND purpose IS NULL', [$legacy]));

        // Without manage the shared rows can be used, not changed.
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        self::assertFalse($this->templates()->get($shared)->manageable);
        $this->expectThrows(AccessDeniedException::class, fn () => $this->templates()->update($shared, new EmailTemplateInput(EmailTemplateKind::Template, true, 'invoice', 'Invoice', 'en', 'S', 'T'), 1));
        $this->expectThrows(AccessDeniedException::class, fn () => $this->templates()->delete($shared, 1));

        // Delete: the classification goes with MailKit's TemplateDeletedEvent; the audit is the CRM's.
        $this->templates()->delete($mine, 1);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_template_purpose WHERE id = ?', [$mine]));
        self::assertContains('crm.template.deleted', $this->recorder()->actions());
        self::assertContains('crm.template.created', $this->recorder()->actions());
        self::assertContains('crm.template.saved', $this->recorder()->actions());

        // Without edit nothing is listed; without the CRM, 404.
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view');
        $this->expectThrows(AccessDeniedException::class, fn (): array => $this->templates()->list());
        $this->actAs('ana smith', 'view', 'edit');
        $this->expectThrows(NotAvailableException::class, fn (): array => $this->templates()->list());
        $this->ctx()->tenant = null;
        $this->expectThrows(NotAvailableException::class, fn (): array => $this->templates()->list());
    }

    public function testPlaceholdersResolveOnlyWhatThePersonMayView(): void
    {
        $this->boot(['pipeline' => ['enabled' => true, 'task_port' => 'test.task_port']]);
        $this->actAs('ana', 'view', 'edit', 'manage', 'amounts_view');
        $ada  = $this->contact('Ada', 'Lovelace', 'ada@example.test', ['job_title' => 'CTO']);
        $acme = $this->company('Acme', ['city' => 'Madrid']);
        $this->records()->affiliate($ada, $acme, 'CTO', true);
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $deal   = $this->service(DealService::class)->create(new DealInput('Engines', $funnel, companyId: $acme, contactId: $ada, amount: new Money('1200.50', 'EUR')));
        $id     = $this->mailKit()->seed('acme', null, TemplateKind::Template, 'full', 'Hi {{ contact.first_name }}', "{{contact.full_name}} ({{ contact.job_title }}) at {{ company.name }}, {{ company.city }}\n{{ deal.title }} {{ deal.amount }} {{ deal.expected_close }}\n{{ me.name }} <{{ me.email }}> {{ contact.unknown }} {{ other.thing }} {{ contact.mobile }}");

        $text = $this->templates()->compose($id, $ada, $deal);
        self::assertSame('Hi Ada', $text->subject);
        self::assertSame("Ada Lovelace (CTO) at Acme, Madrid\nEngines 1200.5 EUR {{ deal.expected_close }}\nAna <ana@example.test> {{ contact.unknown }} {{ other.thing }} {{ contact.mobile }}", $text->text);
        self::assertSame(['deal.expected_close', 'contact.unknown', 'other.thing', 'contact.mobile'], $text->unresolved);

        // Amounts need amounts_view; a deal or company the person may not view stays as text.
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        $this->ctx()->denied[] = 'view:' . $acme;
        $text                  = $this->templates()->compose($id, $ada, $deal);
        self::assertStringContainsString('at {{ company.name }}, {{ company.city }}', $text->text);
        self::assertStringContainsString('Engines {{ deal.amount }}', $text->text);
        $this->ctx()->denied[] = 'view:' . $deal;
        self::assertStringContainsString('{{ deal.title }}', $this->templates()->compose($id, $ada, $deal)->text);
        self::assertStringContainsString('{{ deal.title }}', $this->templates()->compose($id, $ada, 'not-a-deal')->text);
        $this->ctx()->denied[] = 'view:' . $ada;
        $this->expectThrows(AccessDeniedException::class, fn (): object => $this->templates()->compose($id, $ada));
        $this->expectThrows(NotAvailableException::class, fn (): object => $this->templates()->compose('bad', $ada));
        $this->ctx()->denied = [];
        $this->records()->trash(SubjectType::Contact, $ada, $this->service(CrmReaderInterface::class)->record(SubjectType::Contact, $ada)->version);
        $this->expectThrows(NotAvailableException::class, fn (): object => $this->templates()->compose($id, $ada));

        // The editor's list.
        self::assertSame(['contact', 'company', 'deal', 'me'], array_keys($this->templates()->placeholders()));
        self::assertContains('contact.first_name', $this->templates()->placeholders()['contact']);
    }

    public function testProvidersAndThePermissionPortAcceptOnlyTheCallsActor(): void
    {
        $this->boot();
        $this->actAs('ana', 'view', 'edit');
        $ada     = $this->contact('Ada', 'Lovelace');
        $contact = $this->service(ContactPlaceholders::class);
        $own     = new PlaceholderContext(new TemplateActor('acme', 'ana'), 'en', ['contact' => $ada]);
        self::assertSame(['first_name' => 'Ada'], $contact->resolve(['first_name', 'first_name', 'mobile', 'nope'], $own));
        self::assertSame([], $contact->resolve(['nope'], $own));
        self::assertSame([], $contact->resolve(['first_name'], new PlaceholderContext(new TemplateActor('acme', 'leo'), 'en', ['contact' => $ada])), 'another person');
        self::assertSame([], $contact->resolve(['first_name'], new PlaceholderContext(new TemplateActor('globex', 'ana'), 'en', ['contact' => $ada])), 'another tenant');
        self::assertSame([], $contact->resolve(['first_name'], new PlaceholderContext(new TemplateActor('acme', 'ana'), 'en')), 'no contact');
        self::assertSame([], $this->service(CompanyPlaceholders::class)->resolve(['name'], $own));
        self::assertSame([], $this->service(DealPlaceholders::class)->resolve(['title'], new PlaceholderContext(new TemplateActor('acme', 'ana'), 'en', ['deal' => $ada])), 'funnels off');
        self::assertSame(['name' => 'Ana', 'email' => 'ana@example.test'], $this->service(MePlaceholders::class)->resolve(['name', 'email'], $own));
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET status = 'trashed' WHERE id = ?", [$ada]);
        self::assertSame([], $contact->resolve(['first_name'], $own), 'only active records');
        $this->ctx()->owners['acme'] = [];
        self::assertSame([], $this->service(MePlaceholders::class)->resolve(['name'], $own), 'not in the directory');
        self::assertSame(['me', 'contact', 'company', 'deal'], [MePlaceholders::placeholderNamespace(), ContactPlaceholders::placeholderNamespace(), CompanyPlaceholders::placeholderNamespace(), DealPlaceholders::placeholderNamespace()]);
        self::assertSame('field.first_name', $contact->definitions()[0]->label);

        $permission = $this->kernel->getContainer()->get('nowo_crm_kit.template_permission');
        self::assertInstanceOf(CrmTemplatePermission::class, $permission);
        $ana = new TemplateActor('acme', 'ana');
        self::assertTrue($permission->isGranted(TemplatePermission::Personal, $ana));
        self::assertTrue($permission->isGranted(TemplatePermission::UseShared, $ana));
        self::assertFalse($permission->isGranted(TemplatePermission::ManageShared, $ana));
        self::assertFalse($permission->isGranted(TemplatePermission::Personal, new TemplateActor('acme', 'leo')));
        $this->actAs('ana', 'manage');
        self::assertTrue($permission->isGranted(TemplatePermission::ManageShared, $ana));
        $this->ctx()->tenant = null;
        self::assertFalse($permission->isGranted(TemplatePermission::Personal, $ana));
        self::assertSame([], $contact->resolve(['first_name'], $own));
    }

    public function testTheStoreRefusesAnotherTenantsRowAndDropsOldClaims(): void
    {
        $this->boot();
        $this->admin();
        $store = $this->service(DoctrineTemplateMailStore::class);
        $acme  = new TenantRef('acme');
        $other = new TenantRef('globex');
        $id    = '0190a000-0000-7000-8000-00000000beef';
        $this->runner()->write(static fn () => $store->classify($other, $id, null, new DateTimeImmutable('2026-11-18')));
        $this->expectThrows(NotAvailableException::class, fn () => $this->runner()->write(static fn () => $store->classify($acme, $id, 'newsletter', new DateTimeImmutable('2026-11-18'))));
        self::assertSame([], $store->classifications($acme, [$id]));
        self::assertSame([], $store->classifications($acme, []));
        $store->forget('acme', $id);
        self::assertSame([$id => null], $store->classifications($other, [$id]), 'forget names the tenant');

        $old = new DateTimeImmutable('2026-11-16 09:00');
        $now = new DateTimeImmutable('2026-11-18 10:00');
        self::assertTrue($this->runner()->write(static fn (): bool => $store->claim($acme, str_repeat('a', 30), $old)));
        self::assertFalse($this->runner()->write(static fn (): bool => $store->claim($acme, str_repeat('a', 30), $old)));
        self::assertTrue($this->runner()->write(static fn (): bool => $store->claim($acme, str_repeat('b', 30), $now)));
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_counter WHERE resource = 'm:" . str_repeat('a', 30) . "'"), 'a claim older than a day is dropped');
        self::assertTrue($this->runner()->write(static fn (): bool => $store->claim($acme, str_repeat('a', 30), $now)));
        $store->release($acme, str_repeat('a', 30));
        self::assertTrue($this->runner()->write(static fn (): bool => $store->claim($acme, str_repeat('a', 30), $now)));
    }
}
