<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\AuditoriaDatos;
use App\Administracion\HistorialTurnos;
use App\Seguridad\AuditoriaAccesos;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de auditoría de datos e historial de turnos.
 * Reservados exclusivamente a ROLE_ADMIN por `config/packages/security.yaml`.
 */
#[Route('/api/admin/auditoria')]
final readonly class AuditoriaController
{
    public function __construct(
        private HistorialTurnos $historial,
        private AuditoriaDatos $auditoria,
        private AuditoriaAccesos $accesos,
    ) {
    }

    #[Route('/turnos', methods: ['GET'])]
    public function listarTurnos(Request $peticion): JsonResponse
    {
        try {
            $limite = $peticion->query->has('limite') && $peticion->query->getString('limite') !== ''
                ? filter_var($peticion->query->getString('limite'), FILTER_VALIDATE_INT)
                : 100;
            if ($limite === false) {
                throw new InvalidArgumentException('El límite debe ser un número entero.');
            }

            return $this->respuesta($this->historial->listar([
                'desde' => $peticion->query->getString('desde') ?: null,
                'hasta' => $peticion->query->getString('hasta') ?: null,
                'por' => $peticion->query->getString('por') ?: 'evento',
                'tipo' => $peticion->query->getString('tipo') ?: 'todos',
                'cliente' => $peticion->query->getString('cliente'),
                'limite' => $limite,
            ]));
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        }
    }

    #[Route('/turnos/{id}', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['GET'])]
    public function historialTurno(string $id): JsonResponse
    {
        try {
            return $this->respuesta($this->historial->historialTurno($id));
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        } catch (HttpException $error) {
            return $this->respuesta(['error' => $error->getMessage()], $error->getStatusCode());
        }
    }

    #[Route('/datos', methods: ['GET'])]
    public function datos(): JsonResponse
    {
        return $this->respuesta($this->auditoria->resumen());
    }

    #[Route('/accesos', methods: ['GET'])]
    public function listarAccesos(Request $peticion): JsonResponse
    {
        try {
            $limite = $peticion->query->has('limite') && $peticion->query->getString('limite') !== ''
                ? filter_var($peticion->query->getString('limite'), FILTER_VALIDATE_INT)
                : 100;
            if ($limite === false) {
                throw new InvalidArgumentException('El límite debe ser un número entero.');
            }
            $tipo = $peticion->query->getString('tipo') ?: null;

            return $this->respuesta($this->accesos->listar($limite, $tipo));
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        }
    }

    private function respuesta(array $datos, int $estado = 200): JsonResponse
    {
        return new JsonResponse($datos, $estado, ['Cache-Control' => 'no-store']);
    }
}
