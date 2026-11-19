<?php

declare(strict_types=1);

namespace App\Agenda;

final readonly class TurnoDisponible
{
    public function __construct(
        public string $profesionalId,
        public Intervalo $sesion,
        public Intervalo $ocupacion,
    ) {
    }
}
