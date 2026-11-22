<?php

declare(strict_types=1);

namespace App\Administracion;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;

/**
 * Resuelve identificadores de cuentas a nombres legibles (nombre profesional o correo)
 * y normaliza los bloques de auditoría de autoría para administración.
 */
final readonly class NombresAutores
{
    public function __construct(private Connection $conexion)
    {
    }

    /**
     * @param iterable<int|string|null> $ids
     * @return array<string, string>
     */
    public function resolver(iterable $ids): array
    {
        $numericos = [];
        foreach ($ids as $id) {
            if ($id !== null && preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $id) === 1) {
                $numericos[(string) $id] = (string) $id;
            }
        }
        if ($numericos === []) {
            return [];
        }

        $filas = $this->conexion->fetchAllAssociative(
            <<<'SQL'
                SELECT u.id::text AS id, COALESCE(p.nombre, u.email) AS nombre
                FROM usuarios u
                LEFT JOIN profesionales p ON p.usuario_id = u.id
                WHERE u.id = ANY(?::bigint[])
                SQL,
            ['{'.implode(',', $numericos).'}'],
        );

        $mapa = [];
        foreach ($filas as $fila) {
            $mapa[(string) $fila['id']] = (string) $fila['nombre'];
        }

        return $mapa;
    }

    /** @param array<string, string> $mapa */
    public function etiqueta(int|string|null $id, array $mapa): ?string
    {
        if ($id === null) {
            return null;
        }
        $texto = trim((string) $id);
        if ($texto === '') {
            return null;
        }
        if (isset($mapa[$texto])) {
            return $mapa[$texto];
        }

        return match ($texto) {
            'migracion' => 'Migración inicial',
            'inicial' => 'Carga inicial',
            default => preg_match('/\A[1-9][0-9]{0,18}\z/', $texto) === 1 ? "Cuenta #$texto" : $texto,
        };
    }

    public static function instante(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if ($valor instanceof DateTimeInterface) {
            return $valor->format(DateTimeInterface::ATOM);
        }
        if (is_string($valor)) {
            return (new DateTimeImmutable($valor))->format(DateTimeInterface::ATOM);
        }

        return null;
    }

    /**
     * @param array<string, string> $mapa
     * @return array{creado_por: ?string, creado_en: ?string, actualizado_por: ?string, actualizado_en: ?string}
     */
    public function auditoria(
        int|string|null $creadoPor,
        mixed $creadoEn,
        int|string|null $actualizadoPor,
        mixed $actualizadoEn,
        array $mapa,
    ): array {
        return [
            'creado_por' => $this->etiqueta($creadoPor, $mapa),
            'creado_en' => self::instante($creadoEn),
            'actualizado_por' => $this->etiqueta($actualizadoPor, $mapa),
            'actualizado_en' => self::instante($actualizadoEn),
        ];
    }

    /**
     * @param array<string, string> $mapa
     * @return array{actualizado_por: ?string, actualizado_en: ?string}
     */
    public function ultimaModificacion(int|string|null $actualizadoPor, mixed $actualizadoEn, array $mapa): array
    {
        return [
            'actualizado_por' => $this->etiqueta($actualizadoPor, $mapa),
            'actualizado_en' => self::instante($actualizadoEn),
        ];
    }
}
