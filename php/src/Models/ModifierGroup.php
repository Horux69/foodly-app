<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

final class ModifierGroup
{
    /** @param Modifier[] $modifiers */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $minSelect,
        public readonly int $maxSelect,
        public readonly bool $isRequired,
        public readonly array $modifiers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param Modifier[] $modifiers
     */
    public static function fromRow(array $row, array $modifiers = []): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            minSelect: (int) $row['min_select'],
            maxSelect: (int) $row['max_select'],
            isRequired: Row::bool($row['is_required']),
            modifiers: $modifiers,
        );
    }
}
