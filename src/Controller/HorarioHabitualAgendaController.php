<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\HorariosProfesionales;
use App\Administracion\SesionAdministrativa;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HorarioHabitualAgendaController
{
    #[Route('/api/admin/agenda/turnos/horario-habitual', methods: ['GET'])]
    public function consultar(SesionAdministrativa $sesion, HorariosProfesionales $horarios): JsonResponse
    {
        $id = $sesion->actual()['profesional_id'];
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
