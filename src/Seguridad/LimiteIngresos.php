<?php

declare(strict_types=1);

namespace App\Seguridad;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Demora creciente ante contraseñas incorrectas, por cuenta y por IP de origen.
 *
 * A partir del umbral, cada fallo duplica la espera: 1, 2, 4, 8, 16, 32 y luego 60 minutos como
 * máximo. Mientras dura la espera se rechaza el ingreso sin verificar la contraseña ni sumar
 * fallos. El contador se olvida tras 24 horas sin fallos o con un ingreso correcto de la cuenta.
 * También cuenta los correos inexistentes, para no revelar qué cuentas existen.
 */
final readonly class LimiteIngresos
{
    public const UMBRAL_CUENTA = 5;
    public const UMBRAL_ORIGEN = 20;
    public const PRIMERA_DEMORA = 60;
    public const DEMORA_MAXIMA = 3600;

    public function __construct(private Connection $conexion)
    {
    }

    /** Segundos que faltan para poder intentar de nuevo; 0 si no hay demora. */
    public function demora(string $email, ?string $ip): int
    {
        $segundos = $this->conexion->fetchOne(<<<'SQL'
            SELECT ceil(extract(epoch FROM max(bloqueado_hasta) - statement_timestamp()))::int
            FROM intentos_acceso_admin WHERE clave IN (?, ?) AND bloqueado_hasta > statement_timestamp()
            SQL, [self::claveCuenta($email), $ip === null ? '' : self::claveOrigen($ip)]);

        return max(0, (int) $segundos);
    }

    public function registrarFallo(string $email, ?string $ip): void
    {
        $this->fallo(self::claveCuenta($email), self::UMBRAL_CUENTA);
        if ($ip !== null) {
            $this->fallo(self::claveOrigen($ip), self::UMBRAL_ORIGEN);
        }
        if (random_int(1, 100) === 1) {
            $this->conexion->executeStatement('DELETE FROM intentos_acceso_admin WHERE vence_en <= statement_timestamp()');
        }
    }

    public function registrarExito(string $email): void
    {
        $this->desbloquear($email);
    }

    /** Elimina la demora y el contador de la cuenta. Devuelve si había una demora vigente. */
    public function desbloquear(string $email): bool
    {
        return $this->conexion->fetchOne(
            'DELETE FROM intentos_acceso_admin WHERE clave = ? RETURNING bloqueado_hasta > statement_timestamp()',
            [self::claveCuenta($email)],
        ) === true;
    }

    /**
     * Fin de la demora vigente de cada cuenta, en ISO 8601 UTC.
     *
     * @param list<string> $usuarios ids de usuarios
     * @return array<string, string> id => fin de la demora; omite las cuentas sin demora
     */
    public function demorasPorUsuario(array $usuarios): array
    {
        if ($usuarios === []) {
            return [];
        }

        // Misma clave que claveCuenta(): los correos se guardan normalizados en minúsculas.
        return $this->conexion->fetchAllKeyValue(<<<'SQL'
            SELECT u.id::text, to_char(i.bloqueado_hasta AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')
            FROM usuarios u
            JOIN intentos_acceso_admin i ON i.clave = encode(sha256(convert_to('cuenta:' || u.email, 'UTF8')), 'hex')
            WHERE u.id IN (?) AND i.bloqueado_hasta > statement_timestamp()
            SQL, [$usuarios], [ArrayParameterType::STRING]);
    }

    private function fallo(string $clave, int $umbral): void
    {
        $fallos = (int) $this->conexion->fetchOne(<<<'SQL'
            INSERT INTO intentos_acceso_admin AS i (clave, intentos, vence_en)
            VALUES (?, 1, statement_timestamp() + interval '24 hours')
            ON CONFLICT (clave) DO UPDATE SET
                intentos = CASE WHEN i.vence_en <= statement_timestamp() THEN 1 ELSE LEAST(i.intentos + 1, 1000) END,
                bloqueado_hasta = CASE WHEN i.vence_en <= statement_timestamp() THEN NULL ELSE i.bloqueado_hasta END,
                vence_en = excluded.vence_en
            RETURNING intentos
            SQL, [$clave]);
        if ($fallos < $umbral) {
            return;
        }
        $demora = min(self::DEMORA_MAXIMA, self::PRIMERA_DEMORA << min($fallos - $umbral, 10));
        $this->conexion->executeStatement(<<<'SQL'
            UPDATE intentos_acceso_admin
            SET bloqueado_hasta = GREATEST(COALESCE(bloqueado_hasta, '-infinity'), statement_timestamp() + make_interval(secs => ?))
            WHERE clave = ?
            SQL, [$demora, $clave]);
    }

    private static function claveCuenta(string $email): string
    {
        return hash('sha256', 'cuenta:'.strtolower(trim($email)));
    }

    private static function claveOrigen(string $ip): string
    {
        return hash('sha256', 'origen:'.$ip);
    }
}
