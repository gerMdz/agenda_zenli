<?php

declare(strict_types=1);

namespace App\Seguridad;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Cuenta autenticada en la administración.
 *
 * La sesión guarda solo una huella del hash de la contraseña, no el hash. En cada petición el
 * firewall recarga la cuenta y la compara con isEqualTo(): si cambió la contraseña, el permiso de
 * administración o el perfil profesional, o la cuenta se desactivó, la sesión deja de ser válida.
 */
final class UsuarioSesion implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const ADMIN = 'ROLE_ADMIN';
    public const PROFESIONAL = 'ROLE_PROFESIONAL';

    public readonly string $huella;

    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly bool $admin,
        public readonly ?string $profesionalId,
        private ?string $passwordHash,
    ) {
        $this->huella = hash('sha256', (string) $passwordHash);
    }

    public function getRoles(): array
    {
        $roles = [];
        if ($this->admin) {
            $roles[] = self::ADMIN;
        }
        if ($this->profesionalId !== null) {
            $roles[] = self::PROFESIONAL;
        }

        return $roles;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $usuario): bool
    {
        return $usuario instanceof self && $usuario->id === $this->id && hash_equals($this->huella, $usuario->huella)
            && $usuario->admin === $this->admin && $usuario->profesionalId === $this->profesionalId;
    }

    /** @return array{id: string, email: string, admin: bool, profesional_id: ?string} */
    public function datos(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'admin' => $this->admin, 'profesional_id' => $this->profesionalId];
    }

    public function __serialize(): array
    {
        return [$this->id, $this->email, $this->admin, $this->profesionalId, $this->huella];
    }

    public function __unserialize(array $datos): void
    {
        [$this->id, $this->email, $this->admin, $this->profesionalId, $this->huella] = $datos;
        $this->passwordHash = null;
    }
}
