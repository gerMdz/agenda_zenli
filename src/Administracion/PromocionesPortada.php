<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class PromocionesPortada
{
    public function __construct(private Connection $conexion)
    {
    }

    public function listar(bool $publicas = false): array
    {
        $columnas = $publicas ? 'id, nombre, descripcion, imagen_version, orden' : 'id, nombre, descripcion, imagen_version, orden, visible, actualizado_por, actualizado_en';
        $filtro = $publicas ? ' AND visible = true' : '';

        return $this->conexion->fetchAllAssociative("SELECT $columnas FROM promociones_portada WHERE eliminado = false$filtro ORDER BY orden, id");
    }

    public function imagen(int $id, string $version, bool $publica = false): array|false
    {
        $filtro = $publica ? ' AND visible = true' : '';
        return $this->conexion->fetchAssociative("SELECT encode(imagen, 'base64') AS contenido, imagen_tipo FROM promociones_portada WHERE id = ? AND imagen_version = ? AND eliminado = false$filtro", [$id, $version]);
    }

    public function guardar(?int $id, array $datos, string $autor, ?UploadedFile $archivo = null, bool $eliminarImagen = false): void
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
        if ($archivo !== null && $eliminarImagen) {
            throw new InvalidArgumentException('Elegí reemplazar o eliminar la imagen, no ambas opciones.');
        }
        $imagen = null;
        if ($archivo !== null) {
            if (!$archivo->isValid() || $archivo->getSize() === false || $archivo->getSize() < 1 || $archivo->getSize() > 4 * 1024 * 1024) {
                throw new InvalidArgumentException('Elegí una sola imagen de hasta 4 MB.');
            }
            $info = @getimagesize($archivo->getPathname());
            if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
                || $info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000) {
                throw new InvalidArgumentException('Subí una imagen JPG, PNG o WebP de hasta 6000 píxeles por lado.');
            }
            $bytes = $archivo->getContent();
            $imagen = [base64_encode($bytes), $info['mime'], hash('sha256', $bytes)];
        }
        if ($id === null) {
            $this->conexion->transactional(function (Connection $conexion) use ($nombre, $descripcion, $autor, $imagen): void {
                $conexion->executeStatement('LOCK TABLE promociones_portada IN SHARE ROW EXCLUSIVE MODE');
                $conexion->executeStatement("INSERT INTO promociones_portada (nombre, descripcion, actualizado_por, imagen, imagen_tipo, imagen_version, orden) VALUES (?, ?, ?, decode(?, 'base64'), ?, ?, (SELECT COALESCE(MAX(orden), -1) + 1 FROM promociones_portada WHERE eliminado = false))", [$nombre, $descripcion, $autor, ...($imagen ?? [null, null, null])]);
            });
            return;
        }
        $setImagen = $eliminarImagen ? ', imagen = NULL, imagen_tipo = NULL, imagen_version = NULL' : ($imagen !== null ? ", imagen = decode(?, 'base64'), imagen_tipo = ?, imagen_version = ?" : '');
        $afectadas = $this->conexion->executeStatement("UPDATE promociones_portada SET nombre = ?, descripcion = ?, actualizado_por = ?, actualizado_en = statement_timestamp()$setImagen WHERE id = ? AND eliminado = false", [$nombre, $descripcion, $autor, ...($imagen ?? []), $id]);
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

    public function mover(int $id, int $direccion, string $autor): array
    {
        if (!in_array($direccion, [-1, 1], true)) {
            throw new InvalidArgumentException('La dirección del movimiento no es válida.');
        }

        return $this->conexion->transactional(function (Connection $conexion) use ($id, $direccion, $autor): array {
            $conexion->executeStatement('LOCK TABLE promociones_portada IN SHARE ROW EXCLUSIVE MODE');
            $lista = $this->listar();
            $indice = array_search($id, array_map(static fn (array $promocion): int => (int) $promocion['id'], $lista), true);
            if ($indice === false) {
                throw new InvalidArgumentException('La promoción no existe.');
            }
            $destino = $indice + $direccion;
            if ($destino < 0 || $destino >= count($lista)) {
                throw new InvalidArgumentException('La promoción no se puede mover en esa dirección.');
            }
            [$lista[$indice], $lista[$destino]] = [$lista[$destino], $lista[$indice]];
            foreach ($lista as $orden => $promocion) {
                if ((int) $promocion['orden'] !== $orden || $orden === $indice || $orden === $destino) {
                    $conexion->executeStatement('UPDATE promociones_portada SET orden = ?, actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = ?', [$orden, $autor, $promocion['id']]);
                }
            }
            return $this->listar();
        });
    }

    public function eliminar(int $id, string $autor): void
    {
        $afectadas = $this->conexion->executeStatement('UPDATE promociones_portada SET eliminado = true, visible = false, actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = ? AND eliminado = false', [$autor, $id]);
        if ($afectadas !== 1) {
            throw new InvalidArgumentException('La promoción no existe.');
        }
    }
}