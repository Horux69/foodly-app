<?php

declare(strict_types=1);

namespace App\Services;

/** Cobro presencial: la plata se recibe fuera del sistema y aqui queda registrada. */
final class ManualProvider implements PaymentProvider
{
    public function __construct(public readonly string $code)
    {
    }

    public function charge(int $amountCents, ?string $reference): ChargeResult
    {
        return new ChargeResult('paid', $reference, (new \DateTimeImmutable('now'))->format(DATE_ATOM));
    }
}
