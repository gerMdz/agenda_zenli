<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Agenda\TurnoNoDisponible;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class HorariosProfesionales
{
    private const ZONA = 'America/Argentina/Mendoza';

    public function __construct(private Connection $conexion)
    {
    }

    public function consultar(string $id): array
    {
        $perfil = $this->conexion->fetchAssociative(<<<'SQL'
            SELECT p.horarios_revision AS revision,
                   COALESCE(p.horario_por_franjas, EXISTS (
                       SELECT 1 FROM horarios_profesionales h WHERE h.profesional_id = p.id
                   ))::int AS por_franjas
            FROM profesionales p WHERE p.id = ? AND p.activa
            SQL, [$id]);
        if ($perfil === false) {
            throw new HttpException(404, 'La profesional no está disponible.');
        }

        return [
            'revision' => (int) $perfil['revision'],
            'por_franjas' => (bool) $perfil['por_franjas'],
            'franjas' => $this->conexion->fetchAllAssociative(<<<'SQL'
                SELECT dia_semana::int, hora_inicio::text, hora_fin::text, vigente_desde, vigente_hasta
                FROM horarios_profesionales WHERE profesional_id = ?
                ORDER BY vigente_desde, dia_semana, hora_inicio, id
                SQL, [$id]),
            'excepciones' => $this->conexion->fetchAllAssociative(<<<'SQL'
                SELECT 'especial' AS tipo, fecha::text, hora_inicio::text, hora_fin::text
                FROM horarios_profesionales_especiales WHERE profesional_id = ?
                UNION ALL
                SELECT 'bloqueo', (inicio AT TIME ZONE 'America/Argentina/Mendoza')::date::text,
                       (inicio AT TIME ZONE 'America/Argentina/Mendoza')::time::text,
                       CASE WHEN (fin AT TIME ZONE 'America/Argentina/Mendoza')::time = TIME '00:00'
                            THEN '24:00:00' ELSE (fin AT TIME ZONE 'America/Argentina/Mendoza')::time::text END
                FROM bloqueos WHERE profesional_id = ? AND gestion_horarios
                ORDER BY fecha, hora_inicio, tipo
                SQL, [$id, $id]),
        ];
    }

    public function guardar(string $id, array $datos, ?string $autor = null): void
    {
        $modo = $datos['por_franjas'] ?? null;
        $filas = $datos['franjas'] ?? null;
        $revision = $datos['revision'] ?? null;
        if (!is_bool($modo) || !is_array($filas) || !array_is_list($filas) || count($filas) > 200 || !is_int($revision)) {
            throw new InvalidArgumentException('Revisá los horarios e ingresá hasta 200 franjas.');
        }
        $franjas = array_map(self::validarFranja(...), $filas);
        $excepciones = null;
        if (array_key_exists('excepciones', $datos)) {
            if (!is_array($datos['excepciones']) || !array_is_list($datos['excepciones']) || count($datos['excepciones']) > 200) {
                throw new InvalidArgumentException('Revisá las excepciones e ingresá hasta 200 franjas por fecha.');
            }
            $excepciones = array_map(self::validarExcepcion(...), $datos['excepciones']);
        }

        $this->conexion->transactional(function () use ($id, $modo, $revision, $franjas, $excepciones, $autor): void {
            $this->bloquearCambiosAgenda();
            if ($this->consultar($id)['revision'] !== $revision) {
                throw new TurnoNoDisponible('Los horarios cambiaron. Volvé a cargarlos antes de guardar.');
            }
            $this->conexion->executeStatement(
                'UPDATE profesionales SET horario_por_franjas = ?, horarios_revision = horarios_revision + 1 WHERE id = ?',
                [$modo ? 'true' : 'false', $id],
            );
            $this->conexion->delete('horarios_profesionales', ['profesional_id' => $id]);
            foreach ($franjas as $franja) {
                $this->conexion->insert('horarios_profesionales', $franja + ['profesional_id' => $id]);
            }
            if ($excepciones !== null) {
                $this->conexion->delete('horarios_profesionales_especiales', ['profesional_id' => $id]);
                $this->conexion->executeStatement('DELETE FROM bloqueos WHERE profesional_id = ? AND gestion_horarios', [$id]);
                foreach ($excepciones as $excepcion) {
                    if ($excepcion['tipo'] === 'bloqueo') {
                        $this->insertarBloqueo($id, $excepcion, $autor);
                    } else {
                        $this->conexion->executeStatement(<<<'SQL'
                            INSERT INTO horarios_profesionales_especiales (profesional_id, fecha, hora_inicio, hora_fin)
                            VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING
                            SQL, [$id, $excepcion['fecha'], $excepcion['hora_inicio'], $excepcion['hora_fin']]);
                    }
                }
            }
            $this->verificarTurnosExistentes($id);
        });
    }

    public function agregarBloqueo(string $id, array $datos, ?string $autor = null): void
    {
        $bloqueo = self::validarExcepcion(['tipo' => 'bloqueo'] + $datos);
        $this->conexion->transactional(function () use ($id, $bloqueo, $autor): void {
            $this->bloquearCambiosAgenda();
            $this->consultar($id);
            $this->insertarBloqueo($id, $bloqueo, $autor);
            $this->verificarTurnosExistentes($id);
            $this->conexion->executeStatement('UPDATE profesionales SET horarios_revision = horarios_revision + 1 WHERE id = ?', [$id]);
        });
    }

    private function bloquearCambiosAgenda(): void
    {
        // Las altas y ediciones de turnos bloquean el espacio antes de comprobar la disponibilidad.
        $this->conexion->executeStatement('LOCK TABLE recursos IN EXCLUSIVE MODE');
    }

    private function insertarBloqueo(string $id, array $bloqueo, ?string $autor): void
    {
        $inicio = $bloqueo['fecha'].' '.$bloqueo['hora_inicio'];
        $fin = $bloqueo['fecha'].' '.$bloqueo['hora_fin'];
        $this->conexion->executeStatement(<<<'SQL'
            INSERT INTO bloqueos (profesional_id, inicio, fin, gestion_horarios, creado_por)
            VALUES (?, CAST(? AS timestamp) AT TIME ZONE 'America/Argentina/Mendoza',
                       CAST(? AS timestamp) AT TIME ZONE 'America/Argentina/Mendoza', true, ?)
            ON CONFLICT (profesional_id, inicio, fin) WHERE gestion_horarios DO NOTHING
            SQL, [$id, $inicio, $fin, $autor]);
    }

    private function verificarTurnosExistentes(string $id): void
    {
        $ocupaciones = $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT o.inicio, o.fin, COALESCE(t.inicio, s.inicio, o.inicio) AS turno_inicio
            FROM ocupaciones_profesionales o
            LEFT JOIN turnos_administrativos t ON t.id = o.turno_admin_id
            LEFT JOIN reserva_sesiones s ON s.id = o.sesion_id
            WHERE o.profesional_id = ? AND o.fin > now() ORDER BY o.inicio
            SQL, [$id]);
        foreach ($ocupaciones as $ocupacion) {
            if (!$this->permite($id, new DateTimeImmutable($ocupacion['inicio']), new DateTimeImmutable($ocupacion['fin']))) {
                $fecha = (new DateTimeImmutable($ocupacion['turno_inicio']))->setTimezone(new DateTimeZone(self::ZONA))->format('d/m/Y H:i');
                throw new TurnoNoDisponible("Ya hay un turno el $fecha que quedaría fuera del horario o en un horario no disponible. Mové o cancelá ese turno antes de guardar.");
            }
        }
    }

    public function permite(string $id, DateTimeImmutable $inicio, DateTimeImmutable $fin): bool
    {
        $zona = new DateTimeZone(self::ZONA);
        $inicioLocal = $inicio->setTimezone($zona)->format('Y-m-d H:i:s.u');
        $finLocal = $fin->setTimezone($zona)->format('Y-m-d H:i:s.u');
        $resultado = $this->conexion->fetchOne(<<<'SQL'
            WITH periodo AS (SELECT CAST(? AS timestamp) AS inicio, CAST(? AS timestamp) AS fin),
                 dias AS (
                     SELECT generate_series(date_trunc('day', inicio), date_trunc('day', fin - interval '1 microsecond'), interval '1 day')::date AS dia
                     FROM periodo
                 ),
                 perfil AS (
                     SELECT p.id, COALESCE(p.horario_por_franjas, EXISTS (
                         SELECT 1 FROM horarios_profesionales h WHERE h.profesional_id = p.id
                     )) AS restringido
                     FROM profesionales p WHERE p.id = ? AND p.activa
                 ),
                 especiales AS (
                     SELECT e.fecha AS dia, e.hora_inicio, e.hora_fin
                     FROM horarios_profesionales_especiales e JOIN dias d ON d.dia = e.fecha
                     JOIN perfil p ON p.id = e.profesional_id
                 ),
                 franjas AS (
                     SELECT dia, hora_inicio, hora_fin FROM especiales
                     UNION ALL
                     SELECT d.dia, h.hora_inicio, h.hora_fin FROM dias d
                     JOIN horarios_profesionales h ON h.dia_semana = extract(isodow FROM d.dia)
                     JOIN perfil p ON p.id = h.profesional_id AND p.restringido
                     WHERE h.vigente_desde <= d.dia AND (h.vigente_hasta IS NULL OR h.vigente_hasta >= d.dia)
                       AND NOT EXISTS (SELECT 1 FROM especiales e WHERE e.dia = d.dia)
                     UNION ALL
                     SELECT d.dia, TIME '00:00', TIME '24:00' FROM dias d CROSS JOIN perfil p
                     WHERE NOT p.restringido AND NOT EXISTS (SELECT 1 FROM especiales e WHERE e.dia = d.dia)
                 ),
                 cobertura AS (
                     SELECT range_agg(tsrange(dia + hora_inicio, dia + hora_fin, '[)')) AS franjas FROM franjas
                 )
            SELECT (COALESCE(c.franjas @> tsrange(periodo.inicio, periodo.fin, '[)'), false)
                    AND NOT EXISTS (
                        SELECT 1 FROM bloqueos b WHERE b.profesional_id = p.id AND b.gestion_horarios
                          AND b.inicio < (periodo.fin AT TIME ZONE 'America/Argentina/Mendoza')
                          AND b.fin > (periodo.inicio AT TIME ZONE 'America/Argentina/Mendoza')
                    ))::int
            FROM perfil p CROSS JOIN cobertura c CROSS JOIN periodo
            SQL, [$inicioLocal, $finLocal, $id]);

        return (int) $resultado === 1;
    }

    private static function validarExcepcion(mixed $fila): array
    {
        if (!is_array($fila) || !in_array($fila['tipo'] ?? null, ['especial', 'bloqueo'], true)) {
            throw new InvalidArgumentException('Elegí un horario especial o un horario no disponible.');
        }
        $fecha = self::fecha($fila['fecha'] ?? null)->format('Y-m-d');
        $todoElDia = $fila['tipo'] === 'bloqueo' && ($fila['todo_el_dia'] ?? false) === true;
        $inicio = self::hora($todoElDia ? '00:00' : ($fila['hora_inicio'] ?? null));
        $fin = self::hora($todoElDia ? '24:00' : ($fila['hora_fin'] ?? null));
        if ($fin <= $inicio) {
            throw new InvalidArgumentException('La hora de fin debe ser posterior al inicio.');
        }

        return ['tipo' => $fila['tipo'], 'fecha' => $fecha, 'hora_inicio' => $inicio, 'hora_fin' => $fin];
    }

    private static function validarFranja(mixed $fila): array
    {
        if (!is_array($fila)) {
            throw new InvalidArgumentException('Revisá los datos de cada franja.');
        }
        $desde = self::fecha($fila['vigente_desde'] ?? null);
        if (($fila['tipo'] ?? null) === 'puntual') {
            $hasta = $desde;
            $dia = (int) $desde->format('N');
        } elseif (($fila['tipo'] ?? null) === 'semanal') {
            $finVigencia = $fila['vigente_hasta'] ?? '';
            $hasta = $finVigencia === '' ? null : self::fecha($finVigencia);
            $dia = $fila['dia_semana'] ?? null;
            if (!is_int($dia) || $dia < 1 || $dia > 7 || ($hasta !== null && $hasta < $desde)
                || ($hasta == $desde && $dia !== (int) $desde->format('N'))) {
                throw new InvalidArgumentException('Revisá el día y la vigencia de la franja semanal.');
            }
        } else {
            throw new InvalidArgumentException('Elegí si la franja es semanal o puntual.');
        }

        $inicio = self::hora($fila['hora_inicio'] ?? null);
        $fin = self::hora($fila['hora_fin'] ?? null);
        if ($fin <= $inicio) {
            throw new InvalidArgumentException('La hora de fin debe ser posterior al inicio y quedar dentro del mismo día.');
        }

        return [
            'dia_semana' => $dia, 'hora_inicio' => $inicio, 'hora_fin' => $fin,
            'vigente_desde' => $desde->format('Y-m-d'), 'vigente_hasta' => $hasta?->format('Y-m-d'),
        ];
    }

    private static function fecha(mixed $valor): DateTimeImmutable
    {
        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new InvalidArgumentException('Ingresá una fecha válida para cada franja.');
        }

        return $fecha;
    }

    private static function hora(mixed $valor): string
    {
        if ($valor === '24:00' || $valor === '24:00:00') {
            return '24:00:00.000000';
        }
        if (!is_string($valor) || preg_match('/\\A((?:[01][0-9]|2[0-3]):[0-5][0-9])(?::([0-5][0-9])(?:\\.([0-9]{1,6}))?)?\\z/', $valor, $partes) !== 1) {
            throw new InvalidArgumentException('Ingresá las horas en formato de 24 horas, por ejemplo 14:30.');
        }

        return $partes[1].':'.($partes[2] ?? '00').'.'.str_pad($partes[3] ?? '', 6, '0');
    }
}
