<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Agenda\TurnoNoDisponible;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class AgendaAdministrativa
{
    private const POLITICA = 'preparacion_agenda_admin';
    private const ZONA = 'America/Argentina/Mendoza';

    public function __construct(
        private Connection $conexion,
        private HorariosProfesionales $horarios,
    )
    {
    }

    public function consultar(string $fecha, ?string $propia = null, ?string $hasta = null): array
    {
        $dia = self::fecha($fecha, 'Y-m-d');
        $ultimo = self::fecha($hasta ?? $fecha, 'Y-m-d');
        if ($ultimo < $dia || $ultimo > $dia->modify('+30 days')) {
            throw new InvalidArgumentException('El período debe tener entre 1 y 31 días.');
        }
        $intervalo = [$ultimo->modify('+1 day')->format(DATE_ATOM), $dia->format(DATE_ATOM)];
        $turnos = $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT t.id::text, t.servicio_nombre AS servicio, t.cliente_nombre AS cliente, t.inicio, t.fin,
                   t.revision, t.servicio_id::text, r.id::text AS espacio_id, r.nombre AS espacio,
                   COALESCE(json_agg(p.id::text ORDER BY p.id) FILTER (WHERE p.id IS NOT NULL), '[]'::json)::text AS equipo,
                   (extract(epoch FROM t.fin - t.inicio) / 60)::int AS duracion,
                   COALESCE(string_agg(p.nombre, ', ' ORDER BY p.nombre), '') AS profesionales,
                   t.profesionales_requeridos, t.sena_pagada::int AS sena_pagada,
                   t.sena_importe_centavos::text AS sena_importe_centavos
            FROM turnos_administrativos t
            JOIN ocupaciones_recursos ore ON ore.turno_admin_id = t.id
            JOIN recursos r ON r.id = ore.recurso_id
            LEFT JOIN ocupaciones_profesionales op ON op.turno_admin_id = t.id
            LEFT JOIN profesionales p ON p.id = op.profesional_id
            WHERE t.inicio < ? AND t.fin > ? AND t.cancelado_en IS NULL
              AND (CAST(? AS bigint) IS NULL OR EXISTS (
                  SELECT 1 FROM ocupaciones_profesionales propia
                  WHERE propia.turno_admin_id = t.id AND propia.profesional_id = ?
              ))
            GROUP BY t.id, r.id
            ORDER BY t.inicio, t.id
            SQL, [...$intervalo, $propia, $propia]);
        foreach ($turnos as &$turno) {
            $turno['inicio'] = (new DateTimeImmutable($turno['inicio']))->format(DATE_ATOM);
            $turno['fin'] = (new DateTimeImmutable($turno['fin']))->format(DATE_ATOM);
            $turno['equipo'] = json_decode($turno['equipo'], true, flags: JSON_THROW_ON_ERROR);
            $turno['profesionales_requeridos'] = $turno['profesionales_requeridos'] === null ? null : (int) $turno['profesionales_requeridos'];
            $turno['equipo_incompleto'] = $turno['profesionales_requeridos'] === null || count($turno['equipo']) < $turno['profesionales_requeridos'];
            $turno['sena_pagada'] = (bool) $turno['sena_pagada'];
            $turno['editable'] = $propia === null || $turno['equipo'] === [$propia];
        }
        unset($turno);

        $bloqueos = $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT b.id::text, b.inicio, b.fin, p.id::text AS profesional_id, p.nombre AS profesionales,
                   (((b.inicio AT TIME ZONE 'America/Argentina/Mendoza')::time = TIME '00:00')
                    AND ((b.fin AT TIME ZONE 'America/Argentina/Mendoza')::time = TIME '00:00'))::int AS todo_el_dia
            FROM bloqueos b JOIN profesionales p ON p.id = b.profesional_id
            WHERE b.gestion_horarios AND b.inicio < ? AND b.fin > ?
              AND (CAST(? AS bigint) IS NULL OR p.id = ?)
            ORDER BY b.inicio, b.id
            SQL, [...$intervalo, $propia, $propia]);
        foreach ($bloqueos as &$bloqueo) {
            $bloqueo['inicio'] = (new DateTimeImmutable($bloqueo['inicio']))->format(DATE_ATOM);
            $bloqueo['fin'] = (new DateTimeImmutable($bloqueo['fin']))->format(DATE_ATOM);
            $bloqueo['todo_el_dia'] = (bool) $bloqueo['todo_el_dia'];
        }
        unset($bloqueo);

        return [
            'servicios' => $this->conexion->fetchAllAssociative('SELECT id::text, nombre, duracion_minima, profesionales_requeridos FROM servicios_portada ORDER BY orden, nombre'),
            'profesionales' => $this->conexion->fetchAllAssociative('SELECT id::text, nombre, (usuario_id IS NOT NULL)::int AS tiene_cuenta FROM profesionales WHERE activa AND (CAST(? AS bigint) IS NULL OR id = ?) ORDER BY nombre', [$propia, $propia]),
            'espacios' => $this->espacios(),
            'preparacion' => $this->preparacion(),
            'turnos' => $turnos,
            'bloqueos' => $bloqueos,
        ];
    }

    public function configuracion(): array
    {
        return [
            'profesionales' => $this->conexion->fetchAllAssociative(<<<'SQL'
                SELECT p.id::text, p.nombre, (p.usuario_id IS NOT NULL)::int AS tiene_cuenta,
                       COALESCE(jsonb_exists(u.roles, 'ROLE_ADMIN'), false)::int AS admin
                FROM profesionales p LEFT JOIN usuarios u ON u.id = p.usuario_id
                WHERE p.activa ORDER BY p.nombre
                SQL),
            'espacios' => $this->espacios(),
            'preparacion' => $this->preparacion(),
        ];
    }

    private function espacios(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT id::text, nombre, detalle FROM recursos WHERE activo ORDER BY nombre, id');
    }

    public function guardarEspacio(array $datos, ?string $id): void
    {
        $nombre = $datos['nombre'] ?? null;
        $detalle = $datos['detalle'] ?? '';
        if (!is_string($nombre) || preg_match('/\A[^\x00]{1,120}\z/u', trim($nombre)) !== 1
            || !is_string($detalle) || preg_match('/\A[^\x00]{0,1000}\z/u', trim($detalle)) !== 1) {
            throw new InvalidArgumentException('Ingresá un nombre y un detalle de hasta 1000 caracteres.');
        }
        $campos = ['nombre' => trim($nombre), 'detalle' => trim($detalle)];
        if ($id === null) {
            $this->conexion->insert('recursos', $campos + ['codigo' => 'ESP_'.bin2hex(random_bytes(12))]);

            return;
        }
        if ($this->conexion->update('recursos', $campos, ['id' => self::id($id)]) === 0) {
            throw new HttpException(404, 'El espacio ya no está disponible.');
        }
    }

    public function guardarPreparacion(array $datos): void
    {
        if (($datos['modo'] ?? null) === 'general') {
            $politica = ['modo' => 'general', 'minutos' => self::minutos($datos['minutos'] ?? null, 0)];
        } elseif (($datos['modo'] ?? null) === 'por_servicio' && is_array($datos['servicios'] ?? null)) {
            $servicios = [];
            foreach ($this->conexion->fetchFirstColumn('SELECT id FROM servicios_portada') as $id) {
                $valor = $datos['servicios'][$id] ?? null;
                if ($valor !== null) {
                    $servicios[$id] = self::minutos($valor, 0);
                }
            }
            $politica = ['modo' => 'por_servicio', 'servicios' => (object) $servicios];
        } else {
            throw new InvalidArgumentException('Elegí cómo se define la preparación.');
        }

        $this->conexion->executeStatement(<<<'SQL'
            INSERT INTO politicas_reservas (clave, valor) VALUES (?, ?)
            ON CONFLICT (clave) DO UPDATE SET valor = excluded.valor, actualizado_en = now()
            SQL, [self::POLITICA, json_encode($politica, JSON_THROW_ON_ERROR)]);
    }

    public function cargar(array $datos, string $autor, ?string $propia = null, ?string $editar = null): string
    {
        $servicio = self::id($datos['servicio'] ?? null);
        $espacio = self::id($datos['espacio'] ?? null);
        $equipo = $datos['profesionales'] ?? null;
        if (!is_array($equipo) || !array_is_list($equipo) || count($equipo) > 100) {
            throw new InvalidArgumentException('Revisá la selección de profesionales.');
        }
        $equipo = array_unique(array_map(self::id(...), $equipo));
        sort($equipo, SORT_STRING);
        if ($propia !== null && $equipo !== [$propia]) {
            throw new HttpException(403, 'Solo podés asignarte turnos a vos misma.');
        }
        $revision = $datos['revision'] ?? null;
        $cliente = $datos['cliente'] ?? null;
        if (!is_string($cliente) || trim($cliente) === '' || preg_match('/\A[^\x00]{1,150}\z/u', trim($cliente)) !== 1) {
            throw new InvalidArgumentException('Ingresá un nombre de cliente válido.');
        }
        $inicio = self::fecha($datos['inicio'] ?? null, 'Y-m-d\TH:i');
        $duracion = self::minutos($datos['duracion'] ?? null, 1);
        $pagada = $datos['sena_pagada'] ?? null;
        $importe = $datos['sena_importe_centavos'] ?? null;
        if ((array_key_exists('sena_pagada', $datos) && !is_bool($pagada))
            || ($importe !== null && (!is_string($importe) || preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/', $importe) !== 1))) {
            throw new InvalidArgumentException('Ingresá una seña válida en centavos.');
        }

        try {
            return $this->conexion->transactional(function () use ($servicio, $espacio, $equipo, $cliente, $inicio, $duracion, $autor, $propia, $editar, $revision, $datos, $pagada, $importe): string {
                $anterior = $editar === null ? null : $this->bloquearEditable($editar, $propia, $revision);
                if ($this->conexion->fetchOne('SELECT id FROM recursos WHERE id = ? AND activo FOR UPDATE', [$espacio]) === false) {
                    throw new InvalidArgumentException('Elegí un espacio activo.');
                }
                $configuracion = $this->conexion->fetchAssociative('SELECT nombre, duracion_minima, profesionales_requeridos FROM servicios_portada WHERE id = ? FOR SHARE', [$servicio]);
                if ($configuracion === false) {
                    throw new InvalidArgumentException('Elegí un servicio del catálogo.');
                }
                if ((int) $configuracion['duracion_minima'] < 1 || (int) $configuracion['profesionales_requeridos'] < 1) {
                    throw new InvalidArgumentException('El servicio no tiene duración mínima y profesionales requeridos configurados.');
                }
                $politica = $this->preparacion();
                $minutos = ($politica['modo'] ?? null) === 'general' ? $politica['minutos'] : ($politica['servicios'][$servicio] ?? null);
                if ($minutos === null) {
                    throw new InvalidArgumentException('Configurá el tiempo de preparación de este servicio.');
                }

                $desde = $inicio->modify("-$minutos minutes");
                $fin = $inicio->modify("+$duracion minutes");
                $ocupacion = [$desde->format(DATE_ATOM), $fin->format(DATE_ATOM)];
                $this->verificarLibre('recursos', 'recurso_id', $espacio, $ocupacion, $editar);
                foreach ($equipo as $profesional) {
                    if ($this->conexion->fetchOne('SELECT id FROM profesionales WHERE id = ? AND activa', [$profesional]) === false) {
                        throw new InvalidArgumentException('Seleccioná profesionales activas.');
                    }
                    if (!$this->horarios->permite($profesional, $desde, $fin)) {
                        throw new TurnoNoDisponible('El turno y su preparación deben entrar en el horario de todas las profesionales asignadas.');
                    }
                    $this->verificarLibre('profesionales', 'profesional_id', $profesional, $ocupacion, $editar);
                }

                $pagada = array_key_exists('sena_pagada', $datos) ? $pagada : ($anterior === null ? false : (bool) $anterior['sena_pagada']);
                $importe = array_key_exists('sena_importe_centavos', $datos) ? $importe : ($anterior['sena_importe_centavos'] ?? null);
                $campos = [$servicio, $configuracion['nombre'], trim($cliente), $inicio->format(DATE_ATOM), $ocupacion[1], (int) $configuracion['profesionales_requeridos'], $pagada ? 1 : 0, $importe];
                if ($editar === null) {
                    $id = $this->conexion->fetchOne(<<<'SQL'
                        INSERT INTO turnos_administrativos (servicio_id, servicio_nombre, cliente_nombre, inicio, fin, profesionales_requeridos, sena_pagada, sena_importe_centavos, creado_por)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id::text
                        SQL, [...$campos, $autor]);
                } else {
                    $id = $editar;
                    $this->registrarEvento($id, $autor, 'EDITADO', $anterior);
                    $this->liberar($id);
                    $this->conexion->executeStatement(<<<'SQL'
                        UPDATE turnos_administrativos
                        SET servicio_id = ?, servicio_nombre = ?, cliente_nombre = ?, inicio = ?, fin = ?, profesionales_requeridos = ?, sena_pagada = ?, sena_importe_centavos = ?, revision = revision + 1
                        WHERE id = ?
                        SQL, [...$campos, $id]);
                }
                $datos = ['turno_admin_id' => $id, 'inicio' => $ocupacion[0], 'fin' => $ocupacion[1]];
                $this->conexion->insert('ocupaciones_recursos', $datos + ['recurso_id' => $espacio]);
                foreach ($equipo as $profesional) {
                    $this->conexion->insert('ocupaciones_profesionales', $datos + ['profesional_id' => $profesional]);
                }

                return (string) $id;
            });
        } catch (DriverException $error) {
            if (in_array($error->getSQLState(), ['23P01', '40P01'], true)) {
                throw new TurnoNoDisponible('Hay una ocupación concurrente. Revisá el horario e intentá nuevamente.', previous: $error);
            }
            throw $error;
        }
    }

    public function cancelar(string $id, string $autor, ?string $propia, mixed $revision): void
    {
        $this->conexion->transactional(function () use ($id, $autor, $propia, $revision): void {
            $anterior = $this->bloquearEditable($id, $propia, $revision);
            $this->registrarEvento($id, $autor, 'CANCELADO', $anterior);
            $this->liberar($id);
            $this->conexion->executeStatement('UPDATE turnos_administrativos SET cancelado_en = now(), revision = revision + 1 WHERE id = ?', [$id]);
        });
    }

    private function bloquearEditable(string $id, ?string $propia, mixed $revision): array
    {
        self::id($id);
        $turno = $this->conexion->fetchAssociative('SELECT * FROM turnos_administrativos WHERE id = ? AND cancelado_en IS NULL FOR UPDATE', [$id]);
        if ($turno === false) {
            throw new HttpException(404, 'El turno ya no está disponible.');
        }
        $turno['profesionales'] = $this->conexion->fetchFirstColumn('SELECT profesional_id::text FROM ocupaciones_profesionales WHERE turno_admin_id = ? ORDER BY profesional_id', [$id]);
        if ($propia !== null && !in_array($propia, $turno['profesionales'], true)) {
            throw new HttpException(404, 'El turno ya no está disponible.');
        }
        if ($propia !== null && $turno['profesionales'] !== [$propia]) {
            throw new HttpException(403, 'Este turno solo puede modificarlo administración.');
        }
        if ($revision !== (int) $turno['revision']) {
            throw new TurnoNoDisponible('El turno cambió. Volvé a cargar la agenda antes de modificarlo.');
        }
        $turno['espacios'] = $this->conexion->fetchAllAssociative('SELECT recurso_id, inicio, fin FROM ocupaciones_recursos WHERE turno_admin_id = ?', [$id]);

        return $turno;
    }

    private function registrarEvento(string $id, string $autor, string $tipo, array $anterior): void
    {
        $this->conexion->insert('eventos_turnos_administrativos', [
            'turno_id' => $id, 'autor_id' => $autor, 'tipo' => $tipo,
            'estado_anterior' => json_encode($anterior, JSON_THROW_ON_ERROR),
        ]);
    }

    private function liberar(string $id): void
    {
        $this->conexion->delete('ocupaciones_recursos', ['turno_admin_id' => $id]);
        $this->conexion->delete('ocupaciones_profesionales', ['turno_admin_id' => $id]);
    }

    private function verificarLibre(string $tabla, string $columna, string $id, array $intervalo, ?string $editar): void
    {
        $ocupado = $this->conexion->fetchOne(<<<SQL
            SELECT 1 FROM ocupaciones_$tabla WHERE $columna = ? AND fin > ? AND inicio < ? AND turno_admin_id IS DISTINCT FROM CAST(? AS bigint)
            UNION ALL
            SELECT 1 FROM bloqueos WHERE $columna = ? AND fin > ? AND inicio < ?
            LIMIT 1
            SQL, [$id, ...$intervalo, $editar ?? '0', $id, ...$intervalo]);
        if ($ocupado !== false) {
            throw new TurnoNoDisponible('El espacio o una de las profesionales ya tiene una ocupación en ese horario.');
        }
    }

    private function preparacion(): ?array
    {
        $valor = $this->conexion->fetchOne('SELECT valor FROM politicas_reservas WHERE clave = ?', [self::POLITICA]);

        return $valor === false ? null : json_decode($valor, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function fecha(mixed $valor, string $formato): DateTimeImmutable
    {
        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!'.$formato, $valor, new DateTimeZone(self::ZONA)) : false;
        if ($fecha === false || $fecha->format($formato) !== $valor) {
            throw new InvalidArgumentException('Ingresá una fecha y hora válidas.');
        }

        return $fecha;
    }

    private static function id(mixed $id): string
    {
        if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,17}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Revisá la selección de servicio, espacio y profesionales.');
        }

        return $id;
    }

    private static function minutos(mixed $valor, int $minimo): int
    {
        if (!is_int($valor) || $valor < $minimo || $valor > 1440) {
            throw new InvalidArgumentException('Ingresá los minutos como un número entero válido.');
        }

        return $valor;
    }
}
