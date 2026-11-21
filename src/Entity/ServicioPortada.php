<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'servicios_portada')]
class ServicioPortada
{
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $nombre;

    #[ORM\Column(name: 'descripcion_corta', length: 255)]
    public string $descripcionCorta;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $detalle = null;

    #[ORM\Column(type: 'integer')]
    public int $orden = 0;

    #[ORM\Column(type: 'boolean')]
    public bool $visible = true;

    #[ORM\Column(name: 'imagen_version', length: 64, nullable: true)]
    public ?string $imagenVersion = null;

    #[ORM\Column(name: 'actualizado_por', length: 100)]
    public string $actualizadoPor;

    #[ORM\Column(name: 'actualizado_en', type: 'string')]
    public string $actualizadoEn;

    #[ORM\Column(name: 'duracion_minima', type: 'integer', nullable: true)]
    public ?int $duracionMinima = null;

    #[ORM\Column(name: 'profesionales_requeridos', type: 'integer', nullable: true)]
    public ?int $profesionalesRequeridos = null;
}