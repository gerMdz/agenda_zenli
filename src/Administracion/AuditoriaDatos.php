<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;

/**
 * Resumen de autoría (creación y última modificación) de las tablas administrables,
 * solo para el panel de administración.
 */
final readonly class AuditoriaDatos
{
    private NombresAutores $autores;

    public function __construct(private Connection $conexion)
    {
        $this->autores = new NombresAutores($conexion);
    }

    public function resumen(): array
    {
        $portada = $this->conexion->fetchAssociative(
            'SELECT actualizado_por::text AS actualizado_por, actualizado_en FROM configuracion_portada WHERE id = 1',
        ) ?: null;
        $preparacion = $this->conexion->fetchAssociative(
            "SELECT actualizado_por::text AS actualizado_por, actualizado_en FROM politicas_reservas WHERE clave = 'preparacion_agenda_admin'",
        ) ?: null;
        $presentacion = $this->conexion->fetchAssociative(
            'SELECT modo, actualizado_por::text AS actualizado_por, actualizado_en FROM presentacion_profesionales_config WHERE id = 1',
        ) ?: null;
        $servicios = $this->conexion->fetchAllAssociative(
            'SELECT id, nombre, visible, creado_por, creado_en, actualizado_por, actualizado_en FROM servicios_portada ORDER BY orden, id',
        );
        $promociones = $this->conexion->fetchAllAssociative(
            'SELECT id, nombre, visible, eliminado, creado_por, creado_en, actualizado_por, actualizado_en FROM promociones_portada ORDER BY eliminado, orden, id',
        );
        $espacios = $this->conexion->fetchAllAssociative(
            'SELECT id::text AS id, nombre, activo, creado_por::text AS creado_por, creado_en, actualizado_por::text AS actualizado_por, actualizado_en FROM recursos ORDER BY nombre, id',
        );
        $profesionales = $this->conexion->fetchAllAssociative(
            <<<'SQL'
                SELECT p.id::text AS id, p.nombre, p.activa,
                       p.creado_por::text AS creado_por, p.creado_en,
                       p.actualizado_por::text AS actualizado_por, p.actualizado_en,
                       p.horarios_actualizado_por::text AS horarios_actualizado_por, p.horarios_actualizado_en,
                       i.actualizado_por::text AS imagen_actualizado_por, i.actualizado_en AS imagen_actualizado_en
                FROM profesionales p
                LEFT JOIN presentacion_profesionales_imagenes i ON i.profesional_id = p.id
                ORDER BY p.nombre, p.id
                SQL,
        );
        $cuentas = $this->conexion->fetchAllAssociative(
            <<<'SQL'
                SELECT u.id::text AS id, u.email, u.activo, u.roles, p.nombre AS profesional,
                       u.creado_por::text AS creado_por, u.creado_en,
                       u.actualizado_por::text AS actualizado_por, u.actualizado_en
                FROM usuarios u
                LEFT JOIN profesionales p ON p.usuario_id = u.id
                ORDER BY u.email, u.id
                SQL,
        );

        $ids = [];
        foreach ([$portada, $preparacion, $presentacion] as $fila) {
            if ($fila !== null) {
                $ids[] = $fila['actualizado_por'];
            }
        }
        foreach ([$servicios, $promociones, $espacios, $cuentas] as $grupo) {
            foreach ($grupo as $fila) {
                $ids[] = $fila['creado_por'];
                $ids[] = $fila['actualizado_por'];
            }
        }
        foreach ($profesionales as $fila) {
            $ids[] = $fila['creado_por'];
            $ids[] = $fila['actualizado_por'];
            $ids[] = $fila['horarios_actualizado_por'];
            $ids[] = $fila['imagen_actualizado_por'];
        }
        $mapa = $this->autores->resolver($ids);

        return [
            'portada' => $portada === null ? null : $this->autores->ultimaModificacion($portada['actualizado_por'], $portada['actualizado_en'], $mapa),
            'preparacion' => $preparacion === null ? null : $this->autores->ultimaModificacion($preparacion['actualizado_por'], $preparacion['actualizado_en'], $mapa),
            'presentacion_profesionales' => $presentacion === null ? null : [
                'modo' => $presentacion['modo'],
            ] + $this->autores->ultimaModificacion($presentacion['actualizado_por'], $presentacion['actualizado_en'], $mapa),
            'servicios' => array_map(fn (array $s): array => [
                'id' => (int) $s['id'],
                'nombre' => $s['nombre'],
                'visible' => (bool) $s['visible'],
                'auditoria' => $this->autores->auditoria($s['creado_por'], $s['creado_en'], $s['actualizado_por'], $s['actualizado_en'], $mapa),
            ], $servicios),
            'promociones' => array_map(fn (array $p): array => [
                'id' => (int) $p['id'],
                'nombre' => $p['nombre'],
                'visible' => (bool) $p['visible'],
                'eliminado' => (bool) $p['eliminado'],
                'auditoria' => $this->autores->auditoria($p['creado_por'], $p['creado_en'], $p['actualizado_por'], $p['actualizado_en'], $mapa),
            ], $promociones),
            'espacios' => array_map(fn (array $e): array => [
                'id' => $e['id'],
                'nombre' => $e['nombre'],
                'activo' => (bool) $e['activo'],
                'auditoria' => $this->autores->auditoria($e['creado_por'], $e['creado_en'], $e['actualizado_por'], $e['actualizado_en'], $mapa),
            ], $espacios),
            'profesionales' => array_map(fn (array $p): array => [
                'id' => $p['id'],
                'nombre' => $p['nombre'],
                'activa' => (bool) $p['activa'],
                'auditoria' => $this->autores->auditoria($p['creado_por'], $p['creado_en'], $p['actualizado_por'], $p['actualizado_en'], $mapa),
                'horarios_auditoria' => $this->autores->ultimaModificacion($p['horarios_actualizado_por'], $p['horarios_actualizado_en'], $mapa),
                'imagen_auditoria' => $this->autores->ultimaModificacion($p['imagen_actualizado_por'], $p['imagen_actualizado_en'], $mapa),
            ], $profesionales),
            'cuentas' => array_map(fn (array $u): array => [
                'id' => $u['id'],
                'email' => $u['email'],
                'activo' => (bool) $u['activo'],
                'admin' => self::esAdmin($u['roles']),
                'profesional' => $u['profesional'],
                'auditoria' => $this->autores->auditoria($u['creado_por'], $u['creado_en'], $u['actualizado_por'], $u['actualizado_en'], $mapa),
            ], $cuentas),
        ];
    }

    private static function esAdmin(mixed $roles): bool
    {
        if (is_string($roles)) {
            $roles = json_decode($roles, true) ?: [];
        }

        return is_array($roles) && (array_is_list($roles) ? in_array('ROLE_ADMIN', $roles, true) : array_key_exists('ROLE_ADMIN', $roles));
    }
}
