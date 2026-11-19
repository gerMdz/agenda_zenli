<?php

declare(strict_types=1);

namespace App\Agenda;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Availability
{
    /**
     * Consulta para una sesión individual en el espacio compartido.
     *
     * @param list<WeeklyHours> $weeklyHours Franjas de profesionales activos y habilitados para el tratamiento.
     * @param list<TimeRange> $spaceBusy Bloqueos y ocupaciones activas del espacio.
     * @param array<string, list<TimeRange>> $professionalBusy Bloqueos y ocupaciones activas por profesional.
     * @return list<AvailableSlot>
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
                $windowsByProfessional[$hours->professionalId][] = $window;
            }
        }

        $slots = [];
        foreach ($windowsByProfessional as $professionalId => $windows) {
            $busy = array_merge($spaceBusy, $professionalBusy[$professionalId] ?? []);
            foreach ($this->mergeWindows($windows) as $window) {
                for ($start = $window->start->add($preparation); $start < $window->end; $start = $start->add($step)) {
                    $end = $start->add($duration);
                    $occupiedUntil = $end->add($cleanup);
                    if ($occupiedUntil > $window->end) {
                        break;
                    }

                    $occupation = new TimeRange($start->sub($preparation), $occupiedUntil);
                    if ($this->isFree($occupation, $busy)) {
                        $slots[] = new AvailableSlot((string) $professionalId, new TimeRange($start, $end), $occupation);
                    }
                }
            }
        }

        usort($slots, static fn (AvailableSlot $a, AvailableSlot $b): int =>
            [$a->session->start, $a->professionalId] <=> [$b->session->start, $b->professionalId]
        );

        return $slots;
    }

    /** @param list<TimeRange> $busy */
    private function isFree(TimeRange $candidate, array $busy): bool
    {
        foreach ($busy as $range) {
            if ($candidate->overlaps($range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<TimeRange> $windows
     * @return list<TimeRange>
     */
    private function mergeWindows(array $windows): array
    {
        usort($windows, static fn (TimeRange $a, TimeRange $b): int => $a->start <=> $b->start);

        $merged = [];
        foreach ($windows as $window) {
            $last = array_key_last($merged);
            if ($last === null || $merged[$last]->end < $window->start) {
                $merged[] = $window;
            } elseif ($merged[$last]->end < $window->end) {
                $merged[$last] = new TimeRange($merged[$last]->start, $window->end);
            }
        }

        return $merged;
    }
}
