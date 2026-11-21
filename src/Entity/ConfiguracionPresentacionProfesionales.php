<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Fila única (id = 1) con el modo de presentación de profesionales y su último autor. */
#[ORM\Entity]
#[ORM\Table(name: 'presentacion_profesionales_config')]
class ConfiguracionPresentacionProfesionales
{
    #[ORM\Id, ORM\Column(type: 'smallint')]
    public int $id;

    #[ORM\Column(length: 10)]
    public string $modo;

    #[ORM\Column(name: 'actualizado_por', type: 'bigint', nullable: true)]
    public int|string|null $actualizadoPor = null;

    #[ORM\Column(name: 'actualizado_en', type: 'instante')]
    public DateTimeImmutable $actualizadoEn;
}
