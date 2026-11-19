<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final readonly class UsuariosAdministrativos
{
    public const ROL = 'ROLE_ADMIN';

    public function __construct(private Connection $conexion)
    {
    }

    public function listar(): array
    {
        return $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT id::text AS id, email, activo
            FROM usuarios WHERE jsonb_exists(roles, 'ROLE_ADMIN')
            ORDER BY email
            SQL);
    }

    public function crear(string $email, string $clave): void
    {
        $email = self::normalizarEmail($email);
        $this->conexion->insert('usuarios', [
            'email' => $email,
            'password_hash' => self::hashClave($clave),
            'roles' => json_encode([self::ROL], JSON_THROW_ON_ERROR),
        ]);
    }

    public function actualizar(string $id, bool $activo, string $clave, string $autor, string $huella): bool
    {
        if ($id === $autor && !$activo) {
            return false;
        }
        $hash = $clave === '' ? null : self::hashClave($clave);

        return $this->conexion->transactional(static function (Connection $conexion) use ($id, $activo, $hash, $autor, $huella): bool {
            // Serializa los cambios de acceso para que dos administradores no puedan desactivarse entre sí.
            $conexion->executeStatement('LOCK TABLE usuarios IN SHARE ROW EXCLUSIVE MODE');
            $hashActual = $conexion->fetchOne(<<<'SQL'
                SELECT password_hash FROM usuarios WHERE id = ? AND activo AND jsonb_exists(roles, 'ROLE_ADMIN')
                SQL, [$autor]);
            if (!is_string($hashActual) || !hash_equals($huella, hash('sha256', $hashActual))) {
                return false;
            }

            return $conexion->executeStatement(<<<'SQL'
                UPDATE usuarios SET activo = ?, password_hash = COALESCE(?, password_hash)
                WHERE id::text = ? AND jsonb_exists(roles, 'ROLE_ADMIN')
                SQL, [$activo ? 'true' : 'false', $hash, $id]) !== 0;
        });
    }

    public static function normalizarEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ingresá un correo válido.');
        }

        return $email;
    }

    public static function hashClave(string $clave): string
    {
        if (strlen($clave) < 12 || strlen($clave) > 72 || str_contains($clave, "\0")) {
            throw new InvalidArgumentException('La contraseña debe tener entre 12 y 72 bytes.');
        }

        return password_hash($clave, PASSWORD_DEFAULT);
    }
}
