<?php

declare(strict_types=1);

use App\Administracion\PromocionesPortada;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261120000100;
use Psr\Log\NullLogger;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/Administracion/PromocionesPortada.php';
require __DIR__.'/../migrations/Version20261120000100.php';

$socket = $argv[1] ?? '';
if (!preg_match('~\A/tmp/zenli-agenda-pg\.[A-Za-z0-9]+\z~', $socket) || !is_file($socket.'/datos/PG_VERSION') || !file_exists($socket.'/.s.PGSQL.5432')) {
    throw new RuntimeException('Ejecutá la prueba con tests/promociones-portada-postgresql.sh.');
}
$conexion = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $socket, 'user' => 'zenli_pruebas', 'dbname' => 'postgres']);
$migracion = new Version20261120000100($conexion, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $consulta) {
    $conexion->executeStatement($consulta->getStatement(), $consulta->getParameters(), $consulta->getTypes());
}
$promociones = new PromocionesPortada($conexion);
$verificar = static function (bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
};
$promociones->guardar(null, ['nombre' => 'Promoción', 'descripcion' => 'Descripción de prueba'], 'admin');
$lista = $promociones->listar();
$id = (int) $lista[0]['id'];
$verificar(count($lista) === 1 && !$lista[0]['visible'] && $promociones->listar(true) === [], 'Las promociones nuevas deben estar ocultas.');
$promociones->visibilidad($id, true, 'admin');
$verificar(count($promociones->listar(true)) === 1, 'Debe publicar la promoción visible.');
$promociones->guardar($id, ['nombre' => 'Actualizada', 'descripcion' => 'Texto actualizado'], 'otra');
$verificar($promociones->listar(true)[0]['nombre'] === 'Actualizada' && $promociones->listar()[0]['actualizado_por'] === 'otra', 'Debe editar y registrar autor.');
foreach ([['nombre' => '', 'descripcion' => 'Texto válido'], ['nombre' => 'Nombre', 'descripcion' => "Texto\0inválido"]] as $datos) {
    try {
        $promociones->guardar($id, $datos, 'admin');
        throw new RuntimeException('Debe rechazar datos inválidos.');
    } catch (InvalidArgumentException) {
    }
}
$verificar($promociones->listar(true)[0]['nombre'] === 'Actualizada', 'Un error no debe modificar datos.');
$promociones->visibilidad($id, false, 'admin');
$verificar($promociones->listar(true) === [], 'Debe ocultar la promoción.');
$promociones->eliminar($id, 'admin');
$verificar($promociones->listar() === [] && (int) $conexion->fetchOne('SELECT count(*) FROM promociones_portada WHERE eliminado AND NOT visible') === 1, 'Debe conservar el registro eliminado sin publicarlo.');
try {
    $promociones->visibilidad($id, true, 'admin');
    throw new RuntimeException('No debe publicar una promoción eliminada.');
} catch (InvalidArgumentException) {
}
fwrite(STDOUT, "Promociones de portada verificadas.\n");