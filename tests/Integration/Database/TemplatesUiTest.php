<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\ActivityFeedInterface;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplateInput;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\ActivityRecordBlock;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineDialogs;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\TemplateController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\UiSupport;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\UiKitBundle\View\Action;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function is_array;

/**
 * 0.13.0 (Beacon spec 254, A-2): the templates page (list, create / edit dialogs on demand, delete) and *Write e-mail*
 * on a contact's 360° page (insert template and snippet rendered by the server, send, refusals in the dialog).
 */
final class TemplatesUiTest extends TemplatesTestCase
{
    private const DIALOG = ['HTTP_X_NOWO_DIALOG' => '1'];

    public function testTheTemplatesPageCreatesEditsAndDeletes(): void
    {
        $this->boot();
        $this->admin();
        $page = $this->client->request('GET', '/crm/templates');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('[data-testid="crm-templates"]'));
        self::assertStringContainsString('No templates yet', $page->text());
        self::assertCount(1, $page->filter('dialog#' . TemplateController::DIALOG));
        self::assertSame('/crm/templates/new/template', $page->filter('[data-testid="crm-template-new-template"]')->attr('href'));
        self::assertSame(TemplateController::DIALOG, $page->filter('[data-testid="crm-template-new-snippet"]')->attr('data-nowo-ui-dialog-open'));
        self::assertCount(1, $page->filter('a[href="/crm/templates"]'), 'the area tab');

        // A template in the dialog: 422 with MailKit's error on its field, then 204.
        $fragment = $this->client->request('GET', '/crm/templates/new/template', [], [], self::DIALOG);
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('{{ contact.first_name }}', $fragment->text(), 'the placeholders in the help');
        $form = $fragment->filter('form[name="crm_template"]')->form();
        $this->client->submit($form, ['crm_template[name]' => 'Offer', 'crm_template[key]' => 'Bad Key', 'crm_template[locale]' => 'en', 'crm_template[subject]' => 'Offer', 'crm_template[purpose]' => 'marketing_email', 'crm_template[text]' => 'Hi {{ contact.first_name }}', 'crm_template[shared]' => '1'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_template[name]' => 'Offer', 'crm_template[key]' => 'offer', 'crm_template[locale]' => 'en', 'crm_template[subject]' => 'Offer', 'crm_template[purpose]' => 'marketing_email', 'crm_template[text]' => 'Hi {{ contact.first_name }}', 'crm_template[shared]' => '1'], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('/crm/templates', $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
        // A snippet on the page (no-JS).
        $full = $this->client->request('GET', '/crm/templates/new/snippet');
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $full->filter('[name="crm_template[subject]"]'));
        $this->client->submit($full->filter('form[name="crm_template"]')->form(), ['crm_template[name]' => 'Signature', 'crm_template[key]' => 'sig', 'crm_template[locale]' => 'en', 'crm_template[text]' => '-- {{ me.name }}']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());

        $page = $this->client->request('GET', '/crm/templates');
        $rows = $page->filter('[data-testid="crm-templates"] tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('Marketing e-mails', $page->text());
        self::assertStringContainsString('Organisation', $page->text());
        $legacy = $this->mailKit()->seed('acme', null, TemplateKind::Template, 'legacy', 'Old', 'Old', '<p>Old</p>');
        $page   = $this->client->request('GET', '/crm/templates');
        self::assertStringContainsString('Not classified', $page->text());

        // Edit: the stored values; a stale version is a conflict in the dialog; the HTML notice.
        $edit = $this->client->request('GET', '/crm/templates/' . $legacy, [], [], self::DIALOG);
        self::assertStringContainsString('HTML body written elsewhere', $edit->text());
        self::assertSame('legacy', $edit->filter('[name="crm_template[key]"]')->attr('value'));
        self::assertCount(0, $edit->filter('[name="crm_template[shared]"]'), 'the scope never changes');
        $form = $edit->filter('form[name="crm_template"]')->form();
        $this->mailKit()->update(new TemplateActor('acme', 'ana'), $legacy, 1, new TemplateDraft(TemplateKind::Template, TemplateScope::Shared, 'legacy', 'Old', 'en', 'Old', '', 'Changed'));
        $this->client->submit($form, ['crm_template[purpose]' => 'service'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('at the same moment', (string) $this->client->getResponse()->getContent());
        $edit = $this->client->request('GET', '/crm/templates/' . $legacy);
        $this->client->submit($edit->filter('form[name="crm_template"]')->form(), ['crm_template[purpose]' => 'service']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_template_purpose WHERE id = ? AND purpose IS NULL', [$legacy]));

        // Delete (POST action with the token and the shown version).
        $page  = $this->client->request('GET', '/crm/templates');
        $token = (string) $page->filter('input[name="crm_action[_token]"]')->first()->attr('value');
        $this->client->request('POST', '/crm/templates/' . $legacy . '/delete?version=2', ['crm_action' => ['_token' => $token]]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'a stale version is a flash');
        self::assertArrayHasKey($legacy, $this->mailKit()->rows);
        $this->client->request('POST', '/crm/templates/' . $legacy . '/delete?version=3', ['crm_action' => ['_token' => $token]]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_template_purpose WHERE id = ?', [$legacy]));
        $this->client->request('POST', '/crm/templates/' . $legacy . '/delete?version=3', ['crm_action' => ['_token' => $token]]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $other = $this->mailKit()->seed('acme', null, TemplateKind::Template, 'other', 'O', 'O');
        $this->client->request('POST', '/crm/templates/' . $other . '/delete?version=1', ['crm_action' => ['_token' => 'nope']]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'a bad token is a flash');
        self::assertArrayHasKey($other, $this->mailKit()->rows);

        // Shared rows: no edit / delete without manage, 403 on their page; nothing without edit.
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        $page = $this->client->request('GET', '/crm/templates');
        self::assertCount(1, $page->filter('[data-testid="crm-template-edit"]'), 'only the personal snippet');
        self::assertSame(403, $this->httpStatus('GET', '/crm/templates/' . $other));
        $create = $this->client->request('GET', '/crm/templates/new/template');
        self::assertCount(1, $create->filter('[name="crm_template[shared]"]'), 'only "Only me"');
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view');
        self::assertSame(403, $this->httpStatus('GET', '/crm/templates'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/templates/new/template'));
        $this->ctx()->tenant = null;
        self::assertSame(404, $this->httpStatus('GET', '/crm/templates'));
    }

    public function testWriteEmailInsertsAndSendsFromTheContactPage(): void
    {
        $this->boot(['pipeline' => ['enabled' => true, 'task_port' => 'test.task_port']]);
        $this->admin();
        $ada  = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        $acme = $this->company('Acme');
        $this->records()->affiliate($ada, $acme, null, true);
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $deal   = $this->service(DealService::class)->create(new DealInput('Engines', $funnel, contactId: $ada));
        $offer  = $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'offer', 'Offer', 'en', 'Offer for {{ company.name }}', "Hi {{ contact.first_name }},\n{{ deal.title }} {{ contact.mobile }}", 'marketing_email'));
        $plain  = $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'plain', 'Plain', 'en', 'Hello', 'Hello {{ contact.first_name }}'));
        $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Snippet, false, 'sig', 'Signature', 'en', '', '-- {{ me.name }}'));
        $this->mailKit()->seed('acme', null, TemplateKind::Template, 'legacy', 'Old', 'Old');

        // The opener on the contact page (not on a company's), in the activity dialog.
        $page   = $this->client->request('GET', '/crm/contacts/' . $ada);
        $opener = $page->filter('[data-testid="crm-mail-write"]');
        self::assertSame('/crm/contacts/' . $ada . '/write-email', $opener->attr('href'));
        self::assertSame(PipelineDialogs::ACTIVITY_DIALOG, $opener->attr('data-nowo-ui-dialog-open'));
        $this->client->request('GET', '/crm/companies/' . $acme);
        self::assertStringNotContainsString('crm-mail-write', (string) $this->client->getResponse()->getContent());

        $fragment = $this->client->request('GET', '/crm/contacts/' . $ada . '/write-email', [], [], self::DIALOG);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('ada@example.test', $fragment->filter('[data-testid="crm-mail-dialog-form-to"]')->text());
        self::assertSame(['', $offer, $plain], $fragment->filter('select[name="crm_mail[template]"] option')->each(static fn ($o): string => (string) $o->attr('value')), 'unclassified templates are not offered');
        self::assertCount(1, $fragment->filter('select[name="crm_mail[deal]"] option[value="' . $deal . '"]'));
        self::assertSame('crm-mail-dialog-form-default', $fragment->filter('form button[type="submit"]')->first()->attr('data-testid'), 'Enter only shows the form again');
        $form = $fragment->filter('form[name="crm_mail"]')->form();
        $this->client->request('POST', '/crm/contacts/' . $ada . '/write-email', $this->values($form, ['crm_mail[stay]' => '', 'crm_mail[subject]' => 'Draft']), [], self::DIALOG);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->mailer()->sent);

        // Insert the template (200, re-rendered with the values the person may see), then a snippet.
        $this->field($form, 'crm_mail[template]')->setValue($offer);
        $this->field($form, 'crm_mail[deal]')->setValue($deal);
        $fragment = $this->client->request('POST', '/crm/contacts/' . $ada . '/write-email', $this->values($form, ['crm_mail[insert_template]' => '']), [], self::DIALOG);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Offer for Acme', $fragment->filter('[name="crm_mail[subject]"]')->attr('value'));
        self::assertSame("Hi Ada,\nEngines {{ contact.mobile }}", $fragment->filter('textarea[name="crm_mail[text]"]')->text(null, false));
        self::assertStringContainsString('contact.mobile', $fragment->filter('[data-testid="crm-mail-dialog-form-notice"]')->text());
        self::assertSame($offer, $fragment->filter('[name="crm_mail[applied]"]')->attr('value'));
        $form = $fragment->filter('form[name="crm_mail"]')->form();
        $this->field($form, 'crm_mail[snippet]')->setValue((string) $fragment->filter('select[name="crm_mail[snippet]"] option')->eq(1)->attr('value'));
        $fragment = $this->client->request('POST', '/crm/contacts/' . $ada . '/write-email', $this->values($form, ['crm_mail[insert_snippet]' => '']), [], self::DIALOG);
        self::assertSame("Hi Ada,\nEngines {{ contact.mobile }}\n\n-- Ana", $fragment->filter('textarea[name="crm_mail[text]"]')->text(null, false));
        // Insert again with the same template: nothing changes (Enter in a field never overwrites the text).
        $form     = $fragment->filter('form[name="crm_mail"]')->form();
        $fragment = $this->client->request('POST', '/crm/contacts/' . $ada . '/write-email', $this->values($form, ['crm_mail[insert_template]' => '']), [], self::DIALOG);
        self::assertStringContainsString('-- Ana', $fragment->filter('textarea[name="crm_mail[text]"]')->text(null, false));

        // Send: refused without the consent (422 in the dialog), sent with it (204), the second post is refused.
        $form   = $fragment->filter('form[name="crm_mail"]')->form();
        $values = $this->values($form, []);
        $this->client->request('POST', '/crm/contacts/' . $ada . '/write-email', $values, [], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('has not given consent', (string) $this->client->getResponse()->getContent());
        $this->service(ConsentServiceInterface::class)->record($ada, 'marketing_email', true, ConsentSource::Manual);
        $this->client->request('POST', '/crm/contacts/' . $ada . '/write-email', $values, [], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('/crm/contacts/' . $ada, $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
        self::assertCount(1, $this->mailer()->sent);
        $this->client->request('POST', '/crm/contacts/' . $ada . '/write-email', $values, [], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('was sent already', (string) $this->client->getResponse()->getContent());
        $page = $this->client->request('GET', '/crm/contacts/' . $ada);
        self::assertStringContainsString('Offer for Acme', $page->filter('#crm-activities')->text());

        // The page (no-JS): Send first, then Cancel; a rate-limited send is an error on the form.
        $full = $this->client->request('GET', '/crm/contacts/' . $ada . '/write-email');
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());
        $this->client->submit($full->filter('form[name="crm_mail"]')->form(), ['crm_mail[subject]' => 'Hello', 'crm_mail[text]' => 'Plain words']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertCount(2, $this->mailer()->sent);
        $this->mailer()->fail = true;
        $full                 = $this->client->request('GET', '/crm/contacts/' . $ada . '/write-email');
        $this->client->submit($full->filter('form[name="crm_mail"]')->form(), ['crm_mail[subject]' => 'Hello', 'crm_mail[text]' => 'Plain words']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('could not be sent', (string) $this->client->getResponse()->getContent());
    }

    public function testWriteEmailRefusalsAndLimits(): void
    {
        $this->boot(['templates' => ['send_rate_limit' => ['limit' => 1, 'interval' => 60]]]);
        $this->admin();
        $ada    = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        $nobody = $this->contact('No', 'Address');
        $page   = $this->client->request('GET', '/crm/contacts/' . $nobody . '/write-email');
        self::assertStringContainsString('has no e-mail address', $page->filter('[data-testid="crm-mail-to"]')->text());
        self::assertCount(0, $page->filter('select[name="crm_mail[template]"]'), 'no template, no picker');
        $this->client->submit($page->filter('form[name="crm_mail"]')->form(), ['crm_mail[subject]' => 'Hi', 'crm_mail[text]' => 'Text']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $send = function () use ($ada): int {
            $page = $this->client->request('GET', '/crm/contacts/' . $ada . '/write-email', [], [], self::DIALOG);
            $this->client->submit($page->filter('form[name="crm_mail"]')->form(), ['crm_mail[subject]' => 'Hi', 'crm_mail[text]' => 'Text'], self::DIALOG);

            return $this->client->getResponse()->getStatusCode();
        };
        self::assertSame(204, $send());
        self::assertSame(422, $send());
        self::assertStringContainsString('many e-mails', (string) $this->client->getResponse()->getContent());

        // A bad deal value is an invalid form; a merged / trashed contact goes back to its record.
        $page = $this->client->request('GET', '/crm/contacts/' . $ada . '/write-email');
        $this->client->request('POST', '/crm/contacts/' . $ada . '/write-email', ['crm_mail' => ['subject' => 'x', 'text' => 'y', 'deal' => 'nope', '_token' => (string) $page->filter('[name="crm_mail[_token]"]')->attr('value')]]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET status = 'trashed' WHERE id = ?", [$ada]);
        $this->client->request('GET', '/crm/contacts/' . $ada . '/write-email');
        self::assertSame(303, $this->client->getResponse()->getStatusCode());

        // Without UiKit's remote dialog responder the opener is a plain link.
        $ui      = $this->service(UiSupport::class);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = $this->kernel->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
        $block = new ActivityRecordBlock($ui, $this->service(ActivityFeedInterface::class), $this->service(CrmAccess::class), $this->service(CrmReaderInterface::class), new PipelineDialogs($ui, null, true, PipelineDialogs::ACTIVITY_DIALOG), new RequestStack(), mail: true);
        /** @var array{openers: list<Action>} $vars */
        $vars   = $block->variables($this->service(CrmReaderInterface::class)->record(SubjectType::Contact, $nobody), true, []);
        $opener = $vars['openers'][3] ?? null;
        self::assertNotNull($opener);
        self::assertSame('/crm/contacts/' . $nobody . '/write-email', $opener->href);
        self::assertArrayNotHasKey('data-nowo-ui-dialog-open', $opener->attributes);
        $stack->pop();

        // Edit on the contact; the routes exist only with the templates.
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        $this->ctx()->denied[] = 'edit:' . $nobody;
        self::assertSame(403, $this->httpStatus('GET', '/crm/contacts/' . $nobody . '/write-email'));
        $this->bootWithUiKit(['activities' => ['enabled' => true, 'notifier' => 'test.activities']]);
        $this->admin();
        self::assertSame(404, $this->httpStatus('GET', '/crm/templates'));
        $ada = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        self::assertStringNotContainsString('crm-mail-write', $this->client->request('GET', '/crm/contacts/' . $ada)->html());
        self::assertStringNotContainsString('/crm/templates', $this->client->request('GET', '/crm/contacts/' . $ada)->html());
    }

    /**
     * The values of a form plus extra fields (a clicked button).
     *
     * @param array<string, string> $extra
     *
     * @return array<string, mixed>
     */
    private function values(Form $form, array $extra): array
    {
        $values = $form->getPhpValues();
        $fields = is_array($values['crm_mail'] ?? null) ? $values['crm_mail'] : [];
        foreach ($extra as $name => $value) {
            if (preg_match('/\Acrm_mail\[(\w+)\]\z/', $name, $m) === 1) {
                $fields[$m[1]] = $value;
            }
        }

        return ['crm_mail' => $fields];
    }

    private function field(Form $form, string $name): FormField
    {
        $field = $form[$name];
        self::assertInstanceOf(FormField::class, $field);

        return $field;
    }
}
