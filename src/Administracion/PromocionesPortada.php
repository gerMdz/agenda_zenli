<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Entity\PromocionPortada;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class PromocionesPortada
{
    public function __construct(private EntityManagerInterface $entidades)
    {
    }

    public function listar(bool $publicas = false): array
    {
        $columnas = 'p.id AS id, p.nombre AS nombre, p.descripcion AS descripcion, p.imagenVersion AS imagen_version, p.orden AS orden';
        if (!$publicas) {
            $columnas .= ', p.visible AS visible, p.actualizadoPor AS actualizado_por, p.actualizadoEn AS actualizado_en';
        }
        $dql = "SELECT $columnas FROM App\\Entity\\PromocionPortada p WHERE p.eliminado = false";
        if ($publicas) {
            $dql .= ' AND p.visible = true';
        }
        $filas = $this->entidades->createQuery($dql.' ORDER BY p.orden, p.id')->getArrayResult();
        foreach ($filas as &$fila) {
            if (isset($fila['actualizado_en']) && $fila['actualizado_en'] instanceof \DateTimeInterface) {
                $fila['actualizado_en'] = $fila['actualizado_en']->format('Y-m-d H:i:s.uP');
            }
        }
        return $filas;
    }

    public function imagen(int $id, string $version, bool $publica = false): array|false
    {
        $dql = 'SELECT p.imagen AS imagen, p.imagenTipo AS imagen_tipo FROM App\Entity\PromocionPortada p WHERE p.id = :id AND p.imagenVersion = :version AND p.eliminado = false';
        if ($publica) {
            $dql .= ' AND p.visible = true';
        }
        $fila = $this->entidades->createQuery($dql)->setParameter('id', $id)->setParameter('version', $version)->getOneOrNullResult();
        if ($fila === null) {
            return false;
        }
        $bytes = is_resource($fila['imagen']) ? stream_get_contents($fila['imagen']) : $fila['imagen'];
        return ['contenido' => base64_encode($bytes), 'imagen_tipo' => $fila['imagen_tipo']];
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
            $imagen = [$bytes, $info['mime'], hash('sha256', $bytes)];
        }
        if ($id === null) {
            $this->entidades->getConnection()->transactional(function () use ($nombre, $descripcion, $autor, $imagen): void {
                $this->entidades->getConnection()->executeStatement('LOCK TABLE promociones_portada IN SHARE ROW EXCLUSIVE MODE');
                $maximo = $this->entidades->createQuery('SELECT MAX(p.orden) FROM App\Entity\PromocionPortada p WHERE p.eliminado = false')->getSingleScalarResult();
                $promocion = new PromocionPortada();
                $promocion->nombre = $nombre;
                $promocion->descripcion = $descripcion;
                $promocion->actualizadoPor = $autor;
                $promocion->actualizadoEn = new DateTimeImmutable();
                $promocion->orden = $maximo === null ? 0 : (int) $maximo + 1;
                if ($imagen !== null) {
                    [$promocion->imagen, $promocion->imagenTipo, $promocion->imagenVersion] = $imagen;
                }
                $this->entidades->persist($promocion);
                $this->entidades->flush();
            });
            return;
        }
        $setImagen = ($eliminarImagen || $imagen !== null) ? ', p.imagen = :imagen, p.imagenTipo = :tipo, p.imagenVersion = :version' : '';
        $consulta = $this->entidades->createQuery("UPDATE App\\Entity\\PromocionPortada p SET p.nombre = :nombre, p.descripcion = :descripcion, p.actualizadoPor = :autor, p.actualizadoEn = :fecha$setImagen WHERE p.id = :id AND p.eliminado = false")
            ->setParameter('nombre', $nombre)->setParameter('descripcion', $descripcion)->setParameter('autor', $autor)->setParameter('fecha', new DateTimeImmutable())->setParameter('id', $id);
        if ($eliminarImagen || $imagen !== null) {
            $consulta->setParameter('imagen', $imagen[0] ?? null, 'blob')->setParameter('tipo', $imagen[1] ?? null)->setParameter('version', $imagen[2] ?? null);
        }
        $afectadas = $consulta->execute();
        if ($afectadas !== 1) {
            throw new InvalidArgumentException('La promoción no existe.');
        }
    }

    public function visibilidad(int $id, bool $visible, string $autor): void
    {
        $afectadas = $this->entidades->createQuery('UPDATE App\Entity\PromocionPortada p SET p.visible = :visible, p.actualizadoPor = :autor, p.actualizadoEn = :fecha WHERE p.id = :id AND p.eliminado = false')
            ->setParameter('visible', $visible)->setParameter('autor', $autor)->setParameter('fecha', new DateTimeImmutable())->setParameter('id', $id)->execute();
        if ($afectadas !== 1) {
            throw new InvalidArgumentException('La promoción no existe.');
        }
    }

    public function mover(int $id, int $direccion, string $autor): array
    {
        if (!in_array($direccion, [-1, 1], true)) {
            throw new InvalidArgumentException('La dirección del movimiento no es válida.');
        }

        return $this->entidades->getConnection()->transactional(function () use ($id, $direccion, $autor): array {
            $this->entidades->getConnection()->executeStatement('LOCK TABLE promociones_portada IN SHARE ROW EXCLUSIVE MODE');
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
                    $this->entidades->createQuery('UPDATE App\Entity\PromocionPortada p SET p.orden = :orden, p.actualizadoPor = :autor, p.actualizadoEn = :fecha WHERE p.id = :id')
                        ->setParameter('orden', $orden)->setParameter('autor', $autor)->setParameter('fecha', new DateTimeImmutable())->setParameter('id', $promocion['id'])->execute();
                }
            }
            return $this->listar();
        });
    }

    public function eliminar(int $id, string $autor): void
    {
        $afectadas = $this->entidades->createQuery('UPDATE App\Entity\PromocionPortada p SET p.eliminado = true, p.visible = false, p.actualizadoPor = :autor, p.actualizadoEn = :fecha WHERE p.id = :id AND p.eliminado = false')
            ->setParameter('autor', $autor)->setParameter('fecha', new DateTimeImmutable())->setParameter('id', $id)->execute();
        if ($afectadas !== 1) {
            throw new InvalidArgumentException('La promoción no existe.');
        }
    }
}