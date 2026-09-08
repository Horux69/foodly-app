<?php

declare(strict_types=1);

namespace App\Models;

final class Permission
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $description,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(code: $row['code'], description: $row['description']);
    }
}
