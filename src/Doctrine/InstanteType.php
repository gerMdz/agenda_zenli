<?php

declare(strict_types=1);

namespace App\Doctrine;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Type;

/**
 * TIMESTAMPTZ de PostgreSQL con microsegundos.
 *
 * `datetimetz_immutable` de DBAL solo lee `Y-m-d H:i:sO` y falla con los valores que asignan
 * `now()` o `statement_timestamp()`, que incluyen microsegundos. También los descarta al escribir.
 */
final class InstanteType extends DateTimeTzImmutableType
{
    public const NOMBRE = 'instante';

    /** Registra el tipo fuera del contenedor de Symfony, por ejemplo en las pruebas. */
    public static function registrar(): void
    {
        if (!Type::hasType(self::NOMBRE)) {
            Type::addType(self::NOMBRE, self::class);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s.uP') : parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeImmutable
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }
        $instante = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.uO', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:sO', $value);
        if ($instante === false) {
            throw InvalidFormat::new($value, self::class, 'Y-m-d H:i:s[.u]O');
        }

        return $instante;
    }
}
