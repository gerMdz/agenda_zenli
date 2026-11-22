<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\HorariosProfesionales;
use App\Administracion\SesionAdministrativa;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HorarioHabitualAgendaController
{
    #[Route('/api/admin/agenda/turnos/horario-habitual', methods: ['GET'])]
    public function consultar(Request $peticion, SesionAdministrativa $sesion, HorariosProfesionales $horarios): JsonResponse
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
}
