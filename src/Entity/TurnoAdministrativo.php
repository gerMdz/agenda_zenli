<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Turno de la agenda interna. Sus ocupaciones se guardan en `ocupaciones_*`. */
#[ORM\Entity]
#[ORM\Table(name: 'turnos_administrativos')]
class TurnoAdministrativo
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    /** Sin asociación: la clave foránea se anula si se elimina el servicio y el nombre queda copiado. */
    #[ORM\Column(name: 'servicio_id', type: 'integer', nullable: true)]
    public ?int $servicioId = null;

    #[ORM\Column(name: 'servicio_nombre', length: 100)]
    public string $servicioNombre;

    #[ORM\Column(name: 'cliente_id', type: 'bigint', nullable: true)]
    public int|string|null $clienteId = null;

    #[ORM\Column(name: 'cliente_nombre', length: 150)]
    public string $clienteNombre;

    #[ORM\Column(type: 'instante')]
    public DateTimeImmutable $inicio;

    #[ORM\Column(type: 'instante')]
    public DateTimeImmutable $fin;

    #[ORM\Column(name: 'creado_por', type: 'bigint')]
    public int|string $creadoPor;

    #[ORM\Column(name: 'creado_en', type: 'instante', insertable: false, updatable: false, generated: 'INSERT')]
    public ?DateTimeImmutable $creadoEn = null;

    #[ORM\Column(name: 'cancelado_en', type: 'instante', nullable: true)]
    public ?DateTimeImmutable $canceladoEn = null;

    #[ORM\Column(length: 12, options: ['default' => 'programado'])]
    public string $asistencia = 'programado';

    #[ORM\Column(type: 'integer')]
    public int $revision = 1;

    #[ORM\Column(name: 'profesionales_requeridos', type: 'integer', nullable: true)]
    public ?int $profesionalesRequeridos = null;

    #[ORM\Column(name: 'sena_pagada', type: 'boolean')]
    public bool $senaPagada = false;

    #[ORM\Column(name: 'sena_importe_centavos', type: 'bigint', nullable: true)]
    public int|string|null $senaImporteCentavos = null;
}
