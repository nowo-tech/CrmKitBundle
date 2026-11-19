<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Service\CrmCatalogueInterface;

use function array_values;
use function in_array;

/**
 * The configured party roles and affiliation purposes (contract section 6.8), validated by the configuration tree.
 * Input keys are checked against them exactly (`===`); stored keys that are no longer configured are hidden from
 * views, lists and filters but kept in the database. Stateless (configuration only).
 */
final readonly class PartyCatalogue implements CrmCatalogueInterface
{
    /**
     * @param list<string> $roles keys of `roles.catalogue`
     * @param list<string> $purposes keys of `affiliations.purposes`
     */
    public function __construct(private array $roles = ['customer', 'supplier', 'partner'], private array $purposes = ['billing', 'orders', 'technical', 'general'])
    {
    }

    /**
     * {@inheritDoc}
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * {@inheritDoc}
     */
    public function purposes(): array
    {
        return $this->purposes;
    }

    /**
     * Whether a key is a configured role.
     */
    public function hasRole(string $key): bool
    {
        return in_array($key, $this->roles, true);
    }

    /**
     * Whether a key is a configured purpose.
     */
    public function hasPurpose(string $key): bool
    {
        return in_array($key, $this->purposes, true);
    }

    /**
     * The distinct input roles, all configured.
     *
     * @param list<string> $keys
     *
     * @throws InvalidInputException `roles` => `error.role_unknown`
     *
     * @return list<string>
     */
    public function checkRoles(array $keys): array
    {
        return $this->check($keys, $this->roles, 'roles', 'error.role_unknown');
    }

    /**
     * The distinct input purposes, all configured.
     *
     * @param list<string> $keys
     *
     * @throws InvalidInputException `purposes` => `error.purpose_unknown`
     *
     * @return list<string>
     */
    public function checkPurposes(array $keys): array
    {
        return $this->check($keys, $this->purposes, 'purposes', 'error.purpose_unknown');
    }

    /**
     * The stored roles that are configured, in catalogue order.
     *
     * @param list<string> $stored
     *
     * @return list<string>
     */
    public function knownRoles(array $stored): array
    {
        return $this->known($stored, $this->roles);
    }

    /**
     * The stored purposes that are configured, in list order.
     *
     * @param list<string> $stored
     *
     * @return list<string>
     */
    public function knownPurposes(array $stored): array
    {
        return $this->known($stored, $this->purposes);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private function check(array $keys, array $allowed, string $field, string $error): array
    {
        $distinct = [];
        foreach ($keys as $key) {
            if (!in_array($key, $allowed, true)) {
                throw InvalidInputException::field($field, $error);
            }
            $distinct[$key] = $key;
        }

        return array_values($distinct);
    }

    /**
     * @param list<string> $stored
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private function known(array $stored, array $allowed): array
    {
        $known = [];
        foreach ($allowed as $key) {
            if (in_array($key, $stored, true)) {
                $known[] = $key;
            }
        }

        return $known;
    }
}
