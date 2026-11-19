<?php

declare(strict_types=1);

namespace App\Agenda;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WeeklyHours
{
    public function __construct(
        public string $professionalId,
        public int $weekday,
        public string $opensAt,
        public string $closesAt,
        public string $validFrom,
        public ?string $validThrough = null,
    ) {
        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException('El día de la semana debe estar entre 1 y 7.');
        }

        if (!self::validTime($opensAt) || !self::validTime($closesAt) || $opensAt >= $closesAt) {
            throw new InvalidArgumentException('La franja debe usar horas HH:MM:SS del mismo día y tener duración positiva.');
        }

        if (!self::validDate($validFrom) || ($validThrough !== null
            && (!self::validDate($validThrough) || $validThrough < $validFrom))) {
            throw new InvalidArgumentException('La vigencia debe usar fechas válidas en formato YYYY-MM-DD.');
        }
    }

    public function onDay(DateTimeImmutable $day): ?TimeRange
    {
        $date = $day->format('Y-m-d');
        if ((int) $day->format('N') !== $this->weekday || $date < $this->validFrom
            || ($this->validThrough !== null && $date > $this->validThrough)) {
            return null;
        }

        return new TimeRange(
            new DateTimeImmutable("$date {$this->opensAt}", $day->getTimezone()),
            new DateTimeImmutable("$date {$this->closesAt}", $day->getTimezone()),
        );
    }

    private static function validTime(string $value): bool
    {
        return preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/', $value) === 1;
    }

    private static function validDate(string $value): bool
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
