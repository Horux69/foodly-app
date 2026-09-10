<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Como quedaria repartida una cuenta. Una propuesta, no un cobro: nada de
 * esto se escribe hasta que alguien cobre cada parte.
 */
final class BillSplitProposal
{
    /** @param int[] $partsCents importes que suman exactamente el saldo */
    public function __construct(
        public readonly int $pendingCents,
        public readonly array $partsCents,
        /**
         * Lo que no es producto: envio menos descuento mas propina. Se
         * informa porque al repartir por productos ese importe no esta en
         * ninguna linea y alguien lo tiene que pagar igual.
         */
        public readonly int $nonItemCents,
    ) {
    }
}
