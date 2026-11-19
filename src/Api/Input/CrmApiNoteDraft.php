<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

/**
 * A new note (0.3.0, `crm_note_add`): plain text, 1–10 000 characters after the service's trim, untrusted; never
 * pinned through the API.
 */
final readonly class CrmApiNoteDraft implements CrmApiInput
{
    /**
     * Wires the body.
     */
    public function __construct(public string $body)
    {
    }

    /**
     * From an input array (`body`).
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        CrmApiFields::only($data, ['body']);

        return new self(CrmApiFields::requiredText($data, 'body', 20000));
    }

    /**
     * {@inheritDoc}
     */
    public function canonical(): array
    {
        return ['body' => $this->body];
    }
}
