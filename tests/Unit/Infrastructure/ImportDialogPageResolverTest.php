<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ImportDialogPageResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * 0.10.3: the owning list of an import step opened directly. Anything it cannot place (another route, no id, an
 * import the person may not see or that does not exist) is null: no redirect, no oracle.
 */
final class ImportDialogPageResolverTest extends TestCase
{
    public function testTheFileStepFollowsItsSubjectAndAnythingElseIsNull(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request(['subject' => 'contact']));
        $imports = $this->createMock(ImportServiceInterface::class);
        $imports->method('preview')->willThrowException(new NotAvailableException());
        $resolver = new ImportDialogPageResolver($requests, $imports);

        self::assertSame(['route' => 'nowo_crm_kit_list', 'params' => ['type' => 'contacts']], $resolver->resolve(ImportDialogPageResolver::FILE_ROUTE, []));
        self::assertNull($resolver->resolve('nowo_crm_kit_settings', ['id' => 'x']), 'another route');
        self::assertNull($resolver->resolve(ImportDialogPageResolver::IMPORT_ROUTE, []), 'no id');
        self::assertNull($resolver->resolve(ImportDialogPageResolver::IMPORT_ROUTE, ['id' => '01890000-0000-7000-8000-000000000000']), 'refused or unknown');

        // without a request (a CLI warm-up), the file step goes to the companies list
        self::assertSame(['route' => 'nowo_crm_kit_list', 'params' => ['type' => 'companies']], (new ImportDialogPageResolver(new RequestStack(), $imports))->resolve(ImportDialogPageResolver::FILE_ROUTE, []));
    }
}
