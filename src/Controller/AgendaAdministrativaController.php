<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\AgendaAdministrativa;
use App\Administracion\SesionAdministrativa;
use App\Agenda\TurnoNoDisponible;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/agenda')]
final readonly class AgendaAdministrativaController
{
    public function __construct(
        private SesionAdministrativa $sesion,
        private AgendaAdministrativa $agenda,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function consultar(Request $peticion): JsonResponse
    {
        $this->sesion->exigir($peticion);
        try {
            return $this->respuesta($this->agenda->consultar($peticion->query->getString('fecha')));
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        }
    }

    #[Route('/preparacion', methods: ['PUT'])]
    public function guardarPreparacion(Request $peticion): JsonResponse
    {
        $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
        try {
            $this->agenda->guardarPreparacion($peticion->toArray());
        } catch (JsonException) {
            return $this->respuesta(['error' => 'Revisá los datos de preparación.'], 422);
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        }

        return $this->respuesta(['guardado' => true]);
    }

    #[Route('/turnos', methods: ['POST'])]
    public function cargar(Request $peticion): JsonResponse
    {
        $autor = $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
        try {
            return $this->respuesta(['id' => $this->agenda->cargar($peticion->toArray(), $autor['id'])], 201);
        } catch (JsonException) {
            return $this->respuesta(['error' => 'Revisá los datos del turno.'], 422);
        } catch (TurnoNoDisponible $error) {
            return $this->respuesta(['error' => $error->getMessage()], 409);
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        }
    }

    private function respuesta(array $datos, int $estado = 200): JsonResponse
    {
        return new JsonResponse($datos, $estado, ['Cache-Control' => 'no-store']);
    }
}
