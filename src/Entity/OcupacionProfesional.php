<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ocupación de una profesional por un turno interno. Igual que en `OcupacionRecurso`, las filas
 * de reservas públicas (`sesion_id`) quedan fuera del mapeo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ocupaciones_profesionales')]
class OcupacionProfesional
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    #[ORM\ManyToOne(targetEntity: TurnoAdministrativo::class)]
    #[ORM\JoinColumn(name: 'turno_admin_id', referencedColumnName: 'id', nullable: true)]
    public ?TurnoAdministrativo $turno = null;

    #[ORM\ManyToOne(targetEntity: Profesional::class)]
    #[ORM\JoinColumn(name: 'profesional_id', referencedColumnName: 'id', nullable: false)]
    public Profesional $profesional;

    #[ORM\Column(type: 'instante')]
    public DateTimeImmutable $inicio;

    #[ORM\Column(type: 'instante')]
    public DateTimeImmutable $fin;
}
