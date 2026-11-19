<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\Api;

use Nowo\CrmKitBundle\Api\CrmApiGrant;
use Nowo\CrmKitBundle\Api\Input\CrmApiNoteDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordDraft;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Tests\Support\CrmApiConformance;

/**
 * The shipped conformance helper run against the bundle's own kernel: every grant profile × a read, a create, an
 * edit, a destructive write and a consents read.
 */
final class ApiConformanceTest extends ApiTestCase
{
    public function testTheGrantMatrix(): void
    {
        $this->bootApi();
        $t    = $this->acme();
        $base = $this->principal();
        $id   = new RecordId($this->company('Acme'));
        $as   = static fn (CrmApiGrant $g) => CrmApiConformance::withGrant($base, $g);
        $all  = ['view' => 'ok', 'view+amounts' => 'ok', 'edit' => 'ok', 'manage' => 'ok'];
        CrmApiConformance::assertMatrix($all, fn (CrmApiGrant $g) => $this->reads()->company($as($g), $t, $id));
        CrmApiConformance::assertMatrix(['view' => 'TOKEN_001', 'edit' => 'ok', 'manage' => 'ok'], fn (CrmApiGrant $g) => $this->relations()->addNote($as($g), $t, $this->key(), SubjectType::Company, $id, new CrmApiNoteDraft('n')));
        CrmApiConformance::assertMatrix(['view' => 'TOKEN_001', 'edit+amounts' => 'ok'], fn (CrmApiGrant $g) => $this->writes()->createCompany($as($g), $t, $this->key(), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'N' . bin2hex(random_bytes(3))])));
        CrmApiConformance::assertMatrix(['view' => 'TOKEN_001', 'view+consents' => 'TOKEN_001', 'edit' => 'TOKEN_001', 'manage' => 'TOKEN_001'], fn (CrmApiGrant $g) => $this->writes()->trash($as($g), $t, $this->key(), SubjectType::Company, $id, null));
        $contact = new RecordId($this->contact('Ada', 'L'));
        CrmApiConformance::assertMatrix(['view' => 'TOKEN_001', 'view+consents' => 'ok'], fn (CrmApiGrant $g) => $this->reads()->consents($as($g), $t, $contact));
        CrmApiConformance::assertMatrix(['edit+destructive' => 'ok', 'manage+all' => 'ACCESS_001'], fn (CrmApiGrant $g) => $this->writes()->trash($as($g), $t, $this->key(), SubjectType::Company, $id, null));
    }
}
