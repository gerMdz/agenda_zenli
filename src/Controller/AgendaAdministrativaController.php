<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\AgendaAdministrativa;
use App\Administracion\ComunicacionAgenda;
use App\Administracion\HorariosProfesionales;
use App\Administracion\PerfilesProfesionales;
use App\Administracion\SesionAdministrativa;
use App\Agenda\TurnoNoDisponible;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        private ComunicacionAgenda $comunicacion,
        private HorariosProfesionales $horarios,
        private PerfilesProfesionales $perfiles,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function consultar(Request $peticion): JsonResponse
    {
        $usuario = $this->sesion->actual();
        try {
            return $this->respuesta($this->agenda->consultar(
                $peticion->query->getString('fecha'),
                self::agendaPropia($usuario),
                $peticion->query->has('hasta') ? $peticion->query->getString('hasta') : null,
            ));
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        }
    }

    #[Route('/configuracion', methods: ['GET'])]
    public function configuracion(Request $peticion): JsonResponse
    {

        return $this->respuesta($this->agenda->configuracion());
    }

    #[Route('/profesionales/{id}/horarios', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['GET', 'PUT'])]
    #[Route('/profesionales/{id}/bloqueos', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['POST'])]
    public function horarios(Request $peticion, string $id): JsonResponse
    {
        $usuario = $this->sesion->actual();
        try {
            if (!$peticion->isMethod('GET')) {
                if ($peticion->isMethod('POST')) {
                    $this->horarios->agregarBloqueo($id, $peticion->toArray(), $usuario['id']);

                    return $this->respuesta(['guardado' => true], 201);
                }
                $this->horarios->guardar($id, $peticion->toArray(), $usuario['id']);
            }

            return $this->respuesta($this->horarios->consultar($id));
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error instanceof JsonException ? 'Revisá los datos de los horarios.' : $error->getMessage()], 422);
        } catch (TurnoNoDisponible $error) {
            return $this->respuesta(['error' => $error->getMessage()], 409);
        } catch (HttpException $error) {
            return $this->respuesta(['error' => $error->getMessage()], $error->getStatusCode());
        }
    }

    #[Route('/espacios', methods: ['POST'])]
    #[Route('/espacios/{id}', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['PUT'])]
    public function guardarEspacio(Request $peticion, ?string $id = null): JsonResponse
    {
        try {
            $this->agenda->guardarEspacio($peticion->toArray(), $id);
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error instanceof JsonException ? 'Revisá los datos del espacio.' : $error->getMessage()], 422);
        } catch (HttpException $error) {
            return $this->respuesta(['error' => $error->getMessage()], $error->getStatusCode());
        }

        return $this->respuesta($this->agenda->configuracion(), $id === null ? 201 : 200);
    }

    #[Route('/preparacion', methods: ['PUT'])]
    public function guardarPreparacion(Request $peticion): JsonResponse
    {
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
        return $this->modificarTurno($peticion);
    }

    #[Route('/preparaciones/{id}', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['PUT', 'DELETE'])]
    public function modificarPreparacion(Request $peticion, string $id): JsonResponse
    {
        $autor = $this->sesion->actual();
        try {
            $this->agenda->modificarPreparacion($id, $peticion->toArray(), $autor['id'], self::agendaPropia($autor), $peticion->isMethod('DELETE'));
            return $this->respuesta(['guardado' => true]);
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error instanceof JsonException ? 'Revisá los datos de preparación.' : $error->getMessage()], 422);
        } catch (TurnoNoDisponible $error) {
            return $this->respuesta(['error' => $error->getMessage()], 409);
        } catch (HttpException $error) {
            return $this->respuesta(['error' => $error->getMessage()], $error->getStatusCode());
        }
    }

    #[Route('/turnos/{id}', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['PUT', 'DELETE'])]
    public function actualizar(Request $peticion, string $id): JsonResponse
    {
        return $this->modificarTurno($peticion, $id);
    }

    private function modificarTurno(Request $peticion, ?string $id = null): JsonResponse
    {
        $autor = $this->sesion->actual();
        try {
            $datos = $peticion->toArray();
            $propia = self::agendaPropia($autor);
            if ($id !== null && $peticion->isMethod('DELETE')) {
                $this->agenda->cancelar($id, $autor['id'], $propia, $datos['revision'] ?? null);

                return $this->respuesta(['cancelado' => true]);
            }
            return $this->respuesta(['id' => $this->agenda->cargar($datos, $autor['id'], $propia, $id)], $id === null ? 201 : 200);
        } catch (JsonException) {
            return $this->respuesta(['error' => 'Revisá los datos del turno.'], 422);
        } catch (TurnoNoDisponible $error) {
            return $this->respuesta(['error' => $error->getMessage()], 409);
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        } catch (HttpException $error) {
            return $this->respuesta(['error' => $error->getMessage()], $error->getStatusCode());
        }
    }

    #[Route('/mensajes', methods: ['GET', 'POST'])]
    public function mensajes(Request $peticion): JsonResponse
    {
        $usuario = $this->sesion->actual();
        if ($peticion->isMethod('POST')) {
            try {
                $datos = $peticion->toArray();
                $this->comunicacion->enviar($usuario['id'], $datos['destinatario'] ?? null, $datos['texto'] ?? null);
            } catch (JsonException) {
                return $this->respuesta(['error' => 'Revisá los datos del mensaje.'], 422);
            } catch (InvalidArgumentException $error) {
                return $this->respuesta(['error' => $error->getMessage()], 422);
            }
        }

        return $this->respuesta($this->comunicacion->consultar($usuario['id']));
    }

    #[Route('/cuentas-profesionales', methods: ['POST'])]
    public function vincularCuenta(Request $peticion): JsonResponse
    {
        try {
            $datos = $peticion->toArray();
            $this->comunicacion->vincularCuenta(
                $datos['profesional'] ?? null, $datos['email'] ?? null, $datos['clave'] ?? null,
                $datos['admin'] ?? false, $datos['nombre'] ?? null, $datos['cuenta_existente'] ?? false,
                $this->sesion->actual()['id'],
            );
        } catch (JsonException) {
            return $this->respuesta(['error' => 'Revisá los datos de la cuenta.'], 422);
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        } catch (UniqueConstraintViolationException) {
            return $this->respuesta(['error' => 'Ya existe una cuenta con ese correo.'], 409);
        }

        return $this->respuesta(['creada' => true], 201);
    }

    #[Route('/profesionales', methods: ['GET', 'POST'])]
    public function crearProfesional(Request $peticion): JsonResponse
    {
        if ($peticion->isMethod('GET')) {
            return $this->respuesta(['profesionales' => $this->perfiles->listar()]);
        }
        try {
            $datos = $peticion->toArray();
            $id = $this->perfiles->crear($datos['nombre'] ?? null);
        } catch (JsonException) {
            return $this->respuesta(['error' => 'Revisá los datos del perfil profesional.'], 422);
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        }

        return $this->respuesta(['id' => $id], 201);
    }

    #[Route('/profesionales/{id}', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['PUT'])]
    #[Route('/profesionales/{id}/estado', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['PUT'])]
    public function modificarProfesional(Request $peticion, string $id): JsonResponse
    {
        try {
            $datos = $peticion->toArray();
            if (str_ends_with($peticion->getPathInfo(), '/estado')) {
                $this->perfiles->cambiarEstado($id, $datos['activa'] ?? null);
            } else {
                $this->perfiles->renombrar($id, $datos['nombre'] ?? null);
            }
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error instanceof JsonException ? 'Revisá los datos del perfil profesional.' : $error->getMessage()], 422);
        } catch (HttpException $error) {
            return $this->respuesta(['error' => $error->getMessage()], $error->getStatusCode());
        }

        return $this->respuesta(['guardado' => true]);
    }

    private static function agendaPropia(array $usuario): ?string
    {
        return $usuario['admin'] ? null : $usuario['profesional_id'];
    }

    private function respuesta(array $datos, int $estado = 200): JsonResponse
    {
        return new JsonResponse($datos, $estado, ['Cache-Control' => 'no-store']);
    }
}
