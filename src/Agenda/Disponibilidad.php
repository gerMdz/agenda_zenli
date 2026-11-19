<?php

declare(strict_types=1);

namespace App\Agenda;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Disponibilidad
{
    /**
     * Consulta para una sesión individual en el espacio compartido.
     *
     * @param list<HorarioSemanal> $weeklyHours Franjas de profesionales activos y habilitados para el tratamiento.
     * @param list<Intervalo> $spaceBusy Bloqueos y ocupaciones activas del espacio.
     * @param array<string, list<Intervalo>> $professionalBusy Bloqueos y ocupaciones activas por profesional.
     * @return list<TurnoDisponible>
     */
    public function forDay(
        DateTimeImmutable $day,
        array $weeklyHours,
        array $spaceBusy,
        array $professionalBusy,
        int $durationMinutes,
        int $stepMinutes,
        int $preparationMinutes,
        int $cleanupMinutes,
    ): array {
        if ($durationMinutes < 1 || $stepMinutes < 1 || $preparationMinutes < 0 || $cleanupMinutes < 0) {
            throw new InvalidArgumentException('La duración y el paso deben ser positivos. Los márgenes no pueden ser negativos.');
        }

        $day = $day->setTimezone(new DateTimeZone('America/Argentina/Mendoza'));
        $duration = new DateInterval("PT{$durationMinutes}M");
        $step = new DateInterval("PT{$stepMinutes}M");
        $preparation = new DateInterval("PT{$preparationMinutes}M");
        $cleanup = new DateInterval("PT{$cleanupMinutes}M");
        $windowsByProfessional = [];

        foreach ($weeklyHours as $hours) {
            $window = $hours->onDay($day);
            if ($window !== null) {
                $windowsByProfessional[$hours->profesionalId][] = $window;
            }
        }

        $slots = [];
        foreach ($windowsByProfessional as $professionalId => $windows) {
            $busy = array_merge($spaceBusy, $professionalBusy[$professionalId] ?? []);
            foreach ($this->mergeWindows($windows) as $window) {
                for ($start = $window->inicio->add($preparation); $start < $window->fin; $start = $start->add($step)) {
                    $end = $start->add($duration);
                    $occupiedUntil = $end->add($cleanup);
                    if ($occupiedUntil > $window->fin) {
                        break;
                    }

                    $occupation = new Intervalo($start->sub($preparation), $occupiedUntil);
                    if ($this->isFree($occupation, $busy)) {
                        $slots[] = new TurnoDisponible((string) $professionalId, new Intervalo($start, $end), $occupation);
                    }
                }
            }
        }

        usort($slots, static fn (TurnoDisponible $a, TurnoDisponible $b): int =>
            [$a->sesion->inicio, $a->profesionalId] <=> [$b->sesion->inicio, $b->profesionalId]
        );

        return $slots;
    }

    /** @param list<Intervalo> $busy */
    private function isFree(Intervalo $candidate, array $busy): bool
    {
        foreach ($busy as $range) {
            if ($candidate->seSuperpone($range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Intervalo> $windows
     * @return list<Intervalo>
     */
    private function mergeWindows(array $windows): array
    {
        usort($windows, static fn (Intervalo $a, Intervalo $b): int => $a->inicio <=> $b->inicio);

        $merged = [];
        foreach ($windows as $window) {
            $last = array_key_last($merged);
            if ($last === null || $merged[$last]->fin < $window->inicio) {
                $merged[] = $window;
            } elseif ($merged[$last]->fin < $window->fin) {
                $merged[$last] = new Intervalo($merged[$last]->inicio, $window->fin);
            }
        }

        return $merged;
    }
}
