<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Franja semanal o puntual de una profesional (tabla `horarios_profesionales`).
 *
 * Las columnas DATE y TIME se mapean como texto en formato de PostgreSQL: TIME admite 24:00:00,
 * que DateTime convertiría en las 00:00 del día siguiente.
 */
#[ORM\Entity]
#[ORM\Table(name: 'horarios_profesionales')]
class FranjaHoraria
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    #[ORM\Column(name: 'profesional_id', type: 'bigint')]
    public int|string $profesionalId;

    #[ORM\Column(name: 'dia_semana', type: 'smallint')]
    public int $diaSemana;

    #[ORM\Column(name: 'hora_inicio', type: 'string')]
    public string $horaInicio;

    #[ORM\Column(name: 'hora_fin', type: 'string')]
    public string $horaFin;

    #[ORM\Column(name: 'vigente_desde', type: 'string')]
    public string $vigenteDesde;

    #[ORM\Column(name: 'vigente_hasta', type: 'string', nullable: true)]
    public ?string $vigenteHasta = null;
}
