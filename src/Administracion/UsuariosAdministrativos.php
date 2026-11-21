<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Repository\UsuariosAdministrativosRepository;
use App\Seguridad\AuditoriaAccesos;
use App\Seguridad\LimiteIngresos;
use InvalidArgumentException;

final readonly class UsuariosAdministrativos
{
    public const ROL = 'ROLE_ADMIN';

    public function __construct(
        private UsuariosAdministrativosRepository $repositorio,
        private LimiteIngresos $limite,
        private AuditoriaAccesos $auditoria,
    ) {
    }

    /** Cuentas administradoras; `demora_hasta` indica una demora de ingreso vigente (ISO 8601) o null. */
    public function listar(): array
    {
        $usuarios = $this->repositorio->listar();
        $demoras = $this->limite->demorasPorUsuario(array_column($usuarios, 'id'));

        return array_map(static fn (array $u): array => $u + ['demora_hasta' => $demoras[$u['id']] ?? null], $usuarios);
    }

    /** @param string|null $autor null cuando se crea desde la consola */
    public function crear(string $email, string $clave, ?string $autor = null): void
    {
        $email = self::normalizarEmail($email);
        $this->repositorio->crear($email, self::hashClave($clave), $autor);
    }

    /** Quita la demora de ingreso de cualquier cuenta. Devuelve false si la cuenta no existe. */
    public function desbloquear(string $id, ?string $autor): bool
    {
        $email = $this->repositorio->email($id);
        if ($email === null) {
            return false;
        }
        if ($this->limite->desbloquear($email)) {
            $this->auditoria->registrar('DESBLOQUEO', $id, $autor);
        }

        return true;
    }

    /** Desbloqueo desde la consola, por correo. */
    public function desbloquearPorEmail(string $email): bool
    {
        $id = $this->repositorio->idPorEmail(self::normalizarEmail($email));

        return $id !== null && $this->desbloquear($id, null);
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
