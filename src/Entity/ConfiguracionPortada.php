<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'configuracion_portada')]
class ConfiguracionPortada
{
    #[ORM\Id, ORM\Column(type: 'smallint')]
    public int $id;

    #[ORM\Column(length: 16)]
    public string $telefono;

    #[ORM\Column(name: 'imagen_version', length: 64, nullable: true, options: ['fixed' => true])]
    public ?string $imagenVersion = null;

    #[ORM\Column(length: 2048, nullable: true)]
    public ?string $instagram = null;

    #[ORM\Column(length: 2048, nullable: true)]
    public ?string $facebook = null;

    #[ORM\Column(length: 255)]
    public string $direccion;

    #[ORM\Column(type: 'text')]
    public string $horarios;

    #[ORM\Column(name: 'solo_imagen', type: 'boolean')]
    public bool $soloImagen;

    #[ORM\Column(name: 'servicios_visible', type: 'boolean')]
    public bool $serviciosVisible;

    #[ORM\Column(name: 'promociones_visible', type: 'boolean')]
    public bool $promocionesVisible;

    #[ORM\Column(name: 'ubicacion_visible', type: 'boolean')]
    public bool $ubicacionVisible;

    #[ORM\Column(name: 'horarios_visible', type: 'boolean')]
    public bool $horariosVisible;

    #[ORM\Column(name: 'consultas_visible', type: 'boolean')]
    public bool $consultasVisible;

    #[ORM\Column(name: 'actualizado_por', type: 'bigint')]
    public string $actualizadoPor;

    #[ORM\Column(name: 'actualizado_en', type: 'instante')]
    public \DateTimeImmutable $actualizadoEn;
}