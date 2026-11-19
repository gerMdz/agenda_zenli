<?php

declare(strict_types=1);

namespace App\Agenda;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HorarioSemanal
{
    public function __construct(
        public string $profesionalId,
        public int $diaSemana,
        public string $abreA,
        public string $cierraA,
        public string $vigenteDesde,
        public ?string $vigenteHasta = null,
    ) {
        if ($diaSemana < 1 || $diaSemana > 7) {
            throw new InvalidArgumentException('El día de la semana debe estar entre 1 y 7.');
        }

        if (!self::horaValida($abreA) || !self::horaValida($cierraA) || $abreA === '24:00:00'
            || self::horaComparable($abreA) >= self::horaComparable($cierraA)) {
            throw new InvalidArgumentException('La franja debe tener horas válidas y duración positiva en el mismo día.');
        }

        if (!self::validDate($vigenteDesde) || ($vigenteHasta !== null
            && (!self::validDate($vigenteHasta) || $vigenteHasta < $vigenteDesde))) {
            throw new InvalidArgumentException('La vigencia debe usar fechas válidas en formato YYYY-MM-DD.');
        }
    }

    public function onDay(DateTimeImmutable $day): ?Intervalo
    {
        $date = $day->format('Y-m-d');
        if ((int) $day->format('N') !== $this->diaSemana || $date < $this->vigenteDesde
            || ($this->vigenteHasta !== null && $date > $this->vigenteHasta)) {
            return null;
        }

        $fin = $this->cierraA === '24:00:00'
            ? $day->setTime(0, 0)->modify('+1 day')
            : new DateTimeImmutable("$date {$this->cierraA}", $day->getTimezone());

        return new Intervalo(
            new DateTimeImmutable("$date {$this->abreA}", $day->getTimezone()),
            $fin,
        );
    }

    private static function horaValida(string $value): bool
    {
        return $value === '24:00:00'
            || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]{1,6})?\z/', $value) === 1;
    }

    private static function horaComparable(string $hora): string
    {
        return substr($hora, 0, 8).str_pad(substr($hora, 9), 6, '0');
    }

    private static function validDate(string $value): bool
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
