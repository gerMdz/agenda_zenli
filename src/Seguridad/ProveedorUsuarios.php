<?php

declare(strict_types=1);

namespace App\Seguridad;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Carga solo cuentas activas con permiso de administración o con un perfil profesional activo.
 *
 * @implements UserProviderInterface<UsuarioSesion>
 */
final readonly class ProveedorUsuarios implements UserProviderInterface, PasswordUpgraderInterface
{
    private const CONSULTA = <<<'SQL'
        SELECT u.id::text AS id, u.email, u.password_hash, jsonb_exists(u.roles, 'ROLE_ADMIN') AS admin,
               p.id::text AS profesional_id
        FROM usuarios u LEFT JOIN profesionales p ON p.usuario_id = u.id AND p.activa
        WHERE u.activo AND (jsonb_exists(u.roles, 'ROLE_ADMIN') OR p.id IS NOT NULL) AND
        SQL;

    public function __construct(private Connection $conexion)
    {
    }

    public function loadUserByIdentifier(string $identifier): UsuarioSesion
    {
        return $this->cargar('u.email = ?', strtolower(trim($identifier)), $identifier);
    }

    public function refreshUser(UserInterface $user): UsuarioSesion
    {
        if (!$user instanceof UsuarioSesion) {
            throw new UnsupportedUserException(sprintf('Cuenta no admitida: %s.', $user::class));
        }

        return $this->cargar('u.id = ?', $user->id, $user->email);
    }

    public function supportsClass(string $class): bool
    {
        return $class === UsuarioSesion::class;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if ($user instanceof UsuarioSesion) {
            $this->conexion->executeStatement('UPDATE usuarios SET password_hash = ? WHERE id = ?', [$newHashedPassword, $user->id]);
            $user->actualizarHash($newHashedPassword);
        }
    }

    private function cargar(string $condicion, string $valor, string $identificador): UsuarioSesion
    {
        $fila = $this->conexion->fetchAssociative(self::CONSULTA.' '.$condicion, [$valor]);
        if ($fila === false) {
            $error = new UserNotFoundException();
            $error->setUserIdentifier($identificador);
            throw $error;
        }

        return new UsuarioSesion($fila['id'], $fila['email'], (bool) $fila['admin'], $fila['profesional_id'], $fila['password_hash']);
    }
}
