<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'presentacion_profesionales_imagenes')]
class ImagenPresentacionProfesional
{
    #[ORM\Id, ORM\Column(name: 'profesional_id', type: 'bigint')]
    public int|string $profesionalId;

    #[ORM\Column(type: 'blob', nullable: true)]
    public mixed $imagen = null;

    #[ORM\Column(name: 'imagen_tipo', length: 20, nullable: true)]
    public ?string $imagenTipo = null;

    #[ORM\Column(name: 'imagen_version', length: 64, nullable: true, options: ['fixed' => true])]
    public ?string $imagenVersion = null;

    #[ORM\Column(name: 'actualizado_por', type: 'bigint')]
    public int|string $actualizadoPor;

    #[ORM\Column(name: 'actualizado_en', type: 'instante')]
    public DateTimeImmutable $actualizadoEn;
}
