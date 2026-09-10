<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Lo que responde el proveedor.
 *
 * `accepted` es el unico estado bueno; `contingency` significa numerado y
 * sin transmitir —hay que reintentarlo— y `rejected`, que la autoridad lo
 * devolvio y hay que corregir algo.
 */
final class FiscalResult
{
    public const ACCEPTED = 'accepted';
    public const CONTINGENCY = 'contingency';
    public const REJECTED = 'rejected';

    /** @param array<string, mixed>|null $response lo que contesto, tal cual */
    public function __construct(
        public readonly string $status,
        public readonly ?string $externalId = null,
        public readonly ?array $response = null,
    ) {
    }

    public function fueAceptado(): bool
    {
        return $this->status === self::ACCEPTED;
    }
}
