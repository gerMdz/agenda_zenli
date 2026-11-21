<?php

declare(strict_types=1);

namespace App\Repository;

use App\Administracion\ConfiguracionPortada;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ConfiguracionPortadaRepository
{
    public function __construct(private EntityManagerInterface $entidades)
    {
    }

    public function consultar(): array
    {
        return $this->entidades->createQuery('SELECT p.telefono AS telefono, p.imagenVersion AS imagen_version, p.instagram AS instagram, p.facebook AS facebook, p.direccion AS direccion, p.horarios AS horarios, p.soloImagen AS solo_imagen, p.serviciosVisible AS servicios_visible, p.promocionesVisible AS promociones_visible, p.ubicacionVisible AS ubicacion_visible, p.horariosVisible AS horarios_visible, p.consultasVisible AS consultas_visible FROM App\Entity\ConfiguracionPortada p WHERE p.id = 1')->getOneOrNullResult() ?? [];
    }

    public function imagen(string $version): array|false
    {
        return $this->entidades->getConnection()->fetchAssociative("SELECT encode(imagen, 'base64') AS contenido, imagen_tipo FROM configuracion_portada WHERE id = 1 AND imagen_version = ?", [$version]);
    }

    public function actualizar(array $campos, string $autor, ?array $imagen = null): void
    {
        $conexion = $this->entidades->getConnection();
        $conexion->transactional(function () use ($conexion, $campos, $autor, $imagen): void {
            $conexion->executeStatement('INSERT INTO configuracion_portada (id, telefono, actualizado_por, direccion, horarios) VALUES (1, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING', [ConfiguracionPortada::TELEFONO_INICIAL, $autor, ConfiguracionPortada::DIRECCION_INICIAL, ConfiguracionPortada::HORARIOS_INICIALES]);
            $fecha = new \DateTimeImmutable($conexion->fetchOne('SELECT statement_timestamp()'));
            $asignaciones = ['p.actualizadoPor = :autor', 'p.actualizadoEn = :fecha'];
            foreach ($campos as $campo => $valor) {
                $asignaciones[] = "p.$campo = :$campo";
            }
            $consulta = $this->entidades->createQuery('UPDATE App\Entity\ConfiguracionPortada p SET '.implode(', ', $asignaciones).' WHERE p.id = 1')
                ->setParameter('autor', $autor)->setParameter('fecha', $fecha);
            foreach ($campos as $campo => $valor) {
                $consulta->setParameter($campo, $valor);
            }
            $consulta->execute();
            if ($imagen !== null) {
                $conexion->executeStatement("UPDATE configuracion_portada SET imagen = decode(?, 'base64'), imagen_tipo = ?, imagen_version = ? WHERE id = 1", $imagen);
            }
        });
    }
}