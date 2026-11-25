<?php

declare(strict_types=1);

namespace App\Seguridad;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Webauthn\Exception\WebauthnException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Ingreso con correo y contraseña en JSON por POST /api/admin/sesion.
 *
 * Al tener éxito no responde: la petición sigue hasta el controlador, que devuelve la sesión con
 * el token CSRF nuevo. También es el punto de entrada del firewall: sin sesión, responde 401.
 */
final class AutenticadorAdministrativo extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const RUTA = '/api/admin/sesion';
    public const RUTA_CLAVE_ACCESO = '/api/admin/sesion/clave-acceso';
    /** Hash bcrypt de costo 12 sin contraseña conocida: iguala el tiempo de respuesta de correos inexistentes. */
    private const HASH_FICTICIO = '$2y$12$EC4XFX7Oz4bARSA8nzjewOKSV2XSTtNv8W8yJAcRuOVEfr3.J3R6O';

    public function __construct(
        private readonly ProveedorUsuarios $usuarios,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly LimiteIngresos $limite,
        private readonly AuditoriaAccesos $auditoria,
        private readonly TokenCsrf $csrf,
        private readonly ClavesAcceso $clavesAcceso,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && in_array($request->getPathInfo(), [self::RUTA, self::RUTA_CLAVE_ACCESO], true);
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->csrf->valido($request)) {
            throw new IngresoRechazado(TokenCsrf::MENSAJE, Response::HTTP_FORBIDDEN);
        }
        if ($request->getPathInfo() === self::RUTA_CLAVE_ACCESO) {
            try {
                $cuenta = $this->clavesAcceso->autenticar($request);

                return new SelfValidatingPassport(new UserBadge($cuenta->email, static fn () => $cuenta));
            } catch (InvalidArgumentException|ExceptionInterface|WebauthnException|UserNotFoundException|HttpException) {
                throw new IngresoRechazado('No se pudo ingresar con esa clave de acceso. Volvé a intentar.', Response::HTTP_UNAUTHORIZED);
            }
        }
        try {
            $datos = $request->toArray();
        } catch (JsonException) {
            throw new IngresoRechazado('Ingresá el correo y la contraseña.', Response::HTTP_BAD_REQUEST);
        }
        $email = $datos['email'] ?? null;
        $clave = $datos['clave'] ?? null;
        if (!is_string($email) || strlen($email) > 180 || !is_string($clave) || strlen($clave) > 72
            || str_contains($email, "\0") || str_contains($clave, "\0") || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new IngresoRechazado('Correo o contraseña incorrectos.', Response::HTTP_UNAUTHORIZED);
        }
        $email = strtolower(trim($email));
        $request->attributes->set('_zenli_email', $email);

        $demora = $this->limite->demora($email, $request->getClientIp());
        if ($demora > 0) {
            $this->auditoria->registrarPorEmail('DEMORA', $email);
            throw new IngresoRechazado(self::mensajeDemora($demora), Response::HTTP_TOO_MANY_REQUESTS, $demora);
        }

        return new Passport(
            new UserBadge($email, function (string $email) use ($clave): UsuarioSesion {
                try {
                    return $this->usuarios->loadUserByIdentifier($email);
                } catch (UserNotFoundException $error) {
                    $this->hashers->getPasswordHasher(UsuarioSesion::class)->verify(self::HASH_FICTICIO, $clave);
                    throw $error;
                }
            }),
            new PasswordCredentials($clave),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $usuario = $token->getUser();
        if ($usuario instanceof UsuarioSesion) {
            $this->limite->registrarExito($usuario->email);
            $this->auditoria->registrar('INGRESO', $usuario->id);
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof IngresoRechazado) {
            $respuesta = self::error($exception->getMessageKey(), $exception->estado);
            if ($exception->reintentarEn !== null) {
                $respuesta->headers->set('Retry-After', (string) $exception->reintentarEn);
            }

            return $respuesta;
        }
        $email = $request->attributes->get('_zenli_email');
        if (is_string($email)) {
            $this->limite->registrarFallo($email, $request->getClientIp());
            $this->auditoria->registrarPorEmail('FALLO', $email);
        }

        return self::error('Correo o contraseña incorrectos.', Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::error('Ingresá a tu cuenta.', Response::HTTP_UNAUTHORIZED);
    }

    public static function error(string $mensaje, int $estado): JsonResponse
    {
        return new JsonResponse(['error' => $mensaje], $estado, ['Cache-Control' => 'no-store']);
    }

    private static function mensajeDemora(int $segundos): string
    {
        $minutos = (int) ceil($segundos / 60);

        return sprintf('Demasiados intentos. Probá nuevamente en %d %s.', $minutos, $minutos === 1 ? 'minuto' : 'minutos');
    }
}
