<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Agenda\TurnoNoDisponible;
use App\Entity\Espacio;
use App\Entity\EventoTurnoAdministrativo;
use App\Entity\OcupacionProfesional;
use App\Entity\OcupacionRecurso;
use App\Entity\PoliticaReserva;
use App\Entity\PreparacionAgenda;
use App\Entity\Profesional;
use App\Entity\ServicioPortada;
use App\Entity\TurnoAdministrativo;
use App\Entity\UsuarioInterno;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class AgendaAdministrativa
{
    private const POLITICA = 'preparacion_agenda_admin';
    private const ZONA = 'America/Argentina/Mendoza';
    private const INSTANTE = TransaccionEntidades::INSTANTE;
    private const AUDITORIA = 'Y-m-d H:i:s.uP';

    public function __construct(
        private EntityManagerInterface $entidades,
        private HorariosProfesionales $horarios,
    )
    {
    }

    public function consultar(string $fecha, ?string $propia = null, ?string $hasta = null, bool $incluirOcupaciones = false): array
    {
        $dia = self::fecha($fecha, 'Y-m-d');
        $ultimo = self::fecha($hasta ?? $fecha, 'Y-m-d');
        if ($ultimo < $dia || $ultimo > $dia->modify('+30 days')) {
            throw new InvalidArgumentException('El período debe tener entre 1 y 31 días.');
        }
        $periodo = ['hasta' => $ultimo->modify('+1 day'), 'desde' => $dia];
        $propio = $propia === null ? '' : <<<'DQL'
             AND EXISTS (SELECT propia.id FROM App\Entity\OcupacionProfesional propia WHERE propia.turno = t AND propia.profesional = :propia)
            DQL;

        $filas = $this->consulta(<<<DQL
            SELECT t.id AS id, t.servicioNombre AS servicio, t.clienteNombre AS cliente, t.clienteId AS cliente_id, t.inicio AS inicio, t.fin AS fin,
                   t.revision AS revision, t.asistencia AS asistencia, t.servicioId AS servicio_id, e.id AS espacio_id, e.nombre AS espacio,
                   t.profesionalesRequeridos AS profesionales_requeridos, t.senaPagada AS sena_pagada,
                   t.senaImporteCentavos AS sena_importe_centavos
            FROM App\\Entity\\OcupacionRecurso o JOIN o.turno t JOIN o.espacio e
            WHERE t.inicio < :hasta AND t.fin > :desde AND t.canceladoEn IS NULL $propio
            ORDER BY t.inicio, t.id
            DQL, $periodo, $propia);
        $equipos = $this->equipos(array_column($filas, 'id'));
        $turnos = [];
        foreach ($filas as $fila) {
            $equipo = $equipos[$fila['id']]['ids'] ?? [];
            $requeridos = $fila['profesionales_requeridos'];
            $turnos[] = [
                'id' => (string) $fila['id'], 'servicio' => $fila['servicio'], 'cliente' => $fila['cliente'], 'cliente_id' => self::texto($fila['cliente_id']),
                'inicio' => $fila['inicio']->format(DATE_ATOM), 'fin' => $fila['fin']->format(DATE_ATOM),
                'revision' => $fila['revision'], 'asistencia' => $fila['asistencia'], 'servicio_id' => self::texto($fila['servicio_id']),
                'espacio_id' => (string) $fila['espacio_id'], 'espacio' => $fila['espacio'], 'equipo' => $equipo,
                'duracion' => (int) round(($fila['fin']->getTimestamp() - $fila['inicio']->getTimestamp()) / 60),
                'profesionales' => implode(', ', $equipos[$fila['id']]['nombres'] ?? []),
                'profesionales_requeridos' => $requeridos, 'sena_pagada' => $fila['sena_pagada'],
                'sena_importe_centavos' => self::texto($fila['sena_importe_centavos']),
                'equipo_incompleto' => $requeridos === null || count($equipo) < $requeridos,
                'editable' => $propia === null || $equipo === [$propia],
            ];
        }

        $zona = new DateTimeZone(self::ZONA);
        $medianoche = static fn (DateTimeImmutable $instante): bool => $instante->setTimezone($zona)->format('H:i:s.u') === '00:00:00.000000';
        $bloqueos = array_map(static fn (array $b): array => [
            'id' => (string) $b['id'], 'inicio' => $b['inicio']->format(DATE_ATOM), 'fin' => $b['fin']->format(DATE_ATOM),
            'profesional_id' => (string) $b['profesional_id'], 'profesionales' => $b['profesionales'],
            'todo_el_dia' => $medianoche($b['inicio']) && $medianoche($b['fin']),
        ], $this->consulta(<<<'DQL'
            SELECT b.id AS id, b.inicio AS inicio, b.fin AS fin, p.id AS profesional_id, p.nombre AS profesionales
            FROM App\Entity\Bloqueo b JOIN b.profesional p
            WHERE b.gestionHorarios = true AND b.inicio < :hasta AND b.fin > :desde
            DQL.($propia === null ? '' : ' AND p.id = :propia').' ORDER BY b.inicio, b.id', $periodo, $propia));

        $filas = $this->consulta(<<<DQL
            SELECT pa.id AS id, t.id AS turno_id, e.id AS espacio_id, e.nombre AS espacio, o.inicio AS inicio, o.fin AS fin,
                   pa.revision AS revision
            FROM App\\Entity\\OcupacionRecurso o JOIN o.preparacion pa JOIN pa.turno t JOIN o.espacio e
            WHERE pa.eliminada = false AND t.canceladoEn IS NULL AND o.inicio < :hasta AND o.fin > :desde $propio
            ORDER BY o.inicio, pa.id
            DQL, $periodo, $propia);
        $equiposPreparacion = $propia === null ? [] : $this->equipos(array_column($filas, 'turno_id'));
        $preparaciones = array_map(static fn (array $p): array => [
            'id' => (string) $p['id'], 'turno_id' => (string) $p['turno_id'], 'espacio_id' => (string) $p['espacio_id'],
            'espacio' => $p['espacio'], 'inicio' => $p['inicio']->format(DATE_ATOM), 'fin' => $p['fin']->format(DATE_ATOM),
            'revision' => $p['revision'],
            'editable' => $propia === null || count($equiposPreparacion[$p['turno_id']]['ids'] ?? []) === 1,
        ], $filas);

        $servicios = $this->entidades->createQuery(<<<'DQL'
            SELECT s.id AS id, s.nombre AS nombre, s.duracionMinima AS duracion_minima, s.profesionalesRequeridos AS profesionales_requeridos
            FROM App\Entity\ServicioPortada s ORDER BY s.orden, s.nombre
            DQL)->getArrayResult();
        $profesionales = $this->consulta(
            'SELECT p.id AS id, p.nombre AS nombre, IDENTITY(p.usuario) AS usuario FROM App\Entity\Profesional p WHERE p.activa = true'
            .($propia === null ? '' : ' AND p.id = :propia').' ORDER BY p.nombre',
            [],
            $propia,
        );

        $agenda = [
            'servicios' => array_map(static fn (array $s): array => ['id' => (string) $s['id']] + $s, $servicios),
            'profesionales' => array_map(static fn (array $p): array => [
                'id' => (string) $p['id'], 'nombre' => $p['nombre'], 'tiene_cuenta' => $p['usuario'] === null ? 0 : 1,
            ], $profesionales),
            'espacios' => $this->espacios(),
            'preparacion' => $this->preparacion(),
            'preparaciones' => $preparaciones,
            'turnos' => $turnos,
            'bloqueos' => $bloqueos,
            'feriados' => $this->entidades->getConnection()->fetchFirstColumn(
                "SELECT to_char(fecha, 'YYYY-MM-DD') FROM feriados_agenda WHERE activo AND fecha BETWEEN ? AND ? ORDER BY fecha",
                [$dia->format('Y-m-d'), $ultimo->format('Y-m-d')],
            ),
        ];
        if ($incluirOcupaciones) {
            $agenda['ocupaciones_espacios'] = $this->ocupacionesEspacios($periodo['desde'], $periodo['hasta']);
        }

        return $agenda;
    }

    private function ocupacionesEspacios(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        // Ocupaciones anónimas para orientar la consulta sin conexión, incluso en la vista profesional.
        $filas = $this->entidades->getConnection()->fetchAllAssociative(<<<'SQL'
            WITH periodo AS (SELECT tstzrange(CAST(? AS timestamptz), CAST(? AS timestamptz), '[)') AS rango),
                 ocupaciones AS (
                    SELECT recurso_id, inicio, fin FROM ocupaciones_recursos
                    UNION ALL
                    SELECT recurso_id, inicio, fin FROM bloqueos WHERE recurso_id IS NOT NULL
                 ),
                 espacios AS (
                    SELECT r.id, range_agg(tstzrange(o.inicio, o.fin, '[)') * p.rango) AS rangos
                    FROM ocupaciones o JOIN recursos r ON r.id = o.recurso_id AND r.activo
                    CROSS JOIN periodo p
                    WHERE o.inicio < upper(p.rango) AND o.fin > lower(p.rango)
                    GROUP BY r.id
                 )
            SELECT e.id::text AS espacio_id, lower(tramo.rango) AS inicio, upper(tramo.rango) AS fin
            FROM espacios e CROSS JOIN LATERAL unnest(e.rangos) AS tramo(rango)
            ORDER BY e.id, inicio
            SQL, [$desde->format(DATE_ATOM), $hasta->format(DATE_ATOM)]);

        return array_map(static fn (array $fila): array => [
            'espacio_id' => $fila['espacio_id'],
            'inicio' => (new DateTimeImmutable($fila['inicio']))->format(DATE_ATOM),
            'fin' => (new DateTimeImmutable($fila['fin']))->format(DATE_ATOM),
        ], $filas);
    }

    /** @return array{turnos: list<array>, hay_mas: bool} */
    public function buscarTurnos(string $nombre, ?string $propia): array
    {
        $nombre = trim($nombre);
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 150 || str_contains($nombre, "\0")) {
            throw new InvalidArgumentException('Ingresá entre 2 y 150 caracteres del nombre del cliente.');
        }

        $alcance = $propia === null ? '' : <<<'SQL'
            AND EXISTS (SELECT 1 FROM ocupaciones_profesionales p
                        WHERE p.turno_admin_id = t.id AND p.profesional_id = :propia)
            SQL;
        $parametros = ['nombre' => $nombre];
        if ($propia !== null) {
            $parametros['propia'] = $propia;
        }
        $turnos = $this->entidades->getConnection()->fetchAllAssociative(<<<SQL
            SELECT t.id::text AS id, t.cliente_id::text AS cliente_id, t.cliente_nombre AS cliente, t.servicio_nombre AS servicio,
                   t.inicio, t.fin, t.asistencia
            FROM turnos_administrativos t
            WHERE t.cancelado_en IS NULL
              AND strpos(lower(translate(t.cliente_nombre, 'áéíóúüÁÉÍÓÚÜÑ', 'aeiouuAEIOUUñ')),
                         lower(translate(:nombre, 'áéíóúüÁÉÍÓÚÜÑ', 'aeiouuAEIOUUñ'))) > 0
              $alcance
            ORDER BY t.inicio DESC, t.id DESC
            LIMIT 51
            SQL, $parametros);

        return ['turnos' => array_map(static function (array $turno): array {
            $turno['inicio'] = (new DateTimeImmutable($turno['inicio']))->format(DATE_ATOM);
            $turno['fin'] = (new DateTimeImmutable($turno['fin']))->format(DATE_ATOM);

            return $turno;
        }, array_slice($turnos, 0, 50)), 'hay_mas' => count($turnos) > 50];
    }

    public function guardarFeriado(string $fecha, mixed $activo, string $autor): void
    {
        self::fecha($fecha, 'Y-m-d');
        if (!is_bool($activo)) {
            throw new InvalidArgumentException('Indicá si la fecha se marca como feriado.');
        }

        $this->entidades->getConnection()->executeStatement(<<<'SQL'
            INSERT INTO feriados_agenda (fecha, activo, creado_por, actualizado_por)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (fecha) DO UPDATE
            SET activo = EXCLUDED.activo, actualizado_por = EXCLUDED.actualizado_por, actualizado_en = now()
            WHERE feriados_agenda.activo IS DISTINCT FROM EXCLUDED.activo
            SQL, [$fecha, $activo, $autor, $autor], [ParameterType::STRING, ParameterType::BOOLEAN]);
    }

    public function configuracion(): array
    {
        $profesionales = $this->entidades->createQuery(<<<'DQL'
            SELECT p.id AS id, p.nombre AS nombre, IDENTITY(p.usuario) AS usuario, u.roles AS roles
            FROM App\Entity\Profesional p LEFT JOIN p.usuario u
            WHERE p.activa = true ORDER BY p.nombre
            DQL)->getArrayResult();

        return [
            'profesionales' => array_map(static fn (array $p): array => [
                'id' => (string) $p['id'], 'nombre' => $p['nombre'], 'tiene_cuenta' => $p['usuario'] === null ? 0 : 1,
                'admin' => $p['roles'] !== null && UsuarioInterno::rolesAdministrativos($p['roles']) ? 1 : 0,
            ], $profesionales),
            'espacios' => $this->espacios(true),
            'preparacion' => $this->preparacion(),
            'preparacion_auditoria' => $this->auditoriaPreparacion(),
        ];
    }

    private function espacios(bool $conAuditoria = false): array
    {
        $columnas = 'e.id AS id, e.nombre AS nombre, e.detalle AS detalle';
        if ($conAuditoria) {
            $columnas .= ', e.creadoPor AS creado_por, e.creadoEn AS creado_en, e.actualizadoPor AS actualizado_por, e.actualizadoEn AS actualizado_en';
        }
        $filas = $this->entidades->createQuery("SELECT $columnas FROM App\\Entity\\Espacio e WHERE e.activo = true ORDER BY e.nombre, e.id")
            ->getArrayResult();
        if (!$conAuditoria) {
            return array_map(static fn (array $e): array => ['id' => (string) $e['id']] + $e, $filas);
        }
        $autores = new NombresAutores($this->entidades->getConnection());
        $ids = [];
        foreach ($filas as $e) {
            $ids[] = $e['creado_por'];
            $ids[] = $e['actualizado_por'];
        }
        $mapa = $autores->resolver($ids);

        return array_map(static fn (array $e): array => [
            'id' => (string) $e['id'],
            'nombre' => $e['nombre'],
            'detalle' => $e['detalle'],
            'auditoria' => $autores->auditoria($e['creado_por'], $e['creado_en'], $e['actualizado_por'], $e['actualizado_en'], $mapa),
        ], $filas);
    }

    private function auditoriaPreparacion(): ?array
    {
        $fila = $this->entidades->createQuery('SELECT p.actualizadoPor AS actualizado_por, p.actualizadoEn AS actualizado_en FROM App\Entity\PoliticaReserva p WHERE p.clave = :clave')
            ->setParameter('clave', self::POLITICA)->getOneOrNullResult();
        if ($fila === null) {
            return null;
        }
        $autores = new NombresAutores($this->entidades->getConnection());
        $mapa = $autores->resolver([$fila['actualizado_por']]);

        return $autores->ultimaModificacion($fila['actualizado_por'], $fila['actualizado_en'], $mapa);
    }

    public function guardarEspacio(array $datos, ?string $id, ?string $autor = null): void
    {
        $nombre = $datos['nombre'] ?? null;
        $detalle = $datos['detalle'] ?? '';
        if (!is_string($nombre) || preg_match('/\A[^\x00]{1,120}\z/u', trim($nombre)) !== 1
            || !is_string($detalle) || preg_match('/\A[^\x00]{0,1000}\z/u', trim($detalle)) !== 1) {
            throw new InvalidArgumentException('Ingresá un nombre y un detalle de hasta 1000 caracteres.');
        }
        $ahora = new DateTimeImmutable();
        if ($id === null) {
            $espacio = new Espacio();
            $espacio->codigo = 'ESP_'.bin2hex(random_bytes(12));
            $espacio->creadoPor = $autor;
            $espacio->creadoEn = $ahora;
            $this->entidades->persist($espacio);
        } else {
            $espacio = $this->entidades->createQuery('SELECT e FROM App\Entity\Espacio e WHERE e.id = :id')
                ->setParameter('id', self::id($id))->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult()
                ?? throw new HttpException(404, 'El espacio ya no está disponible.');
        }
        $espacio->nombre = trim($nombre);
        $espacio->detalle = trim($detalle);
        $espacio->actualizadoPor = $autor;
        $espacio->actualizadoEn = $ahora;
        $this->entidades->flush();
    }

    public function guardarPreparacion(array $datos, ?string $autor = null): void
    {
        TransaccionEntidades::ejecutar($this->entidades, function () use ($datos, $autor): void {
            // Serializa los cambios de política y los ajustes individuales de cada servicio.
            $this->entidades->getConnection()->executeStatement('LOCK TABLE politicas_reservas IN SHARE ROW EXCLUSIVE MODE');
            $registro = $this->entidades->createQuery('SELECT p FROM App\Entity\PoliticaReserva p WHERE p.clave = :clave')
                ->setParameter('clave', self::POLITICA)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
            $actual = $registro?->valor ?? [];
            $servicios = (array) ($actual['servicios'] ?? []);
            if (array_key_exists('servicio', $datos)) {
                $servicio = self::id($datos['servicio']);
                if (($actual['modo'] ?? null) !== 'por_servicio') {
                    throw new InvalidArgumentException('Activá la preparación individual desde Preparación.');
                }
                if (!array_key_exists('minutos', $datos) || $this->entidades->find(ServicioPortada::class, $servicio) === null) {
                    throw new InvalidArgumentException('Elegí un servicio del catálogo e indicá su preparación.');
                }
                if ($datos['minutos'] === null) {
                    unset($servicios[$servicio]);
                } else {
                    $servicios[$servicio] = self::minutos($datos['minutos'], 0);
                }
                $politica = $actual;
            } else {
                $modo = $datos['modo'] ?? null;
                $politica = match ($modo) {
                    'predeterminado' => ['modo' => $modo],
                    'general' => ['modo' => $modo, 'minutos' => self::minutos($datos['minutos'] ?? null, 0)],
                    'por_servicio' => ['modo' => $modo, 'minutos' => self::minutos($datos['minutos'] ?? 0, 0)],
                    default => throw new InvalidArgumentException('Elegí cómo se define la preparación.'),
                };
                if ($modo === 'por_servicio' && array_key_exists('servicios', $datos)) {
                    if (!is_array($datos['servicios'])) {
                        throw new InvalidArgumentException('Revisá los tiempos de preparación por servicio.');
                    }
                    $servicios = [];
                    foreach ($this->entidades->createQuery('SELECT s.id FROM App\Entity\ServicioPortada s')->getSingleColumnResult() as $id) {
                        if (isset($datos['servicios'][$id])) {
                            $servicios[$id] = self::minutos($datos['servicios'][$id], 0);
                        }
                    }
                }
            }
            if ($registro === null) {
                $registro = new PoliticaReserva();
                $registro->clave = self::POLITICA;
                $this->entidades->persist($registro);
            }
            $registro->valor = $politica;
            $registro->valor['servicios'] = (object) $servicios;
            $registro->actualizadoPor = $autor;
            $registro->actualizadoEn = new DateTimeImmutable();
        });
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
        $clienteId = isset($datos['cliente_id']) ? self::id($datos['cliente_id']) : null;
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

        $operacion = $datos['operacion'] ?? null;
        if ($operacion !== null && ($editar !== null || !is_string($operacion)
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $operacion) !== 1)) {
            throw new InvalidArgumentException('El identificador del alta no es válido.');
        }
        $huella = $operacion === null ? null : hash('sha256', serialize([
            $servicio, $espacio, $equipo, trim($cliente), $inicio->format(DATE_ATOM), $duracion, $pagada, $importe,
            ...($clienteId === null ? [] : [$clienteId]),
        ]));

        return $this->conConcurrencia(fn (): string => TransaccionEntidades::ejecutar($this->entidades, function () use ($servicio, $espacio, $equipo, $cliente, $clienteId, $inicio, $duracion, $autor, $propia, $editar, $revision, $datos, $pagada, $importe, $operacion, $huella): string {
            $conexion = $this->entidades->getConnection();
            if ($operacion !== null) {
                $conexion->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["alta-turno:$autor:$operacion"]);
                $alta = $conexion->fetchAssociative(
                    'SELECT huella, turno_id FROM altas_turnos_sincronizadas WHERE usuario_id = ? AND operacion = ?',
                    [$autor, $operacion],
                );
                if ($alta !== false) {
                    if ($alta['huella'] !== $huella) {
                        throw new TurnoNoDisponible('Este identificador ya se usó para otro turno.');
                    }

                    return (string) $alta['turno_id'];
                }
                if ($inicio < new DateTimeImmutable()) {
                    throw new TurnoNoDisponible('El horario del turno pendiente ya pasó.');
                }
            }
            $anterior = $editar === null ? null : $this->bloquearEditable($editar, $propia, $revision);
            $idAnterior = self::texto($anterior['turno']->clienteId ?? null);
            if (!array_key_exists('cliente_id', $datos)) {
                $clienteId = $idAnterior;
            }
            if ($clienteId !== null) {
                $cliente = $clienteId === $idAnterior
                    ? $anterior['turno']->clienteNombre
                    : (new ClientesAgenda($conexion))->nombreParaVincular($clienteId, $autor, $propia);
            }
            if ($duracion < 20 && ($anterior === null || $anterior['turno']->fin->getTimestamp() - $anterior['turno']->inicio->getTimestamp() !== $duracion * 60)) {
                throw new InvalidArgumentException('La duración mínima del turno es de 20 minutos.');
            }
            $recurso = $this->bloquearEspacio($espacio, true) ?? throw new InvalidArgumentException('Elegí un espacio activo.');
            $configuracion = $this->entidades->createQuery('SELECT s FROM App\Entity\ServicioPortada s WHERE s.id = :id')
                ->setParameter('id', $servicio)->setLockMode(LockMode::PESSIMISTIC_READ)
                ->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
            if (!$configuracion instanceof ServicioPortada) {
                throw new InvalidArgumentException('Elegí un servicio del catálogo.');
            }
            $politica = $this->preparacion();
            $minutos = ($politica['modo'] ?? null) === 'general' ? $politica['minutos'] : ($politica['servicios'][$servicio] ?? $politica['minutos'] ?? 0);

            $fin = $inicio->modify("+$duracion minutes");
            $inicioEspacio = $inicio;
            $this->verificarLibre(true, $espacio, $inicio, $fin, $editar);
            if ($editar === null && $minutos > 0) {
                $this->verificarLibre(true, $espacio, $fin, $fin->modify("+$minutos minutes"), null);
            }
            if ($anterior !== null) {
                $preparacion = $anterior['ocupacion_preparacion'];
                if ($preparacion !== null && ((string) $preparacion->espacio->id !== $espacio || $preparacion->inicio < $fin)) {
                    throw new TurnoNoDisponible('La preparación existente no es compatible con el turno. Modificala primero.');
                }
                $ocupacionAnterior = $anterior['espacios'][0] ?? null;
                if ($ocupacionAnterior !== null && $ocupacionAnterior->inicio < $anterior['turno']->inicio) {
                    if ((string) $ocupacionAnterior->espacio->id !== $espacio || $inicio != $anterior['turno']->inicio) {
                        throw new TurnoNoDisponible('El turno tiene preparación histórica; no se puede desplazar automáticamente.');
                    }
                    $inicioEspacio = $ocupacionAnterior->inicio;
                }
            }
            $iniciosProfesionales = [];
            foreach ($equipo as $profesional) {
                if ((int) $this->entidades->createQuery('SELECT COUNT(p.id) FROM App\Entity\Profesional p WHERE p.id = :id AND p.activa = true')
                    ->setParameter('id', $profesional)->getSingleScalarResult() === 0) {
                    throw new InvalidArgumentException('Seleccioná profesionales activas.');
                }
                if (!$this->horarios->permite($profesional, $inicio, $fin)) {
                    throw new TurnoNoDisponible('Una profesional asignada no está disponible durante el turno; revisá sus horarios y bloqueos.');
                }
                $inicioProfesional = $inicio;
                foreach ($anterior['ocupaciones_profesionales'] ?? [] as $ocupacionProfesional) {
                    if ($ocupacionProfesional->profesional->id() === $profesional && $ocupacionProfesional->inicio < $anterior['turno']->inicio) {
                        if ($inicio != $anterior['turno']->inicio) {
                            throw new TurnoNoDisponible('El turno tiene ocupaciones históricas; no se pueden desplazar automáticamente.');
                        }
                        $inicioProfesional = $ocupacionProfesional->inicio;
                    }
                }
                $iniciosProfesionales[$profesional] = $inicioProfesional;
                $this->verificarLibre(false, $profesional, $inicioProfesional, $fin, $editar);
            }

            if ($anterior === null) {
                $turno = new TurnoAdministrativo();
                $turno->creadoPor = $autor;
                $this->entidades->persist($turno);
            } else {
                $turno = $anterior['turno'];
                $this->registrarEvento($turno, $autor, 'EDITADO', self::instantanea($anterior));
                $this->liberar($anterior);
                ++$turno->revision;
            }
            $turno->servicioId = (int) $servicio;
            $turno->servicioNombre = $configuracion->nombre;
            $turno->clienteId = $clienteId;
            $turno->clienteNombre = trim($cliente);
            $turno->inicio = $inicio;
            $turno->fin = $fin;
            $turno->profesionalesRequeridos = $configuracion->profesionalesRequeridos;
            $turno->senaPagada = array_key_exists('sena_pagada', $datos) ? $pagada : ($anterior !== null && $turno->senaPagada);
            if (array_key_exists('sena_importe_centavos', $datos)) {
                $turno->senaImporteCentavos = $importe;
            }

            $this->ocuparEspacio($recurso, $inicioEspacio, $fin, turno: $turno);
            foreach ($equipo as $profesional) {
                $ocupacion = new OcupacionProfesional();
                $ocupacion->turno = $turno;
                $ocupacion->profesional = $this->entidades->getReference(Profesional::class, $profesional);
                $ocupacion->inicio = $iniciosProfesionales[$profesional];
                $ocupacion->fin = $fin;
                $this->entidades->persist($ocupacion);
            }
            if ($anterior === null) {
                $preparacion = new PreparacionAgenda();
                $preparacion->turno = $turno;
                $preparacion->eliminada = $minutos === 0;
                $this->entidades->persist($preparacion);
                if ($minutos > 0) {
                    $this->ocuparEspacio($recurso, $fin, $fin->modify("+$minutos minutes"), preparacion: $preparacion);
                }
            }
            $this->entidades->flush();

            if ($operacion !== null) {
                $conexion->insert('altas_turnos_sincronizadas', [
                    'usuario_id' => $autor, 'operacion' => $operacion, 'huella' => $huella, 'turno_id' => $turno->id,
                ]);
            }

            return (string) $turno->id;
        }));
    }

    public function registrarAsistencia(string $id, mixed $asistencia, string $autor, ?string $propia, mixed $revision): void
    {
        if (!in_array($asistencia, ['programado', 'atendido', 'ausente'], true)) {
            throw new InvalidArgumentException('Elegí un estado de asistencia válido.');
        }

        TransaccionEntidades::ejecutar($this->entidades, function () use ($id, $asistencia, $autor, $propia, $revision): void {
            $anterior = $this->bloquearEditable($id, $propia, $revision);
            $turno = $anterior['turno'];
            if ($turno->asistencia === $asistencia) {
                return;
            }
            if ($asistencia !== 'programado' && $turno->inicio > new DateTimeImmutable()) {
                throw new TurnoNoDisponible('Podés registrar la asistencia desde la hora de inicio del turno.');
            }
            $this->registrarEvento($turno, $autor, 'ASISTENCIA', self::instantanea($anterior));
            $turno->asistencia = $asistencia;
            ++$turno->revision;
        });
    }

    public function cancelar(string $id, string $autor, ?string $propia, mixed $revision): void
    {
        TransaccionEntidades::ejecutar($this->entidades, function () use ($id, $autor, $propia, $revision): void {
            $anterior = $this->bloquearEditable($id, $propia, $revision);
            $turno = $anterior['turno'];
            $this->registrarEvento($turno, $autor, 'CANCELADO', self::instantanea($anterior));
            if ($anterior['ocupacion_preparacion'] !== null) {
                $this->entidades->remove($anterior['ocupacion_preparacion']);
            }
            $preparacion = $anterior['preparacion'];
            if ($preparacion !== null && !$preparacion->eliminada) {
                $preparacion->eliminada = true;
                ++$preparacion->revision;
            }
            $this->liberar($anterior);
            $turno->canceladoEn = new DateTimeImmutable();
            ++$turno->revision;
        });
    }

    public function modificarPreparacion(string $id, array $datos, string $autor, ?string $propia, bool $eliminar): void
    {
        self::id($id);
        $inicio = $eliminar ? null : self::fecha($datos['inicio'] ?? null, 'Y-m-d\TH:i');
        $fin = $eliminar ? null : self::fecha($datos['fin'] ?? null, 'Y-m-d\TH:i');
        if (!$eliminar && $fin <= $inicio) {
            throw new InvalidArgumentException('Ingresá un intervalo de preparación válido.');
        }
        $this->conConcurrencia(fn () => TransaccionEntidades::ejecutar($this->entidades, function () use ($id, $datos, $autor, $propia, $eliminar, $inicio, $fin): void {
            $noDisponible = new HttpException(404, 'La preparación ya no está disponible.');
            $turnoId = $this->entidades->createQuery('SELECT IDENTITY(p.turno) FROM App\Entity\PreparacionAgenda p WHERE p.id = :id')
                ->setParameter('id', $id)->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR) ?? throw $noDisponible;
            $turno = $this->bloquearTurno((string) $turnoId) ?? throw $noDisponible;
            $equipo = self::idsEquipo($this->ocupacionesProfesionales($turno));
            if ($propia !== null && !in_array($propia, $equipo, true)) {
                throw $noDisponible;
            }
            if ($propia !== null && $equipo !== [$propia]) {
                throw new HttpException(403, 'Este turno solo puede modificarlo administración.');
            }
            $ocupacion = $this->entidades->createQuery(<<<'DQL'
                SELECT o, p FROM App\Entity\OcupacionRecurso o JOIN o.preparacion p
                WHERE p.id = :id AND p.eliminada = false
                DQL)->setParameter('id', $id)->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult() ?? throw $noDisponible;
            $preparacion = $ocupacion->preparacion;
            if (($datos['revision'] ?? null) !== $preparacion->revision) {
                throw new TurnoNoDisponible('La preparación cambió. Volvé a cargar la agenda.');
            }
            $recurso = $this->bloquearEspacio((string) $ocupacion->espacio->id, false);
            if (!$eliminar && $inicio < $turno->fin) {
                throw new InvalidArgumentException('La preparación debe comenzar después del turno.');
            }
            $this->registrarEvento($turno, $autor, $eliminar ? 'PREP_ELIMINADA' : 'PREP_EDITADA', [
                'id' => $preparacion->id, 'revision' => $preparacion->revision, 'recurso_id' => $recurso->id,
                'inicio' => $ocupacion->inicio->format(self::AUDITORIA), 'fin' => $ocupacion->fin->format(self::AUDITORIA),
            ]);
            $this->entidades->remove($ocupacion);
            $this->entidades->flush();
            if (!$eliminar) {
                $this->verificarLibre(true, (string) $recurso->id, $inicio, $fin, null);
                $this->ocuparEspacio($recurso, $inicio, $fin, preparacion: $preparacion);
            }
            ++$preparacion->revision;
            $preparacion->eliminada = $eliminar;
        }));
    }

    /**
     * Bloquea el turno y carga su estado actual. Las ocupaciones, la preparación y la auditoría
     * se leen después del bloqueo para que coincidan con lo que se va a modificar.
     *
     * @return array{turno: TurnoAdministrativo, profesionales: list<string>, espacios: list<OcupacionRecurso>, ocupaciones_profesionales: list<OcupacionProfesional>, preparacion: ?PreparacionAgenda, ocupacion_preparacion: ?OcupacionRecurso}
     */
    private function bloquearEditable(string $id, ?string $propia, mixed $revision): array
    {
        self::id($id);
        $turno = $this->bloquearTurno($id) ?? throw new HttpException(404, 'El turno ya no está disponible.');
        $ocupacionesProfesionales = $this->ocupacionesProfesionales($turno);
        $equipo = self::idsEquipo($ocupacionesProfesionales);
        if ($propia !== null && !in_array($propia, $equipo, true)) {
            throw new HttpException(404, 'El turno ya no está disponible.');
        }
        if ($propia !== null && $equipo !== [$propia]) {
            throw new HttpException(403, 'Este turno solo puede modificarlo administración.');
        }
        if ($revision !== $turno->revision) {
            throw new TurnoNoDisponible('El turno cambió. Volvé a cargar la agenda antes de modificarlo.');
        }
        $preparacion = $this->entidades->createQuery('SELECT p FROM App\Entity\PreparacionAgenda p WHERE p.turno = :turno')
            ->setParameter('turno', $turno)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();

        return [
            'turno' => $turno,
            'profesionales' => $equipo,
            'espacios' => $this->entidades->createQuery('SELECT o FROM App\Entity\OcupacionRecurso o WHERE o.turno = :turno ORDER BY o.id')
                ->setParameter('turno', $turno)->setHint(Query::HINT_REFRESH, true)->getResult(),
            'ocupaciones_profesionales' => $ocupacionesProfesionales,
            'preparacion' => $preparacion,
            'ocupacion_preparacion' => $preparacion === null ? null : $this->entidades->createQuery('SELECT o FROM App\Entity\OcupacionRecurso o WHERE o.preparacion = :preparacion')
                ->setParameter('preparacion', $preparacion)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult(),
        ];
    }

    private function bloquearTurno(string $id): ?TurnoAdministrativo
    {
        return $this->entidades->createQuery('SELECT t FROM App\Entity\TurnoAdministrativo t WHERE t.id = :id AND t.canceladoEn IS NULL')
            ->setParameter('id', $id)->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    /** Bloquea la fila del espacio: es el bloqueo que comparten turnos internos y reservas públicas. */
    private function bloquearEspacio(string $id, bool $soloActivo): ?Espacio
    {
        return $this->entidades->createQuery('SELECT e FROM App\Entity\Espacio e WHERE e.id = :id'.($soloActivo ? ' AND e.activo = true' : ''))
            ->setParameter('id', $id)->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    /** @return list<OcupacionProfesional> */
    private function ocupacionesProfesionales(TurnoAdministrativo $turno): array
    {
        return $this->entidades->createQuery('SELECT o FROM App\Entity\OcupacionProfesional o WHERE o.turno = :turno ORDER BY o.id')
            ->setParameter('turno', $turno)->setHint(Query::HINT_REFRESH, true)->getResult();
    }

    private function ocuparEspacio(Espacio $espacio, DateTimeImmutable $inicio, DateTimeImmutable $fin, ?TurnoAdministrativo $turno = null, ?PreparacionAgenda $preparacion = null): void
    {
        $ocupacion = new OcupacionRecurso();
        $ocupacion->turno = $turno;
        $ocupacion->preparacion = $preparacion;
        $ocupacion->espacio = $espacio;
        $ocupacion->inicio = $inicio;
        $ocupacion->fin = $fin;
        $this->entidades->persist($ocupacion);
    }

    private function registrarEvento(TurnoAdministrativo $turno, string $autor, string $tipo, array $anterior): void
    {
        $evento = new EventoTurnoAdministrativo();
        $evento->turno = $turno;
        $evento->autorId = $autor;
        $evento->tipo = $tipo;
        $evento->estadoAnterior = $anterior;
        $this->entidades->persist($evento);
    }

    /** Elimina las ocupaciones propias del turno y confirma la eliminación antes de crear las nuevas. */
    private function liberar(array $anterior): void
    {
        foreach ([...$anterior['espacios'], ...$anterior['ocupaciones_profesionales']] as $ocupacion) {
            $this->entidades->remove($ocupacion);
        }
        $this->entidades->flush();
    }

    /** Estado anterior del turno para la auditoría, con las mismas claves que la fila de la tabla. */
    private static function instantanea(array $anterior): array
    {
        $turno = $anterior['turno'];
        $fecha = static fn (?DateTimeInterface $valor): ?string => $valor?->format(self::AUDITORIA);
        $ocupacion = static fn (OcupacionRecurso $o): array => [
            'recurso_id' => $o->espacio->id, 'inicio' => $fecha($o->inicio), 'fin' => $fecha($o->fin),
        ];
        $preparacion = $anterior['preparacion'];
        $ocupacionPreparacion = $anterior['ocupacion_preparacion'];

        return [
            'id' => $turno->id, 'servicio_id' => $turno->servicioId, 'servicio_nombre' => $turno->servicioNombre,
            'cliente_nombre' => $turno->clienteNombre, 'cliente_id' => $turno->clienteId, 'inicio' => $fecha($turno->inicio), 'fin' => $fecha($turno->fin),
            'creado_por' => $turno->creadoPor, 'creado_en' => $fecha($turno->creadoEn), 'cancelado_en' => $fecha($turno->canceladoEn),
            'revision' => $turno->revision, 'asistencia' => $turno->asistencia, 'profesionales_requeridos' => $turno->profesionalesRequeridos,
            'sena_pagada' => $turno->senaPagada, 'sena_importe_centavos' => $turno->senaImporteCentavos,
            'profesionales' => $anterior['profesionales'],
            'espacios' => array_map($ocupacion, $anterior['espacios']),
            'ocupaciones_profesionales' => array_map(static fn (OcupacionProfesional $o): array => [
                'profesional_id' => (int) $o->profesional->id(), 'inicio' => $fecha($o->inicio), 'fin' => $fecha($o->fin),
            ], $anterior['ocupaciones_profesionales']),
            'preparacion' => $preparacion === null ? null : [
                'id' => $preparacion->id, 'revision' => $preparacion->revision, 'eliminada' => $preparacion->eliminada,
            ] + ($ocupacionPreparacion === null
                ? ['recurso_id' => null, 'inicio' => null, 'fin' => null]
                : $ocupacion($ocupacionPreparacion)),
        ];
    }

    private function verificarLibre(bool $espacio, string $id, DateTimeImmutable $desde, DateTimeImmutable $hasta, ?string $editar): void
    {
        [$ocupaciones, $campo] = $espacio ? [OcupacionRecurso::class, 'espacio'] : [OcupacionProfesional::class, 'profesional'];
        // Las ocupaciones sin turno interno (reservas públicas y preparaciones) siempre cuentan.
        $ocupado = $this->entidades->createQuery("SELECT o.id FROM $ocupaciones o WHERE o.$campo = :id AND o.fin > :desde AND o.inicio < :hasta AND (o.turno IS NULL OR o.turno <> :editar)")
            ->setParameter('editar', $editar ?? '0');
        $bloqueado = $this->entidades->createQuery("SELECT b.id FROM App\\Entity\\Bloqueo b WHERE b.$campo = :id AND b.fin > :desde AND b.inicio < :hasta");
        foreach ([$ocupado, $bloqueado] as $consulta) {
            $consulta->setParameter('id', $id)->setParameter('desde', $desde, self::INSTANTE)
                ->setParameter('hasta', $hasta, self::INSTANTE)->setMaxResults(1);
            if ($consulta->getOneOrNullResult() !== null) {
                throw new TurnoNoDisponible($espacio
                    ? 'El espacio tiene una ocupación o bloqueo en ese horario.'
                    : 'Una profesional asignada tiene una ocupación o bloqueo en ese horario.');
            }
        }
    }

    private function preparacion(): ?array
    {
        // La hidratación escalar simple no aplica el tipo json; la de arrays sí.
        $politica = $this->entidades->createQuery('SELECT p.valor AS valor FROM App\Entity\PoliticaReserva p WHERE p.clave = :clave')
            ->setParameter('clave', self::POLITICA)->getOneOrNullResult()['valor'] ?? null;
        if ($politica !== null && ($politica['modo'] ?? null) !== 'por_servicio') {
            unset($politica['servicios']);
        }

        return $politica;
    }

    /**
     * Profesionales de cada turno: ids en orden numérico y nombres en el orden de la base.
     *
     * @return array<string, array{ids: list<string>, nombres: list<string>}>
     */
    private function equipos(array $turnos): array
    {
        if ($turnos === []) {
            return [];
        }
        $equipos = [];
        foreach ($this->entidades->createQuery(<<<'DQL'
            SELECT IDENTITY(o.turno) AS turno, p.id AS id, p.nombre AS nombre
            FROM App\Entity\OcupacionProfesional o JOIN o.profesional p
            WHERE o.turno IN (:turnos) ORDER BY p.nombre, p.id
            DQL)->setParameter('turnos', array_values(array_unique($turnos)))->getArrayResult() as $fila) {
            $equipos[$fila['turno']]['ids'][] = (string) $fila['id'];
            $equipos[$fila['turno']]['nombres'][] = $fila['nombre'];
        }
        foreach ($equipos as &$equipo) {
            usort($equipo['ids'], self::compararIds(...));
        }

        return $equipos;
    }

    /** @param list<OcupacionProfesional> $ocupaciones */
    private static function idsEquipo(array $ocupaciones): array
    {
        $ids = array_map(static fn (OcupacionProfesional $o): string => $o->profesional->id(), $ocupaciones);
        usort($ids, self::compararIds(...));

        return $ids;
    }

    private static function compararIds(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    /** Ejecuta una consulta del período, con el filtro opcional de la profesional propia. */
    private function consulta(string $dql, array $periodo, ?string $propia): array
    {
        $consulta = $this->entidades->createQuery($dql);
        foreach ($periodo as $nombre => $instante) {
            $consulta->setParameter($nombre, $instante, self::INSTANTE);
        }
        if ($propia !== null) {
            $consulta->setParameter('propia', $propia);
        }

        return $consulta->getArrayResult();
    }

    /** Traduce los rechazos concurrentes de PostgreSQL (exclusión GiST o interbloqueo). */
    private function conConcurrencia(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (DriverException $error) {
            if (in_array($error->getSQLState(), ['23P01', '40P01'], true)) {
                throw new TurnoNoDisponible('Hay una ocupación concurrente. Revisá el horario e intentá nuevamente.', previous: $error);
            }
            throw $error;
        }
    }

    private static function texto(int|string|null $valor): ?string
    {
        return $valor === null ? null : (string) $valor;
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
