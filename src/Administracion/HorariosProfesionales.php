<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Agenda\TurnoNoDisponible;
use App\Entity\Bloqueo;
use App\Entity\FranjaHoraria;
use App\Entity\HorarioEspecial;
use App\Entity\Profesional;
use App\Entity\ProfesionalHorarios;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class HorariosProfesionales
{
    private const ZONA = 'America/Argentina/Mendoza';

    private const FRANJAS_SQL = <<<'SQL'
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
             WHERE CAST(? AS boolean) AND NOT p.restringido AND NOT EXISTS (SELECT 1 FROM especiales e WHERE e.dia = d.dia)
         )
    SQL;

    private Connection $conexion;

    public function __construct(private EntityManagerInterface $entidades)
    {
        // Solo para el bloqueo de agenda y las comprobaciones de cobertura propias de PostgreSQL.
        $this->conexion = $entidades->getConnection();
    }

    public function consultar(string $id): array
    {
        $perfil = $this->entidades->createQuery(<<<'DQL'
            SELECT p.horariosRevision AS revision, p.horarioPorFranjas AS por_franjas
            FROM App\Entity\ProfesionalHorarios p WHERE p.id = :id AND p.activa = true
            DQL)->setParameter('id', $id)->getOneOrNullResult();
        if ($perfil === null) {
            throw new HttpException(404, 'La profesional no está disponible.');
        }
        $franjas = $this->entidades->createQuery(<<<'DQL'
            SELECT f.diaSemana AS dia_semana, f.horaInicio AS hora_inicio, f.horaFin AS hora_fin,
                   f.vigenteDesde AS vigente_desde, f.vigenteHasta AS vigente_hasta
            FROM App\Entity\FranjaHoraria f WHERE f.profesionalId = :id
            ORDER BY f.vigenteDesde, f.diaSemana, f.horaInicio, f.id
            DQL)->setParameter('id', $id)->getArrayResult();

        $excepciones = array_map(static fn (array $e): array => ['tipo' => 'especial'] + $e, $this->entidades->createQuery(<<<'DQL'
            SELECT e.fecha AS fecha, e.horaInicio AS hora_inicio, e.horaFin AS hora_fin
            FROM App\Entity\HorarioEspecial e WHERE e.profesionalId = :id
            DQL)->setParameter('id', $id)->getArrayResult());
        $bloqueos = $this->entidades->createQuery(<<<'DQL'
            SELECT b.inicio AS inicio, b.fin AS fin FROM App\Entity\Bloqueo b
            WHERE b.profesional = :id AND b.gestionHorarios = true
            DQL)->setParameter('id', $id)->getArrayResult();
        $zona = new DateTimeZone(self::ZONA);
        foreach ($bloqueos as $bloqueo) {
            $fin = self::textoHora($bloqueo['fin']->setTimezone($zona));
            $excepciones[] = [
                'tipo' => 'bloqueo', 'fecha' => $bloqueo['inicio']->setTimezone($zona)->format('Y-m-d'),
                'hora_inicio' => self::textoHora($bloqueo['inicio']->setTimezone($zona)),
                'hora_fin' => $fin === '00:00:00' ? '24:00:00' : $fin,
            ];
        }
        usort($excepciones, static fn (array $a, array $b): int => [$a['fecha'], $a['hora_inicio'], $a['tipo']] <=> [$b['fecha'], $b['hora_inicio'], $b['tipo']]);

        return [
            'revision' => $perfil['revision'],
            'por_franjas' => $perfil['por_franjas'] ?? $franjas !== [],
            'franjas' => $franjas,
            'excepciones' => $excepciones,
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

        TransaccionEntidades::ejecutar($this->entidades, function () use ($id, $modo, $revision, $franjas, $excepciones, $autor): void {
            $this->bloquearCambiosAgenda();
            if ($this->consultar($id)['revision'] !== $revision) {
                throw new TurnoNoDisponible('Los horarios cambiaron. Volvé a cargarlos antes de guardar.');
            }
            $perfil = $this->perfil($id);
            $perfil->horarioPorFranjas = $modo;
            ++$perfil->horariosRevision;

            // Se reemplazan todas las filas; las eliminaciones se confirman antes de insertar las nuevas.
            $anteriores = $this->entidades->createQuery('SELECT f FROM App\Entity\FranjaHoraria f WHERE f.profesionalId = :id')
                ->setParameter('id', $id)->getResult();
            if ($excepciones !== null) {
                $anteriores = [...$anteriores, ...$this->entidades->createQuery('SELECT e FROM App\Entity\HorarioEspecial e WHERE e.profesionalId = :id')
                    ->setParameter('id', $id)->getResult(), ...$this->bloqueosGestionados($id)];
            }
            foreach ($anteriores as $anterior) {
                $this->entidades->remove($anterior);
            }
            $this->entidades->flush();

            foreach ($franjas as $datosFranja) {
                $franja = new FranjaHoraria();
                $franja->profesionalId = $id;
                $franja->diaSemana = $datosFranja['dia_semana'];
                $franja->horaInicio = self::textoHora($datosFranja['hora_inicio']);
                $franja->horaFin = self::textoHora($datosFranja['hora_fin']);
                $franja->vigenteDesde = $datosFranja['vigente_desde'];
                $franja->vigenteHasta = $datosFranja['vigente_hasta'];
                $this->entidades->persist($franja);
            }
            $nuevas = [];
            foreach ($excepciones ?? [] as $excepcion) {
                if ($excepcion['tipo'] === 'bloqueo') {
                    $fila = $this->nuevoBloqueo($id, $excepcion, $autor);
                    $clave = 'bloqueo '.$fila->inicio->format('U.u').' '.$fila->fin->format('U.u');
                } else {
                    $fila = new HorarioEspecial();
                    $fila->profesionalId = $id;
                    $fila->fecha = $excepcion['fecha'];
                    $fila->horaInicio = self::textoHora($excepcion['hora_inicio']);
                    $fila->horaFin = self::textoHora($excepcion['hora_fin']);
                    $clave = "especial $fila->fecha $fila->horaInicio $fila->horaFin";
                }
                // Las filas repetidas se omiten, como hacía ON CONFLICT DO NOTHING.
                if (!isset($nuevas[$clave])) {
                    $nuevas[$clave] = true;
                    $this->entidades->persist($fila);
                }
            }
            $this->entidades->flush();
            $this->verificarTurnosExistentes($id);
        });
    }

    public function agregarBloqueo(string $id, array $datos, ?string $autor = null): void
    {
        $datosBloqueo = self::validarExcepcion(['tipo' => 'bloqueo'] + $datos);
        TransaccionEntidades::ejecutar($this->entidades, function () use ($id, $datosBloqueo, $autor): void {
            $this->bloquearCambiosAgenda();
            $this->consultar($id);
            $bloqueo = $this->nuevoBloqueo($id, $datosBloqueo, $autor);
            $existente = $this->entidades->createQuery(<<<'DQL'
                SELECT COUNT(b.id) FROM App\Entity\Bloqueo b
                WHERE b.profesional = :id AND b.gestionHorarios = true AND b.inicio = :inicio AND b.fin = :fin
                DQL)->setParameter('id', $id)
                ->setParameter('inicio', $bloqueo->inicio, TransaccionEntidades::INSTANTE)
                ->setParameter('fin', $bloqueo->fin, TransaccionEntidades::INSTANTE)
                ->getSingleScalarResult();
            if ((int) $existente === 0) {
                $this->entidades->persist($bloqueo);
                $this->entidades->flush();
            }
            $this->verificarTurnosExistentes($id);
            ++$this->perfil($id)->horariosRevision;
        });
    }

    private function bloquearCambiosAgenda(): void
    {
        // Las altas y ediciones de turnos bloquean el espacio antes de comprobar la disponibilidad.
        $this->conexion->executeStatement('LOCK TABLE recursos IN EXCLUSIVE MODE');
    }

    private function perfil(string $id): ProfesionalHorarios
    {
        return $this->entidades->createQuery('SELECT p FROM App\Entity\ProfesionalHorarios p WHERE p.id = :id')
            ->setParameter('id', $id)->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)->getSingleResult();
    }

    /** @return list<Bloqueo> */
    private function bloqueosGestionados(string $id): array
    {
        return $this->entidades->createQuery('SELECT b FROM App\Entity\Bloqueo b WHERE b.profesional = :id AND b.gestionHorarios = true')
            ->setParameter('id', $id)->getResult();
    }

    private function nuevoBloqueo(string $id, array $datos, ?string $autor): Bloqueo
    {
        $zona = new DateTimeZone(self::ZONA);
        $instante = static function (string $hora) use ($datos, $zona): DateTimeImmutable {
            // 24:00 equivale a las 00:00 del día siguiente, igual que en PostgreSQL.
            if (str_starts_with($hora, '24:')) {
                return (new DateTimeImmutable($datos['fecha'], $zona))->modify('+1 day');
            }

            return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $datos['fecha'].' '.$hora, $zona);
        };
        $bloqueo = new Bloqueo();
        $bloqueo->profesional = $this->entidades->getReference(Profesional::class, $id);
        $bloqueo->inicio = $instante($datos['hora_inicio']);
        $bloqueo->fin = $instante($datos['hora_fin']);
        $bloqueo->gestionHorarios = true;
        $bloqueo->creadoPor = $autor;

        return $bloqueo;
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
        $resultado = $this->conexion->fetchOne(self::FRANJAS_SQL.', '.<<<'SQL'
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
            SQL, [$inicioLocal, $finLocal, $id, 'true']);

        return (int) $resultado === 1;
    }

    public function disponibilidad(string $id, string $fecha): array
    {
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha, new DateTimeZone(self::ZONA));
        if ($dia === false || $dia->format('Y-m-d') !== $fecha) {
            throw new InvalidArgumentException('Ingresá una fecha válida.');
        }

        $filas = $this->conexion->fetchAllAssociative(self::FRANJAS_SQL.', '.<<<'SQL'
            cobertura AS (
                SELECT COALESCE(range_agg(tstzrange(
                    (dia + hora_inicio) AT TIME ZONE 'America/Argentina/Mendoza',
                    (dia + hora_fin) AT TIME ZONE 'America/Argentina/Mendoza', '[)'
                )), '{}'::tstzmultirange) AS rangos
                FROM franjas
            ),
            personal AS (
                SELECT COALESCE(range_agg(tstzrange(o.inicio, o.fin, '[)')), '{}'::tstzmultirange) AS rangos
                FROM (
                    SELECT inicio, fin FROM ocupaciones_profesionales WHERE profesional_id = ?
                    UNION ALL
                    SELECT inicio, fin FROM bloqueos WHERE profesional_id = ?
                ) o CROSS JOIN periodo
                WHERE o.inicio < (periodo.fin AT TIME ZONE 'America/Argentina/Mendoza')
                  AND o.fin > (periodo.inicio AT TIME ZONE 'America/Argentina/Mendoza')
            ),
            espacios AS (
                SELECT r.id, r.nombre, COALESCE(range_agg(tstzrange(o.inicio, o.fin, '[)'))
                    FILTER (WHERE o.inicio IS NOT NULL), '{}'::tstzmultirange) AS rangos
                FROM recursos r CROSS JOIN periodo
                LEFT JOIN (
                    SELECT recurso_id, inicio, fin FROM ocupaciones_recursos
                    UNION ALL
                    SELECT recurso_id, inicio, fin FROM bloqueos WHERE recurso_id IS NOT NULL
                ) o ON o.recurso_id = r.id
                   AND o.inicio < (periodo.fin AT TIME ZONE 'America/Argentina/Mendoza')
                   AND o.fin > (periodo.inicio AT TIME ZONE 'America/Argentina/Mendoza')
                WHERE r.activo GROUP BY r.id, r.nombre
            )
            SELECT e.id::text AS espacio_id, e.nombre,
                   lower(hueco.rango) AS inicio, upper(hueco.rango) AS fin,
                   (c.rangos <> '{}'::tstzmultirange)::int AS hay_franjas
            FROM perfil CROSS JOIN cobertura c CROSS JOIN personal p
            LEFT JOIN espacios e ON true
            LEFT JOIN LATERAL unnest(c.rangos - p.rangos - e.rangos) AS hueco(rango) ON true
            ORDER BY e.nombre, e.id, inicio
            SQL, [$dia->format('Y-m-d H:i:s'), $dia->modify('+1 day')->format('Y-m-d H:i:s'), $id, 'false', $id, $id]);

        if ($filas === []) {
            throw new HttpException(404, 'La profesional no está disponible.');
        }

        $espacios = [];
        foreach ($filas as $fila) {
            if ($fila['espacio_id'] === null) {
                continue;
            }
            $espacio = $fila['espacio_id'];
            $espacios[$espacio] ??= ['id' => $espacio, 'nombre' => $fila['nombre'], 'huecos' => []];
            if ($fila['inicio'] !== null) {
                $espacios[$espacio]['huecos'][] = [
                    'inicio' => (new DateTimeImmutable($fila['inicio']))->format(DATE_ATOM),
                    'fin' => (new DateTimeImmutable($fila['fin']))->format(DATE_ATOM),
                ];
            }
        }

        return ['hay_franjas' => (bool) $filas[0]['hay_franjas'], 'espacios' => array_values($espacios)];
    }

    /** Representación de TIME que devuelve PostgreSQL: segundos completos y fracción sin ceros finales. */
    private static function textoHora(string|DateTimeImmutable $hora): string
    {
        $hora = is_string($hora) ? $hora : $hora->format('H:i:s.u');
        [$entera, $fraccion] = explode('.', $hora) + [1 => ''];
        $fraccion = rtrim($fraccion, '0');

        return $fraccion === '' ? $entera : "$entera.$fraccion";
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
