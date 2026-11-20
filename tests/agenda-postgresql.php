<?php

declare(strict_types=1);

use App\Administracion\AgendaAdministrativa;
use App\Agenda\AgendaPostgresql;
use App\Agenda\Disponibilidad;
use App\Agenda\TurnoNoDisponible;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261118000100;
use DoctrineMigrations\Version20261118000200;
use Psr\Log\NullLogger;

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

function verificar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

$dia = new DateTimeImmutable('2099-06-03 10:10:00 America/Argentina/Mendoza');
$diaSemana = (int) $dia->format('N');
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

foreach (['20261119000300', '20261119000600'] as $version) {
    require __DIR__.'/../migrations/Version'.$version.'.php';
    aplicarMigracion($conexion, 'DoctrineMigrations\\Version'.$version);
}

$conexion->executeStatement("INSERT INTO servicios_portada (id, nombre, descripcion_corta, actualizado_por) VALUES (101, 'Sesión interna', 'Prueba', 'prueba')");
$conexion->executeStatement("INSERT INTO usuarios (id, email, password_hash) VALUES (101, 'admin@example.test', 'prueba')");
$conexion->executeStatement("INSERT INTO profesionales (id, nombre) VALUES (104, 'Prueba C')");
$conexion->executeStatement("INSERT INTO recursos (id, codigo, nombre) VALUES (102, 'PRUEBA_2', 'Espacio dos'), (103, 'PRUEBA_3', 'Espacio tres')");
$interna = new AgendaAdministrativa($conexion);
$turno = [
    'servicio' => '101', 'espacio' => '102', 'profesionales' => ['101', '102'],
    'inicio' => '2099-06-03T12:10', 'duracion' => 40, 'cliente' => 'Cliente ficticio',
];
$interna->guardarPreparacion(['modo' => 'general', 'minutos' => 10]);
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
