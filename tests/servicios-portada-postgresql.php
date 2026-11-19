<?php

declare(strict_types=1);

use App\Administracion\ServiciosPortada;
use App\Administracion\ConfiguracionPortada;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261119000300;
use Psr\Log\NullLogger;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/Administracion/ServiciosPortada.php';
require __DIR__.'/../src/Administracion/ConfiguracionPortada.php';
require __DIR__.'/../migrations/Version20261119000300.php';

$socket = $argv[1] ?? '';
if (!preg_match('~\A/tmp/zenli-agenda-pg\.[A-Za-z0-9]+\z~', $socket)
    || !is_file($socket.'/datos/PG_VERSION') || !file_exists($socket.'/.s.PGSQL.5432')) {
    throw new RuntimeException('Ejecutá la prueba con tests/servicios-portada-postgresql.sh.');
}
$conexion = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $socket, 'user' => 'zenli_pruebas', 'dbname' => 'postgres']);
$migracion = new Version20261119000300($conexion, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $consulta) {
    $conexion->executeStatement($consulta->getStatement(), $consulta->getParameters(), $consulta->getTypes());
}
$conexion->executeStatement("INSERT INTO servicios_portada (nombre, descripcion_corta, orden, visible, actualizado_por) VALUES ('A', 'Prueba', 0, true, 'inicial'), ('B', 'Prueba', 0, false, 'inicial'), ('C', 'Prueba', 8, true, 'inicial')");
$servicios = new ServiciosPortada($conexion);
$nombres = static fn (array $filas): array => array_column($filas, 'nombre');
$verificar = static function (bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
};
$ids = array_column($servicios->listar(), 'id');
$resultado = $servicios->mover((int) $ids[1], -1, 'admin');
$verificar($nombres($resultado) === ['B', 'A', 'C'], 'Debe mover servicios con órdenes duplicados.');
$verificar($nombres($servicios->listarVisibles()) === ['A', 'C'], 'La portada debe omitir servicios ocultos.');
$resultado = $servicios->mover((int) $ids[0], 1, 'admin');
$verificar($nombres($resultado) === ['B', 'C', 'A'], 'Debe mover hacia abajo y persistir el orden.');
$verificar($nombres($servicios->listarVisibles()) === ['C', 'A'], 'La portada debe reflejar el nuevo orden.');
$verificar($resultado[2]['actualizado_por'] === 'admin', 'Debe registrar el autor.');
foreach ([[99999, 1], [(int) $ids[1], -1], [(int) $ids[0], 0]] as [$id, $direccion]) {
    try {
        $servicios->mover($id, $direccion, 'admin');
        throw new RuntimeException('Debe rechazar movimientos inválidos.');
    } catch (InvalidArgumentException) {
    }
}
$verificar($nombres($servicios->listar()) === ['B', 'C', 'A'], 'Un movimiento inválido no debe modificar el orden.');
fwrite(STDOUT, "Reordenamiento de portada verificado.\n");

$conexion->executeStatement('CREATE TABLE usuarios (id BIGINT PRIMARY KEY)');
$conexion->executeStatement('INSERT INTO usuarios VALUES (1)');
foreach (['Version20261119000100', 'Version20261119000200', 'Version20261119000400'] as $nombre) {
    require __DIR__.'/../migrations/'.$nombre.'.php';
    $clase = 'DoctrineMigrations\\'.$nombre;
    $migracion = new $clase($conexion, new NullLogger());
    $migracion->up(new Schema());
    foreach ($migracion->getSql() as $consulta) {
        $conexion->executeStatement($consulta->getStatement(), $consulta->getParameters(), $consulta->getTypes());
    }
    if ($nombre === 'Version20261119000200') {
        $conexion->executeStatement("INSERT INTO configuracion_portada (id, telefono, actualizado_por) VALUES (1, '+5492616356192', 1)");
    }
}
$portada = new ConfiguracionPortada($conexion);
$verificar($portada->consultar()['direccion'] === ConfiguracionPortada::DIRECCION_INICIAL && $portada->consultar()['horarios'] === ConfiguracionPortada::HORARIOS_INICIALES, 'La migración debe conservar los textos publicados para registros existentes.');
$portada->guardar('+5492616356192', null, false, '1', null, null, '  Calle nueva  ', "Lunes\nMartes");
$datos = $portada->consultar();
$verificar($datos['direccion'] === 'Calle nueva' && $datos['horarios'] === "Lunes\nMartes", 'Debe guardar y consultar dirección y horarios.');
$portada->guardar('+5492616356192', null, false, '1');
$verificar($portada->consultar()['direccion'] === 'Calle nueva', 'Una solicitud antigua no debe sobrescribir los datos.');
$portada->guardar('+5492616356192', null, false, '1', null, null, '', '');
$verificar($portada->consultar()['direccion'] === '' && $portada->consultar()['horarios'] === '', 'Debe permitir ocultar ambos datos.');
try {
    $portada->guardar('+5492616356192', null, false, '1', null, null, str_repeat('a', 256), '');
    throw new RuntimeException('Debe rechazar una dirección demasiado larga.');
} catch (InvalidArgumentException) {
}
$verificar($portada->consultar()['direccion'] === '', 'Un dato inválido no debe modificar la configuración.');
fwrite(STDOUT, "Configuración pública verificada.\n");