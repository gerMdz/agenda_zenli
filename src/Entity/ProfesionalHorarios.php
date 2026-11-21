<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Vista de `profesionales` con el modo y la revisión de sus horarios. */
#[ORM\Entity]
#[ORM\Table(name: 'profesionales')]
class ProfesionalHorarios
{
    #[ORM\Id, ORM\Column(type: 'bigint')]
    public int|string $id;

    #[ORM\Column(type: 'boolean')]
    public bool $activa;

    /** Null conserva el comportamiento previo: restringido solo si tiene franjas cargadas. */
    #[ORM\Column(name: 'horario_por_franjas', type: 'boolean', nullable: true)]
    public ?bool $horarioPorFranjas = null;

    #[ORM\Column(name: 'horarios_revision', type: 'integer')]
    public int $horariosRevision = 0;
}
