<?php

declare(strict_types=1);

namespace App\Domain;

final class ScheduleWindow
{
    /** @param string $opensAt "HH:MM:SS" @param string $closesAt "HH:MM:SS" */
    public function __construct(
        public readonly int $weekday,
        public readonly string $opensAt,
        public readonly string $closesAt,
        public readonly ?string $channel,
        public readonly bool $isActive,
    ) {
    }
}
