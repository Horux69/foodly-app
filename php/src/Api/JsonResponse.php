<?php

declare(strict_types=1);

namespace App\Api;

/** Envoltorio opcional para que un handler fije un status distinto de 200 (p.ej. 201 al crear). */
final class JsonResponse
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $status = 200,
    ) {
    }
}
