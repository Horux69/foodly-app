<?php

declare(strict_types=1);

namespace App\Domain;

final class ModifierGroupConstraint
{
    public function __construct(
        public readonly string $groupId,
        public readonly string $name,
        public readonly int $minSelect,
        public readonly int $maxSelect,
        public readonly bool $isRequired,
    ) {
    }
}
