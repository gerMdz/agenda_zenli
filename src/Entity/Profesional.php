<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'profesionales')]
class Profesional
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private int|string|null $id = null;

    #[ORM\Column(length: 120)]
    private string $nombre;

    #[ORM\Column(type: 'boolean')]
    private bool $activa = true;

    #[ORM\ManyToOne(targetEntity: UsuarioPerfil::class)]
    #[ORM\JoinColumn(name: 'usuario_id', referencedColumnName: 'id', nullable: true)]
    private ?UsuarioPerfil $usuario = null;

    public function __construct(string $nombre) { $this->nombre = $nombre; }
    public function id(): string { return (string) $this->id; }
    public function nombre(): string { return $this->nombre; }
    public function activa(): bool { return $this->activa; }
    public function usuario(): ?UsuarioPerfil { return $this->usuario; }
    public function renombrar(string $nombre): void { $this->nombre = $nombre; }
    public function cambiarEstado(bool $activa): void { $this->activa = $activa; }
    public function vincularCuenta(UsuarioPerfil $usuario): void { $this->usuario = $usuario; }
}