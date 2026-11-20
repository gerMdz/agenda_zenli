<?php

declare(strict_types=1);

namespace App\Agenda;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use DomainException;
use LogicException;

final readonly class AgendaPostgresql
{
    public function __construct(
        private Connection $conexion,
        private Disponibilidad $disponibilidad,
    ) {
    }

    /** @return list<TurnoDisponible> */
    public function consultar(
        int $servicioId,
        DateTimeImmutable $dia,
        int $pasoMinutos,
        int $preparacionMinutos,
        int $limpiezaMinutos,
    ): array {
        $espacioId = $this->obtenerEspacio();
        $servicio = $this->obtenerServicio($servicioId);
        if ($espacioId === false || $servicio === false) {
            return [];
        }

        return $this->calcular(
            $espacioId, $dia, (int) $servicio['duracion_minutos'],
            $pasoMinutos, $preparacionMinutos, $limpiezaMinutos,
        );
    }

    /**
     * Registra una reserva individual de confirmación automática.
     * El llamador debe autorizar la operación y aportar los parámetros de agenda vigentes.
     */
    public function confirmar(
        int $servicioId,
        int $clienteId,
        int $profesionalId,
        DateTimeImmutable $inicio,
        int $pasoMinutos,
        int $preparacionMinutos,
        int $limpiezaMinutos,
    ): int {
        if ($this->conexion->isTransactionActive()) {
            throw new LogicException('La confirmación debe iniciar su propia transacción.');
        }

        try {
            return $this->conexion->transactional(function (Connection $conexion) use (
                $servicioId, $clienteId, $profesionalId, $inicio,
                $pasoMinutos, $preparacionMinutos, $limpiezaMinutos,
            ): int {
                $conexion->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $espacioId = $this->obtenerEspacio(bloquear: true);
                $servicio = $this->obtenerServicio($servicioId);
                if ($espacioId === false || $servicio === false || $inicio <= new DateTimeImmutable()) {
                    throw new TurnoNoDisponible('El turno ya no está disponible.');
                }

                $this->validarCliente($clienteId);
                // La lectura posterior al bloqueo ve las confirmaciones que acaban de hacer commit.
                $turnos = $this->calcular(
                    $espacioId, $inicio, (int) $servicio['duracion_minutos'],
                    $pasoMinutos, $preparacionMinutos, $limpiezaMinutos,
                );

                foreach ($turnos as $turno) {
                    if ($turno->profesionalId === (string) $profesionalId && $turno->sesion->inicio == $inicio) {
                        return $this->guardar($espacioId, $servicioId, $clienteId, $servicio, $turno);
                    }
                }

                throw new TurnoNoDisponible('El turno ya no está disponible.');
            });
        } catch (DriverException $error) {
            if ($error->getSQLState() === '23P01') {
                throw new TurnoNoDisponible('El turno ya no está disponible.', previous: $error);
            }

            throw $error;
        }
    }

    private function obtenerEspacio(bool $bloquear = false): int|false
    {
        $sql = "SELECT id FROM recursos WHERE codigo = 'ESPACIO_1' AND activo";
        $id = $this->conexion->fetchOne($sql.($bloquear ? ' FOR UPDATE' : ''));

        return $id === false ? false : (int) $id;
    }

    /** @return array{tratamiento_id: int|string, duracion_minutos: int|string, precio_centavos: int|string|null}|false */
    private function obtenerServicio(int $servicioId): array|false
    {
        return $this->conexion->fetchAssociative(<<<'SQL'
            SELECT ss.tratamiento_id, s.duracion_minutos, s.precio_centavos
            FROM servicios s
            JOIN servicio_sesiones ss ON ss.servicio_id = s.id
            JOIN tratamientos t ON t.id = ss.tratamiento_id
            WHERE s.id = ? AND s.activo AND s.publico AND t.activo
              AND s.codigo <> 'PAREJA' AND s.participantes = 1
              AND s.modo_profesional IN ('MISMA', 'LIBRE')
              AND ss.orden = 1 AND ss.participante_orden = 1
              AND ss.duracion_minutos = s.duracion_minutos
              AND (SELECT count(*) FROM servicio_sesiones WHERE servicio_id = s.id) = 1
            SQL, [$servicioId]);
    }

    /** @return list<TurnoDisponible> */
    private function calcular(
        int $espacioId,
        DateTimeImmutable $dia,
        int $duracionMinutos,
        int $pasoMinutos,
        int $preparacionMinutos,
        int $limpiezaMinutos,
    ): array {
        $desde = $dia->setTimezone(new DateTimeZone('America/Argentina/Mendoza'))->setTime(0, 0);
        $fecha = $desde->format('Y-m-d');
        $filas = $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT h.profesional_id, h.dia_semana, h.hora_inicio, h.hora_fin, h.vigente_desde, h.vigente_hasta
            FROM horarios_profesionales h
            JOIN profesionales p ON p.id = h.profesional_id
            WHERE p.activa AND p.horario_por_franjas IS DISTINCT FROM false
              AND h.dia_semana = ? AND h.vigente_desde <= ?
              AND (h.vigente_hasta IS NULL OR h.vigente_hasta >= ?)
              AND NOT EXISTS (
                  SELECT 1 FROM horarios_profesionales_especiales e WHERE e.profesional_id = p.id AND e.fecha = ?
              )
            UNION ALL
            SELECT e.profesional_id, CAST(? AS smallint), e.hora_inicio, e.hora_fin, e.fecha, e.fecha
            FROM horarios_profesionales_especiales e JOIN profesionales p ON p.id = e.profesional_id
            WHERE p.activa AND e.fecha = ?
            SQL, [(int) $desde->format('N'), $fecha, $fecha, $fecha, (int) $desde->format('N'), $fecha]);
        $horarios = [];
        foreach ($filas as $fila) {
            $horarios[] = new HorarioSemanal(
                (string) $fila['profesional_id'], (int) $fila['dia_semana'],
                $fila['hora_inicio'], $fila['hora_fin'], $fila['vigente_desde'], $fila['vigente_hasta'],
            );
        }

        $ocupados = $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT inicio, fin, profesional_id
            FROM (
                SELECT inicio, fin, profesional_id FROM bloqueos
                WHERE recurso_id = ? OR profesional_id IS NOT NULL
                UNION ALL
                SELECT inicio, fin, NULL FROM ocupaciones_recursos WHERE recurso_id = ?
                UNION ALL
                SELECT inicio, fin, profesional_id FROM ocupaciones_profesionales
            ) intervalos
            WHERE inicio < ? AND fin > ?
            SQL, [$espacioId, $espacioId, self::instante($desde->modify('+1 day')), self::instante($desde)]);
        $delEspacio = [];
        $porProfesional = [];
        foreach ($ocupados as $fila) {
            $intervalo = new Intervalo(new DateTimeImmutable($fila['inicio']), new DateTimeImmutable($fila['fin']));
            if ($fila['profesional_id'] === null) {
                $delEspacio[] = $intervalo;
            } else {
                $porProfesional[$fila['profesional_id']][] = $intervalo;
            }
        }

        $turnos = $this->disponibilidad->forDay(
            $dia, $horarios, $delEspacio, $porProfesional,
            $duracionMinutos, $pasoMinutos, $preparacionMinutos, $limpiezaMinutos,
        );
        $ahora = new DateTimeImmutable();

        return array_values(array_filter($turnos, static fn (TurnoDisponible $turno): bool =>
            $turno->ocupacion->inicio >= $ahora
        ));
    }

    private function validarCliente(int $clienteId): void
    {
        $cliente = $this->conexion->fetchOne('SELECT id FROM clientes WHERE id = ? FOR UPDATE', [$clienteId]);
        $restriccion = $this->conexion->fetchOne(<<<'SQL'
            SELECT 1 FROM clientes_restricciones
            WHERE cliente_id = ? AND tipo IN ('BLOQUEO', 'REVISION')
              AND revocado_en IS NULL AND inicio <= statement_timestamp()
              AND (fin IS NULL OR fin > statement_timestamp())
            LIMIT 1
            SQL, [$clienteId]);

        if ($cliente === false || $restriccion !== false) {
            throw new DomainException('No se puede confirmar automáticamente esta reserva.');
        }
    }

    /**
     * @param array{tratamiento_id: int|string, duracion_minutos: int|string, precio_centavos: int|string|null} $servicio
     */
    private function guardar(
        int $espacioId,
        int $servicioId,
        int $clienteId,
        array $servicio,
        TurnoDisponible $turno,
    ): int {
        $inicio = self::instante($turno->sesion->inicio);
        $fin = self::instante($turno->sesion->fin);
        $precio = $servicio['precio_centavos'];
        $reservaId = (int) $this->conexion->fetchOne(<<<'SQL'
            INSERT INTO reservas
                (servicio_id, solicitante_id, estado, modo_confirmacion, inicio, fin, precio_base_centavos, precio_final_centavos)
            VALUES (?, ?, 'CONFIRMADA', 'AUTOMATICA', ?, ?, ?, ?)
            RETURNING id
            SQL, [$servicioId, $clienteId, $inicio, $fin, $precio, $precio]);
        $participanteId = $this->conexion->fetchOne(<<<'SQL'
            INSERT INTO reserva_participantes (reserva_id, cliente_id, orden) VALUES (?, ?, 1) RETURNING id
            SQL, [$reservaId, $clienteId]);
        $sesionId = $this->conexion->fetchOne(<<<'SQL'
            INSERT INTO reserva_sesiones
                (reserva_id, participante_id, tratamiento_id, profesional_id, orden, inicio, fin, precio_centavos)
            VALUES (?, ?, ?, ?, 1, ?, ?, ?)
            RETURNING id
            SQL, [$reservaId, $participanteId, $servicio['tratamiento_id'], $turno->profesionalId, $inicio, $fin, $precio]);

        $ocupacion = [
            'sesion_id' => $sesionId,
            'inicio' => self::instante($turno->ocupacion->inicio),
            'fin' => self::instante($turno->ocupacion->fin),
        ];
        $this->conexion->insert('ocupaciones_profesionales', $ocupacion + ['profesional_id' => $turno->profesionalId]);
        $this->conexion->insert('ocupaciones_recursos', $ocupacion + ['recurso_id' => $espacioId]);
        $this->conexion->insert('reservas_eventos', ['reserva_id' => $reservaId, 'tipo' => 'CONFIRMADA']);

        return $reservaId;
    }

    private static function instante(DateTimeImmutable $fecha): string
    {
        return $fecha->format('Y-m-d H:i:s.uP');
    }
}
