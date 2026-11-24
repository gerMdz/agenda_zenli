<?php

declare(strict_types=1);

namespace App\Administracion;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ClientesAgenda
{
    // El evento de cancelación conserva las asignaciones que se liberaron al cancelar.
    private const TURNOS_PROPIOS = <<<'SQL'
        (EXISTS (SELECT 1 FROM ocupaciones_profesionales p
                 WHERE p.turno_admin_id = t.id AND p.profesional_id = :profesional)
         OR (t.cancelado_en IS NOT NULL AND EXISTS (
             SELECT 1 FROM eventos_turnos_administrativos e
             CROSS JOIN LATERAL jsonb_array_elements_text(e.estado_anterior::jsonb -> 'profesionales') AS equipo(id)
             WHERE e.turno_id = t.id AND e.tipo = 'CANCELADO' AND equipo.id = :profesional
         )))
        SQL;
    private const CAMPOS = 'c.id::text AS id, c.nombre, c.telefono, c.email, c.archivado_en';

    public function __construct(private Connection $conexion)
    {
    }

    public function listar(array $cuenta, string $buscar = '', bool $incluirArchivados = false): array
    {
        $buscar = trim($buscar);
        if (mb_strlen($buscar) > 150 || str_contains($buscar, "\0")) {
            throw new InvalidArgumentException('La búsqueda admite hasta 150 caracteres.');
        }
        [$alcance, $parametros] = self::alcance($cuenta);
        $parametros['buscar'] = $buscar;
        $activos = $incluirArchivados ? '' : 'AND c.archivado_en IS NULL';
        $filas = $this->conexion->fetchAllAssociative('SELECT '.self::CAMPOS.<<<SQL
             FROM clientes c
             WHERE $alcance $activos
               AND strpos(lower(translate(c.nombre, 'áéíóúüÁÉÍÓÚÜÑ', 'aeiouuAEIOUUñ')),
                          lower(translate(:buscar, 'áéíóúüÁÉÍÓÚÜÑ', 'aeiouuAEIOUUñ'))) > 0
             ORDER BY c.nombre, c.id LIMIT 51
            SQL, $parametros);

        return ['clientes' => array_slice($filas, 0, 50), 'hay_mas' => count($filas) > 50];
    }

    public function ficha(string $id, array $cuenta): array
    {
        $cliente = $this->buscar($id, $cuenta);
        $alcance = $cuenta['admin'] ? '' : 'AND '.self::TURNOS_PROPIOS;
        $parametros = ['id' => $id];
        if (!$cuenta['admin']) {
            $parametros['profesional'] = $cuenta['profesional_id'];
        }
        $turnos = $this->conexion->fetchAllAssociative(<<<SQL
            SELECT t.id::text AS id, t.servicio_nombre AS servicio, t.inicio, t.fin, t.asistencia, t.cancelado_en
            FROM turnos_administrativos t WHERE t.cliente_id = :id $alcance
            ORDER BY t.inicio DESC, t.id DESC LIMIT 101
            SQL, $parametros);
        $notas = $this->conexion->fetchAllAssociative(
            'SELECT id::text AS id, texto, creado_en FROM observaciones_clientes WHERE cliente_id = :id'
            .($cuenta['admin'] ? '' : ' AND autor_id = :autor').' ORDER BY id DESC LIMIT 101',
            $cuenta['admin'] ? ['id' => $id] : ['id' => $id, 'autor' => $cuenta['id']],
        );

        return [
            'cliente' => array_diff_key($cliente, ['creado_por' => true]),
            'editable' => self::editable($cliente, $cuenta),
            'turnos' => array_map(static function (array $turno): array {
                $turno['inicio'] = (new DateTimeImmutable($turno['inicio']))->format(DATE_ATOM);
                $turno['fin'] = (new DateTimeImmutable($turno['fin']))->format(DATE_ATOM);

                return $turno;
            }, array_slice($turnos, 0, 100)),
            'observaciones' => array_slice($notas, 0, 100),
            'mas_turnos' => count($turnos) > 100,
            'mas_observaciones' => count($notas) > 100,
        ];
    }

    public function guardar(?string $id, array $datos, array $cuenta): string
    {
        $campos = [
            'nombre' => self::texto($datos['nombre'] ?? null, 150, 'el nombre', true),
            'telefono' => self::texto($datos['telefono'] ?? null, 30, 'el teléfono'),
            'email' => self::texto($datos['email'] ?? null, 180, 'el correo'),
            'autor' => $cuenta['id'],
        ];
        if ($campos['email'] !== null && !filter_var($campos['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ingresá un correo válido.');
        }
        if ($id === null) {
            return (string) $this->conexion->fetchOne(
                'INSERT INTO clientes (nombre, telefono, email, creado_por, actualizado_por, actualizado_en) VALUES (:nombre, :telefono, :email, :autor, :autor, now()) RETURNING id',
                $campos,
            );
        }

        $this->conexion->transactional(function () use ($id, $cuenta, $campos): void {
            $cliente = $this->buscar($id, $cuenta, true);
            self::exigirEdicion($cliente, $cuenta);
            if ($cliente['archivado_en'] !== null) {
                throw new HttpException(409, 'Reactivá la ficha antes de editarla.');
            }
            $this->conexion->executeStatement(<<<'SQL'
                UPDATE clientes SET nombre = :nombre, telefono = :telefono, email = :email,
                    telefono_verificado = CASE WHEN telefono IS DISTINCT FROM :telefono THEN FALSE ELSE telefono_verificado END,
                    actualizado_por = :autor, actualizado_en = now()
                WHERE id = :id
                SQL, $campos + ['id' => $id]);
        });

        return $id;
    }

    public function cambiarArchivo(string $id, bool $archivar, array $cuenta): void
    {
        $this->conexion->transactional(function () use ($id, $archivar, $cuenta): void {
            $cliente = $this->buscar($id, $cuenta, true);
            self::exigirEdicion($cliente, $cuenta);
            $fecha = $archivar ? 'now()' : 'NULL';
            $this->conexion->executeStatement(
                "UPDATE clientes SET archivado_en = $fecha, actualizado_en = now(), actualizado_por = ? WHERE id = ?",
                [$cuenta['id'], $id],
            );
        });
    }

    public function agregarObservacion(string $id, mixed $texto, array $cuenta): void
    {
        $texto = self::texto($texto, 2000, 'la observación', true);
        $this->conexion->transactional(function () use ($id, $texto, $cuenta): void {
            $cliente = $this->buscar($id, $cuenta, true);
            if ($cliente['archivado_en'] !== null) {
                throw new HttpException(409, 'La ficha está archivada.');
            }
            $this->conexion->insert('observaciones_clientes', ['cliente_id' => $id, 'autor_id' => $cuenta['id'], 'texto' => $texto]);
        });
    }

    /** Se llama durante la transacción del turno. */
    public function nombreParaVincular(string $id, string $autor, ?string $profesional): string
    {
        $cliente = $this->buscar($id, ['id' => $autor, 'admin' => $profesional === null, 'profesional_id' => $profesional], true);
        if ($cliente['archivado_en'] !== null) {
            throw new HttpException(409, 'La ficha está archivada.');
        }

        return $cliente['nombre'];
    }

    private function buscar(string $id, array $cuenta, bool $bloquear = false): array
    {
        [$alcance, $parametros] = self::alcance($cuenta);
        $bloqueo = $bloquear ? ' FOR UPDATE OF c' : '';
        $cliente = $this->conexion->fetchAssociative('SELECT '.self::CAMPOS.", c.creado_por::text AS creado_por FROM clientes c WHERE c.id = :id AND $alcance".$bloqueo, $parametros + ['id' => $id]);
        if ($cliente === false) {
            throw new HttpException(404, 'La ficha no está disponible.');
        }

        return $cliente;
    }

    private static function alcance(array $cuenta): array
    {
        if ($cuenta['admin']) {
            return ['TRUE', []];
        }
        $turnos = self::TURNOS_PROPIOS;

        return [
            "(c.creado_por = :cuenta OR EXISTS (SELECT 1 FROM turnos_administrativos t WHERE t.cliente_id = c.id AND $turnos))",
            ['cuenta' => $cuenta['id'], 'profesional' => $cuenta['profesional_id']],
        ];
    }

    private static function editable(array $cliente, array $cuenta): bool
    {
        return $cuenta['admin'] || $cliente['creado_por'] === $cuenta['id'];
    }

    private static function exigirEdicion(array $cliente, array $cuenta): void
    {
        if (!self::editable($cliente, $cuenta)) {
            throw new HttpException(403, 'Solo administración o quien creó la ficha puede editarla.');
        }
    }

    private static function texto(mixed $valor, int $limite, string $campo, bool $requerido = false): ?string
    {
        if ($valor === null && !$requerido) {
            return null;
        }
        if (!is_string($valor) || str_contains($valor, "\0") || mb_strlen(trim($valor)) > $limite || ($requerido && trim($valor) === '')) {
            throw new InvalidArgumentException("Revisá $campo (hasta $limite caracteres).");
        }

        return trim($valor) === '' ? null : trim($valor);
    }
}
