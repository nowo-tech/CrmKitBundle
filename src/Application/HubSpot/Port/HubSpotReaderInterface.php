<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot\Port;

use Nowo\CrmKitBundle\Application\HubSpot\HubSpotException;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotAssociation;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotIdPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotList;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotOwnerPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotProperty;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotTokenInfo;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;
use Nowo\CrmKitBundle\Domain\Value\HubSpotKey;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotToken;

/**
 * Reads HubSpot (0.10.0, internal port): typed pages, never HTTP types or raw JSON. The implementation calls only the
 * constant host `https://api.hubapi.com`, read-only endpoints, with the token as a bearer header. Every failure is a
 * {@see HubSpotException} with a code and a status. Called outside any database transaction.
 *
 * @internal
 */
interface HubSpotReaderInterface
{
    /**
     * The account (portal) id of the credential (`GET /account-info/v3/details`; any valid credential).
     *
     * @throws HubSpotException 401: the credential is refused
     */
    public function accountId(HubSpotToken $token): HubSpotAccountId;

    /**
     * HubSpot's own account and scope list of a legacy private app token (the token-info endpoint); a service key is
     * not known there (a {@see HubSpotException} that is not transient).
     *
     * @throws HubSpotException
     */
    public function tokenInfo(HubSpotToken $token): HubSpotTokenInfo;

    /**
     * Whether the credential holds a read scope, asked with the smallest request of an endpoint that needs it
     * (`limit=1`): true on 2xx, false on 403.
     *
     * @param string $scope one of the reader's probed scopes
     *
     * @throws HubSpotException any other failure
     */
    public function probe(HubSpotToken $token, string $scope): bool;

    /**
     * How many objects of the kind the account holds (not archived).
     *
     * @throws HubSpotException
     */
    public function count(HubSpotToken $token, HubSpotObject $object): int;

    /**
     * The object's properties (for the mapping step).
     *
     * @throws HubSpotException
     *
     * @return list<HubSpotProperty>
     */
    public function properties(HubSpotToken $token, HubSpotObject $object): array;

    /**
     * One page of objects with the given properties only.
     *
     * @param list<HubSpotKey> $properties
     *
     * @throws HubSpotException
     */
    public function records(HubSpotToken $token, HubSpotObject $object, array $properties, ?HubSpotCursor $after, int $limit): HubSpotPage;

    /**
     * The companies of the given contacts (at most 100 ids).
     *
     * @param list<HubSpotObjectId> $contacts
     *
     * @throws HubSpotException
     *
     * @return list<HubSpotAssociation>
     */
    public function associations(HubSpotToken $token, array $contacts): array;

    /**
     * One page of owners.
     *
     * @throws HubSpotException
     */
    public function owners(HubSpotToken $token, ?HubSpotCursor $after, int $limit): HubSpotOwnerPage;

    /**
     * The account's lists of companies or contacts (the given ids only, or the first 250).
     *
     * @param list<HubSpotObjectId> $ids
     *
     * @throws HubSpotException
     *
     * @return list<HubSpotList>
     */
    public function lists(HubSpotToken $token, array $ids = []): array;

    /**
     * One page of a list's member ids.
     *
     * @throws HubSpotException
     */
    public function members(HubSpotToken $token, HubSpotObjectId $list, ?HubSpotCursor $after, int $limit): HubSpotIdPage;
}
