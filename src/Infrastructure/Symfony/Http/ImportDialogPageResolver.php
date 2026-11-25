<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Http;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\UiKitBundle\Dialog\RemoteDialogPageResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

use function is_string;

/**
 * The list an import step opened directly lands on (0.10.3, `import.dialog.direct_get: redirect`, UiKit 2.74
 * `page_resolver`): the contacts list for a contact import, else the companies list. The file step reads the
 * `subject` of its query (`?subject=contact`); the steps of an import (`/import/{id}`) read the import's subject
 * through {@see ImportServiceInterface::preview()}, which checks the person's access to that import again (audit
 * ZBD-L1: a resolver checks access itself) and answers null when it refuses. Read-only and stateless (worker mode):
 * nothing is kept between calls.
 *
 * @internal
 */
final readonly class ImportDialogPageResolver implements RemoteDialogPageResolverInterface
{
    /** The owning page of the import steps: the companies / contacts list. */
    public const PAGE = 'nowo_crm_kit_list';

    /** The import file step (`/import`). */
    public const FILE_ROUTE = 'nowo_crm_kit_import';

    /** A step of an import (`/import/{id}`). */
    public const IMPORT_ROUTE = 'nowo_crm_kit_import_show';

    /**
     * Wires the collaborators.
     */
    public function __construct(private RequestStack $requests, private ImportServiceInterface $imports)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(string $route, array $params): ?array
    {
        if ($route === self::FILE_ROUTE) {
            $subject = $this->requests->getCurrentRequest()?->query->get('subject');

            return $this->page($subject === SubjectType::Contact->value ? SubjectType::Contact : SubjectType::Company);
        }
        if ($route !== self::IMPORT_ROUTE || !is_string($params['id'] ?? null)) {
            return null;
        }
        try {
            $type = $this->imports->preview($params['id'], 1)->subjectType;
        } catch (Throwable) {
            // Not found, not allowed, another tenant: no redirect (the step keeps its own answer, no oracle).
            return null;
        }

        return $this->page($type === SubjectType::Contact ? SubjectType::Contact : SubjectType::Company);
    }

    /**
     * @return array{route: string, params: array<string, string>}
     */
    private function page(SubjectType $type): array
    {
        return ['route' => self::PAGE, 'params' => ['type' => $type->segment()]];
    }
}
