<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class ServiciosPortada
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return list<array{id: int, nombre: string, descripcion_corta: string, detalle: string, orden: int, visible: bool, imagen_version: ?string, actualizado_por: string, actualizado_en: string}> */
    public function listar(): array
    {
        return $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT id, nombre, descripcion_corta, detalle, orden, visible, imagen_version, actualizado_por, actualizado_en
            FROM servicios_portada
            ORDER BY orden ASC, id ASC
            SQL);
    }

    /** @return array{id: int, nombre: string, descripcion_corta: string, detalle: string, orden: int, visible: bool, imagen_version: ?string, actualizado_por: string, actualizado_en: string}|false */
    public function obtener(int $id)
    {
        return $this->conexion->fetchAssociative(<<<'SQL'
            SELECT id, nombre, descripcion_corta, detalle, orden, visible, imagen_version, actualizado_por, actualizado_en
            FROM servicios_portada
            WHERE id = ?
            SQL, [$id]);
    }

    /** @return list<array{id: int, nombre: string, descripcion_corta: string, detalle: string, orden: int, imagen_version: ?string}> */
    public function listarVisibles(): array
    {
        return $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT id, nombre, descripcion_corta, detalle, orden, imagen_version
            FROM servicios_portada
            WHERE visible = true
            ORDER BY orden ASC, id ASC
            SQL);
    }

    public function imagen(int $id, string $version): array|false
    {
        return $this->conexion->fetchAssociative(
            "SELECT encode(imagen, 'base64') AS contenido, imagen_tipo FROM servicios_portada WHERE id = ? AND imagen_version = ?",
            [$id, $version],
        );
    }

    public function crear(
        string $nombre,
        string $descripcionCorta,
        string $detalle,
        ?UploadedFile $archivo,
        string $autor,
    ): int {
        $nombre = trim($nombre);
        $descripcionCorta = trim($descripcionCorta);
        $detalle = trim($detalle);

        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
            throw new InvalidArgumentException('El nombre debe tener entre 2 y 100 caracteres.');
        }
        if (mb_strlen($descripcionCorta) < 5 || mb_strlen($descripcionCorta) > 255) {
            throw new InvalidArgumentException('La descripción corta debe tener entre 5 y 255 caracteres.');
        }

        // Obtener el máximo orden para colocar el nuevo al final
        $maxOrden = $this->conexion->fetchOne('SELECT COALESCE(MAX(orden), 0) FROM servicios_portada');
        $nuevoOrden = (int)$maxOrden + 1;

        $contenido = null;
        $tipo = null;
        $version = null;

        if ($archivo !== null) {
            if (!$archivo->isValid() || $archivo->getSize() > 4 * 1024 * 1024) {
                throw new InvalidArgumentException('Elegí una sola imagen de hasta 4 MB.');
            }
            $datos = @getimagesize($archivo->getPathname());
            if ($datos === false || !in_array($datos['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
                || $datos[0] < 1 || $datos[1] < 1 || $datos[0] > 6000 || $datos[1] > 6000) {
                throw new InvalidArgumentException('Subí una imagen JPG, PNG o WebP de hasta 6000 píxeles por lado.');
            }
            $bytes = $archivo->getContent();
            $contenido = base64_encode($bytes);
            $tipo = $datos['mime'];
            $version = hash('sha256', $bytes);
        }

        $sql = 'INSERT INTO servicios_portada (nombre, descripcion_corta, detalle, orden, visible, actualizado_por, actualizado_en) VALUES (?, ?, ?, ?, true, ?, statement_timestamp()) RETURNING id';

        if ($archivo !== null) {
            $sql = 'INSERT INTO servicios_portada (nombre, descripcion_corta, detalle, imagen, imagen_tipo, imagen_version, orden, visible, actualizado_por, actualizado_en) VALUES (?, ?, ?, decode(?, \'base64\'), ?, ?, ?, true, ?, statement_timestamp()) RETURNING id';
            return $this->conexion->fetchOne($sql, [$nombre, $descripcionCorta, $detalle, $contenido, $tipo, $version, $nuevoOrden, $autor]);
        }

        return $this->conexion->fetchOne($sql, [$nombre, $descripcionCorta, $detalle, $nuevoOrden, $autor]);
    }

    public function actualizar(
        int $id,
        string $nombre,
        string $descripcionCorta,
        string $detalle,
        ?UploadedFile $archivo,
        bool $eliminarImagen,
        string $autor,
    ): void {
        $existente = $this->obtener($id);
        if ($existente === false) {
            throw new InvalidArgumentException('El servicio no existe.');
        }

        $nombre = trim($nombre);
        $descripcionCorta = trim($descripcionCorta);
        $detalle = trim($detalle);

        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
            throw new InvalidArgumentException('El nombre debe tener entre 2 y 100 caracteres.');
        }
        if (mb_strlen($descripcionCorta) < 5 || mb_strlen($descripcionCorta) > 255) {
            throw new InvalidArgumentException('La descripción corta debe tener entre 5 y 255 caracteres.');
        }

        $setClauses = ['nombre = ?', 'descripcion_corta = ?', 'detalle = ?', 'actualizado_por = ?', 'actualizado_en = statement_timestamp()'];
        $params = [$nombre, $descripcionCorta, $detalle, $autor];

        if ($eliminarImagen) {
            $setClauses[] = 'imagen = NULL';
            $setClauses[] = 'imagen_tipo = NULL';
            $setClauses[] = 'imagen_version = NULL';
        } elseif ($archivo !== null) {
            if (!$archivo->isValid() || $archivo->getSize() > 4 * 1024 * 1024) {
                throw new InvalidArgumentException('Elegí una sola imagen de hasta 4 MB.');
            }
            $datos = @getimagesize($archivo->getPathname());
            if ($datos === false || !in_array($datos['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
                || $datos[0] < 1 || $datos[1] < 1 || $datos[0] > 6000 || $datos[1] > 6000) {
                throw new InvalidArgumentException('Subí una imagen JPG, PNG o WebP de hasta 6000 píxeles por lado.');
            }
            $bytes = $archivo->getContent();
            $contenido = base64_encode($bytes);
            $tipo = $datos['mime'];
            $version = hash('sha256', $bytes);
            $setClauses[] = 'imagen = decode(?, \'base64\')';
            $setClauses[] = 'imagen_tipo = ?';
            $setClauses[] = 'imagen_version = ?';
            $params = array_merge($params, [$contenido, $tipo, $version]);
        }

        $params[] = $id;
        $setSql = implode(', ', $setClauses);
        $this->conexion->executeStatement("UPDATE servicios_portada SET $setSql WHERE id = ?", $params);
    }

    public function eliminar(int $id, string $autor): void
    {
        $existente = $this->obtener($id);
        if ($existente === false) {
            throw new InvalidArgumentException('El servicio no existe.');
        }

        $this->conexion->executeStatement('DELETE FROM servicios_portada WHERE id = ?', [$id]);
    }

    public function mover(int $id, int $direccion, string $autor): array
    {
        if (!in_array($direccion, [-1, 1], true)) {
            throw new InvalidArgumentException('La dirección del movimiento no es válida.');
        }

        return $this->conexion->transactional(function (Connection $conexion) use ($id, $direccion, $autor): array {
            $conexion->executeStatement('LOCK TABLE servicios_portada IN SHARE ROW EXCLUSIVE MODE');
            $lista = $this->listar();
            $indice = array_search($id, array_map(static fn (array $servicio): int => (int) $servicio['id'], $lista), true);
            if ($indice === false) {
                throw new InvalidArgumentException('El servicio no existe.');
            }
            $destino = $indice + $direccion;
            if ($destino < 0 || $destino >= count($lista)) {
                throw new InvalidArgumentException('El servicio no se puede mover en esa dirección.');
            }
            [$lista[$indice], $lista[$destino]] = [$lista[$destino], $lista[$indice]];
            foreach ($lista as $orden => $servicio) {
                if ((int) $servicio['orden'] !== $orden || $orden === $indice || $orden === $destino) {
                    $conexion->executeStatement(
                        'UPDATE servicios_portada SET orden = ?, actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = ?',
                        [$orden, $autor, $servicio['id']],
                    );
                }
            }

            return $this->listar();
        });
    }

    public function cambiarVisibilidad(int $id, bool $visible, string $autor): void
    {
        $existente = $this->obtener($id);
        if ($existente === false) {
            throw new InvalidArgumentException('El servicio no existe.');
        }

        $this->conexion->executeStatement(
            'UPDATE servicios_portada SET visible = ?, actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = ?',
            [$visible, $autor, $id]
        );
    }
}
