<?php

declare(strict_types=1);

namespace App\Services;

interface PaymentProvider
{
    public function charge(int $amountCents, ?string $reference): ChargeResult;
}
