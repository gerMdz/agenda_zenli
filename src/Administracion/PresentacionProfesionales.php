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
            'profesionales' => $this->conexion->fetchAllAssociative('SELECT p.id::text, p.nombre, p.activa, i.imagen_version FROM profesionales p LEFT JOIN presentacion_profesionales_imagenes i ON i.profesional_id = p.id ORDER BY p.nombre, p.id'),
        ];
    }

    public function publicos(): array
    {
        // Una sola consulta evita publicar nombres o versiones con una configuración obsoleta.
        return $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT p.id::text, p.nombre, CASE WHEN c.modo = 'imagenes' THEN i.imagen_version ELSE NULL END AS imagen_version
            FROM presentacion_profesionales_config c CROSS JOIN profesionales p
            LEFT JOIN presentacion_profesionales_imagenes i ON i.profesional_id = p.id
            WHERE c.id = 1 AND c.modo IN ('nombres', 'imagenes') AND p.activa
            ORDER BY p.nombre, p.id
            SQL);
    }

    public function imagen(string $id, string $version, bool $publica): array|false
    {
        $filtro = $publica ? "AND p.activa AND EXISTS (SELECT 1 FROM presentacion_profesionales_config c WHERE c.id = 1 AND c.modo = 'imagenes')" : '';
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