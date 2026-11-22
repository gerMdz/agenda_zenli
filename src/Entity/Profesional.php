<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
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

    #[ORM\Column(name: 'creado_por', type: 'bigint', nullable: true)]
    private int|string|null $creadoPor = null;

    #[ORM\Column(name: 'creado_en', type: 'instante', nullable: true)]
    private ?DateTimeImmutable $creadoEn = null;

    #[ORM\Column(name: 'actualizado_por', type: 'bigint', nullable: true)]
    private int|string|null $actualizadoPor = null;

    #[ORM\Column(name: 'actualizado_en', type: 'instante', nullable: true)]
    private ?DateTimeImmutable $actualizadoEn = null;

    public function __construct(string $nombre, ?string $autor = null)
    {
        $this->nombre = $nombre;
        $ahora = new DateTimeImmutable();
        $this->creadoPor = $autor;
        $this->creadoEn = $ahora;
        $this->actualizadoPor = $autor;
        $this->actualizadoEn = $ahora;
    }

    public function id(): string { return (string) $this->id; }
    public function nombre(): string { return $this->nombre; }
    public function activa(): bool { return $this->activa; }
    public function usuario(): ?UsuarioPerfil { return $this->usuario; }
    public function creadoPor(): ?string { return $this->creadoPor === null ? null : (string) $this->creadoPor; }
    public function creadoEn(): ?DateTimeImmutable { return $this->creadoEn; }
    public function actualizadoPor(): ?string { return $this->actualizadoPor === null ? null : (string) $this->actualizadoPor; }
    public function actualizadoEn(): ?DateTimeImmutable { return $this->actualizadoEn; }
    public function renombrar(string $nombre, ?string $autor = null): void { $this->nombre = $nombre; $this->registrarModificacion($autor); }
    public function cambiarEstado(bool $activa, ?string $autor = null): void { $this->activa = $activa; $this->registrarModificacion($autor); }
    public function vincularCuenta(UsuarioPerfil $usuario, ?string $autor = null): void { $this->usuario = $usuario; $this->registrarModificacion($autor); }

    private function registrarModificacion(?string $autor): void
    {
        $this->actualizadoPor = $autor;
        $this->actualizadoEn = new DateTimeImmutable();
    }
}