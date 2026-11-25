<?php

declare(strict_types=1);

// Seguridad de la administración a través del kernel real (firewall, access_control, sesión y CSRF).
// Ejecutar con tests/seguridad-http.sh.

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';

$sesiones = getenv('ZENLI_SESIONES');
if (!is_string($sesiones) || !is_dir($sesiones) || getenv('APP_ENV') !== 'prod') {
    throw new RuntimeException('Ejecutá la prueba con tests/seguridad-http.sh.');
}

function verificar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

final class Cliente
{
    public array $cookies = [];
    public string $csrf = '';
    public ?Response $ultima = null;

    public function __construct(private readonly Kernel $kernel, public readonly string $ip)
    {
    }

    /** @return array{0: int, 1: mixed} */
    public function pedir(string $metodo, string $ruta, ?array $cuerpo = null, bool $conCsrf = true, ?string $csrf = null): array
    {
        $servidor = ['REMOTE_ADDR' => $this->ip, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($conCsrf) {
            $servidor['HTTP_X_ZENLI_CSRF'] = $csrf ?? $this->csrf;
        }
        $peticion = Request::create($ruta, $metodo, [], $this->cookies, [], $servidor, $cuerpo === null ? null : json_encode($cuerpo));
        $respuesta = $this->kernel->handle($peticion);
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
        $this->kernel->terminate($peticion, $respuesta);
        $this->ultima = $respuesta;
        $datos = json_decode((string) $respuesta->getContent(), true);
        if (is_array($datos) && is_string($datos['csrf'] ?? null)) {
            $this->csrf = $datos['csrf'];
        }

        return [$respuesta->getStatusCode(), $datos];
    }

    public function ingresar(string $email, string $clave): int
    {
        if ($this->csrf === '') {
            $this->pedir('GET', '/api/admin/sesion');
        }

        return $this->pedir('POST', '/api/admin/sesion', ['email' => $email, 'clave' => $clave])[0];
    }
}

$kernel = new Kernel('prod', false);
$kernel->boot();
$conexion = $kernel->getContainer()->get('doctrine')->getConnection();
$clave = 'Clave de prueba segura 1';
$hash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);
$ids = $conexion->fetchFirstColumn(
    "INSERT INTO usuarios (email, password_hash, roles) VALUES ('admin@zenli.test', ?, '[\"ROLE_ADMIN\"]'), ('otra@zenli.test', ?, '[\"ROLE_ADMIN\"]'), ('pro@zenli.test', ?, '[]') RETURNING id::text",
    [$hash, $hash, $hash],
);
[$adminId, $otraId, $proId] = $ids;
$perfilPro = (string) $conexion->fetchOne("INSERT INTO profesionales (usuario_id, nombre) VALUES (?, 'Profesional de prueba') RETURNING id", [$proId]);
$eventos = static fn (string $tipo, ?string $usuario = null): int => (int) $conexion->fetchOne(
    'SELECT count(*) FROM eventos_acceso WHERE tipo = ? AND (?::bigint IS NULL OR usuario_id = ?::bigint)', [$tipo, $usuario, $usuario],
);

// Ingreso, CSRF del ingreso y regeneración de la sesión.
$anonima = new Cliente($kernel, '203.0.113.10');
[$estado, $datos] = $anonima->pedir('GET', '/api/admin/sesion');
verificar($estado === 200 && $datos['usuario'] === null && strlen($datos['csrf']) > 20, 'La sesión anónima debe entregar un token CSRF.');
$rutaPasskey = '/api/admin/sesion/clave-acceso';
verificar($anonima->pedir('POST', $rutaPasskey.'/opciones', [], false)[0] === 403, 'El desafío WebAuthn exige CSRF.');
verificar($anonima->pedir('POST', $rutaPasskey, [], false)[0] === 403, 'El ingreso WebAuthn exige CSRF.');
verificar($anonima->pedir('POST', $rutaPasskey, [])[0] === 401, 'WebAuthn exige un desafío vigente.');
[$estado, $opciones] = $anonima->pedir('POST', $rutaPasskey.'/opciones', []);
verificar($estado === 200 && $opciones['userVerification'] === 'required' && strlen($opciones['challenge']) >= 43, 'WebAuthn exige verificar a la usuaria.');
verificar($anonima->pedir('POST', $rutaPasskey, ['id' => 'falsa'])[0] === 401, 'WebAuthn rechaza una respuesta incompleta.');

[$estado, $datos] = $anonima->pedir('POST', '/api/admin/sesion', ['email' => 'admin@zenli.test', 'clave' => $clave], false);
verificar($estado === 403 && $datos['error'] === 'La sesión del formulario venció. Recargá la página.', 'El ingreso exige CSRF y responde en JSON.');
verificar($anonima->pedir('POST', '/api/admin/sesion', ['email' => 'admin@zenli.test', 'clave' => $clave], true, 'incorrecto')[0] === 403, 'El ingreso exige un CSRF válido.');
[$estado, $datos] = $anonima->pedir('POST', '/api/admin/sesion', ['email' => 'admin@zenli.test', 'clave' => 'otra clave']);
verificar($estado === 401 && $datos['error'] === 'Correo o contraseña incorrectos.', 'Una contraseña incorrecta responde 401.');
verificar($anonima->pedir('POST', '/api/admin/sesion', ['email' => 'nadie@zenli.test', 'clave' => $clave])[1]['error'] === 'Correo o contraseña incorrectos.', 'Un correo inexistente responde igual.');
$admin = new Cliente($kernel, '203.0.113.11');
$admin->pedir('GET', '/api/admin/sesion');
$sesionAnterior = $admin->cookies;
$csrfAnterior = $admin->csrf;
[$estado, $datos] = $admin->pedir('POST', '/api/admin/sesion', ['email' => ' ADMIN@zenli.test ', 'clave' => $clave]);
verificar($estado === 200 && $datos['usuario'] === ['id' => $adminId, 'email' => 'admin@zenli.test', 'admin' => true, 'profesional_id' => null], 'La administradora ingresa con el correo normalizado.');
verificar($admin->cookies !== $sesionAnterior && $admin->csrf !== $csrfAnterior, 'Ingresar regenera la sesión y el token CSRF.');
$cookie = array_values(array_filter($admin->ultima->headers->getCookies(), static fn ($c) => $c->getName() === session_name()))[0] ?? null;
verificar($cookie !== null && $cookie->isHttpOnly() && $cookie->isSecure() && $cookie->getSameSite() === 'lax' && $cookie->getExpiresTime() === 0, 'La cookie de sesión es HttpOnly, Secure, SameSite=Lax y de navegador.');
verificar($admin->pedir('PATCH', '/api/admin/portada/modo', ['solo_imagen' => false], true, $csrfAnterior)[0] === 403, 'El token anterior al ingreso deja de valer.');
verificar($admin->pedir('PATCH', '/api/admin/portada/modo', ['solo_imagen' => false])[0] === 200, 'El token nuevo permite escribir.');
verificar($conexion->fetchOne("SELECT host(ip) FROM eventos_acceso WHERE tipo = 'INGRESO' AND usuario_id = ?", [$adminId]) === '203.0.113.11', 'El ingreso queda auditado con la IP.');
fwrite(STDOUT, "Ingreso, CSRF y regeneración de sesión verificados.\n");

// Permisos de cada ruta: sin sesión, profesional, administradora y escrituras sin CSRF.
$profesional = new Cliente($kernel, '203.0.113.12');
verificar($profesional->ingresar('pro@zenli.test', $clave) === 200, 'La profesional ingresa.');
$agenda = [
    'GET /api/admin/claves-acceso', 'POST /api/admin/claves-acceso', 'POST /api/admin/claves-acceso/opciones', 'DELETE /api/admin/claves-acceso/{id}',
    'GET /api/admin/agenda', 'POST /api/admin/agenda/turnos', 'PUT /api/admin/agenda/turnos/{id}', 'DELETE /api/admin/agenda/turnos/{id}',
    'POST /api/admin/agenda/turnos/buscar', 'PUT /api/admin/agenda/turnos/{id}/asistencia',
    'GET /api/admin/agenda/turnos/horario-habitual', 'GET /api/admin/agenda/turnos/disponibilidad',
    'PUT /api/admin/agenda/preparaciones/{id}', 'DELETE /api/admin/agenda/preparaciones/{id}',
    'GET /api/admin/agenda/clientes', 'POST /api/admin/agenda/clientes',
    'GET /api/admin/agenda/clientes/{id}', 'PUT /api/admin/agenda/clientes/{id}', 'DELETE /api/admin/agenda/clientes/{id}',
    'POST /api/admin/agenda/clientes/buscar', 'POST /api/admin/agenda/clientes/{id}/reactivar', 'POST /api/admin/agenda/clientes/{id}/observaciones',
    'GET /api/admin/agenda/mensajes', 'POST /api/admin/agenda/mensajes',
];
$revisadas = 0;
$agendaRevisadas = [];
foreach ($kernel->getContainer()->get('router')->getRouteCollection() as $ruta) {
    $plantilla = $ruta->getPath();
    if (!str_starts_with($plantilla, '/api/admin') || str_starts_with($plantilla, '/api/admin/sesion')) {
        continue;
    }
    $idRuta = $plantilla === '/api/admin/claves-acceso/{id}' ? str_repeat('a', 64) : '999999';
    $url = strtr($plantilla, ['{id}' => $idRuta, '{version}' => str_repeat('a', 64), '{fecha}' => '2099-06-18']);
    foreach ($ruta->getMethods() as $metodo) {
        $clave_ruta = "$metodo $plantilla";
        $esAgenda = in_array($clave_ruta, $agenda, true);
        $anonimo = $anonima->pedir($metodo, $url)[0];
        verificar($anonimo === 401, "Sin sesión, $clave_ruta debe responder 401 y respondió $anonimo.");
        $deProfesional = $profesional->pedir($metodo, $url)[0];
        verificar($esAgenda ? !in_array($deProfesional, [401, 403], true) : $deProfesional === 403, "Para una profesional, $clave_ruta respondió $deProfesional.");
        if (!in_array($metodo, ['GET', 'HEAD'], true)) {
            [$sinToken, $error] = $admin->pedir($metodo, $url, null, false);
            verificar($sinToken === 403 && ($error['error'] ?? null) === 'La sesión del formulario venció. Recargá la página.', "Sin CSRF, $clave_ruta debe responder 403 y respondió $sinToken.");
        }
        $deAdmin = $admin->pedir($metodo, $url)[0];
        verificar(!in_array($deAdmin, [401, 403], true), "Para administración, $clave_ruta respondió $deAdmin.");
        $revisadas++;
        if ($esAgenda) {
            $agendaRevisadas[] = $clave_ruta;
        }
    }
}
sort($agenda);
sort($agendaRevisadas);
verificar($revisadas > 45 && $agendaRevisadas === $agenda, "La matriz debe cubrir todas las rutas ($revisadas revisadas).");
fwrite(STDOUT, "Permisos verificados en $revisadas combinaciones de ruta y método.\n");

$rutaFeriado = '/api/admin/agenda/feriados/2099-06-18';
$agendaFeriado = '/api/admin/agenda?fecha=2099-06-18';
verificar($admin->pedir('PUT', $rutaFeriado, ['activo' => true])[0] === 200, 'Administración marca el feriado.');
verificar($profesional->pedir('GET', $agendaFeriado)[1]['feriados'] === ['2099-06-18'], 'La profesional ve el feriado en su agenda.');
verificar($admin->pedir('PUT', $rutaFeriado, ['activo' => false])[0] === 200, 'Administración quita el feriado.');
verificar($profesional->pedir('GET', $agendaFeriado)[1]['feriados'] === [], 'La agenda deja de mostrar el feriado.');

// Demora creciente por cuenta y desbloqueo desde el panel y la consola.
$intentos = static fn (string $email): array => $conexion->fetchAssociative(
    "SELECT intentos, extract(epoch FROM bloqueado_hasta - statement_timestamp()) AS segundos FROM intentos_acceso_admin WHERE clave = encode(sha256(convert_to('cuenta:' || ?, 'UTF8')), 'hex')", [$email],
) ?: ['intentos' => 0, 'segundos' => null];
$atacante = new Cliente($kernel, '198.51.100.20');
for ($i = 1; $i <= 5; $i++) {
    verificar($atacante->ingresar('pro@zenli.test', 'clave equivocada') === 401, "El fallo $i responde 401.");
}
[$estado, $datos] = $atacante->pedir('POST', '/api/admin/sesion', ['email' => 'pro@zenli.test', 'clave' => $clave]);
$espera = (int) $atacante->ultima->headers->get('Retry-After');
verificar($estado === 429 && $espera > 0 && $espera <= 60 && $datos['error'] === 'Demasiados intentos. Probá nuevamente en 1 minuto.', 'El quinto fallo demora un minuto, aun con la contraseña correcta.');
verificar($intentos('pro@zenli.test')['intentos'] === 5, 'Durante la demora no se suman fallos.');
$perfiles = $admin->pedir('GET', '/api/admin/agenda/profesionales')[1]['profesionales'];
$perfil = array_values(array_filter($perfiles, static fn ($p) => $p['id'] === $perfilPro))[0];
verificar($perfil['usuario_id'] === $proId && is_string($perfil['demora_hasta']), 'El panel muestra la demora de la profesional.');
verificar($profesional->pedir('DELETE', "/api/admin/usuarios/$proId/bloqueo")[0] === 403, 'Una profesional no puede desbloquear cuentas.');
verificar($admin->pedir('DELETE', '/api/admin/usuarios/999999/bloqueo')[0] === 404, 'Desbloquear una cuenta inexistente responde 404.');
verificar($admin->pedir('DELETE', "/api/admin/usuarios/$proId/bloqueo")[0] === 200, 'La administración desbloquea la cuenta.');
verificar((int) $conexion->fetchOne("SELECT count(*) FROM eventos_acceso WHERE tipo = 'DESBLOQUEO' AND usuario_id = ? AND autor_id = ?", [$proId, $adminId]) === 1, 'El desbloqueo queda auditado con su autora.');
verificar($atacante->ingresar('pro@zenli.test', $clave) === 200, 'Tras el desbloqueo, la contraseña correcta ingresa.');
verificar($intentos('pro@zenli.test')['intentos'] === 0, 'Un ingreso correcto reinicia el contador.');
$atacante->pedir('DELETE', '/api/admin/sesion');
for ($i = 1; $i <= 5; $i++) {
    $atacante->ingresar('pro@zenli.test', 'clave equivocada');
}
$vencer = static fn (string $email) => $conexion->executeStatement(
    "UPDATE intentos_acceso_admin SET bloqueado_hasta = statement_timestamp() - interval '1 second' WHERE clave = encode(sha256(convert_to('cuenta:' || ?, 'UTF8')), 'hex')", [$email],
);
$vencer('pro@zenli.test');
verificar($atacante->ingresar('pro@zenli.test', 'clave equivocada') === 401, 'Vencida la demora, se puede volver a intentar.');
$estadoCuenta = $intentos('pro@zenli.test');
verificar($estadoCuenta['intentos'] === 6 && $estadoCuenta['segundos'] > 110 && $estadoCuenta['segundos'] <= 120, 'El sexto fallo duplica la demora a dos minutos.');
$conexion->executeStatement("UPDATE intentos_acceso_admin SET intentos = 40 WHERE clave = encode(sha256(convert_to('cuenta:pro@zenli.test', 'UTF8')), 'hex')");
$vencer('pro@zenli.test');
$atacante->ingresar('pro@zenli.test', 'clave equivocada');
$estadoCuenta = $intentos('pro@zenli.test');
verificar($estadoCuenta['segundos'] > 3590 && $estadoCuenta['segundos'] <= 3600, 'La demora no supera una hora.');
$consola = static function (string $email): int {
    exec(sprintf('php %s zenli:admin:desbloquear %s 2>&1', escapeshellarg(__DIR__.'/../bin/console'), escapeshellarg($email)), $salida, $codigo);

    return $codigo;
};
verificar($consola('pro@zenli.test') === 0 && $intentos('pro@zenli.test')['intentos'] === 0, 'La consola desbloquea la cuenta.');
verificar($consola('nadie@zenli.test') === 1, 'La consola informa una cuenta inexistente.');
verificar((int) $conexion->fetchOne("SELECT count(*) FROM eventos_acceso WHERE tipo = 'DESBLOQUEO' AND usuario_id = ? AND autor_id IS NULL", [$proId]) === 1, 'El desbloqueo por consola queda auditado sin autora.');
verificar($eventos('DEMORA', $proId) >= 1 && $eventos('FALLO', $proId) >= 11, 'Fallos y demoras quedan auditados.');
fwrite(STDOUT, "Demora creciente y desbloqueo verificados.\n");

// Demora por IP: muchos correos distintos desde el mismo origen.
$barrido = new Cliente($kernel, '192.0.2.50');
for ($i = 1; $i <= 20; $i++) {
    verificar($barrido->ingresar("inexistente$i@zenli.test", 'clave equivocada') === 401, 'Los correos inexistentes responden 401.');
}
verificar($barrido->ingresar('admin@zenli.test', $clave) === 429, 'Veinte fallos desde una IP demoran cualquier cuenta desde esa IP.');
verificar((new Cliente($kernel, '192.0.2.51'))->ingresar('admin@zenli.test', $clave) === 200, 'La demora por IP no afecta a otros orígenes.');
verificar((int) $conexion->fetchOne("SELECT count(*) FROM eventos_acceso WHERE tipo = 'FALLO' AND usuario_id IS NULL AND ip = '192.0.2.50'") === 20, 'Los fallos con correos inexistentes se auditan sin cuenta.');
fwrite(STDOUT, "Demora por IP verificada.\n");

// Cambios de la cuenta invalidan sus sesiones abiertas.
$otra = new Cliente($kernel, '203.0.113.13');
verificar($otra->ingresar('otra@zenli.test', $clave) === 200, 'La segunda administradora ingresa.');
verificar($admin->pedir('PATCH', "/api/admin/usuarios/$otraId", ['activo' => true, 'clave' => 'Clave nueva y segura 2'])[0] === 200, 'Se cambia la contraseña de otra cuenta.');
verificar($otra->pedir('GET', '/api/admin/agenda/configuracion')[0] === 401, 'Cambiar la contraseña cierra las sesiones de esa cuenta.');
verificar($otra->ingresar('otra@zenli.test', 'Clave nueva y segura 2') === 200, 'La contraseña nueva ingresa.');
verificar($admin->pedir('PATCH', "/api/admin/usuarios/$otraId", ['activo' => false, 'clave' => ''])[0] === 200, 'Se desactiva la cuenta.');
verificar($otra->pedir('GET', '/api/admin/agenda/configuracion')[0] === 401, 'Desactivar la cuenta cierra sus sesiones.');
verificar($otra->ingresar('otra@zenli.test', 'Clave nueva y segura 2') === 401, 'Una cuenta desactivada no ingresa.');
verificar($admin->pedir('PATCH', "/api/admin/usuarios/$otraId", ['activo' => true, 'clave' => ''])[0] === 200, 'Se reactiva la cuenta.');
verificar($eventos('CLAVE_CAMBIADA', $otraId) === 1 && $eventos('CUENTA_DESACTIVADA', $otraId) === 1 && $eventos('CUENTA_ACTIVADA', $otraId) === 1, 'Los cambios de cuenta quedan auditados.');
verificar($profesional->pedir('GET', '/api/admin/agenda?fecha=2099-01-01')[0] === 200, 'La profesional sigue con su sesión.');
verificar($admin->pedir('PUT', "/api/admin/agenda/profesionales/$perfilPro/estado", ['activa' => false])[0] === 200, 'Se da de baja el perfil profesional.');
verificar($profesional->pedir('GET', '/api/admin/agenda?fecha=2099-01-01')[0] === 401, 'Dar de baja el perfil cierra la sesión de la profesional.');
$admin->pedir('PUT', "/api/admin/agenda/profesionales/$perfilPro/estado", ['activa' => true]);
fwrite(STDOUT, "Invalidación de sesiones por cambios de cuenta verificada.\n");

// Una hora sin actividad cierra la sesión.
$envejecer = static function (Cliente $cliente, int $segundos) use ($sesiones): void {
    $archivo = $sesiones.'/sess_'.$cliente->cookies[session_name()];
    $contenido = file_get_contents($archivo);
    $nuevo = preg_replace('/s:1:"u";i:\d+;/', 's:1:"u";i:'.(time() - $segundos).';', $contenido, 1, $reemplazos);
    verificar($reemplazos === 1, 'La sesión debe tener metadatos de último uso.');
    file_put_contents($archivo, $nuevo);
};
$inactiva = new Cliente($kernel, '203.0.113.14');
verificar($inactiva->ingresar('pro@zenli.test', $clave) === 200, 'La profesional vuelve a ingresar.');
$envejecer($inactiva, 3500);
verificar($inactiva->pedir('GET', '/api/admin/agenda?fecha=2099-01-01')[0] === 200, 'Menos de una hora sin actividad conserva la sesión.');
$envejecer($inactiva, 3601);
verificar($inactiva->pedir('GET', '/api/admin/agenda?fecha=2099-01-01')[0] === 401, 'Más de una hora sin actividad cierra la sesión.');
fwrite(STDOUT, "Cierre por inactividad verificado.\n");

// Salir invalida la sesión en el servidor, no solo en el navegador.
$copia = $admin->cookies;
[$estado, $datos] = $admin->pedir('DELETE', '/api/admin/sesion');
verificar($estado === 200 && $datos['usuario'] === null, 'Salir devuelve una sesión anónima.');
$robada = new Cliente($kernel, '203.0.113.15');
$robada->cookies = $copia;
verificar($robada->pedir('GET', '/api/admin/agenda/configuracion')[0] === 401, 'La cookie anterior a salir ya no sirve.');
verificar($eventos('SALIDA', $adminId) === 1, 'La salida queda auditada.');
fwrite(STDOUT, "Cierre de sesión verificado.\n");
