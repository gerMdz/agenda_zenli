<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Historial de un turno interno. `creado_en` lo asigna PostgreSQL. */
#[ORM\Entity]
#[ORM\Table(name: 'eventos_turnos_administrativos')]
class EventoTurnoAdministrativo
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    #[ORM\ManyToOne(targetEntity: TurnoAdministrativo::class)]
    #[ORM\JoinColumn(name: 'turno_id', referencedColumnName: 'id', nullable: false)]
    public TurnoAdministrativo $turno;

    #[ORM\Column(name: 'autor_id', type: 'bigint')]
    public int|string $autorId;

    #[ORM\Column(length: 20)]
    public string $tipo;

    #[ORM\Column(name: 'estado_anterior', type: 'json', options: ['jsonb' => true])]
    public array $estadoAnterior;
}
