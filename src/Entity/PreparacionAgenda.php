<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Preparación posterior a un turno interno; su intervalo es una ocupación del espacio. */
#[ORM\Entity]
#[ORM\Table(name: 'preparaciones_agenda')]
class PreparacionAgenda
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    #[ORM\ManyToOne(targetEntity: TurnoAdministrativo::class)]
    #[ORM\JoinColumn(name: 'turno_id', referencedColumnName: 'id', nullable: false)]
    public TurnoAdministrativo $turno;

    #[ORM\Column(type: 'integer')]
    public int $revision = 1;

    #[ORM\Column(type: 'boolean')]
    public bool $eliminada = false;
}
