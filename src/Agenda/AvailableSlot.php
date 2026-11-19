<?php

declare(strict_types=1);

namespace App\Agenda;

final readonly class AvailableSlot
{
    public function __construct(
        public string $professionalId,
        public TimeRange $session,
        public TimeRange $occupation,
    ) {
    }
}
