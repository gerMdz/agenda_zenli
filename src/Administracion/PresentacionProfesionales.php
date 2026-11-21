<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Entity\ConfiguracionPresentacionProfesionales;
use App\Entity\ImagenPresentacionProfesional;
use App\Entity\ProfesionalPortada;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class PresentacionProfesionales
{
    // En PostgreSQL el orden ascendente ubica los NULL al final, como el NULLS LAST anterior.
    private const ORDEN = 'ORDER BY p.ordenPortada, p.nombre, p.id';

    public function __construct(private EntityManagerInterface $entidades)
    {
    }

    public function configuracion(): array
    {
        $modo = $this->entidades->createQuery('SELECT c.modo FROM App\Entity\ConfiguracionPresentacionProfesionales c WHERE c.id = 1')
            ->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR);
        $profesionales = $this->entidades->createQuery(<<<'DQL'
            SELECT p.id AS id, p.nombre AS nombre, p.activa AS activa, p.visiblePortada AS visible_portada, i.imagenVersion AS imagen_version
            FROM App\Entity\ProfesionalPortada p
            LEFT JOIN App\Entity\ImagenPresentacionProfesional i WITH i.profesionalId = p.id
            DQL.' '.self::ORDEN)->getArrayResult();

        return [
            'modo' => $modo ?: 'ocultos',
            'profesionales' => array_map(static fn (array $p): array => ['id' => (string) $p['id']] + $p, $profesionales),
        ];
    }

    public function publicos(): array
    {
        // Una sola consulta evita publicar nombres o versiones con una configuración obsoleta.
        $filas = $this->entidades->createQuery(<<<'DQL'
            SELECT p.id AS id, p.nombre AS nombre, c.modo AS modo, i.imagenVersion AS imagen_version
            FROM App\Entity\ProfesionalPortada p
            LEFT JOIN App\Entity\ImagenPresentacionProfesional i WITH i.profesionalId = p.id,
                 App\Entity\ConfiguracionPresentacionProfesionales c
            WHERE c.id = 1 AND c.modo IN ('nombres', 'imagenes') AND p.activa = true AND p.visiblePortada = true
            DQL.' '.self::ORDEN)->getArrayResult();

        return array_map(static fn (array $p): array => [
            'id' => (string) $p['id'], 'nombre' => $p['nombre'],
            'imagen_version' => $p['modo'] === 'imagenes' ? $p['imagen_version'] : null,
        ], $filas);
    }

    public function cambiarNombre(string $id, mixed $nombre, string $autor): void
    {
        if (!is_string($nombre) || preg_match('/\A[^\x00]{1,120}\z/u', trim($nombre)) !== 1) {
            throw new InvalidArgumentException('Ingresá un nombre de hasta 120 caracteres.');
        }
        TransaccionEntidades::ejecutar($this->entidades, function () use ($id, $nombre, $autor): void {
            $this->profesional($id)->nombre = trim($nombre);
            $this->auditar($autor);
        });
    }

    public function cambiarVisibilidad(string $id, mixed $visible, string $autor): void
    {
        if (!is_bool($visible)) {
            throw new InvalidArgumentException('Elegí una visibilidad válida.');
        }
        TransaccionEntidades::ejecutar($this->entidades, function () use ($id, $visible, $autor): void {
            $this->profesional($id)->visiblePortada = $visible;
            $this->auditar($autor);
        });
    }

    public function mover(string $id, mixed $direccion, string $autor): array
    {
        if (!is_int($direccion) || !in_array($direccion, [-1, 1], true)) {
            throw new InvalidArgumentException('La dirección del movimiento no es válida.');
        }

        return TransaccionEntidades::ejecutar($this->entidades, function () use ($id, $direccion, $autor): array {
            $this->entidades->getConnection()->executeStatement('LOCK TABLE profesionales IN SHARE ROW EXCLUSIVE MODE');
            $lista = $this->entidades->createQuery('SELECT p FROM App\Entity\ProfesionalPortada p '.self::ORDEN)
                ->setHint(Query::HINT_REFRESH, true)->getResult();
            $indice = array_search($id, array_map(static fn (ProfesionalPortada $p): string => (string) $p->id, $lista), true);
            if ($indice === false) {
                throw new InvalidArgumentException('La profesional no existe.');
            }
            $destino = $indice + $direccion;
            if ($destino < 0 || $destino >= count($lista)) {
                throw new InvalidArgumentException('La profesional no se puede mover en esa dirección.');
            }
            [$lista[$indice], $lista[$destino]] = [$lista[$destino], $lista[$indice]];
            foreach ($lista as $orden => $profesional) {
                $profesional->ordenPortada = $orden;
            }
            $this->auditar($autor);
            $this->entidades->flush();

            return $this->configuracion();
        });
    }

    public function imagen(string $id, string $version, bool $publica): array|false
    {
        $filtro = $publica ? <<<'DQL'
             AND p.activa = true AND p.visiblePortada = true AND EXISTS (
                SELECT c.id FROM App\Entity\ConfiguracionPresentacionProfesionales c WHERE c.id = 1 AND c.modo = 'imagenes'
            )
            DQL : '';
        $fila = $this->entidades->createQuery(<<<DQL
            SELECT i.imagen AS imagen, i.imagenTipo AS imagen_tipo
            FROM App\\Entity\\ImagenPresentacionProfesional i JOIN App\\Entity\\ProfesionalPortada p WITH p.id = i.profesionalId
            WHERE p.id = :id AND i.imagenVersion = :version $filtro
            DQL)->setParameter('id', $id)->setParameter('version', $version)->getOneOrNullResult();
        if ($fila === null) {
            return false;
        }
        $bytes = is_resource($fila['imagen']) ? stream_get_contents($fila['imagen']) : $fila['imagen'];

        return ['contenido' => base64_encode($bytes), 'imagen_tipo' => $fila['imagen_tipo']];
    }

    public function cambiarModo(mixed $modo, string $autor): void
    {
        if (!is_string($modo) || !in_array($modo, ['ocultos', 'nombres', 'imagenes'], true)) {
            throw new InvalidArgumentException('Elegí un modo de presentación válido.');
        }
        $configuracion = $this->configuracionActual();
        if ($configuracion === null) {
            return;
        }
        $configuracion->modo = $modo;
        $configuracion->actualizadoPor = $autor;
        $configuracion->actualizadoEn = new DateTimeImmutable();
        $this->entidades->flush();
    }

    public function guardarImagen(string $id, ?UploadedFile $archivo, bool $eliminar, string $autor): void
    {
        if ((int) $this->entidades->createQuery('SELECT COUNT(p.id) FROM App\Entity\ProfesionalPortada p WHERE p.id = :id')
            ->setParameter('id', $id)->getSingleScalarResult() === 0) {
            throw new InvalidArgumentException('La profesional no existe.');
        }
        if ($eliminar && $archivo !== null) {
            throw new InvalidArgumentException('Elegí subir o eliminar la imagen.');
        }
        if ($eliminar) {
            $imagen = $this->imagenActual($id);
            if ($imagen !== null) {
                $this->asignarImagen($imagen, null, null, null, $autor);
                $this->entidades->flush();
            }

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
        TransaccionEntidades::ejecutar($this->entidades, function () use ($id, $bytes, $datos, $autor): void {
            // El bloqueo de la profesional reemplaza al ON CONFLICT: serializa dos altas simultáneas de su imagen.
            $this->entidades->createQuery('SELECT p FROM App\Entity\ProfesionalPortada p WHERE p.id = :id')
                ->setParameter('id', $id)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
            $imagen = $this->imagenActual($id);
            if ($imagen === null) {
                $imagen = new ImagenPresentacionProfesional();
                $imagen->profesionalId = $id;
                $this->entidades->persist($imagen);
            }
            $this->asignarImagen($imagen, $bytes, $datos['mime'], hash('sha256', $bytes), $autor);
        });
    }

    private function profesional(string $id): ProfesionalPortada
    {
        return $this->entidades->createQuery('SELECT p FROM App\Entity\ProfesionalPortada p WHERE p.id = :id')
            ->setParameter('id', $id)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult()
            ?? throw new InvalidArgumentException('La profesional no existe.');
    }

    private function configuracionActual(): ?ConfiguracionPresentacionProfesionales
    {
        return $this->entidades->createQuery('SELECT c FROM App\Entity\ConfiguracionPresentacionProfesionales c WHERE c.id = 1')
            ->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    private function imagenActual(string $id): ?ImagenPresentacionProfesional
    {
        return $this->entidades->createQuery('SELECT i FROM App\Entity\ImagenPresentacionProfesional i WHERE i.profesionalId = :id')
            ->setParameter('id', $id)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    private function asignarImagen(ImagenPresentacionProfesional $imagen, ?string $bytes, ?string $tipo, ?string $version, string $autor): void
    {
        $imagen->imagen = $bytes;
        $imagen->imagenTipo = $tipo;
        $imagen->imagenVersion = $version;
        $imagen->actualizadoPor = $autor;
        $imagen->actualizadoEn = new DateTimeImmutable();
    }

    private function auditar(string $autor): void
    {
        $configuracion = $this->configuracionActual();
        if ($configuracion !== null) {
            $configuracion->actualizadoPor = $autor;
            $configuracion->actualizadoEn = new DateTimeImmutable();
        }
    }
}
