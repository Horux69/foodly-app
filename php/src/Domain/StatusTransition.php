<?php

declare(strict_types=1);

namespace App\Domain;

final class StatusTransition
{
    public function __construct(
        public readonly string $fromStatusId,
        public readonly string $toStatusId,
        public readonly ?string $requiredPermission,
    ) {
    }
}
