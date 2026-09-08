<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\CashSessionTotals;
use App\Models\CashSession;

/** Un turno con su cuadre ya resuelto, que es como lo mira siempre la caja. */
final class CashSessionView
{
    public function __construct(
        public readonly CashSession $session,
        public readonly CashSessionTotals $totals,
    ) {
    }
}
