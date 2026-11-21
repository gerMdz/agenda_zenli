<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'usuarios')]
class UsuarioPerfil
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private int|string $id;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $roles;

    public function id(): string { return (string) $this->id; }

    public function esAdmin(): bool { return in_array('ROLE_ADMIN', $this->roles, true) || array_key_exists('ROLE_ADMIN', $this->roles); }
}