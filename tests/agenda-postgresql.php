<?php

declare(strict_types=1);

use App\Administracion\AgendaAdministrativa;
use App\Administracion\ComunicacionAgenda;
use App\Administracion\HorariosProfesionales;
use App\Agenda\AgendaPostgresql;
use App\Agenda\Disponibilidad;
use App\Agenda\TurnoNoDisponible;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use DoctrineMigrations\Version20261118000100;
use DoctrineMigrations\Version20261118000200;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';

spl_autoload_register(static function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        require __DIR__.'/../src/'.str_replace('\\', '/', substr($clase, 4)).'.php';
    }
});

$socket = $argv[1] ?? '';
if (!preg_match('~\A/tmp/zenli-agenda-pg\.[A-Za-z0-9]+\z~', $socket)
    || !is_file($socket.'/datos/PG_VERSION') || !file_exists($socket.'/.s.PGSQL.5432')) {
    throw new RuntimeException('Ejecutá la prueba con tests/agenda-postgresql.sh.');
}

function conectar(string $socket, string $nombre): Connection
{
    $conexion = DriverManager::getConnection([
        'driver' => 'pdo_pgsql',
        'host' => $socket,
        'user' => 'zenli_pruebas',
        'dbname' => 'postgres',
    ]);
    $conexion->executeStatement('SET application_name = '.$conexion->quote($nombre));
    $conexion->executeStatement("SET statement_timeout = '15s'");

    return $conexion;
}

function entidades(Connection $conexion): EntityManager
{
    App\Doctrine\InstanteType::registrar();
    $configuracion = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../src/Entity'], true);
    $configuracion->enableNativeLazyObjects(true);

    return new EntityManager($conexion, $configuracion);
}

function verificar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

$dia = new DateTimeImmutable('2099-06-03 10:10:00 America/Argentina/Mendoza');
$diaSemana = (int) $dia->format('N');
if (($argv[2] ?? null) === 'interna') {
    $conexion = conectar($socket, 'interna-competidor-'.getmypid());
    $entidades = entidades($conexion);
    $interna = new AgendaAdministrativa($entidades, new HorariosProfesionales($entidades));
    try {
        $interna->cargar(['servicio' => '101', 'espacio' => '103', 'profesionales' => [], 'inicio' => '2099-06-06T10:00', 'duracion' => 40, 'cliente' => 'Prueba'], '101');
        fwrite(STDOUT, "confirmada\n");
    } catch (TurnoNoDisponible) {
        fwrite(STDOUT, "no disponible\n");
    }
    exit;
}
if (isset($argv[2])) {
    $profesionalId = (int) $argv[2];
    $agenda = new AgendaPostgresql(conectar($socket, 'agenda-competidor-'.$profesionalId), new Disponibilidad());
    try {
        $agenda->confirmar(101, $profesionalId, $profesionalId, $dia, 60, 10, 10);
        fwrite(STDOUT, "confirmada\n");
    } catch (TurnoNoDisponible $error) {
        verificar($error->getPrevious() === null, 'El rechazo debe ocurrir al recalcular, antes de intentar el INSERT.');
        fwrite(STDOUT, "no disponible\n");
    }

    exit;
}

$conexion = conectar($socket, 'agenda-prueba');
$agenda = new AgendaPostgresql($conexion, new Disponibilidad());
foreach ([Version20261118000100::class, Version20261118000200::class] as $clase) {
    require __DIR__.'/../migrations/'.substr($clase, strrpos($clase, '\\') + 1).'.php';
    aplicarMigracion($conexion, $clase);
}

foreach (['20261119000100', '20261119000200', '20261119000300', '20261119000400', '20261119000500', '20261119000600', '20261119000700', '20261119000800', '20261119000900', '20261119001000', '20261119001100', '20261119001200', '20261120000100', '20261121000100', '20261121000400', '20261121000500', '20261122000100'] as $version) {
    require __DIR__.'/../migrations/Version'.$version.'.php';
    aplicarMigracion($conexion, 'DoctrineMigrations\\Version'.$version);
}

function contarAuditoria(Connection $conexion, string $tipo): int
{
    return (int) $conexion->fetchOne('SELECT count(*) FROM eventos_acceso WHERE tipo = ?', [$tipo]);
}

function aplicarMigracion(Connection $conexion, string $clase): void
{
    $migracion = new $clase($conexion, new NullLogger());
    $migracion->up(new Schema());
    $conexion->transactional(static function (Connection $conexion) use ($migracion): void {
        foreach ($migracion->getSql() as $consulta) {
            $conexion->executeStatement($consulta->getStatement(), $consulta->getParameters(), $consulta->getTypes());
        }
    });
}

$conexion->executeStatement("INSERT INTO profesionales (id, nombre, activa) VALUES (101, 'Prueba A', TRUE), (102, 'Prueba B', TRUE), (103, 'Inactiva', FALSE)");
$conexion->executeStatement("INSERT INTO clientes (id, nombre) VALUES (101, 'Cliente A'), (102, 'Cliente B')");
$conexion->executeStatement("INSERT INTO tratamientos (id, codigo, nombre) VALUES (101, 'PRUEBA', 'Tratamiento de prueba')");
$conexion->executeStatement("INSERT INTO servicios (id, codigo, nombre, duracion_minutos, publico) VALUES (101, 'PRUEBA', 'Servicio de prueba', 40, TRUE)");
$conexion->executeStatement('INSERT INTO servicio_sesiones (servicio_id, tratamiento_id, orden, participante_orden, duracion_minutos) VALUES (101, 101, 1, 1, 40)');
foreach ([101, 102, 103] as $id) {
    $conexion->executeStatement(
        "INSERT INTO horarios_profesionales (profesional_id, dia_semana, hora_inicio, hora_fin, vigente_desde) VALUES (?, ?, '10:00', '13:00', '2099-01-01')",
        [$id, $diaSemana],
    );
}
$conexion->executeStatement("INSERT INTO bloqueos (recurso_id, inicio, fin) SELECT id, '2099-06-03 12:00-03', '2099-06-03 13:00-03' FROM recursos WHERE codigo = 'ESPACIO_1'");
$conexion->executeStatement("INSERT INTO bloqueos (profesional_id, inicio, fin) VALUES (101, '2099-06-03 11:00-03', '2099-06-03 12:00-03')");

$consultar = static fn (): array => array_map(
    static fn ($turno): string => $turno->sesion->inicio->format('H:i').' '.$turno->profesionalId,
    $agenda->consultar(101, $dia, 60, 10, 10),
);
verificar($consultar() === ['10:10 101', '10:10 102', '11:10 102'], 'La lectura debe aplicar horarios, bloqueos y profesionales activos.');
$parejaId = (int) $conexion->fetchOne("SELECT id FROM servicios WHERE codigo = 'PAREJA'");
verificar($agenda->consultar($parejaId, $dia, 60, 10, 10) === [], 'El masaje en pareja no debe ofrecer turnos.');

$procesos = [];
$conexion->beginTransaction();
try {
    $conexion->fetchOne("SELECT id FROM recursos WHERE codigo = 'ESPACIO_1' FOR UPDATE");
    foreach ([101, 102] as $id) {
        $proceso = proc_open(
            [PHP_BINARY, __FILE__, $socket, (string) $id],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $canales,
        );
        verificar(is_resource($proceso), 'No se pudo iniciar una confirmación concurrente.');
        $procesos[] = [$proceso, $canales];
    }

    // Comprobar que ambos procesos llegaron al bloqueo antes de dejar avanzar a cualquiera.
    $observador = conectar($socket, 'agenda-observador');
    $limite = microtime(true) + 5;
    do {
        $esperando = (int) $observador->fetchOne(<<<'SQL'
            SELECT count(*) FROM pg_stat_activity
            WHERE application_name LIKE 'agenda-competidor-%' AND wait_event_type = 'Lock'
            SQL);
        if ($esperando === 2) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $limite);
    verificar($esperando === 2, 'Las dos confirmaciones debían estar en curso simultáneamente.');
} finally {
    $conexion->rollBack();
}

$resultados = [];
foreach ($procesos as [$proceso, $canales]) {
    $resultados[] = trim(stream_get_contents($canales[1]));
    $errores = stream_get_contents($canales[2]);
    fclose($canales[1]);
    fclose($canales[2]);
    verificar(proc_close($proceso) === 0, 'Error en la confirmación concurrente: '.$errores);
}
sort($resultados);
verificar($resultados === ['confirmada', 'no disponible'], 'Solo una reserva puede ocupar el espacio.');
foreach ([
    'SELECT count(*) FROM reservas' => 'reserva',
    'SELECT count(*) FROM reserva_participantes' => 'participante',
    'SELECT count(*) FROM reserva_sesiones' => 'sesión',
    'SELECT count(*) FROM ocupaciones_profesionales' => 'ocupación profesional',
    'SELECT count(*) FROM ocupaciones_recursos' => 'ocupación del recurso',
    'SELECT count(*) FROM reservas_eventos' => 'evento',
] as $sql => $registro) {
    verificar((int) $conexion->fetchOne($sql) === 1, 'Debe persistir un único registro de '.$registro.'.');
}
verificar($consultar() === ['11:10 102'], 'El horario ocupado debe desaparecer para ambas profesionales.');
$contiguaId = $agenda->confirmar(101, 102, 102, $dia->modify('+1 hour'), 60, 10, 10);
verificar($consultar() === [], 'Debe poder confirmarse la ocupación contigua.');

try {
    $conexion->executeStatement(<<<'SQL'
        UPDATE ocupaciones_recursos
        SET inicio = '2099-06-03 10:59:59-03'
        WHERE sesion_id = (SELECT id FROM reserva_sesiones WHERE reserva_id = ?)
        SQL, [$contiguaId]);
    throw new RuntimeException('PostgreSQL debe rechazar una ocupación superpuesta del espacio.');
} catch (DriverException $error) {
    verificar($error->getSQLState() === '23P01', 'La exclusión GiST debe detectar el solapamiento.');
}

fwrite(STDOUT, "PostgreSQL: dos confirmaciones concurrentes, un solo ganador y sin escrituras parciales.\n");



$conexion->executeStatement("INSERT INTO servicios_portada (id, nombre, descripcion_corta, actualizado_por, duracion_minima, profesionales_requeridos) VALUES (101, 'Sesión interna', 'Prueba', 'prueba', 30, 2), (102, 'Sin configurar', 'Prueba', 'prueba', NULL, NULL)");
$conexion->executeStatement("INSERT INTO usuarios (id, email, password_hash) VALUES (101, 'admin@example.test', 'prueba')");
$conexion->executeStatement("INSERT INTO profesionales (id, nombre) VALUES (104, 'Prueba C'), (105, 'Prueba D')");
$conexion->executeStatement("INSERT INTO recursos (id, codigo, nombre) VALUES (102, 'PRUEBA_2', 'Espacio dos'), (103, 'PRUEBA_3', 'Espacio tres')");
$entidades = entidades($conexion);
$horarios = new HorariosProfesionales($entidades);
$interna = new AgendaAdministrativa($entidades, $horarios);
$turno = [
    'servicio' => '101', 'espacio' => '102', 'profesionales' => ['101', '102'],
    'inicio' => '2099-06-03T12:10', 'duracion' => 40, 'cliente' => 'Cliente ficticio',
];
try {
    $interna->cargar(array_replace($turno, ['duracion' => 19]), '101');
    throw new RuntimeException('Se aceptó un turno menor de 20 minutos.');
} catch (InvalidArgumentException $error) {
    verificar(str_contains($error->getMessage(), '20 minutos'), 'El error explica la duración mínima.');
}
$minimoId = $interna->cargar(array_replace($turno, ['duracion' => 20]), '101');
$interna->cancelar($minimoId, '101', null, 1);
$interna->guardarPreparacion(['modo' => 'por_servicio', 'minutos' => 15, 'servicios' => ['101' => 5]]);
$fallbackId = $interna->cargar(array_replace($turno, ['servicio' => '102', 'inicio' => '2099-08-02T10:00', 'profesionales' => []]), '101');
$fallback = $interna->consultar('2099-08-02')['preparaciones'][0];
verificar(new DateTimeImmutable($fallback['fin']) == new DateTimeImmutable('2099-08-02 10:55 America/Argentina/Mendoza'), 'El servicio sin preparación individual usa el tiempo general.');
$interna->cancelar($fallbackId, '101', null, 1);
$interna->guardarPreparacion(['modo' => 'predeterminado']);
$generalId = $interna->cargar(array_replace($turno, ['inicio' => '2099-08-02T11:00', 'profesionales' => []]), '101');
verificar($interna->consultar('2099-08-02')['preparaciones'] === [], 'La preparación general sin configurar ocupa cero minutos.');
$interna->cancelar($generalId, '101', null, 1);
$interna->guardarPreparacion(['modo' => 'general', 'minutos' => 10]);
$pruebaPreparacion = $interna->cargar(array_replace($turno, ['inicio' => '2099-08-03T10:00', 'profesionales' => ['104']]), '101');
$preparaciones = $interna->consultar('2099-08-03')['preparaciones'] ?? [];
verificar(count($preparaciones) === 1 && $preparaciones[0]['turno_id'] === $pruebaPreparacion && new DateTimeImmutable($preparaciones[0]['inicio']) == new DateTimeImmutable('2099-08-03 10:40 America/Argentina/Mendoza'), 'La preparación debe ser independiente y posterior al turno.');
$prepId = $preparaciones[0]['id'];
verificar($interna->consultar('2099-08-03', '105')['preparaciones'] === [], 'La preparación de otra profesional debe ser privada.');
verificar((int) $conexion->fetchOne('SELECT count(*) FROM ocupaciones_profesionales WHERE turno_admin_id = ? AND fin > ?', [$pruebaPreparacion, '2099-08-03 10:40-03']) === 0, 'La preparación no ocupa a la profesional.');
try {
    $interna->cargar(array_replace($turno, ['inicio' => '2099-08-03T10:40', 'profesionales' => ['105']]), '101');
    throw new RuntimeException('La preparación no bloqueó el espacio.');
} catch (TurnoNoDisponible) {
}
try {
    $interna->modificarPreparacion($prepId, ['revision' => 1], '101', '105', true);
    throw new RuntimeException('Otra profesional eliminó la preparación.');
} catch (HttpException $error) {
    verificar($error->getStatusCode() === 404, 'La preparación ajena no debe revelarse.');
}
$interna->modificarPreparacion($prepId, ['inicio' => '2099-08-03T11:00', 'fin' => '2099-08-03T11:20', 'revision' => 1], '101', '104', false);
try {
    $interna->modificarPreparacion($prepId, ['revision' => 1], '101', '104', true);
    throw new RuntimeException('La revisión obsoleta fue aceptada.');
} catch (TurnoNoDisponible) {
}
$interna->cargar(array_replace($turno, ['inicio' => '2099-08-03T10:00', 'profesionales' => ['104'], 'revision' => 1]), '101', null, $pruebaPreparacion);
verificar($interna->consultar('2099-08-03')['preparaciones'][0]['revision'] === 2, 'Editar el turno debe conservar la preparación editada.');
$interna->modificarPreparacion($prepId, ['revision' => 2], '101', '104', true);
$interna->cargar(array_replace($turno, ['inicio' => '2099-08-03T10:00', 'profesionales' => ['104'], 'revision' => 2]), '101', null, $pruebaPreparacion);
verificar($interna->consultar('2099-08-03')['preparaciones'] === [], 'Editar el turno no debe recrear una preparación eliminada.');
$interna->cancelar($pruebaPreparacion, '101', null, 3);
$pruebaCancelacion = $interna->cargar(array_replace($turno, ['inicio' => '2099-08-04T10:00', 'profesionales' => ['104']]), '101');
$interna->cancelar($pruebaCancelacion, '101', null, 1);
verificar($interna->consultar('2099-08-04')['preparaciones'] === [], 'Cancelar debe liberar la preparación.');
verificar((int) $conexion->fetchOne("SELECT count(*) FROM eventos_turnos_administrativos WHERE turno_id = ? AND tipo IN ('PREP_EDITADA', 'PREP_ELIMINADA')", [$pruebaPreparacion]) === 2, 'Las modificaciones de preparación deben auditarse.');
$legado = $interna->cargar(array_replace($turno, ['inicio' => '2099-08-05T10:00', 'profesionales' => ['104']]), '101');
$conexion->executeStatement('DELETE FROM ocupaciones_recursos WHERE preparacion_id IN (SELECT id FROM preparaciones_agenda WHERE turno_id = ?)', [$legado]);
$conexion->executeStatement('DELETE FROM preparaciones_agenda WHERE turno_id = ?', [$legado]);
$conexion->executeStatement("UPDATE ocupaciones_recursos SET inicio = '2099-08-05 09:50-03' WHERE turno_admin_id = ?", [$legado]);
$conexion->executeStatement("UPDATE ocupaciones_profesionales SET inicio = '2099-08-05 09:50-03' WHERE turno_admin_id = ?", [$legado]);
$interna->cargar(array_replace($turno, ['inicio' => '2099-08-05T10:00', 'profesionales' => ['104'], 'revision' => 1]), '101', null, $legado);
verificar((int) $conexion->fetchOne("SELECT count(*) FROM ocupaciones_profesionales WHERE turno_admin_id = ? AND inicio = '2099-08-05 09:50-03'", [$legado]) === 1, 'La ocupación profesional histórica debe conservarse.');
verificar((int) $conexion->fetchOne("SELECT count(*) FROM ocupaciones_recursos WHERE turno_admin_id = ? AND inicio = '2099-08-05 09:50-03'", [$legado]) === 1, 'La ocupación histórica del espacio debe conservarse.');
$interna->cancelar($legado, '101', null, 2);
$horarios->agregarBloqueo('104', ['fecha' => '2099-07-10', 'todo_el_dia' => true], '101');
$horarios->agregarBloqueo('104', ['fecha' => '2099-07-11', 'hora_inicio' => '10:00', 'hora_fin' => '11:00'], '101');
verificar((int) $conexion->fetchOne("SELECT count(*) FROM bloqueos WHERE profesional_id = 104 AND gestion_horarios AND recurso_id IS NULL AND inicio >= '2099-07-10 00:00-03' AND inicio < '2099-07-12 00:00-03'") === 2, 'Los bloqueos personales no deben asociarse a una sala.');
verificar($interna->consultar('2099-07-11', '105')['bloqueos'] === [], 'El bloqueo por hora no debe exponerse a otra profesional.');
$porHora = array_replace($turno, ['profesionales' => ['105'], 'inicio' => '2099-07-11T10:00']);
$porHoraId = $interna->cargar($porHora, '101');
$interna->cancelar($porHoraId, '101', null, 1);
try {
    $interna->cargar(array_replace($porHora, ['profesionales' => ['104']]), '101');
    throw new RuntimeException('El bloqueo por hora permitió agendar a la profesional bloqueada.');
} catch (TurnoNoDisponible $error) {
    verificar(str_contains($error->getMessage(), 'profesional'), 'El bloqueo por hora debe identificar a la profesional.');
}
$contiguo = $interna->cargar(array_replace($porHora, ['profesionales' => ['104'], 'inicio' => '2099-07-11T11:10']), '101');
$interna->cancelar($contiguo, '101', null, 1);
$contiguo = $interna->cargar(array_replace($porHora, ['profesionales' => ['104'], 'inicio' => '2099-07-11T11:00']), '101');
$interna->cancelar($contiguo, '101', null, 1);
$aislado = array_replace($turno, ['profesionales' => ['105'], 'inicio' => '2099-07-10T10:00']);
$aisladoId = $interna->cargar($aislado, '101');
try {
    $interna->cargar(array_replace($aislado, ['espacio' => '103']), '101');
    throw new RuntimeException('La ocupación de la profesional no fue detectada.');
} catch (TurnoNoDisponible $error) {
    verificar(str_contains($error->getMessage(), 'profesional') && !str_contains($error->getMessage(), 'espacio'), 'El conflicto debe identificar a la profesional: '.$error->getMessage());
}
try {
    $interna->cargar(array_replace($aislado, ['profesionales' => []]), '101');
    throw new RuntimeException('La ocupación del espacio no fue detectada.');
} catch (TurnoNoDisponible $error) {
    verificar(str_contains($error->getMessage(), 'espacio') && !str_contains($error->getMessage(), 'profesional'), 'El conflicto debe identificar el espacio.');
}
$interna->cancelar($aisladoId, '101', null, 1);
try {
    $interna->cargar(array_replace($aislado, ['profesionales' => ['104']]), '101');
    throw new RuntimeException('El bloqueo de la profesional no fue detectado.');
} catch (TurnoNoDisponible $error) {
    verificar(str_contains($error->getMessage(), 'profesional') && !str_contains($error->getMessage(), 'espacio'), 'El bloqueo debe identificar a la profesional.');
}
$conexion->executeStatement("INSERT INTO bloqueos (recurso_id, inicio, fin) VALUES (102, '2099-07-10 10:00-03', '2099-07-10 11:00-03')");
try {
    $interna->cargar($aislado, '101');
    throw new RuntimeException('El bloqueo del espacio no fue detectado.');
} catch (TurnoNoDisponible $error) {
    verificar(str_contains($error->getMessage(), 'espacio') && !str_contains($error->getMessage(), 'profesional'), 'El bloqueo debe identificar el espacio.');
}
$conexion->executeStatement("DELETE FROM bloqueos WHERE recurso_id = 102 AND inicio = '2099-07-10 10:00-03'");
$conexion->executeStatement("INSERT INTO servicios_portada (id, nombre, descripcion_corta, actualizado_por, duracion_minima, profesionales_requeridos) VALUES (103, 'Mínimos de prueba', 'Prueba', 'prueba', 120, 1)");
foreach ([30, 45, 60, 90] as $duracion) {
    $datosMinimos = array_replace($turno, ['servicio' => '103', 'profesionales' => [], 'duracion' => $duracion]);
    $minimosId = $interna->cargar($datosMinimos, '101');
    $consultado = $interna->consultar('2099-06-03')['turnos'][0];
    verificar($consultado['duracion'] === $duracion && $consultado['equipo_incompleto'], 'Cada duración debe admitirse aun por debajo del mínimo configurado.');
    $interna->cargar(array_replace($datosMinimos, ['duracion' => 30, 'revision' => 1]), '101', null, $minimosId);
    $interna->cancelar($minimosId, '101', null, 2);
}
$datosEquipo = array_replace($turno, ['servicio' => '103', 'duracion' => 30]);
$equipoId = $interna->cargar($datosEquipo, '101');
$consultado = $interna->consultar('2099-06-03')['turnos'][0];
verificar(!$consultado['equipo_incompleto'] && count($consultado['equipo']) === 2, 'Un equipo superior al mínimo debe aceptarse sin alerta.');
verificar((int) $conexion->fetchOne('SELECT count(*) FROM ocupaciones_profesionales WHERE turno_admin_id = ?', [$equipoId]) === 2, 'Todas las profesionales adicionales deben quedar ocupadas.');
$interna->cargar(array_replace($datosEquipo, ['revision' => 1]), '101', null, $equipoId);
$interna->cancelar($equipoId, '101', null, 2);
$pendiente = array_replace($turno, ['profesionales' => [], 'inicio' => '2099-06-05T10:00', 'sena_pagada' => true, 'sena_importe_centavos' => '12345']);
$pendienteId = $interna->cargar($pendiente, '101');
$sinEquipo = $interna->consultar('2099-06-05')['turnos'][0];
verificar($sinEquipo['equipo'] === [] && $sinEquipo['equipo_incompleto'] && $sinEquipo['sena_pagada'] && $sinEquipo['sena_importe_centavos'] === '12345', 'El turno sin equipo y su seña deben aparecer en administración.');
verificar($interna->consultar('2099-06-05', '104')['turnos'] === [], 'El turno sin asignar no debe exponerse a profesionales.');
try {
    $interna->cargar(array_replace($pendiente, ['profesionales' => ['104']]), '101');
    throw new RuntimeException('El turno sin equipo no ocupó el espacio.');
} catch (TurnoNoDisponible) {
}
$sinConfigId = $interna->cargar(array_replace($pendiente, ['servicio' => '102', 'espacio' => '103']), '101');
verificar($conexion->fetchOne('SELECT profesionales_requeridos FROM turnos_administrativos WHERE id = ?', [$sinConfigId]) === null, 'El servicio sin configuración conserva la cantidad de profesionales sin definir.');
$interna->cancelar($sinConfigId, '101', null, 1);
$interna->cargar(array_replace($pendiente, ['profesionales' => ['104'], 'revision' => 1, 'sena_pagada' => false]), '101', null, $pendienteId);
$parcial = $interna->consultar('2099-06-05', '104')['turnos'][0];
verificar($parcial['equipo_incompleto'] && !$parcial['sena_pagada'], 'La asignación parcial conserva la alerta y actualiza la seña.');
$evento = json_decode($conexion->fetchOne('SELECT estado_anterior FROM eventos_turnos_administrativos WHERE turno_id = ?', [$pendienteId]), true, flags: JSON_THROW_ON_ERROR);
verificar((string) $evento['sena_importe_centavos'] === '12345' && $evento['profesionales'] === [], 'La auditoría conserva la seña y el equipo anteriores.');
try {
    $interna->cargar(array_replace($pendiente, ['profesionales' => ['104', '105'], 'revision' => 2]), '101', '104', $pendienteId);
    throw new RuntimeException('Una profesional asignó a otra profesional.');
} catch (HttpException $error) {
    verificar($error->getStatusCode() === 403, 'Solo administración puede asignar a otra profesional.');
}
$interna->cargar(array_replace($pendiente, ['profesionales' => ['104', '105'], 'revision' => 2]), '101', null, $pendienteId);
verificar(!$interna->consultar('2099-06-05')['turnos'][0]['equipo_incompleto'], 'La asignación completa elimina la alerta.');
$interna->cancelar($pendienteId, '101', null, 3);

$conexion->beginTransaction();
try {
    $conexion->fetchOne('SELECT id FROM recursos WHERE id = 103 FOR UPDATE');
    $procesosInternos = [];
    for ($i = 0; $i < 2; ++$i) {
        $proceso = proc_open([PHP_BINARY, __FILE__, $socket, 'interna'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $canales);
        verificar(is_resource($proceso), 'No se pudo iniciar el turno concurrente.');
        $procesosInternos[] = [$proceso, $canales];
    }
    $observadorInterno = conectar($socket, 'interna-observador');
    $limite = microtime(true) + 5;
    do {
        $esperando = (int) $observadorInterno->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE 'interna-competidor-%' AND wait_event_type = 'Lock'");
        if ($esperando === 2) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $limite);
    verificar($esperando === 2, 'Los turnos internos deben competir por el bloqueo del espacio.');
} finally {
    $conexion->rollBack();
}
$resultadosInternos = [];
foreach ($procesosInternos as [$proceso, $canales]) {
    $resultadosInternos[] = trim(stream_get_contents($canales[1]));
    $errores = stream_get_contents($canales[2]);
    fclose($canales[1]);
    fclose($canales[2]);
    verificar(proc_close($proceso) === 0, 'Error en turno concurrente: '.$errores);
}
sort($resultadosInternos);
verificar($resultadosInternos === ['confirmada', 'no disponible'], 'Solo un turno sin profesionales puede ocupar el espacio.');
$concurrenteId = $interna->consultar('2099-06-06')['turnos'][0]['id'];
$interna->cancelar($concurrenteId, '101', null, 1);
$id = $interna->cargar($turno, '101');
verificar((int) $conexion->fetchOne('SELECT count(*) FROM ocupaciones_profesionales WHERE turno_admin_id = ?', [$id]) === 2, 'Un turno de equipo debe ocupar a ambas profesionales.');
verificar((int) $conexion->fetchOne('SELECT count(*) FROM ocupaciones_recursos WHERE turno_admin_id = ?', [$id]) === 1, 'El equipo usa un solo espacio.');
verificar($interna->consultar('2099-06-03')['turnos'][0]['profesionales'] === 'Prueba A, Prueba B', 'La agenda debe listar al equipo completo.');

foreach ([
    ['espacio' => '103', 'profesionales' => ['102']],
    ['profesionales' => ['104']],
    ['espacio' => '103', 'inicio' => '2099-06-03T13:10'],
] as $cambios) {
    try {
        $interna->cargar(array_replace($turno, $cambios), '101');
        throw new RuntimeException('La agenda aceptó un turno con conflicto de espacio, profesional o franja.');
    } catch (TurnoNoDisponible) {
    }
}
$interna->guardarPreparacion(['modo' => 'por_servicio', 'servicios' => ['101' => 0]]);
$interna->cargar(array_replace($turno, ['espacio' => '103', 'profesionales' => ['104']]), '101');
verificar(count($interna->consultar('2099-06-03')['turnos']) === 2, 'Otro espacio y otra profesional permiten cargar un turno simultáneo.');
fwrite(STDOUT, "PostgreSQL: agenda interna, preparación, equipo, horarios y espacios verificados.\n");

$individual = $interna->consultar('2099-06-03', '104')['turnos'];
verificar(count($individual) === 1 && $individual[0]['editable'], 'Una profesional solo ve sus turnos y puede editar el individual.');
$compartido = $interna->consultar('2099-06-03', '101')['turnos'];
verificar(count($compartido) === 1 && !$compartido[0]['editable'], 'Un turno en equipo es de solo lectura para la profesional.');
try {
    $interna->cancelar($id, '101', '102', 1);
    throw new RuntimeException('Se permitió cancelar un turno compartido.');
} catch (HttpException $error) {
    verificar($error->getStatusCode() === 403, 'La edición de un equipo requiere administración.');
}
try {
    $interna->cargar(array_replace($turno, ['espacio' => '103', 'revision' => 1]), '101', null, $id);
    throw new RuntimeException('Se permitió mover un turno a un espacio ocupado.');
} catch (TurnoNoDisponible) {
    verificar($interna->consultar('2099-06-03', '101')['turnos'][0]['espacio_id'] === '102', 'El intento fallido debe conservar el turno original.');
}
try {
    $interna->cargar(array_replace($turno, ['inicio' => '2099-06-03T12:15', 'revision' => 1]), '101', null, $id);
    throw new RuntimeException('Se permitió superponer el turno con su preparación.');
} catch (TurnoNoDisponible) {
}
$interna->cargar(array_replace($turno, ['revision' => 1]), '101', null, $id);
verificar((int) $interna->consultar('2099-06-03', '101')['turnos'][0]['revision'] === 2, 'La edición valida el equipo sin chocar con su propia ocupación.');
$interna->cancelar($id, '101', null, 2);
verificar($interna->consultar('2099-06-03', '101')['turnos'] === [], 'Cancelar debe retirar el turno activo.');
verificar((int) $conexion->fetchOne('SELECT count(*) FROM eventos_turnos_administrativos WHERE turno_id = ?', [$id]) === 2, 'Se conserva el historial de cambios.');
verificar((int) $conexion->fetchOne('SELECT count(*) FROM ocupaciones_profesionales WHERE turno_admin_id = ?', [$id]) === 0, 'La cancelación libera a todo el equipo.');
$interna->cargar($turno, '101');

$comunicacion = new ComunicacionAgenda($entidades, new App\Seguridad\AuditoriaAccesos($conexion, new Symfony\Component\HttpFoundation\RequestStack()));
$comunicacion->vincularCuenta('101', 'profesional@example.test', 'Clave local de prueba 1');
$comunicacion->vincularCuenta('102', 'otra@example.test', 'Clave local de prueba 2');
$remitente = (string) $conexion->fetchOne('SELECT usuario_id FROM profesionales WHERE id = 101');
$destinataria = (string) $conexion->fetchOne('SELECT usuario_id FROM profesionales WHERE id = 102');
$comunicacion->enviar($remitente, $destinataria, 'Mensaje de prueba');
verificar($comunicacion->consultar($destinataria)['mensajes'][0]['texto'] === 'Mensaje de prueba', 'La destinataria puede ver el mensaje.');
verificar($comunicacion->consultar('101')['mensajes'] === [], 'Una cuenta ajena al mensaje no puede leerlo.');
fwrite(STDOUT, "PostgreSQL: edición, cancelación, alcance profesional y mensajes privados verificados.\n");

// El firewall autentica con ProveedorUsuarios y el hasher bcrypt; acá se prueba qué cuenta carga.
$proveedor = new App\Seguridad\ProveedorUsuarios($conexion);
$ingresar = static function (string $email, string $clave) use ($proveedor): App\Seguridad\UsuarioSesion {
    $cuenta = $proveedor->loadUserByIdentifier($email);
    verificar(password_verify($clave, $cuenta->getPassword()), 'La contraseña de la cuenta vinculada debe verificarse.');

    return $cuenta;
};
$cuenta = $ingresar('profesional@example.test', 'Clave local de prueba 1');
verificar($cuenta->profesionalId === '101', 'La sesión usa la profesional vinculada.');
verificar($cuenta->getRoles() === ['ROLE_PROFESIONAL'], 'Los permisos de administración siguen separados.');
verificar(contarAuditoria($conexion, 'CUENTA_VINCULADA') === 2, 'Vincular cuentas queda auditado.');

verificar(count($interna->consultar('2099-06-01', null, '2099-06-30')['turnos']) === 2, 'El mes incluye turnos fuera del primer día.');
verificar(count($interna->consultar('2099-06-01', '102', '2099-06-07')['turnos']) === 1, 'La semana conserva el alcance profesional.');
verificar($interna->consultar('2099-06-04', null, '2099-06-30')['turnos'] === [], 'El período excluye los turnos fuera de rango.');
try {
    $interna->consultar('2099-06-01', null, '2099-07-02');
    throw new RuntimeException('La consulta aceptó más de 31 días.');
} catch (InvalidArgumentException) {
}

$comunicacion->vincularCuenta(null, 'dual@example.test', 'Clave local de prueba 3', true, 'Profesional administradora');
$dual = $ingresar('dual@example.test', 'Clave local de prueba 3');
verificar($dual->admin && is_string($dual->profesionalId) && $dual->getRoles() === ['ROLE_ADMIN', 'ROLE_PROFESIONAL'], 'La misma sesión conserva administración e identidad profesional.');

$comunicacion->vincularCuenta('104', 'dual-existente@example.test', 'Clave local de prueba 4', true);
$conexion->executeStatement("INSERT INTO usuarios (email, password_hash, roles) VALUES ('existente@example.test', 'prueba', '[\"ROLE_ADMIN\"]')");
$comunicacion->vincularCuenta(null, 'existente@example.test', null, nombre: 'Profesional con cuenta existente', cuentaExistente: true);
verificar($conexion->fetchOne("SELECT p.nombre FROM profesionales p JOIN usuarios u ON u.id = p.usuario_id WHERE u.email = 'existente@example.test'") === 'Profesional con cuenta existente', 'Se vincula la cuenta administradora existente.');
fwrite(STDOUT, "PostgreSQL: vistas por período, creación de profesionales y cuenta de rol doble verificadas.\n");

$interna->guardarEspacio(['nombre' => 'Cabina nueva', 'detalle' => 'Camilla ajustable'], null);
$nuevoEspacio = array_values(array_filter($interna->configuracion()['espacios'], static fn (array $espacio): bool => $espacio['nombre'] === 'Cabina nueva'))[0];
verificar($nuevoEspacio['detalle'] === 'Camilla ajustable', 'El espacio permite guardar su detalle.');
$interna->guardarEspacio(['nombre' => 'Cabina principal', 'detalle' => 'Entrada a la derecha'], '102');
$espacioEditado = array_values(array_filter($interna->configuracion()['espacios'], static fn (array $espacio): bool => $espacio['id'] === '102'))[0];
verificar($espacioEditado['nombre'] === 'Cabina principal' && $espacioEditado['detalle'] === 'Entrada a la derecha', 'Se puede editar un espacio existente.');
verificar((int) $conexion->fetchOne('SELECT count(*) FROM ocupaciones_recursos WHERE recurso_id = 102 AND turno_admin_id IS NOT NULL') === 1, 'Editar el espacio conserva la ocupación del turno.');

verificar($conexion->fetchOne("SELECT nombre FROM recursos WHERE codigo = 'ESPACIO_1'") === 'Sala principal', 'Se reemplaza el nombre inicial de la sala.');
$ocasional = $dual->profesionalId;
verificar(!$horarios->consultar($ocasional)['por_franjas'], 'Una profesional nueva admite agendado manual inicialmente.');
$pauta = [
    'por_franjas' => true,
    'revision' => 0,
    'franjas' => [
        ['tipo' => 'puntual', 'vigente_desde' => '2099-06-04', 'hora_inicio' => '09:00', 'hora_fin' => '11:00'],
        ['tipo' => 'semanal', 'vigente_desde' => '2099-06-01', 'dia_semana' => 4, 'hora_inicio' => '15:00', 'hora_fin' => '18:00'],
    ],
];
$horarios->guardar($ocasional, $pauta);
$turnoOcasional = array_replace($turno, [
    'espacio' => '103', 'profesionales' => [$ocasional], 'inicio' => '2099-06-04T10:00',
]);
$idOcasional = $interna->cargar($turnoOcasional, '101');
try {
    $interna->cargar(array_replace($turnoOcasional, ['inicio' => '2099-06-11T10:00']), '101');
    throw new RuntimeException('Una franja puntual se repitió otra semana.');
} catch (TurnoNoDisponible) {
}
$interna->cargar(array_replace($turnoOcasional, ['inicio' => '2099-06-11T15:30']), '101');

try {
    $horarios->guardar($ocasional, array_replace($pauta, ['revision' => 1, 'franjas' => [$pauta['franjas'][1]]]));
    throw new RuntimeException('Se retiró una franja con un turno futuro.');
} catch (TurnoNoDisponible) {
    verificar(count($horarios->consultar($ocasional)['franjas']) === 2, 'El cambio con conflicto conserva las franjas originales.');
}
$interna->cancelar($idOcasional, '101', null, 1);
$horarios->guardar($ocasional, array_replace($pauta, ['revision' => 1, 'franjas' => [$pauta['franjas'][1]]]));
verificar(count($horarios->consultar($ocasional)['franjas']) === 1, 'Se puede retirar la franja después de cancelar el turno.');
$horarios->guardar('1', ['por_franjas' => true, 'revision' => 0, 'franjas' => []]);
try {
    $interna->cargar(array_replace($turnoOcasional, ['profesionales' => ['1']]), '101');
    throw new RuntimeException('Una profesional restringida sin franjas quedó disponible.');
} catch (TurnoNoDisponible) {
}
fwrite(STDOUT, "PostgreSQL: horarios semanales, puntuales y cambios sin afectar turnos verificados.\n");

$especial = ['tipo' => 'especial', 'fecha' => '2099-06-18', 'hora_inicio' => '09:00', 'hora_fin' => '11:00'];
$pautaSemanal = array_replace($pauta, ['revision' => 2, 'franjas' => [$pauta['franjas'][1]], 'excepciones' => [$especial]]);
$horarios->guardar($ocasional, $pautaSemanal);
try {
    $interna->cargar(array_replace($turnoOcasional, ['inicio' => '2099-06-18T15:30']), '101');
    throw new RuntimeException('El horario especial no reemplazó la franja semanal de la fecha.');
} catch (TurnoNoDisponible) {
}
$interna->guardarFeriado('2099-06-18', true, '101');
$interna->cargar(array_replace($turnoOcasional, ['inicio' => '2099-06-18T10:00']), '101');
verificar($interna->consultar('2099-06-18', $ocasional)['feriados'] === ['2099-06-18'], 'El feriado se muestra en la agenda propia y permite cargar un turno.');
$interna->cargar(array_replace($turnoOcasional, ['inicio' => '2099-06-25T15:30']), '101');
try {
    $horarios->agregarBloqueo($ocasional, ['fecha' => '2099-06-18', 'todo_el_dia' => true], '101');
    throw new RuntimeException('Se guardó un bloqueo que pisa un turno.');
} catch (TurnoNoDisponible $error) {
    verificar(str_contains($error->getMessage(), '18/06/2099 10:00'), 'El aviso identifica la fecha y hora del turno.');
    verificar($interna->consultar('2099-06-18')['bloqueos'] === [], 'El bloqueo con conflicto se revierte.');
}
$horarios->agregarBloqueo($ocasional, ['fecha' => '2099-06-18', 'hora_inicio' => '10:40', 'hora_fin' => '11:00'], '101');
$horarios->agregarBloqueo('104', ['fecha' => '2099-06-19', 'todo_el_dia' => true], '101');
verificar(count($interna->consultar('2099-06-18')['bloqueos']) === 1, 'El bloqueo aparece junto a los turnos.');
verificar($interna->consultar('2099-06-19', '104')['bloqueos'][0]['todo_el_dia'] === true, 'Un bloqueo de día completo aparece en la agenda propia.');
verificar($interna->consultar('2099-06-19', $ocasional)['bloqueos'] === [], 'No se exponen bloqueos de otras profesionales.');
try {
    $interna->cargar(array_replace($turnoOcasional, ['inicio' => '2099-06-19T14:00', 'profesionales' => ['104']]), '101');
    throw new RuntimeException('El bloqueo de día completo permitió agendar un turno en modo manual.');
} catch (TurnoNoDisponible) {
}
fwrite(STDOUT, "PostgreSQL: excepciones por fecha y bloqueos sin cliente verificados.\n");

$interna->cargar(array_replace($turnoOcasional, [
    'espacio' => '102', 'inicio' => '2099-06-18T09:20', 'profesionales' => ['104'],
]), '101');
$conexion->executeStatement(<<<'SQL'
    INSERT INTO bloqueos (recurso_id, inicio, fin) VALUES (102, '2099-06-18 09:00-03', '2099-06-18 09:10-03')
    SQL);
$libres = $horarios->disponibilidad($ocasional, '2099-06-18');
verificar($libres['hay_franjas'], 'El horario especial define la franja del día.');
$porSala = array_column($libres['espacios'], 'huecos', 'id');
$horas = static fn (array $huecos): array => array_map(static fn (array $hueco): array =>
    array_map(static fn (string $instante): string => (new DateTimeImmutable($instante))
        ->setTimezone(new DateTimeZone('America/Argentina/Mendoza'))->format('H:i'), $hueco), $huecos);
verificar($horas($porSala[102]) === [['inicio' => '09:10', 'fin' => '09:20']], 'El espacio descuenta su bloqueo y el turno de otra profesional.');
verificar($horas($porSala[103]) === [['inicio' => '09:00', 'fin' => '10:00']], 'Otro espacio sigue libre, descontando la ocupación y el bloqueo de la profesional.');
$libresManual = $horarios->disponibilidad('104', '2099-06-18');
verificar($libresManual['jornada_manual'] && $horas(array_column($libresManual['espacios'], 'huecos', 'id')[103])[0] === ['inicio' => '00:00', 'fin' => '09:20'], 'El modo manual calcula huecos desde el inicio de la fecha sin horario especial.');
$bloqueadoManual = $horarios->disponibilidad('104', '2099-06-19');
verificar($bloqueadoManual['jornada_manual'] && !array_filter(array_column($bloqueadoManual['espacios'], 'huecos')), 'El bloqueo de todo el día elimina los huecos de la fecha.');
fwrite(STDOUT, "PostgreSQL: huecos privados por profesional y espacio verificados.\n");
