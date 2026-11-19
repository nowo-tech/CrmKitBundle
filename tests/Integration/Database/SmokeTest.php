<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;

/**
 * Boot, create and show: the first path through every layer.
 */
final class SmokeTest extends CrmTestCase
{
    public function testCreateAndShowACompany(): void
    {
        $this->boot();
        $this->admin();
        $id   = $this->company('Acme Tools', ['website' => 'https://www.acme.test/x', 'tax_id' => 'b-12.345 678']);
        $view = $this->service(CrmReaderInterface::class)->record(SubjectType::Company, $id);
        self::assertSame('Acme Tools', $view->label);
        self::assertSame('acme.test', $this->text('SELECT domain_key FROM nowo_crm_company'));
        self::assertSame('B12345678', $this->text('SELECT tax_id_key FROM nowo_crm_company'));
        $this->client->request('GET', '/crm/companies');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/crm/companies/' . $id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Acme Tools', (string) $this->client->getResponse()->getContent());
    }
}
