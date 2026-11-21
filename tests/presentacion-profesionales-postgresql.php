<?php

declare(strict_types=1);

use App\Administracion\PresentacionProfesionales;
use App\Controller\PresentacionProfesionalesController;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use DoctrineMigrations\Version20261120000200;
use DoctrineMigrations\Version20261120000400;
use DoctrineMigrations\Version20261121000300;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\HttpException;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../migrations/Version20261120000200.php';
require __DIR__.'/../migrations/Version20261120000400.php';
require __DIR__.'/../migrations/Version20261121000300.php';
spl_autoload_register(static function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        require __DIR__.'/../src/'.str_replace('\\', '/', substr($clase, 4)).'.php';
    }
});

$socket = $argv[1] ?? '';
if (!preg_match('~\A/tmp/zenli-agenda-pg\.[A-Za-z0-9]+\z~', $socket) || !is_file($socket.'/datos/PG_VERSION') || !file_exists($socket.'/.s.PGSQL.5432')) {
    throw new RuntimeException('Ejecutá la prueba con tests/presentacion-profesionales-postgresql.sh.');
}
$db = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $socket, 'user' => 'zenli_pruebas', 'dbname' => 'postgres']);
$db->executeStatement("CREATE TABLE usuarios (id BIGINT PRIMARY KEY, email TEXT, password_hash TEXT, roles JSONB, activo BOOLEAN DEFAULT true)");
$db->executeStatement("CREATE TABLE profesionales (id BIGINT PRIMARY KEY, nombre TEXT NOT NULL, activa BOOLEAN NOT NULL DEFAULT true, usuario_id BIGINT)");
$hash = password_hash('prueba', PASSWORD_DEFAULT);
$db->executeStatement("INSERT INTO usuarios VALUES (1, 'admin@example.test', ?, '[\"ROLE_ADMIN\"]', true), (2, 'prof@example.test', ?, '[]', true)", [$hash, $hash]);
$db->executeStatement("INSERT INTO profesionales VALUES (1, 'Nataly', true, 2), (2, 'Micaela', true, NULL), (3, 'Inactiva', false, NULL)");
$migracion = new Version20261120000200($db, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
$migracion = new Version20261120000400($db, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
$migracion = new Version20261121000300($db, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
$verificar = static function (bool $valor, string $mensaje): void { if (!$valor) throw new RuntimeException($mensaje); };
$verificar((int) $db->fetchOne('SELECT count(*) FROM profesionales WHERE visible_portada') === 2, 'Solo los activos existentes conservan visibilidad.');
App\Doctrine\InstanteType::registrar();
$configuracion = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../src/Entity'], true);
$configuracion->enableNativeLazyObjects(true);
$servicio = new PresentacionProfesionales(new EntityManager($db, $configuracion));
$ids = static fn (array $lista): array => array_column($lista, 'id');
$verificar($ids($servicio->configuracion()['profesionales']) === ['3', '2', '1'], 'Orden inicial por nombre.');
$servicio->mover('1', -1, '1');
$verificar($ids($servicio->configuracion()['profesionales']) === ['3', '1', '2'], 'Movimiento persistido incluyendo inactivos.');
$verificar((string) $db->fetchOne('SELECT actualizado_por FROM presentacion_profesionales_config WHERE id = 1') === '1', 'Movimiento registra autor.');
$servicio->cambiarModo('nombres', '1');
$verificar($ids($servicio->publicos()) === ['1', '2'], 'Orden público filtra inactivos.');
$servicio->cambiarModo('ocultos', '1');
foreach ([['3', -1], ['2', 1], ['999', 1], ['1', 0]] as [$id, $direccion]) {
    try { $servicio->mover($id, $direccion, '1'); throw new RuntimeException('Movimiento inválido aceptado.'); } catch (InvalidArgumentException) {}
}
$db->executeStatement("INSERT INTO profesionales (id, nombre, activa) VALUES (4, 'Ana', true)");
$verificar($ids($servicio->configuracion()['profesionales']) === ['3', '1', '2', '4'], 'Perfil nuevo al final.');
$servicio->cambiarModo('nombres', '1');
$verificar(!in_array('4', $ids($servicio->publicos()), true), 'Perfil nuevo oculto por defecto.');
$servicio->cambiarModo('ocultos', '1');
$db->executeStatement('DELETE FROM profesionales WHERE id = 4');
$verificar($servicio->configuracion()['modo'] === 'ocultos' && $servicio->publicos() === [], 'Inicialmente ocultos.');
$archivo = new UploadedFile(__DIR__.'/../frontend/src/image/favicon.svg', 'archivo.svg', null, null, true);
try { $servicio->guardarImagen('1', $archivo, false, '1'); throw new RuntimeException('Debe rechazar SVG.'); } catch (InvalidArgumentException) {}
$archivo = new UploadedFile(__DIR__.'/../frontend/src/image/index.png', 'foto.png', null, null, true);
$servicio->guardarImagen('1', $archivo, false, '1');
$version = null;
foreach ($servicio->configuracion()['profesionales'] as $p) if ($p['id'] === '1') $version = $p['imagen_version'];
$verificar(is_string($version) && $servicio->imagen('1', $version, true) === false && $servicio->imagen('1', $version, false) !== false, 'La imagen inicial es privada.');
$servicio->cambiarModo('nombres', '1');
$publicos = $servicio->publicos();
$verificar(count($publicos) === 2 && $publicos[0]['imagen_version'] === null && $servicio->imagen('1', $version, true) === false, 'Nombres sin imágenes ni perfiles inactivos.');
$servicio->cambiarModo('imagenes', '1');
$verificar($servicio->imagen('1', $version, true) !== false && count($servicio->publicos()) === 2, 'Modo imágenes habilita imágenes existentes.');
$servicio->cambiarVisibilidad('1', false, '1');
$verificar($ids($servicio->publicos()) === ['2'] && $servicio->imagen('1', $version, true) === false && $servicio->imagen('1', $version, false) !== false, 'Ocultar revoca lista e imagen públicas, no imagen privada.');
$perfil = array_values(array_filter($servicio->configuracion()['profesionales'], static fn (array $p): bool => $p['id'] === '1'))[0];
$verificar($perfil['visible_portada'] === false && $perfil['activa'] === true, 'Configuración expone visibilidad booleana independiente de actividad.');
$servicio->cambiarModo('ocultos', '1');
$servicio->cambiarModo('imagenes', '1');
$verificar($ids($servicio->publicos()) === ['2'], 'Cambiar modo conserva visibilidad individual.');
$servicio->cambiarVisibilidad('1', true, '1');
$verificar($servicio->imagen('1', $version, true) !== false, 'Mostrar restablece imagen.');
$db->executeStatement('UPDATE profesionales SET activa = false WHERE id = 1');
$verificar($servicio->imagen('1', $version, true) === false, 'Una profesional inactiva no publica imagen.');
$servicio->cambiarVisibilidad('1', true, '1');
$verificar(!in_array('1', $ids($servicio->publicos()), true), 'Habilitar visibilidad no activa el perfil.');
$db->executeStatement('UPDATE profesionales SET activa = true WHERE id = 1');
$servicio->cambiarModo('ocultos', '1');
$verificar($servicio->imagen('1', $version, true) === false && $servicio->publicos() === [], 'Ocultar revoca acceso público.');
try { $servicio->cambiarModo('otro', '1'); throw new RuntimeException('Modo inválido aceptado.'); } catch (InvalidArgumentException) {}
$servicio->guardarImagen('1', null, true, '1');
$verificar($servicio->imagen('1', $version, false) === false && (string) $db->fetchOne('SELECT actualizado_por FROM presentacion_profesionales_imagenes WHERE profesional_id = 1') === '1', 'Eliminar conserva autor y revoca imagen.');
require __DIR__.'/soporte-seguridad.php';
$controller = new PresentacionProfesionalesController($servicio, sesionDePrueba(administradoraDePrueba()));
$sesion = new Session(new MockArraySessionStorage());
$visibilidad = Request::create('/api/admin/profesionales-portada/1/visibilidad', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{"visible":false}');
$visibilidad->setSession($sesion);
$visibilidad->headers->set('X-Zenli-CSRF', 'token');
$verificar($controller->visibilidad($visibilidad, '1')->getStatusCode() === 200, 'Administración puede ocultar.');
$verificar((string) $db->fetchOne('SELECT actualizado_por FROM presentacion_profesionales_config WHERE id = 1') === '1', 'Visibilidad registra autor.');
foreach (['{"visible":"false"}', '{}', '{'] as $contenido) {
    $invalida = Request::create('/', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: $contenido);
    $invalida->setSession($sesion); $invalida->headers->set('X-Zenli-CSRF', 'token');
    $verificar($controller->visibilidad($invalida, '1')->getStatusCode() === 422, 'Visibilidad inválida rechazada.');
}
$verificar($controller->visibilidad($visibilidad, '999')->getStatusCode() === 422, 'Profesional inexistente rechazada.');
$servicio->cambiarVisibilidad('1', true, '1');
$peticion = Request::create('/api/admin/profesionales-portada/modo', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{"modo":"imagenes"}');
$peticion->setSession($sesion);
$peticion->headers->set('X-Zenli-CSRF', 'token');
$verificar($controller->modo($peticion)->getStatusCode() === 200, 'Administración puede cambiar modo.');
$orden = Request::create('/api/admin/profesionales-portada/1/orden', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{"direccion":1}');
$orden->setSession($sesion);
$orden->headers->set('X-Zenli-CSRF', 'token');
$verificar($controller->orden($orden, '1')->getStatusCode() === 200, 'Administración puede ordenar.');
$orden = Request::create('/api/admin/profesionales-portada/1/orden', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{"direccion":"1"}');
$orden->setSession($sesion); $orden->headers->set('X-Zenli-CSRF', 'token');
$verificar($controller->orden($orden, '1')->getStatusCode() === 422, 'Dirección debe ser entera.');
$nombre = Request::create('/api/admin/profesionales-portada/1/nombre', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{"nombre":"  Nataly Editada  "}');
$nombre->setSession($sesion);
$nombre->headers->set('X-Zenli-CSRF', 'token');
$verificar($controller->nombre($nombre, '1')->getStatusCode() === 200, 'Nombre guardado.');
$verificar($db->fetchOne('SELECT nombre FROM profesionales WHERE id = 1') === 'Nataly Editada', 'Nombre compartido actualizado.');
$verificar((string) $db->fetchOne('SELECT actualizado_por FROM presentacion_profesionales_config WHERE id = 1') === '1', 'Cambio registra autor.');
$servicio->cambiarModo('nombres', '1');
$verificar(in_array('Nataly Editada', array_column($servicio->publicos(), 'nombre'), true), 'Nombre actualizado en portada.');
foreach (['{"nombre":"   "}', '{"nombre":123}', '{"nombre":"'.str_repeat('x', 121).'"}', '{'] as $contenido) {
    $invalida = Request::create('/api/admin/profesionales-portada/1/nombre', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: $contenido);
    $invalida->setSession($sesion); $invalida->headers->set('X-Zenli-CSRF', 'token');
    $verificar($controller->nombre($invalida, '1')->getStatusCode() === 422, 'Nombre inválido rechazado.');
}
$verificar($controller->nombre($nombre, '999')->getStatusCode() === 422, 'Profesional inexistente rechazada.');
$verificar($db->fetchOne('SELECT nombre FROM profesionales WHERE id = 1') === 'Nataly Editada', 'Los errores no modifican el nombre.');
fwrite(STDOUT, "Presentación, privacidad y permisos verificados.\n");