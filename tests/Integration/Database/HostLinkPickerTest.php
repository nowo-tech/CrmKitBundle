<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Service\HostObjectPickerInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectMatch;
use Nowo\CrmKitBundle\Tests\Fixtures\TestOddResolver;
use Nowo\CrmKitBundle\Tests\Fixtures\TestTickets;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function array_map;
use function is_array;
use function json_decode;
use function str_repeat;
use function substr;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use const JSON_THROW_ON_ERROR;

/**
 * The link picker of the 360° page (0.7.0): the optional resolver capabilities (type label, search), the search
 * endpoint's access, tenancy and bound, and the unchanged form of a resolver with neither capability.
 */
final class HostLinkPickerTest extends CrmTestCase
{
    public function testAResolverWithoutCapabilitiesKeepsTheIdField(): void
    {
        $this->boot();
        $this->admin();
        $id   = $this->company('Acme');
        $page = $this->client->request('GET', '/crm/companies/' . $id);
        $form = $page->filter('[data-testid="crm-link-form"]');
        self::assertSame(['project'], $form->filter('select[name="crm_link[hostType]"] option')->each(static fn ($o): string => $o->text()));
        self::assertCount(1, $form->filter('input[name="crm_link[hostId]"]'));
        self::assertCount(0, $form->filter('input[name="crm_link[hostRef]"]'));
        self::assertCount(0, $form->filter('[data-nowo-ui-combobox]'));
        self::assertStringContainsString('Object id', $form->text());
        self::assertSame([], $this->service(HostObjectPickerInterface::class)->searchableTypes());
        self::assertSame([], $this->service(HostObjectPickerInterface::class)->search(SubjectType::Company, $id, 'Web'));
        self::assertSame(['groups' => []], $this->search($id, 'Web'));

        // The link itself works as before.
        $this->client->submit($form->filter('form')->form(), ['crm_link[hostType]' => 'project', 'crm_link[hostId]' => 'p1']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_host_link'));
    }

    public function testTheLabelAndThePickerOfAResolverWithBothCapabilities(): void
    {
        $this->bootTickets();
        $this->admin();
        $id   = $this->company('Acme');
        $page = $this->client->request('GET', '/crm/companies/' . $id);
        $form = $page->filter('[data-testid="crm-link-form"]');
        self::assertSame(['ticket' => 'Support ticket', 'project' => 'project'], $this->options($form->filter('select[name="crm_link[hostType]"] option')->each(static fn ($o): array => [(string) $o->attr('value'), $o->text()])));
        $root = $form->filter('[data-nowo-ui-combobox]');
        self::assertCount(1, $root);
        self::assertSame('/crm/companies/' . $id . '/links/search', $root->attr('data-nowo-ui-combobox-url'));
        $hidden = $form->filter('input[type="hidden"][name="crm_link[hostRef]"]');
        self::assertSame($hidden->attr('id'), $root->attr('data-nowo-ui-combobox-value-input'));
        self::assertCount(1, $root->filter('input[name="crm_link[hostId]"]'), 'without JavaScript the id field still submits');
        self::assertStringContainsString('nowo-ui-combobox.js', (string) $this->client->getResponse()->getContent());

        self::assertSame(['groups' => [['label' => 'Support ticket', 'options' => [['value' => 'ticket:t-1', 'label' => 'Printer broken'], ['value' => 'ticket:t-2', 'label' => 'Printer toner']]]]], $this->search($id, ' Printer '));
        self::assertSame(['no-store, private'], [$this->client->getResponse()->headers->get('Cache-Control')]);
        self::assertSame(['acme', 'Printer', 20], $this->ctx()->ticketSearches[0], 'trimmed only, in the tenant, the default bound');
        self::assertSame(['groups' => []], $this->search($id, 'leak'), "another tenant's object the resolver answers is dropped");
        self::assertSame(['groups' => []], $this->search($id, 'printer'), 'the resolver owns the comparison: CrmKit folds nothing');

        // Picking: the hidden `<type>:<id>` wins; the resolver confirms it byte for byte.
        $this->client->submit($form->filter('form')->form(), ['crm_link[hostType]' => 'project', 'crm_link[hostId]' => 'Printer broken', 'crm_link[hostRef]' => 'ticket:t-1']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame('ticket', $this->text('SELECT host_type FROM nowo_crm_host_link'));
        self::assertSame('t-1', $this->text('SELECT host_id FROM nowo_crm_host_link'));
        foreach (['ticket:T-1', 'ticket:ghost', 'project:p1', 'issue:1', 'ticket', 'ticket:'] as $forged) {
            $page = $this->client->request('GET', '/crm/companies/' . $id);
            $this->client->submit($page->filter('[data-testid="crm-link-form"] form')->form(), ['crm_link[hostType]' => 'project', 'crm_link[hostId]' => 'p2', 'crm_link[hostRef]' => $forged]);
            self::assertSame(303, $this->client->getResponse()->getStatusCode(), $forged);
            self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_host_link'), $forged . ' is refused, never a free link');
        }
        // Without a pick (JavaScript off) the typed text is the id of the chosen type, as before.
        $page = $this->client->request('GET', '/crm/companies/' . $id);
        $this->client->submit($page->filter('[data-testid="crm-link-form"] form')->form(), ['crm_link[hostType]' => 'ticket', 'crm_link[hostId]' => 't-2', 'crm_link[hostRef]' => '']);
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_host_link'));
    }

    public function testTheBoundAndTheTextLimits(): void
    {
        $this->bootTickets();
        $this->admin();
        $id = $this->company('Acme');
        self::assertSame(['groups' => [['label' => 'Support ticket', 'options' => [['value' => 'ticket:t-1', 'label' => 'Printer broken']]]]], $this->search($id, 'Printer', '1'));
        $this->search($id, 'Printer', '1000');
        $this->search($id, 'Printer', '-5');
        $this->search($id, 'Printer', 'abc');
        self::assertSame([1, 20, 1, 20], array_map(static fn (array $c): int => $c[2], $this->ctx()->ticketSearches));
        $matches = $this->service(HostObjectPickerInterface::class)->search(SubjectType::Company, $id, 'Printer', 1000);
        self::assertSame(['t-1', 't-2'], array_map(static fn (HostObjectMatch $m): string => $m->ref->id, $matches));
        $this->ctx()->ticketSearches = [];
        self::assertSame(['groups' => []], $this->search($id, ''));
        self::assertSame(['groups' => []], $this->search($id, str_repeat('a', HostObjectPickerInterface::MAX_TEXT + 1)));
        $this->client->request('GET', '/crm/companies/' . $id . '/links/search', ['q' => ['Printer']]);
        self::assertSame(['groups' => []], $this->decoded());
        self::assertSame([], $this->ctx()->ticketSearches, 'empty, too long or non-text terms never reach the host');
    }

    public function testAUserWhoCannotLinkGetsNothing(): void
    {
        $this->bootTickets();
        $this->admin();
        $id   = $this->company('Acme');
        $path = '/crm/companies/' . $id . '/links/search?q=Printer';

        $this->actAs('vic', 'view');
        self::assertSame(403, $this->httpStatus('GET', $path), 'view only: the link action needs edit');
        $this->actAs('eve', 'view', 'edit');
        $this->ctx()->denied[] = 'edit:' . $id;
        self::assertSame(403, $this->httpStatus('GET', $path), 'a record the access check refuses');
        $this->ctx()->denied = [];
        self::assertSame(404, $this->httpStatus('GET', '/crm/companies/' . $this->otherId($id) . '/links/search?q=Printer'), 'an unknown record');
        $this->ctx()->tenant = 'beta';
        $this->actAs('eve', 'view', 'edit');
        self::assertSame(404, $this->httpStatus('GET', $path), "another tenant's record");
        $this->ctx()->tenant          = 'acme';
        $this->ctx()->enabled['acme'] = false;
        self::assertSame(404, $this->httpStatus('GET', $path), 'the CRM is off for the tenant');
        self::assertSame([], $this->ctx()->ticketSearches, 'the host was never asked');

        // In its own tenant a beta user finds only beta's tickets.
        $this->ctx()->enabled = [];
        $this->ctx()->tenant  = 'beta';
        $this->actAs('bob', 'view', 'edit', 'create');
        $beta = $this->company('Beta');
        self::assertSame(['groups' => [['label' => 'Support ticket', 'options' => [['value' => 'ticket:t-9', 'label' => 'Printer beta']]]]], $this->search($beta, 'Printer'));
        self::assertSame('beta', $this->ctx()->ticketSearches[0][0]);
    }

    public function testATrashedRecordAnswersAConflict(): void
    {
        $this->bootTickets();
        $this->admin();
        $id = $this->company('Acme');
        $this->records()->trash(SubjectType::Company, $id, 1);
        self::assertSame(409, $this->httpStatus('GET', '/crm/companies/' . $id . '/links/search?q=Printer'));
        self::assertSame([], $this->ctx()->ticketSearches);
    }

    public function testOddResolvers(): void
    {
        $this->bootWithUiKit([], [], static function (ServicesConfigurator $services): void {
            $services->set('test.tickets', TestTickets::class)->args([service('test.context')])->tag(CrmHostObjectResolverInterface::HOST_OBJECT_TAG);
            $services->set('test.odd_duplicate', TestOddResolver::class)->args(['ticket', 'Second tickets', null, ['x-1' => 'Printer x']])->tag(CrmHostObjectResolverInterface::HOST_OBJECT_TAG);
            $services->set('test.odd_invalid', TestOddResolver::class)->args(['Bad Type', 'Bad', null, ['b-1' => 'Printer b']])->tag(CrmHostObjectResolverInterface::HOST_OBJECT_TAG);
            $services->set('test.odd_shared', TestOddResolver::class)->args(['case', 'Support ticket', null, ['c-1' => ' ', 'c-2' => 'Second case']])->tag(CrmHostObjectResolverInterface::HOST_OBJECT_TAG);
            $services->set('test.odd_translated', TestOddResolver::class)->args(['thing', 'link.form.object', 'NowoCrmKitBundle', []])->tag(CrmHostObjectResolverInterface::HOST_OBJECT_TAG);
        });
        $this->admin();
        $id   = $this->company('Acme');
        $page = $this->client->request('GET', '/crm/companies/' . $id);
        self::assertSame(['ticket' => 'Support ticket', 'Bad Type' => 'Bad', 'case' => 'Support ticket (case)', 'thing' => 'Object', 'project' => 'project'], $this->options($page->filter('select[name="crm_link[hostType]"] option')->each(static fn ($o): array => [(string) $o->attr('value'), $o->text()])));
        self::assertSame(['ticket', 'case', 'thing'], $this->service(HostObjectPickerInterface::class)->searchableTypes());
        // One resolver per type (the first), no invalid type, a blank label shows the id, a type with no answer has no group.
        self::assertSame(['groups' => [
            ['label' => 'Support ticket', 'options' => [['value' => 'ticket:t-1', 'label' => 'Printer broken'], ['value' => 'ticket:t-2', 'label' => 'Printer toner']]],
            ['label' => 'Support ticket (case)', 'options' => [['value' => 'case:c-1', 'label' => 'c-1'], ['value' => 'case:c-2', 'label' => 'Second case']]],
        ]], $this->search($id, 'Printer'));
    }

    private function bootTickets(): void
    {
        $this->bootWithUiKit([], [], static function (ServicesConfigurator $services): void {
            $services->set('test.tickets', TestTickets::class)->args([service('test.context')])->tag(CrmHostObjectResolverInterface::HOST_OBJECT_TAG);
        });
    }

    /**
     * @return array<mixed>
     */
    private function search(string $id, string $text, ?string $limit = null): array
    {
        $this->client->request('GET', '/crm/companies/' . $id . '/links/search', ['q' => $text] + ($limit === null ? [] : ['limit' => $limit]), [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->decoded();
    }

    /**
     * @return array<mixed>
     */
    private function decoded(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($data));

        return $data;
    }

    /**
     * @param list<array{string, string}> $pairs
     *
     * @return array<string, string>
     */
    private function options(array $pairs): array
    {
        $options = [];
        foreach ($pairs as [$value, $text]) {
            $options[$value] = $text;
        }

        return $options;
    }

    private function otherId(string $id): string
    {
        return $id[0] === '0' ? '1' . substr($id, 1) : '0' . substr($id, 1);
    }
}
