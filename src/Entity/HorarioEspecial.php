<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Horario que reemplaza las franjas de una profesional en una fecha. La clave es compuesta;
 * fecha y horas se guardan con el texto canónico de PostgreSQL (ver `FranjaHoraria`) para que
 * la identidad en memoria coincida con la leída de la base.
 */
#[ORM\Entity]
#[ORM\Table(name: 'horarios_profesionales_especiales')]
class HorarioEspecial
{
    #[ORM\Id, ORM\Column(name: 'profesional_id', type: 'bigint')]
    public int|string $profesionalId;

    #[ORM\Id, ORM\Column(type: 'string')]
    public string $fecha;

    #[ORM\Id, ORM\Column(name: 'hora_inicio', type: 'string')]
    public string $horaInicio;

    #[ORM\Id, ORM\Column(name: 'hora_fin', type: 'string')]
    public string $horaFin;
}
