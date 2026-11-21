<?php

declare(strict_types=1);

namespace App\Seguridad;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

final class AccesoDenegado implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return AutenticadorAdministrativo::error('Esta sección requiere permisos de administración.', Response::HTTP_FORBIDDEN);
    }
}
