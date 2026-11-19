<?php

declare(strict_types=1);

use App\Agenda\Availability;
use App\Agenda\AvailableSlot;
use App\Agenda\TimeRange;
use App\Agenda\WeeklyHours;

require __DIR__.'/../src/Agenda/TimeRange.php';
require __DIR__.'/../src/Agenda/WeeklyHours.php';
require __DIR__.'/../src/Agenda/AvailableSlot.php';
require __DIR__.'/../src/Agenda/Availability.php';

function expectSame(mixed $expected, mixed $actual, string $scenario): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($scenario.' — esperado '.var_export($expected, true)
            .', obtenido '.var_export($actual, true));
    }
}

function mendozaFixtureTime(string $time): DateTimeImmutable
{
    return new DateTimeImmutable("2030-06-03 $time", new DateTimeZone('America/Argentina/Mendoza'));
}

/** @param list<AvailableSlot> $slots */
function slotLabels(array $slots): array
{
    return array_map(static fn (AvailableSlot $slot): string =>
        $slot->session->start->format('H:i').' '.$slot->professionalId, $slots);
}

$agenda = new Availability();
// En UTC ya es martes, pero en Mendoza todavía es lunes.
$day = new DateTimeImmutable('2030-06-04T01:00:00Z');
$hours = [
    new WeeklyHours('a', 1, '10:00:00', '10:45:00', '2030-06-03', '2030-06-03'),
    new WeeklyHours('a', 1, '10:45:00', '12:00:00', '2030-06-03'),
    new WeeklyHours('b', 1, '10:00:00', '12:00:00', '2030-06-01'),
    new WeeklyHours('fuera-de-vigencia', 1, '10:00:00', '12:00:00', '2030-05-01', '2030-06-02'),
    new WeeklyHours('otro-dia', 2, '10:00:00', '12:00:00', '2030-06-01'),
];

$initial = $agenda->forDay($day, $hours, [], [], 40, 60, 10, 10);
expectSame(['10:10 a', '10:10 b', '11:10 a', '11:10 b'], slotLabels($initial), 'Franjas vigentes y contiguas');
expectSame('10:00', $initial[0]->occupation->start->format('H:i'), 'Inicio con preparación');
expectSame('10:50', $initial[0]->session->end->format('H:i'), 'Fin de la sesión');
expectSame('11:00', $initial[0]->occupation->end->format('H:i'), 'Fin con limpieza');

$spaceBusy = [$initial[0]->occupation];
expectSame(
    ['11:10 a', '11:10 b'],
    slotLabels($agenda->forDay($day, $hours, $spaceBusy, [], 40, 60, 10, 10)),
    'Una ocupación del espacio afecta a ambos profesionales y permite el turno contiguo',
);

$professionalBusy = ['a' => [new TimeRange(mendozaFixtureTime('11:55'), mendozaFixtureTime('12:15'))]];
expectSame(
    ['11:10 b'],
    slotLabels($agenda->forDay($day, $hours, $spaceBusy, $professionalBusy, 40, 60, 10, 10)),
    'Un bloqueo durante la limpieza afecta solo al profesional correspondiente',
);

$spaceBusy[] = new TimeRange(mendozaFixtureTime('11:00'), mendozaFixtureTime('11:05'));
expectSame(
    [],
    $agenda->forDay($day, $hours, $spaceBusy, [], 40, 60, 10, 10),
    'Un bloqueo del espacio durante la preparación agota la disponibilidad',
);
expectSame(
    [],
    $agenda->forDay($day, $hours, [], [], 120, 60, 0, 1),
    'La limpieza debe terminar dentro de la franja',
);
expectSame(
    slotLabels($initial),
    slotLabels($agenda->forDay($day, $hours, [], [], 40, 60, 10, 10)),
    'Al retirar las ocupaciones y los bloqueos se recuperan los horarios',
);

fwrite(STDOUT, "Pruebas de disponibilidad y ocupación correctas.\n");
