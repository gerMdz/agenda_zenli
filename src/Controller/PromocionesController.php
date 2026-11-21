<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\PromocionesPortada;
use App\Administracion\SesionAdministrativa;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PromocionesController
{
    public function __construct(private PromocionesPortada $promociones, private SesionAdministrativa $sesion)
    {
    }

    #[Route('/api/promociones', methods: ['GET'])]
    public function publicas(): JsonResponse
    {
        return $this->respuesta(['promociones' => $this->promociones->listar(true)]);
    }

    #[Route('/api/admin/promociones', methods: ['GET'])]
    public function listar(Request $peticion): JsonResponse
    {
        $this->sesion->exigir($peticion);
        return $this->respuesta(['promociones' => $this->promociones->listar()]);
    }

    #[Route('/api/admin/promociones', methods: ['POST'])]
    #[Route('/api/admin/promociones/{id}', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['PUT'])]
    public function guardar(Request $peticion, ?int $id = null): JsonResponse
    {
        $usuario = $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
        try {
            $this->promociones->guardar($id, $peticion->toArray(), $usuario['id']);
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error instanceof JsonException ? 'Revisá los datos de la promoción.' : $error->getMessage()], 422);
        }
        return $this->respuesta(['promociones' => $this->promociones->listar()], $id === null ? 201 : 200);
    }

    #[Route('/api/admin/promociones/{id}/visibilidad', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['PATCH'])]
    public function visibilidad(Request $peticion, int $id): JsonResponse
    {
        $usuario = $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
        try {
            $datos = $peticion->toArray();
            if (!isset($datos['visible']) || !is_bool($datos['visible'])) {
                throw new InvalidArgumentException('Elegí una visibilidad válida.');
            }
            $this->promociones->visibilidad($id, $datos['visible'], $usuario['id']);
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error instanceof JsonException ? 'Revisá los datos de la promoción.' : $error->getMessage()], 422);
        }
        return $this->respuesta(['promociones' => $this->promociones->listar()]);
    }

    #[Route('/api/admin/promociones/{id}', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['DELETE'])]
    public function eliminar(Request $peticion, int $id): JsonResponse
    {
        $usuario = $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
        try {
            $this->promociones->eliminar($id, $usuario['id']);
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        }
        return $this->respuesta(['promociones' => $this->promociones->listar()]);
    }

    private function respuesta(array $datos, int $estado = 200): JsonResponse
    {
        return new JsonResponse($datos, $estado, ['Cache-Control' => 'no-store']);
    }
}