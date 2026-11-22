<?php

declare(strict_types=1);

namespace App\Administracion;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Consulta del historial de turnos internos para administración.
 *
 * Reconstruye cambios legibles (seña, horario, servicio, cliente, espacio, profesionales,
 * preparación y cancelaciones) a partir de `turnos_administrativos` y `eventos_turnos_administrativos`.
 */
final readonly class HistorialTurnos
{
    private const ZONA = 'America/Argentina/Mendoza';
    private const TIPOS_FILTRO = ['todos', 'sena', 'cancelado', 'editado', 'creado', 'preparacion'];

    private NombresAutores $autores;

    public function __construct(private Connection $conexion)
    {
        $this->autores = new NombresAutores($conexion);
    }

    public function historialTurno(string $id): array
    {
        if (preg_match('/\A[1-9][0-9]{0,17}\z/', $id) !== 1) {
            throw new InvalidArgumentException('Identificador de turno inválido.');
        }

        $turno = $this->conexion->fetchAssociative(
            <<<'SQL'
                SELECT id::text AS id, servicio_id, servicio_nombre, cliente_nombre, inicio, fin,
                       creado_por::text AS creado_por, creado_en, cancelado_en, revision,
                       profesionales_requeridos, sena_pagada, sena_importe_centavos::text AS sena_importe_centavos
                FROM turnos_administrativos
                WHERE id = ?
                SQL,
            [$id],
        );
        if ($turno === false) {
            throw new HttpException(404, 'El turno no existe.');
        }

        $eventos = $this->eventosPorTurnos([$id])[$id] ?? [];
        $actuales = $this->estadosActuales([$turno]);
        [$mapaAutores, $mapaProfesionales, $mapaEspacios] = $this->catalogos([$turno], [$id => $eventos], $actuales);

        $linea = $this->construirLinea($turno, $eventos, $actuales[$id], $mapaAutores, $mapaProfesionales, $mapaEspacios);

        return [
            'turno' => $this->resumenTurno($turno, $eventos, $actuales[$id], $mapaAutores, $mapaProfesionales, $mapaEspacios),
            'eventos' => array_reverse($linea),
        ];
    }

    /**
     * @param array{desde?: ?string, hasta?: ?string, por?: ?string, tipo?: ?string, cliente?: ?string, limite?: ?int} $filtros
     */
    public function listar(array $filtros = []): array
    {
        $por = $filtros['por'] ?? 'evento';
        if (!in_array($por, ['evento', 'turno'], true)) {
            throw new InvalidArgumentException('Elegí un criterio de fecha válido.');
        }
        $tipo = $filtros['tipo'] ?? 'todos';
        if (!in_array($tipo, self::TIPOS_FILTRO, true)) {
            throw new InvalidArgumentException('Elegí un filtro de tipo válido.');
        }
        $limite = $filtros['limite'] ?? 100;
        if (!is_int($limite) || $limite < 1 || $limite > 200) {
            throw new InvalidArgumentException('El límite debe ser un entero entre 1 y 200.');
        }

        $zona = new DateTimeZone(self::ZONA);
        $desde = isset($filtros['desde']) && $filtros['desde'] !== '' ? self::fechaDia($filtros['desde'], $zona) : null;
        $hasta = isset($filtros['hasta']) && $filtros['hasta'] !== '' ? self::fechaDia($filtros['hasta'], $zona) : null;
        if ($desde !== null && $hasta !== null && $hasta < $desde) {
            throw new InvalidArgumentException('La fecha «hasta» debe ser igual o posterior a «desde».');
        }
        $hastaExclusivo = $hasta?->modify('+1 day');

        $cliente = isset($filtros['cliente']) && is_string($filtros['cliente']) ? trim($filtros['cliente']) : '';
        if (mb_strlen($cliente) > 150) {
            throw new InvalidArgumentException('El filtro de cliente es demasiado largo.');
        }

        $turnos = $this->buscarTurnosCandidatos($por, $desde, $hastaExclusivo, $cliente, $limite);
        if ($turnos === []) {
            return ['eventos' => [], 'cancelados' => []];
        }

        $ids = array_column($turnos, 'id');
        $eventosPorTurno = $this->eventosPorTurnos($ids);
        $actuales = $this->estadosActuales($turnos);
        [$mapaAutores, $mapaProfesionales, $mapaEspacios] = $this->catalogos($turnos, $eventosPorTurno, $actuales);

        $entradas = [];
        $cancelados = [];
        foreach ($turnos as $turno) {
            $id = $turno['id'];
            $eventos = $eventosPorTurno[$id] ?? [];
            $resumenTurno = $this->resumenTurno($turno, $eventos, $actuales[$id], $mapaAutores, $mapaProfesionales, $mapaEspacios);
            $linea = $this->construirLinea($turno, $eventos, $actuales[$id], $mapaAutores, $mapaProfesionales, $mapaEspacios);

            foreach ($linea as $item) {
                if ($por === 'evento') {
                    $instante = new DateTimeImmutable($item['fecha']);
                    if ($desde !== null && $instante < $desde) {
                        continue;
                    }
                    if ($hastaExclusivo !== null && $instante >= $hastaExclusivo) {
                        continue;
                    }
                }
                if (!self::coincideTipo($item, $tipo)) {
                    continue;
                }
                $entradas[] = $item + ['turno' => $resumenTurno];
            }

            if ($resumenTurno['cancelado']) {
                $eventoCancelacion = null;
                foreach (array_reverse($linea) as $item) {
                    if ($item['tipo'] === 'CANCELADO') {
                        $eventoCancelacion = $item;
                        break;
                    }
                }
                $incluirCancelado = true;
                if ($por === 'evento' && $resumenTurno['cancelado_en'] !== null) {
                    $instante = new DateTimeImmutable($resumenTurno['cancelado_en']);
                    if (($desde !== null && $instante < $desde) || ($hastaExclusivo !== null && $instante >= $hastaExclusivo)) {
                        $incluirCancelado = false;
                    }
                }
                if ($incluirCancelado) {
                    $cancelados[] = $resumenTurno + [
                        'cancelado_por' => $eventoCancelacion['autor'] ?? null,
                    ];
                }
            }
        }

        usort($entradas, static function (array $a, array $b): int {
            return strcmp($b['fecha'], $a['fecha']) ?: ($b['_orden'] <=> $a['_orden']);
        });
        $entradas = array_map(static function (array $e): array {
            unset($e['_orden']);

            return $e;
        }, array_slice($entradas, 0, $limite));

        usort($cancelados, static fn (array $a, array $b): int => strcmp((string) ($b['cancelado_en'] ?? ''), (string) ($a['cancelado_en'] ?? '')));

        return [
            'eventos' => $entradas,
            'cancelados' => array_slice($cancelados, 0, $limite),
        ];
    }

    private function buscarTurnosCandidatos(
        string $por,
        ?DateTimeImmutable $desde,
        ?DateTimeImmutable $hastaExclusivo,
        string $cliente,
        int $limite,
    ): array {
        $condiciones = [];
        $parametros = [];
        if ($cliente !== '') {
            $condiciones[] = 't.cliente_nombre ILIKE ?';
            $parametros[] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $cliente).'%';
        }

        if ($por === 'turno') {
            if ($hastaExclusivo !== null) {
                $condiciones[] = 't.inicio < ?';
                $parametros[] = $hastaExclusivo->format('Y-m-d H:i:s.uP');
            }
            if ($desde !== null) {
                $condiciones[] = 't.fin > ?';
                $parametros[] = $desde->format('Y-m-d H:i:s.uP');
            }
        } else {
            if ($desde !== null || $hastaExclusivo !== null) {
                $sub = [];
                $subCreacion = [];
                if ($desde !== null) {
                    $sub[] = 'e.creado_en >= ?';
                    $parametros[] = $desde->format('Y-m-d H:i:s.uP');
                }
                if ($hastaExclusivo !== null) {
                    $sub[] = 'e.creado_en < ?';
                    $parametros[] = $hastaExclusivo->format('Y-m-d H:i:s.uP');
                }
                if ($desde !== null) {
                    $subCreacion[] = 't.creado_en >= ?';
                    $parametros[] = $desde->format('Y-m-d H:i:s.uP');
                }
                if ($hastaExclusivo !== null) {
                    $subCreacion[] = 't.creado_en < ?';
                    $parametros[] = $hastaExclusivo->format('Y-m-d H:i:s.uP');
                }
                $condiciones[] = '(EXISTS (SELECT 1 FROM eventos_turnos_administrativos e WHERE e.turno_id = t.id AND '.implode(' AND ', $sub).') OR ('.implode(' AND ', $subCreacion).'))';
            }
        }

        $where = $condiciones === [] ? '' : 'WHERE '.implode(' AND ', $condiciones);
        $tope = min(400, $limite * 3);
        $parametros[] = $tope;

        return $this->conexion->fetchAllAssociative(
            <<<SQL
                SELECT t.id::text AS id, t.servicio_id, t.servicio_nombre, t.cliente_nombre, t.inicio, t.fin,
                       t.creado_por::text AS creado_por, t.creado_en, t.cancelado_en, t.revision,
                       t.profesionales_requeridos, t.sena_pagada, t.sena_importe_centavos::text AS sena_importe_centavos
                FROM turnos_administrativos t
                $where
                ORDER BY GREATEST(t.creado_en, COALESCE((SELECT MAX(e.creado_en) FROM eventos_turnos_administrativos e WHERE e.turno_id = t.id), t.creado_en)) DESC, t.id DESC
                LIMIT ?
                SQL,
            $parametros,
        );
    }

    /** @param list<string> $turnoIds */
    private function eventosPorTurnos(array $turnoIds): array
    {
        if ($turnoIds === []) {
            return [];
        }
        $filas = $this->conexion->fetchAllAssociative(
            <<<'SQL'
                SELECT id::text AS id, turno_id::text AS turno_id, autor_id::text AS autor_id,
                       tipo, estado_anterior, creado_en
                FROM eventos_turnos_administrativos
                WHERE turno_id = ANY(?::bigint[])
                ORDER BY turno_id, id ASC
                SQL,
            ['{'.implode(',', $turnoIds).'}'],
        );
        $porTurno = [];
        foreach ($filas as $fila) {
            $estado = is_string($fila['estado_anterior'])
                ? json_decode($fila['estado_anterior'], true, 512, JSON_THROW_ON_ERROR)
                : $fila['estado_anterior'];
            $porTurno[$fila['turno_id']][] = [
                'id' => $fila['id'],
                'autor_id' => $fila['autor_id'],
                'tipo' => $fila['tipo'],
                'estado_anterior' => is_array($estado) ? $estado : [],
                'creado_en' => $fila['creado_en'],
            ];
        }

        return $porTurno;
    }

    /** @param list<array<string, mixed>> $turnos */
    private function estadosActuales(array $turnos): array
    {
        $ids = array_column($turnos, 'id');
        $lista = '{'.implode(',', $ids).'}';

        $espaciosPorTurno = [];
        foreach ($this->conexion->fetchAllAssociative(
            'SELECT turno_admin_id::text AS turno_id, recurso_id::text AS recurso_id, inicio, fin FROM ocupaciones_recursos WHERE turno_admin_id = ANY(?::bigint[]) ORDER BY id',
            [$lista],
        ) as $fila) {
            $espaciosPorTurno[$fila['turno_id']][] = [
                'recurso_id' => $fila['recurso_id'],
                'inicio' => $fila['inicio'],
                'fin' => $fila['fin'],
            ];
        }

        $profesionalesPorTurno = [];
        foreach ($this->conexion->fetchAllAssociative(
            'SELECT turno_admin_id::text AS turno_id, profesional_id::text AS profesional_id FROM ocupaciones_profesionales WHERE turno_admin_id = ANY(?::bigint[]) ORDER BY profesional_id',
            [$lista],
        ) as $fila) {
            $profesionalesPorTurno[$fila['turno_id']][] = $fila['profesional_id'];
        }

        $preparacionesPorTurno = [];
        foreach ($this->conexion->fetchAllAssociative(
            <<<'SQL'
                SELECT p.turno_id::text AS turno_id, p.eliminada, o.recurso_id::text AS recurso_id, o.inicio, o.fin
                FROM preparaciones_agenda p
                LEFT JOIN ocupaciones_recursos o ON o.preparacion_id = p.id
                WHERE p.turno_id = ANY(?::bigint[])
                SQL,
            [$lista],
        ) as $fila) {
            $preparacionesPorTurno[$fila['turno_id']] = [
                'eliminada' => (bool) $fila['eliminada'],
                'recurso_id' => $fila['recurso_id'],
                'inicio' => $fila['inicio'],
                'fin' => $fila['fin'],
            ];
        }

        $actuales = [];
        foreach ($turnos as $turno) {
            $id = $turno['id'];
            $actuales[$id] = [
                'servicio_nombre' => $turno['servicio_nombre'],
                'cliente_nombre' => $turno['cliente_nombre'],
                'inicio' => $turno['inicio'],
                'fin' => $turno['fin'],
                'sena_pagada' => (bool) $turno['sena_pagada'],
                'sena_importe_centavos' => $turno['sena_importe_centavos'],
                'profesionales' => $profesionalesPorTurno[$id] ?? [],
                'espacios' => $espaciosPorTurno[$id] ?? [],
                'preparacion' => $preparacionesPorTurno[$id] ?? null,
            ];
        }

        return $actuales;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>}
     */
    private function catalogos(array $turnos, array $eventosPorTurno, array $actuales): array
    {
        $autorIds = [];
        $profIds = [];
        $espacioIds = [];

        foreach ($turnos as $turno) {
            $autorIds[] = $turno['creado_por'];
            $estado = $actuales[$turno['id']] ?? [];
            foreach ($estado['profesionales'] ?? [] as $pid) {
                $profIds[(string) $pid] = (string) $pid;
            }
            foreach ($estado['espacios'] ?? [] as $esp) {
                if (isset($esp['recurso_id'])) {
                    $espacioIds[(string) $esp['recurso_id']] = (string) $esp['recurso_id'];
                }
            }
        }
        foreach ($eventosPorTurno as $lista) {
            foreach ($lista as $evento) {
                $autorIds[] = $evento['autor_id'];
                $ant = $evento['estado_anterior'];
                foreach ($ant['profesionales'] ?? [] as $pid) {
                    $profIds[(string) $pid] = (string) $pid;
                }
                foreach ($ant['espacios'] ?? [] as $esp) {
                    if (isset($esp['recurso_id'])) {
                        $espacioIds[(string) $esp['recurso_id']] = (string) $esp['recurso_id'];
                    }
                }
                if (isset($ant['recurso_id'])) {
                    $espacioIds[(string) $ant['recurso_id']] = (string) $ant['recurso_id'];
                }
            }
        }

        $mapaAutores = $this->autores->resolver($autorIds);
        $mapaProfesionales = [];
        if ($profIds !== []) {
            foreach ($this->conexion->fetchAllAssociative(
                'SELECT id::text AS id, nombre FROM profesionales WHERE id = ANY(?::bigint[])',
                ['{'.implode(',', $profIds).'}'],
            ) as $fila) {
                $mapaProfesionales[$fila['id']] = $fila['nombre'];
            }
        }
        $mapaEspacios = [];
        if ($espacioIds !== []) {
            foreach ($this->conexion->fetchAllAssociative(
                'SELECT id::text AS id, nombre FROM recursos WHERE id = ANY(?::bigint[])',
                ['{'.implode(',', $espacioIds).'}'],
            ) as $fila) {
                $mapaEspacios[$fila['id']] = $fila['nombre'];
            }
        }

        return [$mapaAutores, $mapaProfesionales, $mapaEspacios];
    }

    private function resumenTurno(
        array $turno,
        array $eventos,
        array $actual,
        array $mapaAutores,
        array $mapaProfesionales,
        array $mapaEspacios,
    ): array {
        // Si el turno fue cancelado, sus ocupaciones activas fueron liberadas; recuperamos espacio y equipo del evento CANCELADO.
        $profesionales = $actual['profesionales'];
        $espacios = $actual['espacios'];
        if ($turno['cancelado_en'] !== null) {
            foreach (array_reverse($eventos) as $evento) {
                if ($evento['tipo'] === 'CANCELADO') {
                    $profesionales = array_map('strval', $evento['estado_anterior']['profesionales'] ?? []);
                    $espacios = $evento['estado_anterior']['espacios'] ?? [];
                    break;
                }
            }
        }

        return [
            'id' => $turno['id'],
            'cliente' => $turno['cliente_nombre'],
            'servicio' => $turno['servicio_nombre'],
            'inicio' => NombresAutores::instante($turno['inicio']),
            'fin' => NombresAutores::instante($turno['fin']),
            'espacio' => self::nombreEspacio($espacios, $mapaEspacios),
            'profesionales' => self::nombresProfesionales($profesionales, $mapaProfesionales),
            'sena_pagada' => (bool) $turno['sena_pagada'],
            'sena_importe_centavos' => $turno['sena_importe_centavos'],
            'cancelado' => $turno['cancelado_en'] !== null,
            'cancelado_en' => NombresAutores::instante($turno['cancelado_en']),
            'creado_por' => $this->autores->etiqueta($turno['creado_por'], $mapaAutores),
            'creado_en' => NombresAutores::instante($turno['creado_en']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function construirLinea(
        array $turno,
        array $eventos,
        array $actual,
        array $mapaAutores,
        array $mapaProfesionales,
        array $mapaEspacios,
    ): array {
        $eventosTurno = [];
        $eventosPrep = [];
        foreach ($eventos as $indice => $evento) {
            if (in_array($evento['tipo'], ['EDITADO', 'CANCELADO'], true)) {
                $eventosTurno[] = [$indice, $evento];
            } else {
                $eventosPrep[] = [$indice, $evento];
            }
        }

        // Estado inicial del turno (antes de la primera edición/cancelación, o el actual si nunca cambió).
        $inicial = $eventosTurno !== [] ? $eventosTurno[0][1]['estado_anterior'] : $actual;
        $senaInicialPagada = (bool) ($inicial['sena_pagada'] ?? false);
        $senaInicialImporte = self::normalizarImporte($inicial['sena_importe_centavos'] ?? null);
        $cambioSenaInicial = $senaInicialPagada || $senaInicialImporte !== null;

        $detallesCreacion = [
            sprintf(
                '%s · %s',
                (string) ($inicial['servicio_nombre'] ?? $turno['servicio_nombre']),
                self::rangoMendoza($inicial['inicio'] ?? $turno['inicio'], $inicial['fin'] ?? $turno['fin']),
            ),
        ];
        $profsIniciales = self::nombresProfesionales($inicial['profesionales'] ?? [], $mapaProfesionales);
        $detallesCreacion[] = 'Profesionales: '.($profsIniciales !== '' ? $profsIniciales : 'Sin asignar');
        $espacioInicial = self::nombreEspacio($inicial['espacios'] ?? [], $mapaEspacios);
        if ($espacioInicial !== null) {
            $detallesCreacion[] = 'Espacio: '.$espacioInicial;
        }
        if ($senaInicialPagada) {
            $detallesCreacion[] = $senaInicialImporte !== null
                ? 'Seña pagada ($ '.self::formatearImporte($senaInicialImporte).')'
                : 'Seña pagada';
        } elseif ($senaInicialImporte !== null) {
            $detallesCreacion[] = 'Seña registrada: $ '.self::formatearImporte($senaInicialImporte).' (no pagada)';
        }

        $resumenCreacion = $senaInicialPagada
            ? ($senaInicialImporte !== null
                ? 'Turno creado con seña pagada ($ '.self::formatearImporte($senaInicialImporte).')'
                : 'Turno creado con seña pagada')
            : 'Turno creado';

        $linea = [[
            'id' => 'creacion-'.$turno['id'],
            'tipo' => 'CREADO',
            'etiqueta' => 'Creado',
            'resumen' => $resumenCreacion,
            'cambios' => $detallesCreacion,
            'cambio_sena' => $cambioSenaInicial,
            'autor' => $this->autores->etiqueta($turno['creado_por'], $mapaAutores),
            'fecha' => NombresAutores::instante($turno['creado_en']),
            '_orden' => 0,
        ]];

        // Mapear el estado posterior de cada evento EDITADO/CANCELADO y PREP_EDITADA.
        $siguienteTurno = [];
        foreach ($eventosTurno as $pos => [$indice, $evento]) {
            if ($evento['tipo'] === 'EDITADO') {
                $siguienteTurno[$indice] = isset($eventosTurno[$pos + 1])
                    ? $eventosTurno[$pos + 1][1]['estado_anterior']
                    : $actual;
            }
        }
        $siguientePrep = [];
        foreach ($eventosPrep as $pos => [$indice, $evento]) {
            if ($evento['tipo'] !== 'PREP_EDITADA') {
                continue;
            }
            if (isset($eventosPrep[$pos + 1])) {
                $siguientePrep[$indice] = $eventosPrep[$pos + 1][1]['estado_anterior'];
                continue;
            }
            $encontrada = null;
            for ($j = $indice + 1, $total = count($eventos); $j < $total; ++$j) {
                $prepPosterior = $eventos[$j]['estado_anterior']['preparacion'] ?? null;
                if (is_array($prepPosterior) && isset($prepPosterior['inicio'], $prepPosterior['fin'])) {
                    $encontrada = $prepPosterior;
                    break;
                }
            }
            $siguientePrep[$indice] = $encontrada ?? $actual['preparacion'];
        }

        foreach ($eventos as $indice => $evento) {
            $autor = $this->autores->etiqueta($evento['autor_id'], $mapaAutores);
            $fecha = NombresAutores::instante($evento['creado_en']);
            $ant = $evento['estado_anterior'];
            $tipo = $evento['tipo'];
            $orden = (int) $evento['id'];

            if ($tipo === 'CANCELADO') {
                $cambios = [
                    sprintf(
                        'Turno cancelado (%s · %s)',
                        (string) ($ant['servicio_nombre'] ?? $turno['servicio_nombre']),
                        self::rangoMendoza($ant['inicio'] ?? $turno['inicio'], $ant['fin'] ?? $turno['fin']),
                    ),
                ];
                $pagada = (bool) ($ant['sena_pagada'] ?? false);
                $importe = self::normalizarImporte($ant['sena_importe_centavos'] ?? null);
                if ($pagada || $importe !== null) {
                    $cambios[] = 'Seña al cancelar: '.($pagada ? 'pagada' : 'no pagada')
                        .($importe !== null ? ' ($ '.self::formatearImporte($importe).')' : '');
                }
                $linea[] = [
                    'id' => 'evento-'.$evento['id'],
                    'tipo' => 'CANCELADO',
                    'etiqueta' => 'Cancelado',
                    'resumen' => 'Turno cancelado',
                    'cambios' => $cambios,
                    'cambio_sena' => false,
                    'autor' => $autor,
                    'fecha' => $fecha,
                    '_orden' => $orden,
                ];
            } elseif ($tipo === 'EDITADO') {
                $despues = $siguienteTurno[$indice] ?? $actual;
                [$cambios, $cambioSena, $resumenSena] = self::compararEstados($ant, $despues, $mapaProfesionales, $mapaEspacios);
                $resumen = $cambios === []
                    ? 'Turno editado'
                    : ($cambioSena && count($cambios) === 1 ? $resumenSena : implode(' · ', $cambios));
                $etiqueta = $cambioSena && count($cambios) === 1 ? 'Seña' : ($cambioSena ? 'Editado · Seña' : 'Editado');
                $linea[] = [
                    'id' => 'evento-'.$evento['id'],
                    'tipo' => 'EDITADO',
                    'etiqueta' => $etiqueta,
                    'resumen' => $resumen,
                    'cambios' => $cambios === [] ? ['Turno guardado sin cambios visibles'] : $cambios,
                    'cambio_sena' => $cambioSena,
                    'autor' => $autor,
                    'fecha' => $fecha,
                    '_orden' => $orden,
                ];
            } elseif ($tipo === 'PREP_EDITADA') {
                $rangoAntes = self::rangoHoraMendoza($ant['inicio'] ?? null, $ant['fin'] ?? null);
                $prepDespues = $siguientePrep[$indice] ?? null;
                $rangoDespues = is_array($prepDespues) ? self::rangoHoraMendoza($prepDespues['inicio'] ?? null, $prepDespues['fin'] ?? null) : null;
                $resumen = $rangoAntes !== null && $rangoDespues !== null
                    ? "Preparación cambiada de $rangoAntes a $rangoDespues"
                    : ($rangoAntes !== null ? "Preparación editada (antes $rangoAntes)" : 'Preparación editada');
                $linea[] = [
                    'id' => 'evento-'.$evento['id'],
                    'tipo' => 'PREP_EDITADA',
                    'etiqueta' => 'Preparación',
                    'resumen' => $resumen,
                    'cambios' => [$resumen],
                    'cambio_sena' => false,
                    'autor' => $autor,
                    'fecha' => $fecha,
                    '_orden' => $orden,
                ];
            } elseif ($tipo === 'PREP_ELIMINADA') {
                $rangoAntes = self::rangoHoraMendoza($ant['inicio'] ?? null, $ant['fin'] ?? null);
                $resumen = $rangoAntes !== null ? "Preparación eliminada (era $rangoAntes)" : 'Preparación eliminada';
                $linea[] = [
                    'id' => 'evento-'.$evento['id'],
                    'tipo' => 'PREP_ELIMINADA',
                    'etiqueta' => 'Preparación',
                    'resumen' => $resumen,
                    'cambios' => [$resumen],
                    'cambio_sena' => false,
                    'autor' => $autor,
                    'fecha' => $fecha,
                    '_orden' => $orden,
                ];
            }
        }

        return $linea;
    }

    /**
     * @return array{0: list<string>, 1: bool, 2: string}
     */
    private static function compararEstados(
        array $antes,
        array $despues,
        array $mapaProfesionales,
        array $mapaEspacios,
    ): array {
        $cambios = [];
        $cambioSena = false;
        $resumenSena = '';

        // 1. Seña
        $pagadaAntes = (bool) ($antes['sena_pagada'] ?? false);
        $pagadaDespues = (bool) ($despues['sena_pagada'] ?? false);
        $importeAntes = self::normalizarImporte($antes['sena_importe_centavos'] ?? null);
        $importeDespues = self::normalizarImporte($despues['sena_importe_centavos'] ?? null);

        if ($pagadaAntes !== $pagadaDespues || $importeAntes !== $importeDespues) {
            $cambioSena = true;
            if (!$pagadaAntes && $pagadaDespues) {
                $resumenSena = $importeDespues !== null
                    ? 'Seña cargada como pagada ($ '.self::formatearImporte($importeDespues).')'
                    : 'Seña marcada como pagada';
            } elseif ($pagadaAntes && !$pagadaDespues) {
                $resumenSena = 'Seña marcada como no pagada';
                if ($importeAntes !== $importeDespues) {
                    $resumenSena .= $importeDespues !== null
                        ? ' (importe $ '.self::formatearImporte($importeDespues).')'
                        : ' (importe quitado)';
                }
            } elseif ($importeAntes === null && $importeDespues !== null) {
                $resumenSena = 'Importe de seña cargado: $ '.self::formatearImporte($importeDespues);
            } elseif ($importeAntes !== null && $importeDespues === null) {
                $resumenSena = 'Importe de seña quitado (antes $ '.self::formatearImporte($importeAntes).')';
            } else {
                $resumenSena = sprintf(
                    'Importe de seña cambiado de $ %s a $ %s',
                    self::formatearImporte($importeAntes),
                    self::formatearImporte($importeDespues),
                );
            }
            $cambios[] = $resumenSena;
        }

        // 2. Horario / fecha / duración
        $rangoAntes = self::rangoMendoza($antes['inicio'] ?? null, $antes['fin'] ?? null);
        $rangoDespues = self::rangoMendoza($despues['inicio'] ?? null, $despues['fin'] ?? null);
        if ($rangoAntes !== $rangoDespues) {
            $cambios[] = "Horario cambiado de $rangoAntes a $rangoDespues";
        }

        // 3. Servicio
        $servAntes = (string) ($antes['servicio_nombre'] ?? '');
        $servDespues = (string) ($despues['servicio_nombre'] ?? '');
        if ($servAntes !== $servDespues) {
            $cambios[] = "Servicio cambiado de $servAntes a $servDespues";
        }

        // 4. Cliente
        $cliAntes = (string) ($antes['cliente_nombre'] ?? '');
        $cliDespues = (string) ($despues['cliente_nombre'] ?? '');
        if ($cliAntes !== $cliDespues) {
            $cambios[] = "Cliente cambiado de $cliAntes a $cliDespues";
        }

        // 5. Espacio
        $espAntes = self::idEspacio($antes['espacios'] ?? []);
        $espDespues = self::idEspacio($despues['espacios'] ?? []);
        if ($espAntes !== $espDespues) {
            $nombreAntes = $espAntes !== null ? ($mapaEspacios[$espAntes] ?? "Espacio #$espAntes") : 'Sin espacio';
            $nombreDespues = $espDespues !== null ? ($mapaEspacios[$espDespues] ?? "Espacio #$espDespues") : 'Sin espacio';
            $cambios[] = "Espacio cambiado de $nombreAntes a $nombreDespues";
        }

        // 6. Profesionales
        $profsAntes = array_map('strval', $antes['profesionales'] ?? []);
        $profsDespues = array_map('strval', $despues['profesionales'] ?? []);
        sort($profsAntes, SORT_STRING);
        sort($profsDespues, SORT_STRING);
        if ($profsAntes !== $profsDespues) {
            $txtAntes = self::nombresProfesionales($profsAntes, $mapaProfesionales) ?: 'Sin asignar';
            $txtDespues = self::nombresProfesionales($profsDespues, $mapaProfesionales) ?: 'Sin asignar';
            $cambios[] = "Profesionales: de $txtAntes a $txtDespues";
        }

        return [$cambios, $cambioSena, $resumenSena];
    }

    private static function coincideTipo(array $item, string $filtro): bool
    {
        return match ($filtro) {
            'todos' => true,
            'sena' => (bool) $item['cambio_sena'],
            'cancelado' => $item['tipo'] === 'CANCELADO',
            'editado' => $item['tipo'] === 'EDITADO',
            'creado' => $item['tipo'] === 'CREADO',
            'preparacion' => in_array($item['tipo'], ['PREP_EDITADA', 'PREP_ELIMINADA'], true),
            default => true,
        };
    }

    private static function idEspacio(array $espacios): ?string
    {
        if (!isset($espacios[0]['recurso_id'])) {
            return null;
        }

        return (string) $espacios[0]['recurso_id'];
    }

    private static function nombreEspacio(array $espacios, array $mapaEspacios): ?string
    {
        $id = self::idEspacio($espacios);

        return $id === null ? null : ($mapaEspacios[$id] ?? "Espacio #$id");
    }

    private static function nombresProfesionales(array $ids, array $mapaProfesionales): string
    {
        if ($ids === []) {
            return '';
        }
        $nombres = array_map(
            static fn (int|string $id): string => $mapaProfesionales[(string) $id] ?? "Profesional #$id",
            $ids,
        );
        sort($nombres, SORT_NATURAL | SORT_FLAG_CASE);

        return implode(', ', $nombres);
    }

    private static function normalizarImporte(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return (string) $valor;
    }

    private static function formatearImporte(string $centavos): string
    {
        $relleno = str_pad($centavos, 3, '0', STR_PAD_LEFT);
        $entera = substr($relleno, 0, -2);
        $decimales = substr($relleno, -2);
        $conMiles = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $entera) ?? $entera;

        return "$conMiles,$decimales";
    }

    private static function rangoMendoza(mixed $inicio, mixed $fin): string
    {
        if (!is_string($inicio) || !is_string($fin)) {
            return '';
        }
        $zona = new DateTimeZone(self::ZONA);
        $desde = (new DateTimeImmutable($inicio))->setTimezone($zona);
        $hasta = (new DateTimeImmutable($fin))->setTimezone($zona);
        if ($desde->format('Y-m-d') === $hasta->format('Y-m-d')) {
            return $desde->format('d/m/Y H:i').'–'.$hasta->format('H:i');
        }

        return $desde->format('d/m/Y H:i').'–'.$hasta->format('d/m/Y H:i');
    }

    private static function rangoHoraMendoza(mixed $inicio, mixed $fin): ?string
    {
        if (!is_string($inicio) || !is_string($fin)) {
            return null;
        }
        $zona = new DateTimeZone(self::ZONA);
        $desde = (new DateTimeImmutable($inicio))->setTimezone($zona);
        $hasta = (new DateTimeImmutable($fin))->setTimezone($zona);

        return $desde->format('H:i').'–'.$hasta->format('H:i');
    }

    private static function fechaDia(string $valor, DateTimeZone $zona): DateTimeImmutable
    {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor, $zona);
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            throw new InvalidArgumentException('Ingresá fechas válidas en formato AAAA-MM-DD.');
        }

        return $fecha;
    }
}
