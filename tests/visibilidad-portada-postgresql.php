<?php

declare(strict_types=1);

use App\Administracion\ConfiguracionPortada;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261120000300;
use Psr\Log\NullLogger;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/Administracion/ConfiguracionPortada.php';
require __DIR__.'/../migrations/Version20261120000300.php';

$socket = $argv[1] ?? '';
if (!preg_match('~\A/tmp/zenli-agenda-pg\.[A-Za-z0-9]+\z~', $socket) || !is_file($socket.'/datos/PG_VERSION') || !file_exists($socket.'/.s.PGSQL.5432')) {
    throw new RuntimeException('Ejecutá la prueba con tests/visibilidad-portada-postgresql.sh.');
}
$conexion = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $socket, 'user' => 'zenli_pruebas', 'dbname' => 'postgres']);
$conexion->executeStatement("CREATE TABLE configuracion_portada (id SMALLINT PRIMARY KEY, telefono VARCHAR(16) NOT NULL, imagen BYTEA, imagen_tipo VARCHAR(20), imagen_version CHAR(64), instagram TEXT, facebook TEXT, direccion TEXT NOT NULL DEFAULT 'Dirección', horarios TEXT NOT NULL DEFAULT 'Horario', solo_imagen BOOLEAN NOT NULL DEFAULT FALSE, actualizado_por BIGINT NOT NULL, actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now())");
$conexion->executeStatement("INSERT INTO configuracion_portada (id, telefono, actualizado_por) VALUES (1, '+5492616356192', 1)");
$migracion = new Version20261120000300($conexion, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $consulta) {
    $conexion->executeStatement($consulta->getStatement(), $consulta->getParameters(), $consulta->getTypes());
}
$verificar = static function (bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
};
$portada = new ConfiguracionPortada($conexion);
$inicial = array_fill_keys(ConfiguracionPortada::SECCIONES, true);
$verificar($portada->consultar()['visibilidad'] === $inicial, 'Una fila existente debe comenzar visible.');
foreach (ConfiguracionPortada::SECCIONES as $seccion) {
    $portada->cambiarVisibilidad($seccion, false, '2');
    $inicial[$seccion] = false;
    $verificar((new ConfiguracionPortada($conexion))->consultar()['visibilidad'] === $inicial, 'Cada cambio debe persistir independientemente.');
}
$portada->guardar('+5492616356192', null, false, '1', null, null, 'Dirección conservada', 'Horario conservado');
$portada->cambiarModo(true, '1');
$portada->cambiarVisibilidad('servicios', true, '2');
$inicial['servicios'] = true;
$datos = $portada->consultar();
$verificar($datos['visibilidad'] === $inicial && $datos['direccion'] === 'Dirección conservada' && $datos['horarios'] === 'Horario conservado' && $datos['solo_imagen'], 'Debe conservar datos y modo al cambiar visibilidad.');
$verificar((string) $conexion->fetchOne('SELECT actualizado_por FROM configuracion_portada WHERE id = 1') === '2', 'Debe registrar el autor.');
try {
    $portada->cambiarVisibilidad('profesionales', true, '2');
    throw new RuntimeException('Debe rechazar secciones desconocidas.');
} catch (InvalidArgumentException) {
}
$conexion->executeStatement('DELETE FROM configuracion_portada');
$verificar($portada->consultar()['visibilidad'] === array_fill_keys(ConfiguracionPortada::SECCIONES, true), 'Sin fila, todas son visibles.');
$portada->cambiarVisibilidad('consultas', false, '1');
$verificar(!$portada->consultar()['visibilidad']['consultas'] && $portada->consultar()['visibilidad']['horarios'], 'Crear configuración debe preservar los otros valores predeterminados.');
fwrite(STDOUT, "Visibilidad de portada verificada.\n");