<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mensajes_agenda')]
class MensajeAgenda
{
    #[ORM\Id, ORM\Column(type: 'bigint'), ORM\GeneratedValue(strategy: 'IDENTITY')]
    public int|string|null $id = null;

    #[ORM\ManyToOne(targetEntity: UsuarioInterno::class)]
    #[ORM\JoinColumn(name: 'remitente_id', referencedColumnName: 'id', nullable: false)]
    public UsuarioInterno $remitente;

    #[ORM\ManyToOne(targetEntity: UsuarioInterno::class)]
    #[ORM\JoinColumn(name: 'destinatario_id', referencedColumnName: 'id', nullable: false)]
    public UsuarioInterno $destinatario;

    #[ORM\Column(length: 2000)]
    public string $texto;

    #[ORM\Column(name: 'creado_en', type: 'instante', insertable: false, updatable: false, generated: 'INSERT')]
    public ?DateTimeImmutable $creadoEn = null;
}
