<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Agenda\TurnoNoDisponible;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use InvalidArgumentException;

final readonly class AgendaAdministrativa
{
    private const POLITICA = 'preparacion_agenda_admin';
    private const ZONA = 'America/Argentina/Mendoza';

    public function __construct(private Connection $conexion)
    {
    }

    public function consultar(string $fecha): array
    {
        $dia = self::fecha($fecha, 'Y-m-d');
        $intervalo = [$dia->modify('+1 day')->format(DATE_ATOM), $dia->format(DATE_ATOM)];
        $turnos = $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT t.id::text, t.servicio_nombre AS servicio, t.cliente_nombre AS cliente, t.inicio, t.fin,
                   r.nombre AS espacio, string_agg(p.nombre, ', ' ORDER BY p.nombre) AS profesionales
            FROM turnos_administrativos t
            JOIN ocupaciones_recursos ore ON ore.turno_admin_id = t.id
            JOIN recursos r ON r.id = ore.recurso_id
            JOIN ocupaciones_profesionales op ON op.turno_admin_id = t.id
            JOIN profesionales p ON p.id = op.profesional_id
            WHERE ore.inicio < ? AND ore.fin > ?
            GROUP BY t.id, r.nombre
            ORDER BY t.inicio, t.id
            SQL, $intervalo);

        return [
            'servicios' => $this->conexion->fetchAllAssociative('SELECT id::text, nombre FROM servicios_portada ORDER BY orden, nombre'),
            'profesionales' => $this->conexion->fetchAllAssociative('SELECT id::text, nombre FROM profesionales WHERE activa ORDER BY nombre'),
            'espacios' => $this->conexion->fetchAllAssociative('SELECT id::text, nombre FROM recursos WHERE activo ORDER BY nombre'),
            'preparacion' => $this->preparacion(),
            'turnos' => $turnos,
        ];
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

    public function cargar(array $datos, string $autor): string
    {
        $servicio = self::id($datos['servicio'] ?? null);
        $espacio = self::id($datos['espacio'] ?? null);
        $equipo = $datos['profesionales'] ?? null;
        if (!is_array($equipo) || !array_is_list($equipo) || $equipo === [] || count($equipo) > 100) {
            throw new InvalidArgumentException('Seleccioná al menos una profesional.');
        }
        $equipo = array_unique(array_map(self::id(...), $equipo));
        sort($equipo, SORT_STRING);
        $cliente = $datos['cliente'] ?? null;
        if (!is_string($cliente) || trim($cliente) === '' || preg_match('/\A[^\x00]{1,150}\z/u', trim($cliente)) !== 1) {
            throw new InvalidArgumentException('Ingresá un nombre de cliente válido.');
        }
        $inicio = self::fecha($datos['inicio'] ?? null, 'Y-m-d\TH:i');
        $duracion = self::minutos($datos['duracion'] ?? null, 1);

        try {
            return $this->conexion->transactional(function () use ($servicio, $espacio, $equipo, $cliente, $inicio, $duracion, $autor): string {
                if ($this->conexion->fetchOne('SELECT id FROM recursos WHERE id = ? AND activo FOR UPDATE', [$espacio]) === false) {
                    throw new InvalidArgumentException('Elegí un espacio activo.');
                }
                $nombre = $this->conexion->fetchOne('SELECT nombre FROM servicios_portada WHERE id = ?', [$servicio]);
                if ($nombre === false) {
                    throw new InvalidArgumentException('Elegí un servicio del catálogo.');
                }
                $politica = $this->preparacion();
                $minutos = ($politica['modo'] ?? null) === 'general' ? $politica['minutos'] : ($politica['servicios'][$servicio] ?? null);
                if ($minutos === null) {
                    throw new InvalidArgumentException('Configurá el tiempo de preparación de este servicio.');
                }

                $desde = $inicio->modify("-$minutos minutes");
                $fin = $inicio->modify("+$duracion minutes");
                $ocupacion = [$desde->format(DATE_ATOM), $fin->format(DATE_ATOM)];
                $this->verificarLibre('recursos', 'recurso_id', $espacio, $ocupacion);
                foreach ($equipo as $profesional) {
                    if ($this->conexion->fetchOne('SELECT id FROM profesionales WHERE id = ? AND activa', [$profesional]) === false) {
                        throw new InvalidArgumentException('Seleccioná profesionales activas.');
                    }
                    $this->verificarHoras($profesional, $desde, $fin);
                    $this->verificarLibre('profesionales', 'profesional_id', $profesional, $ocupacion);
                }

                $id = $this->conexion->fetchOne(<<<'SQL'
                    INSERT INTO turnos_administrativos (servicio_id, servicio_nombre, cliente_nombre, inicio, fin, creado_por)
                    VALUES (?, ?, ?, ?, ?, ?) RETURNING id::text
                    SQL, [$servicio, $nombre, trim($cliente), $inicio->format(DATE_ATOM), $ocupacion[1], $autor]);
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

    private function verificarLibre(string $tabla, string $columna, string $id, array $intervalo): void
    {
        $ocupado = $this->conexion->fetchOne(<<<SQL
            SELECT 1 FROM ocupaciones_$tabla WHERE $columna = ? AND fin > ? AND inicio < ?
            UNION ALL
            SELECT 1 FROM bloqueos WHERE $columna = ? AND fin > ? AND inicio < ?
            LIMIT 1
            SQL, [$id, ...$intervalo, $id, ...$intervalo]);
        if ($ocupado !== false) {
            throw new TurnoNoDisponible('El espacio o una de las profesionales ya tiene una ocupación en ese horario.');
        }
    }

    private function verificarHoras(string $id, DateTimeImmutable $inicio, DateTimeImmutable $fin): void
    {
        $fecha = $inicio->format('Y-m-d');
        $disponible = $this->conexion->fetchOne(<<<'SQL'
            SELECT CASE WHEN count(*) = 0 THEN 1 ELSE COALESCE((
                range_agg(tsrange(CAST(? AS date) + hora_inicio, CAST(? AS date) + hora_fin, '[)'))
                    FILTER (WHERE dia_semana = ? AND vigente_desde <= CAST(? AS date)
                        AND (vigente_hasta IS NULL OR vigente_hasta >= CAST(? AS date)))
                @> tsrange(CAST(? AS timestamp), CAST(? AS timestamp), '[)')
            )::int, 0) END
            FROM horarios_profesionales WHERE profesional_id = ?
            SQL, [$fecha, $fecha, (int) $inicio->format('N'), $fecha, $fecha, $inicio->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'), $id]);
        if ((int) $disponible !== 1) {
            throw new TurnoNoDisponible('El turno y su preparación deben entrar en el horario de todas las profesionales asignadas.');
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
