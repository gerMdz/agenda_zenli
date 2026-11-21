<?php

declare(strict_types=1);

use App\Administracion\PromocionesPortada;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261120000100;
use DoctrineMigrations\Version20261120000500;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/Administracion/PromocionesPortada.php';
require __DIR__.'/../migrations/Version20261120000100.php';
require __DIR__.'/../migrations/Version20261120000500.php';

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
$migracion = new Version20261120000500($conexion, new NullLogger());
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
$rutaImagen = $socket.'/imagen.png';
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/aXcAAAAASUVORK5CYII=');
file_put_contents($rutaImagen, $bytes);
$archivo = new UploadedFile($rutaImagen, 'imagen.png', 'image/png', null, true);
$datos = ['nombre' => 'Promoción', 'descripcion' => 'Descripción de prueba'];
$promociones->guardar($id, $datos, 'admin', $archivo);
$version = hash('sha256', $bytes);
$verificar($promociones->listar()[0]['imagen_version'] === $version && $promociones->imagen($id, $version, true) === false, 'La imagen oculta solo debe estar disponible en administración.');
$verificar(base64_decode($promociones->imagen($id, $version)['contenido']) === $bytes, 'Debe conservar los bytes de la imagen.');
$promociones->guardar($id, $datos, 'admin');
$verificar($promociones->listar()[0]['imagen_version'] === $version, 'Editar texto debe conservar la imagen.');
file_put_contents($rutaImagen, 'archivo inválido');
clearstatcache(true, $rutaImagen);
try {
    $promociones->guardar($id, ['nombre' => 'No guardar', 'descripcion' => 'Descripción de prueba'], 'admin', $archivo);
    throw new RuntimeException('Debe rechazar imágenes inválidas.');
} catch (InvalidArgumentException) {
}
$verificar($promociones->listar()[0]['nombre'] === 'Promoción' && $promociones->listar()[0]['imagen_version'] === $version, 'Una imagen inválida no debe modificar la promoción.');
file_put_contents($rutaImagen, $bytes.str_repeat('x', 4 * 1024 * 1024));
clearstatcache(true, $rutaImagen);
try {
    $promociones->guardar($id, $datos, 'admin', $archivo);
    throw new RuntimeException('Debe rechazar archivos mayores a 4 MB.');
} catch (InvalidArgumentException) {
}
file_put_contents($rutaImagen, $bytes.'nueva');
clearstatcache(true, $rutaImagen);
$promociones->guardar($id, $datos, 'otra', $archivo);
$nuevaVersion = hash('sha256', $bytes.'nueva');
$verificar($promociones->imagen($id, $version) === false && $promociones->listar()[0]['imagen_version'] === $nuevaVersion && $promociones->listar()[0]['actualizado_por'] === 'otra', 'Debe reemplazar la imagen y registrar el autor.');
$version = $nuevaVersion;
$promociones->visibilidad($id, true, 'admin');
$verificar(count($promociones->listar(true)) === 1, 'Debe publicar la promoción visible.');
$verificar($promociones->imagen($id, $version, true) !== false, 'Debe servir la imagen visible.');
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
$promociones->guardar($id, ['nombre' => 'Actualizada', 'descripcion' => 'Texto actualizado'], 'admin', null, true);
$verificar($promociones->listar()[0]['imagen_version'] === null && $promociones->imagen($id, $version, true) === false, 'Debe eliminar la imagen y su versión.');
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