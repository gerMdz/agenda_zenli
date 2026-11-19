<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class SesionAdministrativa
{
    private const HASH_FICTICIO = '$2y$12$EC4XFX7Oz4bARSA8nzjewOKSV2XSTtNv8W8yJAcRuOVEfr3.J3R6O';

    public function __construct(private Connection $conexion)
    {
    }

    public function token(SessionInterface $sesion): string
    {
        $token = $sesion->get('admin_csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $sesion->set('admin_csrf', $token);
        }

        return $token;
    }

    public function verificarToken(Request $peticion): void
    {
        $token = $peticion->getSession()->get('admin_csrf');
        if (!is_string($token) || !hash_equals($token, $peticion->headers->get('X-Zenli-CSRF', ''))) {
            throw new HttpException(403, 'La sesión del formulario venció.');
        }
    }

    public function usuario(SessionInterface $sesion): ?array
    {
        $id = $sesion->get('admin_id');
        if (!is_string($id)) {
            return null;
        }

        $usuario = $this->conexion->fetchAssociative(<<<'SQL'
            SELECT id::text AS id, email, password_hash FROM usuarios
            WHERE id = ? AND activo AND jsonb_exists(roles, 'ROLE_ADMIN')
            SQL, [$id]);

        if ($usuario === false || !hash_equals($sesion->get('admin_credencial', ''), self::huella($usuario))) {
            $sesion->invalidate();

            return null;
        }

        return ['id' => $usuario['id'], 'email' => $usuario['email']];
    }

    public function exigir(Request $peticion): array
    {
        return $this->usuario($peticion->getSession()) ?? throw new HttpException(401, 'Ingresá a tu cuenta.');
    }

    public function ingresar(Request $peticion, string $email, string $clave): bool
    {
        $email = strtolower(trim($email));
        // Límites independientes por origen y por cuenta. Se aplican también a cuentas inexistentes.
        $this->consumirIntento('origen:'.$peticion->getClientIp(), 30);
        $this->consumirIntento('cuenta:'.$email, 8);
        $usuario = $this->conexion->fetchAssociative(<<<'SQL'
            SELECT id::text AS id, email, password_hash FROM usuarios
            WHERE email = ? AND activo AND jsonb_exists(roles, 'ROLE_ADMIN')
            SQL, [$email]);

        if (!password_verify($clave, $usuario['password_hash'] ?? self::HASH_FICTICIO) || $usuario === false) {
            return false;
        }

        $this->conexion->delete('intentos_acceso_admin', ['clave' => hash('sha256', 'cuenta:'.$email)]);
        $sesion = $peticion->getSession();
        $sesion->invalidate();
        $sesion->set('admin_id', $usuario['id']);
        $sesion->set('admin_credencial', self::huella($usuario));

        return true;
    }

    private function consumirIntento(string $clave, int $limite): void
    {
        $intentos = $this->conexion->fetchOne(<<<'SQL'
            INSERT INTO intentos_acceso_admin (clave, intentos, vence_en)
            VALUES (?, 1, statement_timestamp() + interval '10 minutes')
            ON CONFLICT (clave) DO UPDATE SET
                intentos = CASE WHEN intentos_acceso_admin.vence_en <= statement_timestamp()
                    THEN 1 ELSE LEAST(intentos_acceso_admin.intentos + 1, 31) END,
                vence_en = CASE WHEN intentos_acceso_admin.vence_en <= statement_timestamp()
                    THEN excluded.vence_en ELSE intentos_acceso_admin.vence_en END
            RETURNING intentos
            SQL, [hash('sha256', $clave)]);

        if ((int) $intentos > $limite) {
            throw new HttpException(429, 'Demasiados intentos. Probá nuevamente en unos minutos.');
        }

        if (random_int(1, 100) === 1) {
            $this->conexion->executeStatement('DELETE FROM intentos_acceso_admin WHERE vence_en <= statement_timestamp()');
        }
    }

    private static function huella(array $usuario): string
    {
        return hash('sha256', $usuario['password_hash']);
    }
}
