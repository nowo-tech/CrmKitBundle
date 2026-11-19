<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

/**
 * The configured catalogues (contract section 6.8): party roles (`roles.catalogue`) and affiliation purposes
 * (`affiliations.purposes`). Labels are the translation keys `role.<key>` and `purpose.<key>` of the
 * `NowoCrmKitBundle` domain.
 */
interface CrmCatalogueInterface
{
    /**
     * The party role keys, configuration order.
     *
     * @return list<string>
     */
    public function roles(): array;

    /**
     * The affiliation purpose keys, configuration order.
     *
     * @return list<string>
     */
    public function purposes(): array;
}
