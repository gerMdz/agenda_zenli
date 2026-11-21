<?php

declare(strict_types=1);

namespace App\Seguridad;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Ingreso rechazado antes de verificar la contraseña: datos inválidos o demora vigente. */
final class IngresoRechazado extends AuthenticationException
{
    public function __construct(private readonly string $mensaje, public readonly int $estado, public readonly ?int $reintentarEn = null)
    {
        parent::__construct($mensaje);
    }

    public function getMessageKey(): string
    {
        return $this->mensaje;
    }

    public function __serialize(): array
    {
        return [$this->mensaje, $this->estado, $this->reintentarEn, parent::__serialize()];
    }

    public function __unserialize(array $datos): void
    {
        [$this->mensaje, $this->estado, $this->reintentarEn, $padre] = $datos;
        parent::__unserialize($padre);
    }
}
