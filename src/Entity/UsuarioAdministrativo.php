<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'usuarios')]
class UsuarioAdministrativo
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public ?string $id = null;

    #[ORM\Column(length: 180, unique: true)]
    public string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    public string $passwordHash;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    public array $roles = [];

    #[ORM\Column(type: 'boolean')]
    public bool $activo = true;

    public function esAdministrador(): bool
    {
        return self::rolesAdministrativos($this->roles);
    }

    /** Equivale a `jsonb_exists(roles, 'ROLE_ADMIN')` para listas y objetos JSON. */
    public static function rolesAdministrativos(array $roles): bool
    {
        return array_is_list($roles)
            ? in_array('ROLE_ADMIN', $roles, true)
            : array_key_exists('ROLE_ADMIN', $roles);
    }
}