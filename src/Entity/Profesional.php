<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Única entidad de la tabla `profesionales`: perfil, cuenta interna opcional,
 * presentación en portada y revisión de sus horarios.
 */
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

    /** Cuenta interna opcional; los permisos de administración dependen de sus roles. */
    #[ORM\ManyToOne(targetEntity: UsuarioInterno::class)]
    #[ORM\JoinColumn(name: 'usuario_id', referencedColumnName: 'id', nullable: true)]
    private ?UsuarioInterno $usuario = null;

    #[ORM\Column(name: 'visible_portada', type: 'boolean')]
    private bool $visiblePortada = false;

    #[ORM\Column(name: 'orden_portada', type: 'integer', nullable: true)]
    private ?int $ordenPortada = null;

    /** Null conserva el comportamiento previo: restringido solo si tiene franjas cargadas. */
    #[ORM\Column(name: 'horario_por_franjas', type: 'boolean', nullable: true)]
    private ?bool $horarioPorFranjas = null;

    #[ORM\Column(name: 'horarios_revision', type: 'integer')]
    private int $horariosRevision = 0;

    #[ORM\Column(name: 'horarios_actualizado_por', type: 'bigint', nullable: true)]
    private int|string|null $horariosActualizadoPor = null;

    #[ORM\Column(name: 'horarios_actualizado_en', type: 'instante', nullable: true)]
    private ?DateTimeImmutable $horariosActualizadoEn = null;

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
    public function usuario(): ?UsuarioInterno { return $this->usuario; }
    public function ordenPortada(): ?int { return $this->ordenPortada; }
    public function creadoPor(): ?string { return $this->creadoPor === null ? null : (string) $this->creadoPor; }
    public function creadoEn(): ?DateTimeImmutable { return $this->creadoEn; }
    public function actualizadoPor(): ?string { return $this->actualizadoPor === null ? null : (string) $this->actualizadoPor; }
    public function actualizadoEn(): ?DateTimeImmutable { return $this->actualizadoEn; }

    public function renombrar(string $nombre, ?string $autor = null): void { $this->nombre = $nombre; $this->registrarModificacion($autor); }
    public function cambiarEstado(bool $activa, ?string $autor = null): void { $this->activa = $activa; $this->registrarModificacion($autor); }
    public function vincularCuenta(UsuarioInterno $usuario, ?string $autor = null): void { $this->usuario = $usuario; $this->registrarModificacion($autor); }
    public function cambiarVisibilidadPortada(bool $visible, ?string $autor = null): void { $this->visiblePortada = $visible; $this->registrarModificacion($autor); }

    /** Solo asigna la posición; quien reordena decide qué perfiles registran la modificación. */
    public function ubicarEnPortada(int $orden): void { $this->ordenPortada = $orden; }

    /** Cambia el modo de horario y avanza la revisión que protege las ediciones simultáneas. */
    public function cambiarModoHorario(?bool $porFranjas, ?string $autor): void
    {
        $this->horarioPorFranjas = $porFranjas;
        $this->registrarCambioHorarios($autor);
    }

    public function registrarCambioHorarios(?string $autor): void
    {
        ++$this->horariosRevision;
        $this->horariosActualizadoPor = $autor;
        $this->horariosActualizadoEn = new DateTimeImmutable();
    }

    public function registrarModificacion(?string $autor, ?DateTimeImmutable $en = null): void
    {
        $this->actualizadoPor = $autor;
        $this->actualizadoEn = $en ?? new DateTimeImmutable();
    }
}
