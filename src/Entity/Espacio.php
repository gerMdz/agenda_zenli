<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Espacio físico de atención (tabla `recursos`). */
#[ORM\Entity]
#[ORM\Table(name: 'recursos')]
class Espacio
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    #[ORM\Column(length: 60)]
    public string $codigo;

    #[ORM\Column(length: 120)]
    public string $nombre;

    #[ORM\Column(length: 1000, nullable: true)]
    public ?string $detalle = null;

    #[ORM\Column(type: 'boolean')]
    public bool $activo = true;
}
