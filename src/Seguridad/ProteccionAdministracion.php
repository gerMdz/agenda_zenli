<?php

declare(strict_types=1);

namespace App\Seguridad;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Controles de sesión de /api/admin que el firewall no cubre.
 *
 * - Antes del firewall (prioridad 9): cierra la sesión tras una hora sin actividad. El recolector
 *   de sesiones de PHP es probabilístico; este control es exacto.
 * - Después del firewall (prioridad 7): exige el token CSRF en toda escritura. Corre después para
 *   que una sesión vencida responda 401 y no 403. El ingreso lo valida el autenticador, porque el
 *   firewall regenera la sesión al ingresar.
 */
final readonly class ProteccionAdministracion
{
    public const INACTIVIDAD = 3600;

    public function __construct(private TokenCsrf $csrf)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
    public function cerrarSesionInactiva(RequestEvent $evento): void
    {
        $peticion = $evento->getRequest();
        if (!$evento->isMainRequest() || !self::administracion($peticion->getPathInfo()) || !$peticion->hasPreviousSession()) {
            return;
        }
        $sesion = $peticion->getSession();
        $sesion->start();
        $ultimoUso = $sesion->getMetadataBag()->getLastUsed();
        if ($ultimoUso > 0 && time() - $ultimoUso > self::INACTIVIDAD) {
            $sesion->invalidate();
        }
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function exigirCsrf(RequestEvent $evento): void
    {
        $peticion = $evento->getRequest();
        if (!$evento->isMainRequest() || !self::administracion($peticion->getPathInfo()) || $peticion->isMethodSafe()
            || ($peticion->isMethod('POST') && in_array($peticion->getPathInfo(), [AutenticadorAdministrativo::RUTA, AutenticadorAdministrativo::RUTA_CLAVE_ACCESO], true))) {
            return;
        }
        if (!$this->csrf->valido($peticion)) {
            $evento->setResponse(AutenticadorAdministrativo::error(TokenCsrf::MENSAJE, 403));
        }
    }

    private static function administracion(string $ruta): bool
    {
        return $ruta === '/api/admin' || str_starts_with($ruta, '/api/admin/');
    }
}
