<?php

declare(strict_types=1);

use App\Administracion\ServiciosPortada;
use App\Administracion\ConfiguracionPortada;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use DoctrineMigrations\Version20261119000300;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
spl_autoload_register(static function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        require __DIR__.'/../src/'.str_replace('\\', '/', substr($clase, 4)).'.php';
    }
}, true, true);
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
require __DIR__.'/../migrations/Version20261119001100.php';
$conexion->beginTransaction();
$migracion = new DoctrineMigrations\Version20261119001100($conexion, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $consulta) {
    $conexion->executeStatement($consulta->getStatement(), $consulta->getParameters(), $consulta->getTypes());
}
if ((int) $conexion->fetchOne("SELECT count(*) FROM servicios_portada WHERE nombre = 'Masaje en pareja' AND NOT visible AND duracion_minima IS NULL AND profesionales_requeridos IS NULL") !== 1) {
    throw new RuntimeException('La pareja importada debe comenzar oculta e incompleta.');
}
$conexion->rollBack();
$conexion->executeStatement("INSERT INTO servicios_portada (nombre, descripcion_corta, orden, visible, actualizado_por) VALUES ('Masaje Descontracturante', 'Ya existente', 100, true, 'admin'), ('Masaje en pareja', 'Ya existente', 101, true, 'admin'), ('Otra pareja', 'Ya existente', 102, false, 'admin')");
$migracion = new DoctrineMigrations\Version20261119001100($conexion, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $consulta) {
    $conexion->executeStatement($consulta->getStatement(), $consulta->getParameters(), $consulta->getTypes());
}
$configuracion = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../src/Entity'], true);
$configuracion->enableNativeLazyObjects(true);
$entidades = new EntityManager($conexion, $configuracion);
$servicios = new ServiciosPortada($entidades);
$nombres = static fn (array $filas): array => array_values(array_filter(array_column($filas, 'nombre'), static fn (string $nombre): bool => in_array($nombre, ['A', 'B', 'C'], true)));
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

$verificar((int) $conexion->fetchOne("SELECT count(*) FROM servicios_portada WHERE lower(nombre) LIKE '%descontracturante%'") === 1, 'No debe duplicar tratamientos existentes.');
$verificar((int) $conexion->fetchOne("SELECT count(*) FROM servicios_portada WHERE nombre IN ('Relajante', 'Mixto', 'Tailandés', 'Bruxismo cervicocraneofacial', 'Reflexología de pies', 'Lomi Lomi', 'Experiencia Zenli') AND visible = false AND duracion_minima IS NULL AND profesionales_requeridos IS NULL") === 7, 'Debe importar los tratamientos restantes ocultos e incompletos.');
$pareja = (int) $conexion->fetchOne("SELECT id FROM servicios_portada WHERE nombre = 'Masaje en pareja'");
$otraPareja = (int) $conexion->fetchOne("SELECT id FROM servicios_portada WHERE nombre = 'Otra pareja'");
$verificar(in_array('Masaje en pareja', array_column($servicios->listarVisibles(), 'nombre'), true), 'La pareja visible debe seguir visible tras la migración.');
$verificar(!in_array('Otra pareja', array_column($servicios->listarVisibles(), 'nombre'), true), 'La pareja oculta debe seguir oculta tras la migración.');
$servicios->cambiarVisibilidad($otraPareja, true, 'admin');
$servicios->actualizar($otraPareja, 'Experiencia en pareja', 'Descripción editada', '', null, false, 'admin');
$verificar(in_array('Experiencia en pareja', array_column($servicios->listarVisibles(), 'nombre'), true), 'Debe permitir mostrar y editar pareja.');
$nuevaPareja = $servicios->crear('Nueva pareja', 'Descripción nueva', '', null, 'admin');
$verificar(in_array('Nueva pareja', array_column($servicios->listarVisibles(), 'nombre'), true), 'Debe permitir crear pareja visible.');
$servicios->actualizar($pareja, 'Masaje compartido', 'Descripción editada', '', null, false, 'admin');
$servicios->actualizar($pareja, 'Masaje en pareja', 'Descripción editada', '', null, false, 'admin');
$verificar(in_array('Masaje en pareja', array_column($servicios->listarVisibles(), 'nombre'), true), 'Debe permitir renombrar un servicio visible como pareja.');
$id = (int) $ids[0];
$verificar(!$servicios->obtener($id)['configuracion_completa'], 'Los servicios existentes comienzan incompletos.');
$servicios->configurar($id, 60, 2, 'admin');
$verificar($servicios->obtener($id)['configuracion_completa'] && (int) $servicios->obtener($id)['duracion_minima'] === 60, 'Debe guardar configuración completa.');
$servicios->configurar($id, null, 2, 'admin');
$verificar(!$servicios->obtener($id)['configuracion_completa'], 'Debe permitir volver a configuración incompleta.');
try {
    $servicios->configurar($id, 0, 1, 'admin');
    throw new RuntimeException('Debe rechazar valores no positivos.');
} catch (InvalidArgumentException) {
}
$verificar($servicios->obtener($id)['duracion_minima'] === null, 'La configuración inválida no debe modificar datos.');
fwrite(STDOUT, "Configuración y catálogo de servicios verificados.\n");

$conexion->executeStatement('UPDATE servicios_portada SET nombre = ? WHERE id = ?', ['Cambio externo', $id]);
$verificar($servicios->obtener($id)['nombre'] === 'Cambio externo', 'Debe leer cambios externos aun con la entidad cargada.');
$otroServicio = new ServiciosPortada($entidades);
$otroServicio->cambiarVisibilidad($id, false, 'otro');
$verificar(!$servicios->obtener($id)['visible'], 'Dos servicios con el mismo gestor deben compartir el estado actualizado.');
$servicios->cambiarVisibilidad($id, true, 'admin');
$otraConexion = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $socket, 'user' => 'zenli_pruebas', 'dbname' => 'postgres']);
$otraConexion->executeStatement("SET lock_timeout = '100ms'");
$conexion->beginTransaction();
$conexion->executeStatement('LOCK TABLE servicios_portada IN SHARE ROW EXCLUSIVE MODE');
try {
    (new ServiciosPortada(new EntityManager($otraConexion, $configuracion)))->mover($id, 1, 'otro');
    throw new RuntimeException('Un movimiento concurrente debe esperar el bloqueo.');
} catch (Doctrine\DBAL\Exception\DriverException $error) {
    $verificar($error->getSQLState() === '55P03', 'Debe fallar por espera de bloqueo.');
} finally {
    $conexion->rollBack();
}
$rutaImagen = $socket.'/imagen-servicio.png';
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/aXcAAAAASUVORK5CYII=');
file_put_contents($rutaImagen, $bytes);
$archivo = new UploadedFile($rutaImagen, 'imagen.png', 'image/png', null, true);
$imagenId = $servicios->crear('Con imagen', 'Descripción válida', '', $archivo, 'admin');
$version = hash('sha256', $bytes);
$verificar(base64_decode($servicios->imagen($imagenId, $version)['contenido']) === $bytes, 'Debe conservar los bytes al crear.');
$servicios->actualizar($imagenId, 'Con imagen editada', 'Descripción válida', '', null, false, 'admin');
$verificar(base64_decode($servicios->imagen($imagenId, $version)['contenido']) === $bytes, 'Editar texto debe conservar la imagen.');
file_put_contents($rutaImagen, $bytes.'nueva');
clearstatcache(true, $rutaImagen);
$servicios->actualizar($imagenId, 'Con imagen editada', 'Descripción válida', '', $archivo, false, 'admin');
$nuevaVersion = hash('sha256', $bytes.'nueva');
$verificar($servicios->imagen($imagenId, $version) === false && base64_decode($servicios->imagen($imagenId, $nuevaVersion)['contenido']) === $bytes.'nueva', 'Debe reemplazar la imagen.');
$servicios->actualizar($imagenId, 'Con imagen editada', 'Descripción válida', '', null, true, 'admin');
$verificar($servicios->imagen($imagenId, $nuevaVersion) === false && $servicios->obtener($imagenId)['imagen_version'] === null, 'Debe eliminar la imagen y su versión.');
fwrite(STDOUT, "Imágenes y lectura actualizada verificadas.\n");

$conexion->executeStatement('CREATE TABLE usuarios (id BIGINT PRIMARY KEY)');
$conexion->executeStatement('INSERT INTO usuarios VALUES (1)');
foreach (['Version20261119000100', 'Version20261119000200', 'Version20261119000400', 'Version20261119000500', 'Version20261120000300'] as $nombre) {
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
$portada = new ConfiguracionPortada(new App\Repository\ConfiguracionPortadaRepository($entidades));
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

$conexion->executeStatement("ALTER TABLE usuarios ADD email TEXT, ADD password_hash TEXT, ADD roles JSONB, ADD activo BOOLEAN DEFAULT true");
$conexion->executeStatement("CREATE TABLE profesionales (id BIGINT, usuario_id BIGINT, activa BOOLEAN)");
$hash = password_hash('clave de prueba', PASSWORD_DEFAULT);
$conexion->executeStatement("UPDATE usuarios SET email = 'admin@example.test', password_hash = ?, roles = '[\"ROLE_ADMIN\"]' WHERE id = 1", [$hash]);
foreach (['SesionAdministrativa', 'UsuariosAdministrativos'] as $clase) {
    require __DIR__.'/../src/Administracion/'.$clase.'.php';
}
require __DIR__.'/../src/Controller/AdministracionController.php';
$controller = new App\Controller\AdministracionController(new App\Administracion\SesionAdministrativa($conexion), $portada, $servicios, new App\Administracion\UsuariosAdministrativos(new App\Repository\UsuariosAdministrativosRepository($entidades)));
$sesion = new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
$sesion->set('admin_id', '1');
$sesion->set('admin_credencial', hash('sha256', $hash));
$sesion->set('admin_csrf', 'token');
$peticion = Symfony\Component\HttpFoundation\Request::create('/api/admin/servicios/'.$otraPareja.'/visible', 'PATCH', server: ['HTTP_X_ZENLI_CSRF' => 'token', 'CONTENT_TYPE' => 'application/json'], content: '{"visible":false}');
$peticion->setSession($sesion);
$verificar($controller->cambiarVisibilidadServicio($peticion, (string) $otraPareja)->getStatusCode() === 200 && !$servicios->obtener($otraPareja)['visible'], 'Debe ocultar mediante JSON.');
$peticion = Symfony\Component\HttpFoundation\Request::create('/api/admin/servicios/'.$otraPareja.'/visible', 'PATCH', server: ['HTTP_X_ZENLI_CSRF' => 'token', 'CONTENT_TYPE' => 'application/json'], content: '{"visible":true}');
$peticion->setSession($sesion);
$verificar($controller->cambiarVisibilidadServicio($peticion, (string) $otraPareja)->getStatusCode() === 200 && $servicios->obtener($otraPareja)['visible'], 'Debe mostrar mediante JSON.');
foreach (['{}', '{"visible":"false"}', '{'] as $contenidoInvalido) {
    $peticion = Symfony\Component\HttpFoundation\Request::create('/api/admin/servicios/'.$otraPareja.'/visible', 'PATCH', server: ['HTTP_X_ZENLI_CSRF' => 'token', 'CONTENT_TYPE' => 'application/json'], content: $contenidoInvalido);
    $peticion->setSession($sesion);
    $verificar(in_array($controller->cambiarVisibilidadServicio($peticion, (string) $otraPareja)->getStatusCode(), [400, 422], true), 'Debe rechazar visibilidad inválida.');
    $verificar((bool) $servicios->obtener($otraPareja)['visible'], 'Una solicitud inválida no debe cambiar la visibilidad.');
}
$ruta = (new ReflectionMethod($controller, 'actualizarServicio'))->getAttributes(Symfony\Component\Routing\Attribute\Route::class)[0]->newInstance();
$verificar(in_array('POST', $ruta->methods, true), 'La edición con archivos debe aceptar POST multipart.');
$peticion = Symfony\Component\HttpFoundation\Request::create('/api/admin/servicios/'.$otraPareja, 'POST', ['nombre' => 'Servicio editado', 'descripcion_corta' => 'Descripción editada', 'detalle' => 'Detalle'], server: ['HTTP_X_ZENLI_CSRF' => 'token']);
$peticion->setSession($sesion);
$verificar($controller->actualizarServicio($peticion, (string) $otraPareja)->getStatusCode() === 200 && $servicios->obtener($otraPareja)['nombre'] === 'Servicio editado', 'Debe editar un servicio mediante formulario.');
fwrite(STDOUT, "Visibilidad y edición del controlador verificadas.\n");