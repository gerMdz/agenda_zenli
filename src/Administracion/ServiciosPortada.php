<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Entity\ServicioPortada;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ServiciosPortada
{
    private Connection $conexion;

    public function __construct(private EntityManagerInterface $entidades)
    {
        $this->conexion = $entidades->getConnection();
    }

    public function listar(): array
    {
        return $this->mapearFilas($this->consulta());
    }

    public function obtener(int $id)
    {
        $servicio = $this->buscar($id);

        return $servicio === null ? false : $this->mapearFilas([$servicio])[0];
    }

    /** @return list<array{id: int, nombre: string, descripcion_corta: string, detalle: string, orden: int, imagen_version: ?string}> */
    public function listarVisibles(): array
    {
        return array_map(fn (ServicioPortada $servicio): array => $this->fila($servicio, true), $this->consulta(true));
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

        $maxOrden = $this->entidades->createQuery('SELECT MAX(s.orden) FROM App\Entity\ServicioPortada s')->getSingleScalarResult();
        $nuevoOrden = (int) $maxOrden + 1;

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

        return $this->conexion->transactional(function () use ($nombre, $descripcionCorta, $detalle, $nuevoOrden, $autor, $contenido, $tipo, $version): int {
            $servicio = new ServicioPortada();
            $servicio->nombre = $nombre;
            $servicio->descripcionCorta = $descripcionCorta;
            $servicio->detalle = $detalle;
            $servicio->orden = $nuevoOrden;
            $servicio->imagenVersion = $version;
            $this->auditar($servicio, $autor, true);
            $this->entidades->persist($servicio);
            $this->entidades->flush();
            if ($contenido !== null) {
                $this->guardarImagen($servicio->id, $contenido, $tipo);
            }
            return $servicio->id;
        });
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
        $servicio = $this->buscar($id);
        if ($servicio === null) {
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

        $contenido = null;
        $tipo = null;
        $version = null;

        if ($eliminarImagen) {
            $servicio->imagenVersion = null;
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
            $servicio->imagenVersion = $version;
        }

        $this->conexion->transactional(function () use ($servicio, $nombre, $descripcionCorta, $detalle, $autor, $eliminarImagen, $contenido, $tipo): void {
            $servicio->nombre = $nombre;
            $servicio->descripcionCorta = $descripcionCorta;
            $servicio->detalle = $detalle;
            $this->auditar($servicio, $autor);
            $this->entidades->flush();
            if ($eliminarImagen || $contenido !== null) {
                $this->guardarImagen($servicio->id, $contenido, $tipo);
            }
        });
    }

    public function eliminar(int $id, string $autor): void
    {
        $servicio = $this->buscar($id);
        if ($servicio === null) {
            throw new InvalidArgumentException('El servicio no existe.');
        }

        $this->entidades->remove($servicio);
        $this->entidades->flush();
    }

    public function mover(int $id, int $direccion, string $autor): array
    {
        if (!in_array($direccion, [-1, 1], true)) {
            throw new InvalidArgumentException('La dirección del movimiento no es válida.');
        }

        return $this->conexion->transactional(function (Connection $conexion) use ($id, $direccion, $autor): array {
            $conexion->executeStatement('LOCK TABLE servicios_portada IN SHARE ROW EXCLUSIVE MODE');
            $lista = $this->consulta();
            $indice = array_search($id, array_map(static fn (ServicioPortada $servicio): int => $servicio->id, $lista), true);
            if ($indice === false) {
                throw new InvalidArgumentException('El servicio no existe.');
            }
            $destino = $indice + $direccion;
            if ($destino < 0 || $destino >= count($lista)) {
                throw new InvalidArgumentException('El servicio no se puede mover en esa dirección.');
            }
            [$lista[$indice], $lista[$destino]] = [$lista[$destino], $lista[$indice]];
            foreach ($lista as $orden => $servicio) {
                if ($servicio->orden !== $orden || $orden === $indice || $orden === $destino) {
                    $servicio->orden = $orden;
                    $this->auditar($servicio, $autor);
                }
            }
            $this->entidades->flush();
            return $this->mapearFilas($lista);
        });
    }

    public function cambiarVisibilidad(int $id, bool $visible, string $autor): void
    {
        $servicio = $this->buscar($id);
        if ($servicio === null) {
            throw new InvalidArgumentException('El servicio no existe.');
        }
        $servicio->visible = $visible;
        $this->auditar($servicio, $autor);
        $this->entidades->flush();
    }

    public function configurar(int $id, ?int $duracionMinima, ?int $profesionalesRequeridos, string $autor): void
    {
        if (($duracionMinima !== null && ($duracionMinima < 1 || $duracionMinima > 2147483647))
            || ($profesionalesRequeridos !== null && ($profesionalesRequeridos < 1 || $profesionalesRequeridos > 2147483647))) {
            throw new InvalidArgumentException('La duración y la cantidad de profesionales deben ser enteros positivos o null.');
        }
        $servicio = $this->buscar($id);
        if ($servicio === null) {
            throw new InvalidArgumentException('El servicio no existe.');
        }
        $servicio->duracionMinima = $duracionMinima;
        $servicio->profesionalesRequeridos = $profesionalesRequeridos;
        $this->auditar($servicio, $autor);
        $this->entidades->flush();
    }

    private function consulta(bool $visibles = false): array
    {
        $query = $this->entidades->createQuery('SELECT s FROM App\Entity\ServicioPortada s'.($visibles ? ' WHERE s.visible = true' : '').' ORDER BY s.orden ASC, s.id ASC');
        $query->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true);
        return $query->getResult();
    }

    private function buscar(int $id): ?ServicioPortada
    {
        $servicio = $this->entidades->find(ServicioPortada::class, $id);
        if ($servicio !== null) {
            $this->entidades->refresh($servicio);
        }
        return $servicio;
    }

    /** @param list<ServicioPortada> $servicios */
    private function mapearFilas(array $servicios): array
    {
        $autores = new NombresAutores($this->conexion);
        $ids = [];
        foreach ($servicios as $servicio) {
            $ids[] = $servicio->creadoPor;
            $ids[] = $servicio->actualizadoPor;
        }
        $mapa = $autores->resolver($ids);

        return array_map(fn (ServicioPortada $servicio): array => $this->fila($servicio, false, $autores, $mapa), $servicios);
    }

    private function fila(ServicioPortada $servicio, bool $publica = false, ?NombresAutores $autores = null, array $mapa = []): array
    {
        $fila = [
            'id' => $servicio->id, 'nombre' => $servicio->nombre,
            'descripcion_corta' => $servicio->descripcionCorta, 'detalle' => $servicio->detalle,
            'orden' => $servicio->orden, 'imagen_version' => $servicio->imagenVersion,
            'duracion_minima' => $servicio->duracionMinima,
            'profesionales_requeridos' => $servicio->profesionalesRequeridos,
            'configuracion_completa' => $servicio->duracionMinima !== null && $servicio->profesionalesRequeridos !== null,
        ];
        if (!$publica) {
            $fila += [
                'visible' => $servicio->visible,
                'actualizado_por' => $servicio->actualizadoPor,
                'actualizado_en' => $servicio->actualizadoEn->format('Y-m-d H:i:s.uP'),
                'auditoria' => ($autores ?? new NombresAutores($this->conexion))->auditoria(
                    $servicio->creadoPor,
                    $servicio->creadoEn,
                    $servicio->actualizadoPor,
                    $servicio->actualizadoEn,
                    $mapa,
                ),
            ];
        }
        return $fila;
    }

    private function auditar(ServicioPortada $servicio, string $autor, bool $creacion = false): void
    {
        $ahora = new DateTimeImmutable();
        if ($creacion) {
            $servicio->creadoPor = $autor;
            $servicio->creadoEn = $ahora;
        }
        $servicio->actualizadoPor = $autor;
        $servicio->actualizadoEn = $ahora;
    }

    private function guardarImagen(int $id, ?string $contenido, ?string $tipo): void
    {
        $this->conexion->executeStatement("UPDATE servicios_portada SET imagen = decode(?, 'base64'), imagen_tipo = ? WHERE id = ?", [$contenido, $tipo, $id]);
    }
}
