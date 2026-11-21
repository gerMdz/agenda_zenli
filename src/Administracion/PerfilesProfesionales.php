<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final readonly class PerfilesProfesionales
{
    public function __construct(private Connection $conexion)
    {
    }

    public function crear(mixed $nombre): string
    {
        if (!is_string($nombre) || preg_match('/\A[^\x00]{1,120}\z/u', trim($nombre)) !== 1) {
            throw new InvalidArgumentException('Ingresá un nombre profesional válido.');
        }

        return (string) $this->conexion->fetchOne(
            'INSERT INTO profesionales (nombre) VALUES (?) RETURNING id::text',
            [trim($nombre)],
        );
    }
}