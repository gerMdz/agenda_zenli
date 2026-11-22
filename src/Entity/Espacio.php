<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
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

    #[ORM\Column(name: 'creado_por', type: 'bigint', nullable: true)]
    public int|string|null $creadoPor = null;

    #[ORM\Column(name: 'creado_en', type: 'instante', nullable: true)]
    public ?DateTimeImmutable $creadoEn = null;

    #[ORM\Column(name: 'actualizado_por', type: 'bigint', nullable: true)]
    public int|string|null $actualizadoPor = null;

    #[ORM\Column(name: 'actualizado_en', type: 'instante', nullable: true)]
    public ?DateTimeImmutable $actualizadoEn = null;
}
