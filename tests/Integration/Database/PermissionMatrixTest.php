<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Tests\Fixtures\TestContext;

use function in_array;

/**
 * Every page × every profile (contract section 13, deny by default): no tenant, CRM off for the tenant, nobody signed
 * in ⇒ 404; a record of another tenant ⇒ 404; inside the tenant the exact permission decides (403 without it).
 */
final class PermissionMatrixTest extends CrmTestCase
{
    public function testEveryPageForEveryProfile(): void
    {
        $this->boot();
        $this->admin();
        $company = $this->company('Acme');
        $contact = $this->contact('Ada', 'L', 'ada@x.test');
        $tag     = $this->service(TagServiceInterface::class)->create('VIP', 'red');
        $field   = $this->service(FieldServiceInterface::class)->define(SubjectType::Company, 'tier', 'Tier', FieldType::Text, [], false, 1);
        $import  = $this->service(ImportServiceInterface::class)->upload(SubjectType::Company, ImportFormat::Csv, 'a.csv', "name\nX\n");
        $this->service(ImportServiceInterface::class)->confirm($import, ['name' => 'name'], DuplicatePolicy::Skip, 1);

        // page => the permission it needs
        $pages = [
            '/crm/companies'                             => 'view',
            '/crm/contacts'                              => 'view',
            '/crm/companies/' . $company                 => 'view',
            '/crm/contacts/' . $contact                  => 'view',
            '/crm/contacts/' . $contact . '/vcard'       => 'view',
            '/crm/companies/new'                         => 'edit',
            '/crm/companies/' . $company . '/edit'       => 'edit',
            '/crm/contacts/' . $contact . '/edit'        => 'edit',
            '/crm/companies/' . $company . '/merge'      => 'edit',
            '/crm/import'                                => 'edit',
            '/crm/import/' . $import                     => 'edit',
            '/crm/companies/export.csv'                  => 'manage',
            '/crm/contacts/' . $contact . '/gdpr-export' => 'manage',
            '/crm/admin/tags'                            => 'manage',
            '/crm/admin/tags/' . $tag                    => 'manage',
            '/crm/admin/fields'                          => 'manage',
            '/crm/admin/fields/' . $field                => 'manage',
            '/crm/admin/settings'                        => 'manage',
        ];
        // profile => [grants (view is always granted with edit / manage in Beacon), allowed permissions]
        $profiles = [
            'viewer'  => [['view'], ['view']],
            'editor'  => [['view', 'edit'], ['view', 'edit']],
            'manager' => [['view', 'edit', 'manage'], ['view', 'edit', 'manage']],
            'nothing' => [[], []],
        ];
        foreach ($profiles as $actor => [$grants, $allowed]) {
            $this->ctx()->grants['acme'][$actor] = $grants;
            $this->ctx()->actor                  = $actor;
            foreach ($pages as $page => $needs) {
                $expected = in_array($needs, $allowed, true) ? 200 : 403;
                self::assertSame($expected, $this->httpStatus('GET', $page), $actor . ' ' . $page);
            }
        }

        $this->ctx()->actor = 'manager';
        foreach (['tenant' => static function (TestContext $ctx): void { $ctx->tenant = null; }, 'feature' => static function (TestContext $ctx): void { $ctx->enabled['acme'] = false; }, 'actor' => static function (TestContext $ctx): void { $ctx->actor = null; }] as $what => $break) {
            $break($this->ctx());
            foreach ($pages as $page => $needs) {
                self::assertSame(404, $this->httpStatus('GET', $page), 'no ' . $what . ' ' . $page);
            }
            $this->ctx()->tenant  = 'acme';
            $this->ctx()->enabled = [];
            $this->ctx()->actor   = 'manager';
        }

        // Another tenant where the same person holds every permission: the records of acme are not there.
        $this->ctx()->tenant                    = 'beta';
        $this->ctx()->grants['beta']['manager'] = ['view', 'edit', 'manage'];
        foreach ($pages as $page => $needs) {
            $expected = str_contains($page, $company) || str_contains($page, $contact) || str_contains($page, $tag) || str_contains($page, $field) || str_contains($page, $import) ? 404 : 200;
            self::assertSame($expected, $this->httpStatus('GET', $page), 'beta ' . $page);
        }
    }
}
