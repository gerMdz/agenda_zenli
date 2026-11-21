<?php

declare(strict_types=1);

use App\Administracion\PresentacionProfesionales;
use App\Administracion\SesionAdministrativa;
use App\Controller\PresentacionProfesionalesController;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261120000200;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\HttpException;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../migrations/Version20261120000200.php';
require __DIR__.'/../src/Administracion/PresentacionProfesionales.php';
require __DIR__.'/../src/Administracion/SesionAdministrativa.php';
require __DIR__.'/../src/Controller/PresentacionProfesionalesController.php';

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
$verificar = static function (bool $valor, string $mensaje): void { if (!$valor) throw new RuntimeException($mensaje); };
$servicio = new PresentacionProfesionales($db);
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
$db->executeStatement('UPDATE profesionales SET activa = false WHERE id = 1');
$verificar($servicio->imagen('1', $version, true) === false, 'Una profesional inactiva no publica imagen.');
$db->executeStatement('UPDATE profesionales SET activa = true WHERE id = 1');
$servicio->cambiarModo('ocultos', '1');
$verificar($servicio->imagen('1', $version, true) === false && $servicio->publicos() === [], 'Ocultar revoca acceso público.');
try { $servicio->cambiarModo('otro', '1'); throw new RuntimeException('Modo inválido aceptado.'); } catch (InvalidArgumentException) {}
$servicio->guardarImagen('1', null, true, '1');
$verificar($servicio->imagen('1', $version, false) === false && (string) $db->fetchOne('SELECT actualizado_por FROM presentacion_profesionales_imagenes WHERE profesional_id = 1') === '1', 'Eliminar conserva autor y revoca imagen.');
$controller = new PresentacionProfesionalesController($servicio, new SesionAdministrativa($db));
$sesion = new Session(new MockArraySessionStorage());
$peticion = Request::create('/api/admin/profesionales-portada/modo', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{"modo":"imagenes"}');
$peticion->setSession($sesion);
try { $controller->modo($peticion); throw new RuntimeException('Acceso anónimo aceptado.'); } catch (HttpException $e) { $verificar($e->getStatusCode() === 401, 'Debe requerir sesión.'); }
$sesion->set('admin_id', '2'); $sesion->set('admin_credencial', hash('sha256', $hash));
try { $controller->modo($peticion); throw new RuntimeException('Acceso profesional aceptado.'); } catch (HttpException $e) { $verificar($e->getStatusCode() === 403, 'Debe requerir administración.'); }
$sesion->set('admin_id', '1'); $sesion->set('admin_credencial', hash('sha256', $hash)); $sesion->set('admin_csrf', 'token');
try { $controller->modo($peticion); throw new RuntimeException('Escritura sin CSRF aceptada.'); } catch (HttpException $e) { $verificar($e->getStatusCode() === 403, 'Debe requerir CSRF.'); }
$peticion->headers->set('X-Zenli-CSRF', 'token');
$verificar($controller->modo($peticion)->getStatusCode() === 200, 'Administración puede cambiar modo.');
fwrite(STDOUT, "Presentación, privacidad y permisos verificados.\n");