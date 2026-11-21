<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Vista de `profesionales` con los datos de su presentación en portada. */
#[ORM\Entity]
#[ORM\Table(name: 'profesionales')]
class ProfesionalPortada
{
    #[ORM\Id, ORM\Column(type: 'bigint')]
    public int|string $id;

    #[ORM\Column(length: 120)]
    public string $nombre;

    #[ORM\Column(type: 'boolean')]
    public bool $activa;

    #[ORM\Column(name: 'visible_portada', type: 'boolean')]
    public bool $visiblePortada = false;

    #[ORM\Column(name: 'orden_portada', type: 'integer', nullable: true)]
    public ?int $ordenPortada = null;
}
