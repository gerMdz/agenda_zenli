<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;

final readonly class PromocionesPortada
{
    public function __construct(private Connection $conexion)
    {
    }

    public function listar(bool $publicas = false): array
    {
        $columnas = $publicas ? 'id, nombre, descripcion' : 'id, nombre, descripcion, visible, actualizado_por, actualizado_en';
        $filtro = $publicas ? ' AND visible = true' : '';

        return $this->conexion->fetchAllAssociative("SELECT $columnas FROM promociones_portada WHERE eliminado = false$filtro ORDER BY id DESC");
    }

    public function guardar(?int $id, array $datos, string $autor): void
    {
        $nombre = $datos['nombre'] ?? null;
        $descripcion = $datos['descripcion'] ?? null;
        if (!is_string($nombre) || !is_string($descripcion) || str_contains($nombre, "\0") || str_contains($descripcion, "\0")) {
            throw new InvalidArgumentException('Completá el nombre y la descripción.');
        }
        $nombre = trim($nombre);
        $descripcion = trim($descripcion);
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100 || mb_strlen($descripcion) < 5 || mb_strlen($descripcion) > 5000) {
            throw new InvalidArgumentException('El nombre debe tener entre 2 y 100 caracteres y la descripción entre 5 y 5000.');
        }
        if ($id === null) {
            $this->conexion->executeStatement('INSERT INTO promociones_portada (nombre, descripcion, actualizado_por) VALUES (?, ?, ?)', [$nombre, $descripcion, $autor]);
            return;
        }
        $afectadas = $this->conexion->executeStatement('UPDATE promociones_portada SET nombre = ?, descripcion = ?, actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = ? AND eliminado = false', [$nombre, $descripcion, $autor, $id]);
        if ($afectadas !== 1) {
            throw new InvalidArgumentException('La promoción no existe.');
        }
    }

    public function visibilidad(int $id, bool $visible, string $autor): void
    {
        $afectadas = $this->conexion->executeStatement('UPDATE promociones_portada SET visible = ?, actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = ? AND eliminado = false', [$visible, $autor, $id], [ParameterType::BOOLEAN, ParameterType::STRING, ParameterType::INTEGER]);
        if ($afectadas !== 1) {
            throw new InvalidArgumentException('La promoción no existe.');
        }
    }

    public function eliminar(int $id, string $autor): void
    {
        $afectadas = $this->conexion->executeStatement('UPDATE promociones_portada SET eliminado = true, visible = false, actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = ? AND eliminado = false', [$autor, $id]);
        if ($afectadas !== 1) {
            throw new InvalidArgumentException('La promoción no existe.');
        }
    }
}