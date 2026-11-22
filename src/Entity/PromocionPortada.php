<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'promociones_portada')]
class PromocionPortada
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $nombre;

    #[ORM\Column(type: 'text')]
    public string $descripcion;

    #[ORM\Column(type: 'boolean')]
    public bool $visible = false;

    #[ORM\Column(type: 'boolean')]
    public bool $eliminado = false;

    #[ORM\Column(name: 'creado_por', length: 100, nullable: true)]
    public ?string $creadoPor = null;

    #[ORM\Column(name: 'creado_en', type: 'instante', nullable: true)]
    public ?DateTimeImmutable $creadoEn = null;

    #[ORM\Column(name: 'actualizado_por', length: 100)]
    public string $actualizadoPor;

    #[ORM\Column(name: 'actualizado_en', type: 'instante')]
    public DateTimeImmutable $actualizadoEn;

    #[ORM\Column(type: 'blob', nullable: true)]
    public mixed $imagen = null;

    #[ORM\Column(name: 'imagen_tipo', length: 20, nullable: true)]
    public ?string $imagenTipo = null;

    #[ORM\Column(name: 'imagen_version', length: 64, nullable: true, options: ['fixed' => true])]
    public ?string $imagenVersion = null;

    #[ORM\Column(type: 'integer')]
    public int $orden;
}