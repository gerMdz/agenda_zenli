<?php

declare(strict_types=1);

namespace App\Agenda;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Intervalo
{
    public function __construct(
        public DateTimeImmutable $inicio,
        public DateTimeImmutable $fin,
    ) {
        if ($fin <= $inicio) {
            throw new InvalidArgumentException('El fin debe ser posterior al inicio.');
        }
    }

    public function seSuperpone(self $other): bool
    {
        return $this->inicio < $other->fin && $other->inicio < $this->fin;
    }
}
