<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class ConfiguracionPortada
{
    public const TELEFONO_INICIAL = '+5492616356192';
    public const DIRECCION_INICIAL = 'Espejo 167, Ciudad de Mendoza';
    public const HORARIOS_INICIALES = "Lun, mié y vie · 10:00 a 20:00\nMar, jue y sáb · 11:00 a 19:00";

    public function __construct(private Connection $conexion)
    {
    }

    public function consultar(): array
    {
        $fila = $this->conexion->fetchAssociative('SELECT telefono, imagen_version, instagram, facebook, direccion, horarios, solo_imagen::int AS solo_imagen FROM configuracion_portada WHERE id = 1');

        return [
            'telefono' => $fila['telefono'] ?? self::TELEFONO_INICIAL,
            'imagen' => isset($fila['imagen_version']) ? '/api/portada/imagen/'.$fila['imagen_version'] : null,
            'instagram' => $fila['instagram'] ?? null,
            'facebook' => $fila['facebook'] ?? null,
            'direccion' => $fila['direccion'] ?? self::DIRECCION_INICIAL,
            'horarios' => $fila['horarios'] ?? self::HORARIOS_INICIALES,
            'solo_imagen' => (bool) ($fila['solo_imagen'] ?? false),
        ];
    }

    public function imagen(string $version): array|false
    {
        return $this->conexion->fetchAssociative(
            "SELECT encode(imagen, 'base64') AS contenido, imagen_tipo FROM configuracion_portada WHERE id = 1 AND imagen_version = ?",
            [$version],
        );
    }

    public function cambiarModo(bool $soloImagen, string $autor): void
    {
        $this->conexion->executeStatement(<<<'SQL'
            INSERT INTO configuracion_portada (id, telefono, actualizado_por, solo_imagen)
            VALUES (1, ?, ?, ?)
            ON CONFLICT (id) DO UPDATE SET
                solo_imagen = excluded.solo_imagen,
                actualizado_por = excluded.actualizado_por,
                actualizado_en = statement_timestamp()
            SQL, [self::TELEFONO_INICIAL, $autor, $soloImagen ? 'true' : 'false']);
    }

    public function guardar(
        string $telefono,
        ?UploadedFile $archivo,
        bool $restaurar,
        string $autor,
        ?string $instagram = null,
        ?string $facebook = null,
        ?string $direccion = null,
        ?string $horarios = null,
    ): void {
        $telefono = preg_replace('/[\s()-]/', '', trim($telefono));
        if (!preg_match('/\A\+[1-9][0-9]{7,14}\z/', $telefono)) {
            throw new InvalidArgumentException('Ingresá el teléfono internacional con + y código de país.');
        }

        $enlaceInstagram = self::validarEnlace($instagram, 'Instagram', ['instagram.com', 'www.instagram.com']);
        $enlaceFacebook = self::validarEnlace($facebook, 'Facebook', ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.com']);
        foreach (['dirección' => [$direccion, 255], 'horarios' => [$horarios, 2000]] as $campo => [$valor, $limite]) {
            $longitud = $valor === null ? 0 : preg_match_all('/./us', $valor);
            if ($longitud === false || $longitud > $limite || ($valor !== null && str_contains($valor, "\0"))) {
                throw new InvalidArgumentException("Revisá el texto de $campo (máximo $limite caracteres).");
            }
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
            INSERT INTO configuracion_portada (id, telefono, imagen, imagen_tipo, imagen_version, actualizado_por, instagram, facebook, direccion, horarios)
            VALUES (1, ?, decode(?, 'base64'), ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (id) DO UPDATE SET
                telefono = excluded.telefono,
                actualizado_por = excluded.actualizado_por,
                actualizado_en = statement_timestamp()
            SQL;
        if ($archivo !== null || $restaurar) {
            $sql .= ', imagen = excluded.imagen, imagen_tipo = excluded.imagen_tipo, imagen_version = excluded.imagen_version';
        }
        if ($instagram !== null) {
            $sql .= ', instagram = excluded.instagram';
        }
        if ($facebook !== null) {
            $sql .= ', facebook = excluded.facebook';
        }
        if ($direccion !== null) {
            $sql .= ', direccion = excluded.direccion';
        }
        if ($horarios !== null) {
            $sql .= ', horarios = excluded.horarios';
        }


        $this->conexion->executeStatement($sql, [
            $telefono, $contenido, $tipo, $version, $autor, $enlaceInstagram, $enlaceFacebook,
            $direccion === null ? self::DIRECCION_INICIAL : trim($direccion),
            $horarios === null ? self::HORARIOS_INICIALES : trim($horarios),
        ]);
    }

    /** @param list<string> $dominios */
    private static function validarEnlace(?string $enlace, string $red, array $dominios): ?string
    {
        $enlace = trim($enlace ?? '');
        if ($enlace === '') {
            return null;
        }

        $partes = parse_url($enlace);
        if (strlen($enlace) > 2048 || !filter_var($enlace, FILTER_VALIDATE_URL) || str_contains($enlace, '\\')
            || strtolower($partes['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($partes['host'] ?? ''), $dominios, true)
            || isset($partes['user']) || isset($partes['pass']) || isset($partes['port'])
            || trim($partes['path'] ?? '', '/') === '') {
            throw new InvalidArgumentException("Ingresá un enlace HTTPS válido de $red.");
        }

        return $enlace;
    }
}
