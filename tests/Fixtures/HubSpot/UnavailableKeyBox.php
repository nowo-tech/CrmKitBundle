<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures\HubSpot;

use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use SensitiveParameter;

/**
 * A secret box whose key is misconfigured (0.10.1 tests): every seal and unseal is "key not available".
 */
final class UnavailableKeyBox implements CrmSecretBoxInterface
{
    public function seal(#[SensitiveParameter] string $plaintext, SecretContext $context): SealedSecret
    {
        throw new SecretKeyUnavailableException('broken');
    }

    public function unseal(SealedSecret $sealed, SecretContext $context): string
    {
        throw new SecretKeyUnavailableException($sealed->keyId);
    }

    public function currentKeyId(): string
    {
        return 'broken';
    }
}
