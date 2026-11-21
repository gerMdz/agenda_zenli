<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Seguridad\LimiteIngresos;
use InvalidArgumentException;

final readonly class PerfilesProfesionales
{
    public function __construct(private RepositorioPerfilesProfesionales $perfiles, private LimiteIngresos $limite)
    {
    }

    public function crear(mixed $nombre): string
    {
        $nombre = self::nombre($nombre);

        return $this->perfiles->crear($nombre);
    }

    /** `demora_hasta` indica una demora de ingreso vigente de la cuenta vinculada (ISO 8601) o null. */
    public function listar(): array
    {
        $perfiles = $this->perfiles->listar();
        $demoras = $this->limite->demorasPorUsuario(array_values(array_filter(array_column($perfiles, 'usuario_id'))));

        return array_map(static fn (array $p): array => $p + ['demora_hasta' => $demoras[$p['usuario_id'] ?? ''] ?? null], $perfiles);
    }

    public function renombrar(string $id, mixed $nombre): void
    {
        $this->perfiles->renombrar($id, self::nombre($nombre));
    }

    public function cambiarEstado(string $id, mixed $activa): void
    {
        if (!is_bool($activa)) {
            throw new InvalidArgumentException('Indicá si la profesional está activa.');
        }
        $this->perfiles->cambiarEstado($id, $activa);
    }

    private static function nombre(mixed $nombre): string
    {
        if (!is_string($nombre) || preg_match('/\A[^\x00]{1,120}\z/u', trim($nombre)) !== 1) {
            throw new InvalidArgumentException('Ingresá un nombre profesional válido.');
        }

        return trim($nombre);
    }
}