<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Seguridad\TokenCsrf;
use App\Seguridad\UsuarioSesion;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Acceso de los controladores a la cuenta autenticada por el firewall de Symfony.
 *
 * La autenticación, los permisos por ruta (access_control) y el CSRF de las escrituras se aplican
 * antes del controlador; ver config/packages/security.yaml y App\Seguridad.
 */
final readonly class SesionAdministrativa
{
    public function __construct(private TokenStorageInterface $tokens, private TokenCsrf $csrf)
    {
    }

    public function token(): string
    {
        return $this->csrf->valor();
    }

    public function cuenta(): ?UsuarioSesion
    {
        $usuario = $this->tokens->getToken()?->getUser();

        return $usuario instanceof UsuarioSesion ? $usuario : null;
    }

    /** @return array{id: string, email: string, admin: bool, profesional_id: ?string}|null */
    public function usuario(): ?array
    {
        return $this->cuenta()?->datos();
    }

    /**
     * Cuenta de la petición actual. El firewall ya exigió la sesión; la excepción solo cubre una
     * ruta que quede fuera de access_control por error.
     *
     * @return array{id: string, email: string, admin: bool, profesional_id: ?string}
     */
    public function actual(): array
    {
        return $this->usuario() ?? throw new HttpException(401, 'Ingresá a tu cuenta.');
    }
}
