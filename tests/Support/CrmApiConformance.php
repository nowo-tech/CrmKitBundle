<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Support;

use Nowo\CrmKitBundle\Api\CrmApiGrant;
use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use PHPUnit\Framework\Assert;

/**
 * Shipped with the package (not archive-excluded) so a host can run the bundle's decision matrix against its own
 * kernel, area checker and token principal: build a principal per grant profile, run one operation, and compare the
 * decision (`ok`, or the refusal code) with the expected one. Depends only on the bundle's `Api` classes and PHPUnit.
 *
 * Usage (a host test): `CrmApiConformance::assertMatrix(['view' => 'TOKEN_001', 'edit' => 'ok'], fn (CrmApiGrant $g) =>
 * $ops->createCompany($principalFor($g), $tenant, new IdempotencyKey(uniqid()), $draft));`
 */
final class CrmApiConformance
{
    /**
     * The grant profiles of the matrix.
     *
     * @return array<string, CrmApiGrant>
     */
    public static function profiles(): array
    {
        return [
            'view'             => new CrmApiGrant(CrmApiLevel::View),
            'view+amounts'     => new CrmApiGrant(CrmApiLevel::View, true),
            'view+consents'    => new CrmApiGrant(CrmApiLevel::View, false, true),
            'edit'             => new CrmApiGrant(CrmApiLevel::Edit),
            'edit+amounts'     => new CrmApiGrant(CrmApiLevel::Edit, true),
            'edit+destructive' => new CrmApiGrant(CrmApiLevel::Edit, false, false, true),
            'manage'           => new CrmApiGrant(CrmApiLevel::Manage),
            'manage+all'       => new CrmApiGrant(CrmApiLevel::Manage, true, true, true),
        ];
    }

    /**
     * The decision of one call: `ok`, or the refusal code.
     */
    public static function decide(callable $call): string
    {
        try {
            $call();
        } catch (CrmApiRefusal $refusal) {
            return $refusal->errorCode;
        }

        return 'ok';
    }

    /**
     * Runs `$call` once per listed profile (`profile => expected decision`) and asserts every decision.
     *
     * @param array<string, string> $expected
     * @param callable(CrmApiGrant): mixed $call
     */
    public static function assertMatrix(array $expected, callable $call): void
    {
        $profiles = self::profiles();
        $actual   = [];
        foreach ($expected as $profile => $decision) {
            Assert::assertArrayHasKey($profile, $profiles, 'unknown profile ' . $profile);
            $actual[$profile] = self::decide(static fn (): mixed => $call($profiles[$profile]));
        }
        Assert::assertSame($expected, $actual);
    }

    /**
     * A principal with the same user, actor, reach and token as `$base` and another grant.
     */
    public static function withGrant(CrmApiPrincipal $base, CrmApiGrant $grant): CrmApiPrincipal
    {
        return new CrmApiPrincipal($base->user, $base->actor, $grant, $base->reach, $base->tokenId, $base->tokenName, $base->channel, $base->hostContext);
    }
}
