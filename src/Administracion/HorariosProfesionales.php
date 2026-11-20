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
        ];
    }

    public function guardar(string $id, array $datos): void
    {
        $modo = $datos['por_franjas'] ?? null;
        $filas = $datos['franjas'] ?? null;
        $revision = $datos['revision'] ?? null;
        if (!is_bool($modo) || !is_array($filas) || !array_is_list($filas) || count($filas) > 200 || !is_int($revision)) {
            throw new InvalidArgumentException('Revisá los horarios e ingresá hasta 200 franjas.');
        }
        $franjas = array_map(self::validarFranja(...), $filas);

        $this->conexion->transactional(function () use ($id, $modo, $revision, $franjas): void {
            // Las altas y ediciones de turnos bloquean el espacio antes de comprobar la disponibilidad.
            $this->conexion->executeStatement('LOCK TABLE recursos IN EXCLUSIVE MODE');
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
            if (!$modo) {
                return;
            }

            $ocupaciones = $this->conexion->fetchAllAssociative(
                'SELECT inicio, fin FROM ocupaciones_profesionales WHERE profesional_id = ? AND fin > now() ORDER BY inicio',
                [$id],
            );
            foreach ($ocupaciones as $ocupacion) {
                $inicio = new DateTimeImmutable($ocupacion['inicio']);
                if (!$this->permite($id, $inicio, new DateTimeImmutable($ocupacion['fin']))) {
                    $fecha = $inicio->setTimezone(new DateTimeZone(self::ZONA))->format('d/m/Y H:i');
                    throw new TurnoNoDisponible("El cambio deja fuera de horario una ocupación del $fecha. Mové o cancelá ese turno antes de guardar.");
                }
            }
        });
    }

    public function permite(string $id, DateTimeImmutable $inicio, DateTimeImmutable $fin): bool
    {
        $zona = new DateTimeZone(self::ZONA);
        $inicioLocal = $inicio->setTimezone($zona)->format('Y-m-d H:i:s.u');
        $finLocal = $fin->setTimezone($zona)->format('Y-m-d H:i:s.u');
        $resultado = $this->conexion->fetchOne(<<<'SQL'
            WITH periodo AS (SELECT CAST(? AS timestamp) AS inicio, CAST(? AS timestamp) AS fin),
                 dias AS (
                     SELECT generate_series(date_trunc('day', inicio), date_trunc('day', fin), interval '1 day')::date AS dia
                     FROM periodo
                 ),
                 cobertura AS (
                     SELECT range_agg(tsrange(d.dia + h.hora_inicio, d.dia + h.hora_fin, '[)')) AS franjas
                     FROM horarios_profesionales h JOIN dias d ON h.dia_semana = extract(isodow FROM d.dia)
                     WHERE h.profesional_id = ? AND h.vigente_desde <= d.dia AND (h.vigente_hasta IS NULL OR h.vigente_hasta >= d.dia)
                 )
            SELECT CASE WHEN COALESCE(p.horario_por_franjas, EXISTS (
                SELECT 1 FROM horarios_profesionales h WHERE h.profesional_id = p.id
            )) THEN COALESCE((c.franjas @> tsrange(periodo.inicio, periodo.fin, '[)'))::int, 0) ELSE 1 END
            FROM profesionales p CROSS JOIN cobertura c CROSS JOIN periodo WHERE p.id = ? AND p.activa
            SQL, [$inicioLocal, $finLocal, $id, $id]);

        return (int) $resultado === 1;
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
