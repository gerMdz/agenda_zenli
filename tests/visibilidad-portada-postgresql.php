<?php

declare(strict_types=1);

use App\Administracion\ConfiguracionPortada;
use App\Repository\ConfiguracionPortadaRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use DoctrineMigrations\Version20261120000300;
use Psr\Log\NullLogger;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/Administracion/ConfiguracionPortada.php';
require __DIR__.'/../src/Doctrine/InstanteType.php';
require __DIR__.'/../src/Entity/ConfiguracionPortada.php';
require __DIR__.'/../src/Repository/ConfiguracionPortadaRepository.php';
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
App\Doctrine\InstanteType::registrar();
$configuracion = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../src/Entity'], true);
$configuracion->enableNativeLazyObjects(true);
$entidades = new EntityManager($conexion, $configuracion);
$portada = new ConfiguracionPortada(new ConfiguracionPortadaRepository($entidades));
$inicial = array_fill_keys(ConfiguracionPortada::SECCIONES, true);
$verificar($portada->consultar()['visibilidad'] === $inicial, 'Una fila existente debe comenzar visible.');
foreach (ConfiguracionPortada::SECCIONES as $seccion) {
    $portada->cambiarVisibilidad($seccion, false, '2');
    $inicial[$seccion] = false;
    $verificar($portada->consultar()['visibilidad'] === $inicial, 'Cada cambio debe persistir independientemente.');
}
$portada->guardar('+5492616356192', null, false, '1', null, null, 'Dirección conservada', 'Horario conservado');
$portada->cambiarModo(true, '1');
$portada->cambiarVisibilidad('servicios', true, '2');
$inicial['servicios'] = true;
$datos = $portada->consultar();
$verificar($datos['visibilidad'] === $inicial && $datos['direccion'] === 'Dirección conservada' && $datos['horarios'] === 'Horario conservado' && $datos['solo_imagen'], 'Debe conservar datos y modo al cambiar visibilidad.');
$verificar((string) $conexion->fetchOne('SELECT actualizado_por FROM configuracion_portada WHERE id = 1') === '2', 'Debe registrar el autor.');
$entidades->find(App\Entity\ConfiguracionPortada::class, 1);
$conexion->executeStatement("UPDATE configuracion_portada SET direccion = 'Cambio externo' WHERE id = 1");
$verificar($portada->consultar()['direccion'] === 'Cambio externo', 'La lectura no debe usar una entidad obsoleta.');
$conexion->executeStatement('ALTER TABLE configuracion_portada ADD CONSTRAINT imagen_completa CHECK ((imagen IS NULL AND imagen_tipo IS NULL AND imagen_version IS NULL) OR (imagen IS NOT NULL AND imagen_tipo IS NOT NULL AND imagen_version IS NOT NULL))');
try {
    (new ConfiguracionPortadaRepository($entidades))->actualizar(['telefono' => '+5492611111111'], '2', [base64_encode('imagen'), null, null]);
    throw new RuntimeException('Debe rechazar una imagen incompleta.');
} catch (Doctrine\DBAL\Exception\DriverException) {
}
$verificar($portada->consultar()['telefono'] === '+5492616356192', 'Un error de imagen debe revertir también el texto.');
$rutaImagen = $socket.'/portada.png';
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/aXcAAAAASUVORK5CYII=');
file_put_contents($rutaImagen, $bytes);
$archivo = new Symfony\Component\HttpFoundation\File\UploadedFile($rutaImagen, 'portada.png', 'image/png', null, true);
$portada->guardar('+5492616356192', $archivo, false, '1');
$version = hash('sha256', $bytes);
$verificar(base64_decode($portada->imagen($version)['contenido']) === $bytes, 'La imagen debe conservar sus bytes.');
$portada->guardar('+5492616356192', null, false, '1');
$verificar($portada->consultar()['imagen'] === '/api/portada/imagen/'.$version, 'La edición parcial debe conservar la imagen.');
$portada->guardar('+5492616356192', null, true, '1');
$verificar($portada->imagen($version) === false && $portada->consultar()['imagen'] === null, 'Restaurar debe quitar la imagen y la versión.');
unlink($rutaImagen);
$otraConexion = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $socket, 'user' => 'zenli_pruebas', 'dbname' => 'postgres']);
$otraConexion->executeStatement("SET lock_timeout = '100ms'");
$otraPortada = new ConfiguracionPortada(new ConfiguracionPortadaRepository(new EntityManager($otraConexion, $configuracion)));
$conexion->beginTransaction();
$portada->cambiarModo(false, '1');
try {
    $otraPortada->cambiarVisibilidad('horarios', true, '2');
    throw new RuntimeException('La escritura concurrente debe esperar el bloqueo.');
} catch (Doctrine\DBAL\Exception\DriverException $error) {
    $verificar($error->getSQLState() === '55P03', 'Debe fallar por espera de bloqueo.');
} finally {
    $conexion->rollBack();
}
$otraPortada->cambiarVisibilidad('horarios', true, '2');
$verificar($portada->consultar()['solo_imagen'] && $portada->consultar()['visibilidad']['horarios'], 'La escritura posterior debe preservar el modo revertido.');
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