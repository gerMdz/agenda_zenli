<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Bloqueo de una profesional o de un espacio. Los de `gestionHorarios` se editan desde los horarios. */
#[ORM\Entity]
#[ORM\Table(name: 'bloqueos')]
class Bloqueo
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    #[ORM\ManyToOne(targetEntity: Profesional::class)]
    #[ORM\JoinColumn(name: 'profesional_id', referencedColumnName: 'id', nullable: true)]
    public ?Profesional $profesional = null;

    #[ORM\ManyToOne(targetEntity: Espacio::class)]
    #[ORM\JoinColumn(name: 'recurso_id', referencedColumnName: 'id', nullable: true)]
    public ?Espacio $espacio = null;

    #[ORM\Column(type: 'instante')]
    public DateTimeImmutable $inicio;

    #[ORM\Column(type: 'instante')]
    public DateTimeImmutable $fin;

    #[ORM\Column(length: 200, nullable: true)]
    public ?string $motivo = null;

    #[ORM\Column(name: 'creado_por', type: 'bigint', nullable: true)]
    public int|string|null $creadoPor = null;

    #[ORM\Column(name: 'creado_en', type: 'instante', nullable: true)]
    public ?DateTimeImmutable $creadoEn = null;

    #[ORM\Column(name: 'gestion_horarios', type: 'boolean')]
    public bool $gestionHorarios = false;
}
