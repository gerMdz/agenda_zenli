<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Repository\UsuariosAdministrativosRepository;
use InvalidArgumentException;

final readonly class UsuariosAdministrativos
{
    public const ROL = 'ROLE_ADMIN';

    public function __construct(private UsuariosAdministrativosRepository $repositorio)
    {
    }

    public function listar(): array
    {
        return $this->repositorio->listar();
    }

    public function crear(string $email, string $clave): void
    {
        $email = self::normalizarEmail($email);
        $this->repositorio->crear($email, self::hashClave($clave));
    }

    public function actualizar(string $id, bool $activo, string $clave, string $autor, string $huella): bool
    {
        if ($id === $autor && !$activo) {
            return false;
        }
        $hash = $clave === '' ? null : self::hashClave($clave);

        return $this->repositorio->actualizar($id, $activo, $hash, $autor, $huella);
    }

    public static function normalizarEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ingresá un correo válido.');
        }

        return $email;
    }

    public static function hashClave(string $clave): string
    {
        if (strlen($clave) < 12 || strlen($clave) > 72 || str_contains($clave, "\0")) {
            throw new InvalidArgumentException('La contraseña debe tener entre 12 y 72 bytes.');
        }

        return password_hash($clave, PASSWORD_DEFAULT);
    }
}
