<?php

declare(strict_types=1);

namespace App\Services;

final class PaymentProviders
{
    /** @return array<string, PaymentProvider> */
    private static function all(): array
    {
        return [
            'cash' => new ManualProvider('cash'),
            'card' => new ManualProvider('card'),
            'transfer' => new ManualProvider('transfer'),
        ];
    }

    public static function get(string $method): ?PaymentProvider
    {
        return self::all()[$method] ?? null;
    }

    /** @return string[] */
    public static function availableMethods(): array
    {
        return array_keys(self::all());
    }
}
