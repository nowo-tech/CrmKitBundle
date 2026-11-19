<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\SearchKit;

use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_map;
use function implode;

/**
 * SearchKit provider of companies or contacts (contract section 12): the current tenant and `crm.view` per call, only
 * active records, at most the query's limit; any refusal is `null` (no 403, no hint). Registered once per type when
 * SearchKit is installed and the UI is on. Stateless.
 */
final readonly class CrmSearchProvider implements SearchProviderInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private SubjectType $type,
        private CrmReader $reader,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The group of matching records, or null.
     */
    public function search(SearchQuery $query): ?SearchGroup
    {
        $found = $this->reader->search($this->type, $query->text, $query->limit, $query->scope);
        if ($found === null || $found[0] === []) {
            return null;
        }
        [$views, $total] = $found;
        $segment         = $this->type->segment();

        return new SearchGroup(
            'crm_' . $segment,
            $this->translator->trans('search.group.' . $segment, [], NowoCrmKitBundle::TRANSLATION_DOMAIN),
            array_map(fn (RecordView $view): SearchItem => new SearchItem(
                $view->label,
                $this->urls->generate('nowo_crm_kit_show', ['type' => $segment, 'id' => $view->id]),
                implode(' · ', array_filter([$view->values['email'] ?? null, $view->values['city'] ?? null], static fn (?string $v): bool => $v !== null && $v !== '')),
                $this->type === SubjectType::Company ? 'tabler:building' : 'tabler:user',
            ), $views),
            $total,
            $this->urls->generate('nowo_crm_kit_list', ['type' => $segment, 'q' => $query->text]),
            $this->translator->trans('search.see_all', ['%count%' => $total], NowoCrmKitBundle::TRANSLATION_DOMAIN),
        );
    }
}
