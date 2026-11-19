<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class ConfiguracionPortada
{
    public const TELEFONO_INICIAL = '+5492616356192';

    public function __construct(private Connection $conexion)
    {
    }

    public function consultar(): array
    {
        $fila = $this->conexion->fetchAssociative('SELECT telefono, imagen_version FROM configuracion_portada WHERE id = 1');

        return [
            'telefono' => $fila['telefono'] ?? self::TELEFONO_INICIAL,
            'imagen' => isset($fila['imagen_version']) ? '/api/portada/imagen/'.$fila['imagen_version'] : null,
        ];
    }

    public function imagen(string $version): array|false
    {
        return $this->conexion->fetchAssociative(
            "SELECT encode(imagen, 'base64') AS contenido, imagen_tipo FROM configuracion_portada WHERE id = 1 AND imagen_version = ?",
            [$version],
        );
    }

    public function guardar(string $telefono, ?UploadedFile $archivo, bool $restaurar, string $autor): void
    {
        $telefono = preg_replace('/[\s()-]/', '', trim($telefono));
        if (!preg_match('/\A\+[1-9][0-9]{7,14}\z/', $telefono)) {
            throw new InvalidArgumentException('Ingresá el teléfono internacional con + y código de país.');
        }

        $contenido = null;
        $tipo = null;
        $version = null;
        if ($archivo !== null) {
            if ($restaurar || !$archivo->isValid() || $archivo->getSize() > 4 * 1024 * 1024) {
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

        $sql = <<<'SQL'
            INSERT INTO configuracion_portada (id, telefono, imagen, imagen_tipo, imagen_version, actualizado_por)
            VALUES (1, ?, decode(?, 'base64'), ?, ?, ?)
            ON CONFLICT (id) DO UPDATE SET
                telefono = excluded.telefono,
                actualizado_por = excluded.actualizado_por,
                actualizado_en = statement_timestamp()
            SQL;
        if ($archivo !== null || $restaurar) {
            $sql .= ', imagen = excluded.imagen, imagen_tipo = excluded.imagen_tipo, imagen_version = excluded.imagen_version';
        }

        $this->conexion->executeStatement($sql, [$telefono, $contenido, $tipo, $version, $autor]);
    }
}
