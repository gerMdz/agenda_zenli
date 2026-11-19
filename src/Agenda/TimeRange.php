<?php

declare(strict_types=1);

namespace App\Agenda;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TimeRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($end <= $start) {
            throw new InvalidArgumentException('El fin debe ser posterior al inicio.');
        }
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }
}
