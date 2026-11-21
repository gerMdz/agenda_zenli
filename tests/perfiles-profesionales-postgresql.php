<?php

declare(strict_types=1);

use App\Administracion\AgendaAdministrativa;
use App\Administracion\ComunicacionAgenda;
use App\Administracion\HorariosProfesionales;
use App\Administracion\PerfilesProfesionales;
use App\Administracion\SesionAdministrativa;
use App\Controller\AgendaAdministrativaController;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261118000100;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\HttpException;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../migrations/Version20261118000100.php';

spl_autoload_register(static function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        require __DIR__.'/../src/'.str_replace('\\', '/', substr($clase, 4)).'.php';
    }
});

$socket = $argv[1] ?? '';
if (!preg_match('~\A/tmp/zenli-agenda-pg\.[A-Za-z0-9]+\z~', $socket)
    || !is_file($socket.'/datos/PG_VERSION') || !file_exists($socket.'/.s.PGSQL.5432')) {
    throw new RuntimeException('Ejecutá la prueba con tests/perfiles-profesionales-postgresql.sh.');
}
$conexion = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $socket, 'user' => 'zenli_pruebas', 'dbname' => 'postgres']);
$migracion = new Version20261118000100($conexion, new NullLogger());
$migracion->up(new Schema());
foreach ($migracion->getSql() as $consulta) {
    $conexion->executeStatement($consulta->getStatement(), $consulta->getParameters(), $consulta->getTypes());
}
$verificar = static function (bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
};
$hash = password_hash('clave de prueba segura', PASSWORD_DEFAULT);
$conexion->executeStatement("INSERT INTO usuarios (id, email, password_hash, roles) VALUES (101, 'admin@example.com', ?, '[\"ROLE_ADMIN\"]'), (102, 'profesional@example.com', ?, '[]')", [$hash, $hash]);
$conexion->executeStatement("INSERT INTO profesionales (usuario_id, nombre) VALUES (102, 'Profesional')");
$sesion = new SesionAdministrativa($conexion);
$horarios = new HorariosProfesionales($conexion);
$comunicacion = new ComunicacionAgenda($conexion);
$controller = new AgendaAdministrativaController($sesion, new AgendaAdministrativa($conexion, $horarios), $comunicacion, $horarios, new PerfilesProfesionales($conexion));
$peticion = static function (?string $usuario, string $contenido, bool $token = true) use ($hash): Request {
    $request = Request::create('/api/admin/agenda/profesionales', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $contenido);
    $session = new Session(new MockArraySessionStorage());
    if ($usuario !== null) {
        $session->set('admin_id', $usuario);
        $session->set('admin_credencial', hash('sha256', $hash));
    }
    $session->set('admin_csrf', 'token');
    $request->setSession($session);
    if ($token) $request->headers->set('X-Zenli-CSRF', 'token');

    return $request;
};
foreach ([[null, true, 401], ['102', true, 403], ['101', false, 403]] as [$usuario, $token, $estado]) {
    try {
        $controller->crearProfesional($peticion($usuario, '{"nombre":"Sin permiso"}', $token));
        throw new RuntimeException('Debe rechazar la solicitud sin permisos o token.');
    } catch (HttpException $error) {
        $verificar($error->getStatusCode() === $estado, 'Estado de autorización incorrecto.');
    }
}
foreach (['{}', '{', '{"nombre":"   "}', '{"nombre":123}', '{"nombre":"'.str_repeat('a', 121).'"}', '{"nombre":"a\u0000b"}'] as $invalido) {
    $respuesta = $controller->crearProfesional($peticion('101', $invalido));
    $verificar($respuesta->getStatusCode() === 422, 'Debe rechazar nombres y JSON inválidos.');
}
$verificar((int) $conexion->fetchOne('SELECT count(*) FROM profesionales') === 1, 'Las solicitudes inválidas no deben crear perfiles.');
$usuariosAntes = (int) $conexion->fetchOne('SELECT count(*) FROM usuarios');
$respuesta = $controller->crearProfesional($peticion('101', '{"nombre":"  María  "}'));
$verificar($respuesta->getStatusCode() === 201, 'Debe crear el perfil.');
$id = json_decode($respuesta->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];
$perfil = $conexion->fetchAssociative('SELECT nombre, usuario_id, activa FROM profesionales WHERE id = ?', [$id]);
$verificar($perfil['nombre'] === 'María' && $perfil['usuario_id'] === null && (bool) $perfil['activa'], 'El perfil debe estar activo y no tener cuenta.');
$verificar((int) $conexion->fetchOne('SELECT count(*) FROM usuarios') === $usuariosAntes, 'No debe crear un usuario.');
$comunicacion->vincularCuenta($id, 'nueva@example.com', 'clave de prueba segura');
$verificar($conexion->fetchOne('SELECT u.email FROM profesionales p JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = ?', [$id]) === 'nueva@example.com', 'Debe poder vincular una cuenta posteriormente.');
fwrite(STDOUT, "Alta de perfiles profesionales verificada.\n");