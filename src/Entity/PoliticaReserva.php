<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'politicas_reservas')]
class PoliticaReserva
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    #[ORM\Column(length: 80)]
    public string $clave;

    /** Admite objetos para conservar `{}` en JSON; al leerse se obtiene un array asociativo. */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    public mixed $valor;

    #[ORM\Column(name: 'actualizado_por', type: 'bigint', nullable: true)]
    public int|string|null $actualizadoPor = null;

    #[ORM\Column(name: 'actualizado_en', type: 'instante')]
    public DateTimeImmutable $actualizadoEn;
}
