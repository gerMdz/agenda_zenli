<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\HorariosProfesionales;
use App\Administracion\SesionAdministrativa;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HorarioHabitualAgendaController
{
    #[Route('/api/admin/agenda/turnos/horario-habitual', methods: ['GET'])]
    public function consultar(Request $peticion, SesionAdministrativa $sesion, HorariosProfesionales $horarios): JsonResponse
    {
        $id = self::profesionalSolicitada($peticion, $sesion);
        $horario = $id === null ? null : $horarios->consultar($id);
        if ($horario !== null) {
            unset($horario['revision']);
            foreach (['franjas', 'excepciones'] as $grupo) {
                foreach ($horario[$grupo] as &$fila) {
                    foreach ($fila as $campo => &$valor) {
                        if ($valor instanceof DateTimeInterface) {
                            $valor = $valor->format(str_starts_with($campo, 'hora_') ? 'H:i:s' : 'Y-m-d');
                        }
                    }
                    unset($valor);
                }
                unset($fila);
            }
        }

        return new JsonResponse(['horario' => $horario], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/admin/agenda/turnos/disponibilidad', methods: ['GET'])]
    public function disponibilidad(Request $peticion, SesionAdministrativa $sesion, HorariosProfesionales $horarios): JsonResponse
    {
        $id = self::profesionalSolicitada($peticion, $sesion);
        if ($id === null) {
            throw new HttpException(422, 'Elegí una profesional.');
        }

        try {
            $resultado = $horarios->disponibilidad($id, $peticion->query->getString('fecha'));
        } catch (InvalidArgumentException $error) {
            return new JsonResponse(['error' => $error->getMessage()], 422, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse($resultado, headers: ['Cache-Control' => 'no-store']);
    }

    private static function profesionalSolicitada(Request $peticion, SesionAdministrativa $sesion): ?string
    {
        $usuario = $sesion->actual();
        $id = $usuario['profesional_id'];
        if ($peticion->query->has('profesional')) {
            if (!$usuario['admin']) {
                throw new HttpException(403, 'Solo administración puede consultar el horario de otra profesional.');
            }
            $id = $peticion->query->getString('profesional');
            if (preg_match('/\A[1-9][0-9]{0,17}\z/', $id) !== 1) {
                throw new HttpException(422, 'Elegí una profesional válida.');
            }
        }

        return $id;
    }
}
