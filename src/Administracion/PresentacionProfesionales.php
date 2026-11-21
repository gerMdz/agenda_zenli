<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class PresentacionProfesionales
{
    public function __construct(private Connection $conexion)
    {
    }

    public function configuracion(): array
    {
        return [
            'modo' => $this->conexion->fetchOne('SELECT modo FROM presentacion_profesionales_config WHERE id = 1') ?: 'ocultos',
            'profesionales' => $this->conexion->fetchAllAssociative('SELECT p.id::text, p.nombre, p.activa, p.visible_portada, i.imagen_version FROM profesionales p LEFT JOIN presentacion_profesionales_imagenes i ON i.profesional_id = p.id ORDER BY p.orden_portada NULLS LAST, p.nombre, p.id'),
        ];
    }

    public function publicos(): array
    {
        // Una sola consulta evita publicar nombres o versiones con una configuración obsoleta.
        return $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT p.id::text, p.nombre, CASE WHEN c.modo = 'imagenes' THEN i.imagen_version ELSE NULL END AS imagen_version
            FROM presentacion_profesionales_config c CROSS JOIN profesionales p
            LEFT JOIN presentacion_profesionales_imagenes i ON i.profesional_id = p.id
            WHERE c.id = 1 AND c.modo IN ('nombres', 'imagenes') AND p.activa AND p.visible_portada
            ORDER BY p.orden_portada NULLS LAST, p.nombre, p.id
            SQL);
    }

    public function cambiarNombre(string $id, mixed $nombre, string $autor): void
    {
        if (!is_string($nombre) || preg_match('/\A[^\x00]{1,120}\z/u', trim($nombre)) !== 1) {
            throw new InvalidArgumentException('Ingresá un nombre de hasta 120 caracteres.');
        }
        $this->conexion->transactional(function (Connection $conexion) use ($id, $nombre, $autor): void {
            if ($conexion->executeStatement('UPDATE profesionales SET nombre = ? WHERE id = ?', [trim($nombre), $id]) !== 1) {
                throw new InvalidArgumentException('La profesional no existe.');
            }
            $conexion->executeStatement('UPDATE presentacion_profesionales_config SET actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = 1', [$autor]);
        });
    }

    public function cambiarVisibilidad(string $id, mixed $visible, string $autor): void
    {
        if (!is_bool($visible)) {
            throw new InvalidArgumentException('Elegí una visibilidad válida.');
        }
        $this->conexion->transactional(function (Connection $conexion) use ($id, $visible, $autor): void {
            if ($conexion->executeStatement('UPDATE profesionales SET visible_portada = ? WHERE id = ?', [$visible, $id], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING]) !== 1) {
                throw new InvalidArgumentException('La profesional no existe.');
            }
            $conexion->executeStatement('UPDATE presentacion_profesionales_config SET actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = 1', [$autor]);
        });
    }

    public function mover(string $id, mixed $direccion, string $autor): array
    {
        if (!is_int($direccion) || !in_array($direccion, [-1, 1], true)) {
            throw new InvalidArgumentException('La dirección del movimiento no es válida.');
        }

        return $this->conexion->transactional(function (Connection $conexion) use ($id, $direccion, $autor): array {
            $conexion->executeStatement('LOCK TABLE profesionales IN SHARE ROW EXCLUSIVE MODE');
            $lista = $this->configuracion()['profesionales'];
            $indice = array_search($id, array_column($lista, 'id'), true);
            if ($indice === false) {
                throw new InvalidArgumentException('La profesional no existe.');
            }
            $destino = $indice + $direccion;
            if ($destino < 0 || $destino >= count($lista)) {
                throw new InvalidArgumentException('La profesional no se puede mover en esa dirección.');
            }
            [$lista[$indice], $lista[$destino]] = [$lista[$destino], $lista[$indice]];
            foreach ($lista as $orden => $profesional) {
                $conexion->executeStatement('UPDATE profesionales SET orden_portada = ? WHERE id = ?', [$orden, $profesional['id']]);
            }
            $conexion->executeStatement('UPDATE presentacion_profesionales_config SET actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = 1', [$autor]);
            return $this->configuracion();
        });
    }

    public function imagen(string $id, string $version, bool $publica): array|false
    {
        $filtro = $publica ? "AND p.activa AND p.visible_portada AND EXISTS (SELECT 1 FROM presentacion_profesionales_config c WHERE c.id = 1 AND c.modo = 'imagenes')" : '';
        return $this->conexion->fetchAssociative("SELECT encode(i.imagen, 'base64') AS contenido, i.imagen_tipo FROM presentacion_profesionales_imagenes i JOIN profesionales p ON p.id = i.profesional_id WHERE p.id = ? AND i.imagen_version = ? $filtro", [$id, $version]);
    }

    public function cambiarModo(mixed $modo, string $autor): void
    {
        if (!is_string($modo) || !in_array($modo, ['ocultos', 'nombres', 'imagenes'], true)) {
            throw new InvalidArgumentException('Elegí un modo de presentación válido.');
        }
        $this->conexion->executeStatement('UPDATE presentacion_profesionales_config SET modo = ?, actualizado_por = ?, actualizado_en = statement_timestamp() WHERE id = 1', [$modo, $autor]);
    }

    public function guardarImagen(string $id, ?UploadedFile $archivo, bool $eliminar, string $autor): void
    {
        if (!$this->conexion->fetchOne('SELECT 1 FROM profesionales WHERE id = ?', [$id])) {
            throw new InvalidArgumentException('La profesional no existe.');
        }
        if ($eliminar && $archivo !== null) {
            throw new InvalidArgumentException('Elegí subir o eliminar la imagen.');
        }
        if ($eliminar) {
            $this->conexion->executeStatement('UPDATE presentacion_profesionales_imagenes SET imagen = NULL, imagen_tipo = NULL, imagen_version = NULL, actualizado_por = ?, actualizado_en = statement_timestamp() WHERE profesional_id = ?', [$autor, $id]);
            return;
        }
        if ($archivo === null || !$archivo->isValid() || $archivo->getSize() > 4 * 1024 * 1024) {
            throw new InvalidArgumentException('Elegí una imagen de hasta 4 MB.');
        }
        $datos = @getimagesize($archivo->getPathname());
        if ($datos === false || !in_array($datos['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $datos[0] < 1 || $datos[1] < 1 || $datos[0] > 6000 || $datos[1] > 6000) {
            throw new InvalidArgumentException('Subí una imagen JPG, PNG o WebP de hasta 6000 píxeles por lado.');
        }
        $bytes = $archivo->getContent();
        $this->conexion->executeStatement(<<<'SQL'
            INSERT INTO presentacion_profesionales_imagenes (profesional_id, imagen, imagen_tipo, imagen_version, actualizado_por)
            VALUES (?, decode(?, 'base64'), ?, ?, ?)
            ON CONFLICT (profesional_id) DO UPDATE SET imagen = excluded.imagen, imagen_tipo = excluded.imagen_tipo,
                imagen_version = excluded.imagen_version, actualizado_por = excluded.actualizado_por, actualizado_en = statement_timestamp()
            SQL, [$id, base64_encode($bytes), $datos['mime'], hash('sha256', $bytes), $autor]);
    }
}