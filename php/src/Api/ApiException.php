<?php

declare(strict_types=1);

namespace App\Api;

/** Excepcion con status HTTP explicito, equivalente a HTTPException de FastAPI. */
final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
