<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'profesionales')]
class ProfesionalCuentaAdministrativa
{
    #[ORM\Id, ORM\Column(type: 'bigint')]
    public string $id;

    #[ORM\ManyToOne(targetEntity: UsuarioAdministrativo::class)]
    #[ORM\JoinColumn(name: 'usuario_id', referencedColumnName: 'id', nullable: true)]
    public ?UsuarioAdministrativo $usuario = null;

    #[ORM\Column(length: 120)]
    public string $nombre;

    #[ORM\Column(type: 'boolean')]
    public bool $activa;
}