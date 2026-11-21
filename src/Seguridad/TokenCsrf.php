<?php

declare(strict_types=1);

namespace App\Seguridad;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Token CSRF de la administración, enviado por el frontend en la cabecera X-Zenli-CSRF. */
final readonly class TokenCsrf
{
    public const CABECERA = 'X-Zenli-CSRF';
    private const ID = 'administracion';

    public function __construct(private CsrfTokenManagerInterface $tokens)
    {
    }

    public function valor(): string
    {
        return $this->tokens->getToken(self::ID)->getValue();
    }

    public const MENSAJE = 'La sesión del formulario venció. Recargá la página.';

    public function valido(Request $peticion): bool
    {
        $valor = $peticion->headers->get(self::CABECERA);

        return is_string($valor) && $valor !== '' && $this->tokens->isTokenValid(new CsrfToken(self::ID, $valor));
    }
}
