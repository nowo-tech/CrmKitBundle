<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Api;

use Nowo\CrmKitBundle\Api\CrmApiCapability;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Tests\Support\CrmApiContract;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function getenv;

/**
 * The shipped contract file equals the operation catalogue; every operation's metadata holds the catalogue's rules.
 */
final class ApiContractTest extends TestCase
{
    public function testTheContractFileMatchesTheCatalogue(): void
    {
        if (getenv('NOWO_WRITE_CONTRACT') === '1') {
            file_put_contents(CrmApiContract::FILE, CrmApiContract::json());
        }
        self::assertSame(CrmApiContract::json(), file_get_contents(CrmApiContract::FILE), 'regenerate with NOWO_WRITE_CONTRACT=1 and review the diff');
    }

    public function testCatalogueRules(): void
    {
        $tools = [];
        foreach (CrmApiOperation::cases() as $operation) {
            self::assertSame('0.3.0', $operation->since());
            self::assertTrue($operation->idempotent());
            if ($operation->kind() === CrmApiOperation::KIND_DESTRUCTIVE) {
                self::assertSame(CrmApiCapability::Trash, $operation->capability());
            }
            foreach ($operation->codes() as $code) {
                self::assertMatchesRegularExpression(CrmApiCode::PATTERN, $code);
                self::assertArrayHasKey($code, CrmApiCode::STATUS);
            }
            if ($operation->isWrite()) {
                self::assertContains(CrmApiCode::IDEMP_MISSING, $operation->codes());
            }
            $tool = $operation->toolName();
            if ($tool !== null) {
                self::assertArrayNotHasKey($tool, $tools);
                $tools[$tool] = true;
            }
        }
        self::assertCount(26, $tools);
        foreach (CrmApiCode::STATUS as $code => $status) {
            self::assertMatchesRegularExpression(CrmApiCode::PATTERN, $code);
            self::assertArrayHasKey($code, CrmApiCode::MESSAGES);
            self::assertDoesNotMatchRegularExpression('/%|\{/', CrmApiCode::MESSAGES[$code]);
        }
    }
}
