<?php

declare(strict_types=1);

use App\Administracion\AgendaAdministrativa;
use App\Administracion\ComunicacionAgenda;
use App\Administracion\HorariosProfesionales;
use App\Administracion\PerfilesProfesionales;
use App\Administracion\RepositorioPerfilesProfesionales;
use App\Controller\AgendaAdministrativaController;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
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
$conexion->executeStatement('ALTER TABLE usuarios ADD creado_por BIGINT REFERENCES usuarios(id), ADD creado_en TIMESTAMPTZ, ADD actualizado_por BIGINT REFERENCES usuarios(id), ADD actualizado_en TIMESTAMPTZ');
$conexion->executeStatement('ALTER TABLE profesionales ADD creado_por BIGINT REFERENCES usuarios(id), ADD creado_en TIMESTAMPTZ, ADD actualizado_por BIGINT REFERENCES usuarios(id), ADD actualizado_en TIMESTAMPTZ, ADD horarios_actualizado_por BIGINT REFERENCES usuarios(id), ADD horarios_actualizado_en TIMESTAMPTZ');
$conexion->executeStatement('ALTER TABLE recursos ADD creado_por BIGINT REFERENCES usuarios(id), ADD creado_en TIMESTAMPTZ, ADD actualizado_por BIGINT REFERENCES usuarios(id), ADD actualizado_en TIMESTAMPTZ');
$conexion->executeStatement('ALTER TABLE politicas_reservas ADD actualizado_por BIGINT REFERENCES usuarios(id)');
$conexion->executeStatement('ALTER TABLE bloqueos ADD creado_en TIMESTAMPTZ');
$verificar = static function (bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
};
$hash = password_hash('clave de prueba segura', PASSWORD_DEFAULT);
$conexion->executeStatement("INSERT INTO usuarios (id, email, password_hash, roles) VALUES (101, 'admin@example.com', ?, '[\"ROLE_ADMIN\"]'), (102, 'profesional@example.com', ?, '[]')", [$hash, $hash]);
$conexion->executeStatement("INSERT INTO profesionales (usuario_id, nombre) VALUES (102, 'Profesional')");
require __DIR__.'/soporte-seguridad.php';
$auditoria = auditoriaDePrueba($conexion);
$sesion = sesionDePrueba(administradoraDePrueba('101'));
App\Doctrine\InstanteType::registrar();
$configuracion = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../src/Entity'], true);
$configuracion->enableNativeLazyObjects(true);
$entidades = new EntityManager($conexion, $configuracion);
$horarios = new HorariosProfesionales($entidades);
$comunicacion = new ComunicacionAgenda($entidades, $auditoria);
$controller = new AgendaAdministrativaController($sesion, new AgendaAdministrativa($entidades, $horarios), $comunicacion, $horarios, new PerfilesProfesionales(new RepositorioPerfilesProfesionales($entidades), new App\Seguridad\LimiteIngresos($conexion)));
$peticion = static function (?string $usuario, string $contenido, bool $token = true, string $metodo = 'POST', string $ruta = '/api/admin/agenda/profesionales') use ($hash): Request {
    $request = Request::create($ruta, $metodo, [], [], [], ['CONTENT_TYPE' => 'application/json'], $contenido);
    // Los permisos y el CSRF los aplica el firewall (tests/seguridad-http.php); acá solo importa el cuerpo.
    $request->setSession(new Session(new MockArraySessionStorage()));

    return $request;
};
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
$ruta = '/api/admin/agenda/profesionales/'.$id;
foreach (['{}', '{', '{"nombre":"   "}', '{"nombre":123}'] as $invalido) {
    $verificar($controller->modificarProfesional($peticion('101', $invalido, true, 'PUT', $ruta), $id)->getStatusCode() === 422, 'Debe rechazar nombre inválido.');
}
$verificar($controller->modificarProfesional($peticion('101', '{"activa":1}', true, 'PUT', $ruta.'/estado'), $id)->getStatusCode() === 422, 'El estado debe ser booleano.');
$verificar($controller->modificarProfesional($peticion('101', '{"activa":false}', true, 'PUT', '/api/admin/agenda/profesionales/99999/estado'), '99999')->getStatusCode() === 404, 'Debe rechazar baja de perfiles inexistentes.');
$verificar($controller->modificarProfesional($peticion('101', '{"nombre":"Nadie"}', true, 'PUT', '/api/admin/agenda/profesionales/99999'), '99999')->getStatusCode() === 404, 'Debe rechazar perfiles inexistentes.');
$verificar($controller->modificarProfesional($peticion('101', '{"nombre":"  María Nueva  "}', true, 'PUT', $ruta), $id)->getStatusCode() === 200, 'Debe editar el nombre.');
$conexion->executeStatement("INSERT INTO tratamientos (id, codigo, nombre) VALUES (1, 'T', 'Tratamiento')");
$conexion->executeStatement("INSERT INTO servicios (id, codigo, nombre, duracion_minutos) VALUES (1, 'S', 'Servicio', 60)");
$conexion->executeStatement("INSERT INTO clientes (id, nombre) VALUES (1, 'Cliente')");
$conexion->executeStatement("INSERT INTO reservas (id, servicio_id, solicitante_id, estado, modo_confirmacion, inicio, fin) VALUES (1, 1, 1, 'CONFIRMADA', 'AUTOMATICA', now(), now() + interval '1 hour')");
$conexion->executeStatement('INSERT INTO reserva_participantes (id, reserva_id, cliente_id, orden) VALUES (1, 1, 1, 1)');
$conexion->executeStatement("INSERT INTO reserva_sesiones (id, reserva_id, participante_id, tratamiento_id, profesional_id, orden, inicio, fin) VALUES (1, 1, 1, 1, ?, 1, now(), now() + interval '1 hour')", [$id]);
$conexion->executeStatement("INSERT INTO ocupaciones_profesionales (sesion_id, profesional_id, inicio, fin) VALUES (1, ?, now(), now() + interval '1 hour')", [$id]);
$estado = fn (bool $activa) => $controller->modificarProfesional($peticion('101', json_encode(['activa' => $activa], JSON_THROW_ON_ERROR), true, 'PUT', $ruta.'/estado'), $id);
$verificar($estado(false)->getStatusCode() === 409, 'Debe rechazar baja con ocupación vigente.');
$conexion->executeStatement("UPDATE ocupaciones_profesionales SET inicio = now() - interval '2 hours', fin = now() - interval '1 hour' WHERE sesion_id = 1");
$verificar($estado(false)->getStatusCode() === 200, 'Debe permitir baja con ocupación histórica.');
$listado = json_decode($controller->crearProfesional($peticion('101', '', true, 'GET'))->getContent(), true, 512, JSON_THROW_ON_ERROR)['profesionales'];
$encontrado = array_values(array_filter($listado, fn ($item) => $item['id'] === $id))[0];
$verificar($encontrado['nombre'] === 'María Nueva' && $encontrado['activa'] === false && $encontrado['tiene_cuenta'] === true, 'El listado debe incluir inactivas y conservar la cuenta.');
$verificar((int) $conexion->fetchOne('SELECT count(*) FROM reserva_sesiones WHERE profesional_id = ?', [$id]) === 1, 'Debe conservar el historial.');
$verificar((bool) $conexion->fetchOne('SELECT u.activo FROM usuarios u JOIN profesionales p ON p.usuario_id = u.id WHERE p.id = ?', [$id]), 'La cuenta debe seguir activa.');
$verificar($estado(true)->getStatusCode() === 200, 'Debe reactivar el perfil.');
$conexion->executeStatement("UPDATE usuarios SET roles = '[\"ROLE_ADMIN\"]' WHERE email = 'nueva@example.com'");
$listado = json_decode($controller->crearProfesional($peticion('101', '', true, 'GET'))->getContent(), true, 512, JSON_THROW_ON_ERROR)['profesionales'];
$encontrado = array_values(array_filter($listado, fn ($item) => $item['id'] === $id))[0];
$verificar($encontrado['activa'] === true && $encontrado['admin'] === true, 'El listado debe reflejar la reactivación y cambios externos de roles.');
fwrite(STDOUT, "Alta, edición y estado de perfiles profesionales verificados.\n");