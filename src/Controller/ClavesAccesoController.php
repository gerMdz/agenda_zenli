<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\SesionAdministrativa;
use App\Seguridad\ClavesAcceso;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Webauthn\Exception\WebauthnException;

#[Route('/api/admin')]
final readonly class ClavesAccesoController
{
    public function __construct(private ClavesAcceso $claves, private SesionAdministrativa $sesion)
    {
    }

    #[Route('/sesion/clave-acceso/opciones', methods: ['POST'])]
    public function opcionesIngreso(Request $peticion): JsonResponse
    {
        try {
            return self::respuesta($this->claves->opcionesIngreso($peticion));
        } catch (HttpException $error) {
            return self::error($error);
        }
    }

    #[Route('/claves-acceso', methods: ['GET'])]
    public function listar(): JsonResponse
    {
        return self::respuesta(['claves' => $this->claves->listar($this->sesion->actual()['id'])]);
    }

    #[Route('/claves-acceso/opciones', methods: ['POST'])]
    public function opcionesAlta(Request $peticion): JsonResponse
    {
        try {
            $clave = $peticion->toArray()['clave'] ?? null;
            if (!is_string($clave) || $clave === '') {
                return self::respuesta(['error' => 'Ingresá tu contraseña de Zenli.'], 422);
            }

            return self::respuesta($this->claves->opcionesAlta($peticion, $this->sesion->cuenta(), $clave));
        } catch (HttpException $error) {
            return self::error($error);
        } catch (JsonException) {
            return self::respuesta(['error' => 'Ingresá tu contraseña de Zenli.'], 422);
        }
    }

    #[Route('/claves-acceso', methods: ['POST'])]
    public function registrar(Request $peticion): JsonResponse
    {
        try {
            $this->claves->registrar($peticion, $this->sesion->cuenta());

            return self::respuesta(['claves' => $this->claves->listar($this->sesion->actual()['id'])], 201);
        } catch (HttpException $error) {
            return self::error($error);
        } catch (InvalidArgumentException|ExceptionInterface|WebauthnException) {
            return self::respuesta(['error' => 'No se pudo registrar la clave. Volvé a iniciar el proceso.'], 422);
        }
    }

    #[Route('/claves-acceso/{id}', requirements: ['id' => '[0-9a-f]{64}'], methods: ['DELETE'])]
    public function revocar(string $id): JsonResponse
    {
        $cuenta = $this->sesion->actual()['id'];
        try {
            $this->claves->revocar($cuenta, $id);

            return self::respuesta(['claves' => $this->claves->listar($cuenta)]);
        } catch (HttpException $error) {
            return self::error($error);
        }
    }

    private static function error(HttpException $error): JsonResponse
    {
        return new JsonResponse(['error' => $error->getMessage()], $error->getStatusCode(), $error->getHeaders() + ['Cache-Control' => 'no-store']);
    }

    private static function respuesta(array $datos, int $estado = 200): JsonResponse
    {
        return new JsonResponse($datos, $estado, ['Cache-Control' => 'no-store']);
    }
}
