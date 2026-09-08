<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;

/**
 * Un turno de caja: desde que se abre el cajon con una base hasta que se
 * cuenta y se cierra.
 *
 * `openedByName` y `closedByName` solo vienen cuando la consulta hizo el
 * join. Pueden ser null aunque haya id: el usuario pudo darse de baja
 * (`opened_by` es ON DELETE SET NULL) y el turno no debe perderse por eso.
 */
final class CashSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $branchId,
        public readonly ?string $openedBy,
        public readonly ?string $closedBy,
        public readonly int $openingFloatCents,
        public readonly ?int $countedCashCents,
        public readonly ?string $note,
        public readonly string $openedAt,
        public readonly ?string $closedAt,
        public readonly ?string $openedByName = null,
        public readonly ?string $closedByName = null,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->closedAt === null;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            branchId: $row['branch_id'],
            openedBy: $row['opened_by'],
            closedBy: $row['closed_by'],
            openingFloatCents: Money::fromDecimalString((string) $row['opening_float']),
            countedCashCents: $row['counted_cash'] === null
                ? null
                : Money::fromDecimalString((string) $row['counted_cash']),
            note: $row['note'],
            openedAt: $row['opened_at'],
            closedAt: $row['closed_at'],
            openedByName: $row['opened_by_name'] ?? null,
            closedByName: $row['closed_by_name'] ?? null,
        );
    }
}
