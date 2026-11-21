<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ocupación de un espacio por un turno interno o su preparación. La columna `sesion_id` de las
 * reservas públicas no se mapea: esas filas las escribe `AgendaPostgresql`. La exclusión GiST
 * de la tabla sigue siendo la defensa final ante solapamientos.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ocupaciones_recursos')]
class OcupacionRecurso
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    #[ORM\ManyToOne(targetEntity: TurnoAdministrativo::class)]
    #[ORM\JoinColumn(name: 'turno_admin_id', referencedColumnName: 'id', nullable: true)]
    public ?TurnoAdministrativo $turno = null;

    #[ORM\ManyToOne(targetEntity: PreparacionAgenda::class)]
    #[ORM\JoinColumn(name: 'preparacion_id', referencedColumnName: 'id', nullable: true)]
    public ?PreparacionAgenda $preparacion = null;

    #[ORM\ManyToOne(targetEntity: Espacio::class)]
    #[ORM\JoinColumn(name: 'recurso_id', referencedColumnName: 'id', nullable: false)]
    public Espacio $espacio;

    #[ORM\Column(type: 'instante')]
    public DateTimeImmutable $inicio;

    #[ORM\Column(type: 'instante')]
    public DateTimeImmutable $fin;
}
