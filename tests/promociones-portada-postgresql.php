<?php

declare(strict_types=1);

use App\Administracion\PromocionesPortada;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261120000100;
use DoctrineMigrations\Version20261120000500;
use DoctrineMigrations\Version20261121000200;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/Administracion/PromocionesPortada.php';
require __DIR__.'/../migrations/Version20261120000100.php';
require __DIR__.'/../migrations/Version20261120000500.php';
require __DIR__.'/../migrations/Version20261121000200.php';

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
$conexion->executeStatement("INSERT INTO promociones_portada (nombre, descripcion, actualizado_por, visible, eliminado) VALUES ('Antigua', 'Descripción', 'inicial', true, false), ('Oculta', 'Descripción', 'inicial', false, false), ('Eliminada', 'Descripción', 'inicial', false, true)");
$migracion = new Version20261121000200($conexion, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $consulta) {
    $conexion->executeStatement($consulta->getStatement(), $consulta->getParameters(), $consulta->getTypes());
}
$ordenInicial = $conexion->fetchFirstColumn('SELECT nombre FROM promociones_portada ORDER BY orden, id');
if ($ordenInicial !== ['Eliminada', 'Oculta', 'Antigua']) throw new RuntimeException('La migración debe preservar el orden previo.');
$conexion->executeStatement('DELETE FROM promociones_portada');
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
$promociones->guardar(null, ['nombre' => 'Primera', 'descripcion' => 'Descripción'], 'admin');
$promociones->guardar(null, ['nombre' => 'Segunda', 'descripcion' => 'Descripción'], 'admin');
$promociones->guardar(null, ['nombre' => 'Tercera', 'descripcion' => 'Descripción'], 'admin');
$lista = $promociones->listar();
$ids = array_map('intval', array_column($lista, 'id'));
$promociones->visibilidad($ids[0], true, 'admin');
$promociones->visibilidad($ids[2], true, 'admin');
$resultado = $promociones->mover($ids[2], -1, 'otra');
$verificar(array_column($resultado, 'nombre') === ['Primera', 'Tercera', 'Segunda'], 'Debe subir entre promociones ocultas y visibles.');
$verificar(array_column($promociones->listar(true), 'nombre') === ['Primera', 'Tercera'], 'El orden público debe omitir las ocultas.');
$verificar($resultado[1]['actualizado_por'] === 'otra' && $resultado[2]['actualizado_por'] === 'otra', 'Debe registrar el autor del movimiento.');
$promociones->mover($ids[0], 1, 'admin');
$verificar(array_column($promociones->listar(true), 'nombre') === ['Tercera', 'Primera'], 'Debe persistir el movimiento hacia abajo.');
foreach ([[$ids[2], -1], [$ids[1], 1], [$ids[0], 0], [999999, 1]] as [$invalido, $direccion]) {
    try {
        $promociones->mover($invalido, $direccion, 'admin');
        throw new RuntimeException('Debe rechazar el movimiento.');
    } catch (InvalidArgumentException) {
    }
}
$verificar(array_column($promociones->listar(), 'nombre') === ['Tercera', 'Primera', 'Segunda'], 'Los errores no deben cambiar el orden.');
$promociones->eliminar($ids[0], 'admin');
$promociones->guardar(null, ['nombre' => 'Cuarta', 'descripcion' => 'Descripción'], 'admin');
$verificar(array_column($promociones->listar(), 'nombre') === ['Tercera', 'Segunda', 'Cuarta'], 'Una promoción nueva debe agregarse al final y excluir eliminadas.');

$conexion->executeStatement('CREATE TABLE usuarios (id BIGINT PRIMARY KEY, email TEXT, password_hash TEXT, roles JSONB, activo BOOLEAN)');
$conexion->executeStatement('CREATE TABLE profesionales (id BIGINT, usuario_id BIGINT, activa BOOLEAN)');
$hash = password_hash('clave de prueba', PASSWORD_DEFAULT);
$conexion->executeStatement("INSERT INTO usuarios VALUES (1, 'admin@example.test', ?, '[\"ROLE_ADMIN\"]', true), (2, 'profesional@example.test', ?, '[]', true)", [$hash, $hash]);
$conexion->executeStatement('INSERT INTO profesionales VALUES (1, 2, true)');
require __DIR__.'/../src/Administracion/SesionAdministrativa.php';
require __DIR__.'/../src/Controller/PromocionesController.php';
$controller = new App\Controller\PromocionesController($promociones, new App\Administracion\SesionAdministrativa($conexion));
$sesion = new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
$sesion->set('admin_id', '1');
$sesion->set('admin_credencial', hash('sha256', $hash));
$sesion->set('admin_csrf', 'token');
$peticion = static function (string $contenido, $sesion, string $token = 'token') use ($ids) {
    $request = Symfony\Component\HttpFoundation\Request::create('/api/admin/promociones/'.$ids[1].'/orden', 'PATCH', server: ['HTTP_X_ZENLI_CSRF' => $token, 'CONTENT_TYPE' => 'application/json'], content: $contenido);
    $request->setSession($sesion);
    return $request;
};
foreach (['{' => 400, '{}' => 422, '{"direccion":"-1"}' => 422, '{"direccion":0}' => 422] as $contenido => $estado) {
    $verificar($controller->orden($peticion($contenido, $sesion), $ids[1])->getStatusCode() === $estado, 'Debe rechazar JSON o dirección inválida.');
}
foreach ([['1', '', 403], ['2', 'token', 403], [null, 'token', 401]] as [$usuario, $token, $estado]) {
    $sesion->set('admin_id', $usuario);
    try {
        $controller->orden($peticion('{"direccion":-1}', $sesion, $token), $ids[1]);
        throw new RuntimeException('Debe exigir administración y CSRF.');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
        $verificar($error->getStatusCode() === $estado, 'Debe rechazar accesos no autorizados.');
    }
}
$sesion->set('admin_id', '1');
$sesion->set('admin_credencial', hash('sha256', $hash));
$sesion->set('admin_csrf', 'token');
$verificar($controller->orden($peticion('{"direccion":-1}', $sesion), $ids[1])->getStatusCode() === 200, 'Debe permitir el movimiento autenticado.');
$otraConexion = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $socket, 'user' => 'zenli_pruebas', 'dbname' => 'postgres']);
$otraConexion->executeStatement("SET lock_timeout = '100ms'");
$conexion->beginTransaction();
$conexion->executeStatement('LOCK TABLE promociones_portada IN SHARE ROW EXCLUSIVE MODE');
try {
    (new PromocionesPortada($otraConexion))->mover($ids[1], 1, 'otra');
    throw new RuntimeException('Un movimiento concurrente debe esperar el bloqueo.');
} catch (Doctrine\DBAL\Exception\DriverException $error) {
    $verificar($error->getSQLState() === '55P03', 'Debe fallar por espera de bloqueo.');
} finally {
    $conexion->rollBack();
}
$verificar(array_column($promociones->listar(), 'nombre') === ['Segunda', 'Tercera', 'Cuarta'], 'Un movimiento bloqueado no debe dejar cambios parciales.');
(new PromocionesPortada($otraConexion))->mover($ids[1], 1, 'otra');
$verificar(array_column($promociones->listar(), 'nombre') === ['Tercera', 'Segunda', 'Cuarta'], 'Debe poder mover tras liberarse el bloqueo.');
fwrite(STDOUT, "Promociones de portada verificadas.\n");