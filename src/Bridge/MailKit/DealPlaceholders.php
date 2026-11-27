<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\Pipeline\DealReader;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;

/**
 * `{{ deal.* }}` (0.13.0, with `pipeline.enabled`): the deal named by the render's `deal` subject, read through the
 * CRM's deal reader (tenant, `view`); the amount only for people who may view amounts (`amounts_view`), as on the deal
 * page; nothing for a deal in the trash.
 */
final class DealPlaceholders extends CrmPlaceholders
{
    /** Names offered (name ⇒ label key). */
    public const NAMES = [
        'title'          => 'template.placeholder.deal_title',
        'amount'         => 'template.placeholder.deal_amount',
        'expected_close' => 'template.placeholder.deal_expected_close',
        'owner_name'     => 'template.placeholder.owner_name',
    ];

    /**
     * Wires the reader (null while the funnels are off: nothing resolves).
     */
    public function __construct(CrmTemplateActors $actors, private readonly ?DealReader $deals = null)
    {
        parent::__construct($actors);
    }

    /**
     * `deal`.
     */
    public static function placeholderNamespace(): string
    {
        return 'deal';
    }

    /**
     * {@inheritDoc}
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * {@inheritDoc}
     */
    protected function values(CrmContext $context, PlaceholderContext $placeholders, array $names): array
    {
        $id = self::subjectId($placeholders, 'deal');
        if ($id === null || !$this->deals instanceof DealReader) {
            return [];
        }
        // A deal in the trash, of another tenant or that the person may not view is "not found" (caught by the caller).
        $deal = $this->deals->deal($id);

        return [
            'title'          => $deal->title,
            'amount'         => !$deal->amountsHidden && $deal->amount instanceof Money ? $deal->amount->amount . ' ' . $deal->amount->currency : null,
            'expected_close' => $deal->expectedClose?->value,
            'owner_name'     => $deal->ownerName,
        ];
    }
}
