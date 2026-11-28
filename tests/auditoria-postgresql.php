<?php

declare(strict_types=1);

// Prueba integral de auditoría de datos e historial de turnos a través del kernel real de Symfony.
// Ejecutar con tests/auditoria-postgresql.sh.

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require getenv('ZENLI_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';

$sesiones = getenv('ZENLI_SESIONES');
if (!is_string($sesiones) || !is_dir($sesiones) || getenv('APP_ENV') !== 'prod') {
    throw new RuntimeException('Ejecutá la prueba con tests/auditoria-postgresql.sh.');
}

function verificar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

final class ClienteAuditoria
{
    public array $cookies = [];
    public string $csrf = '';

    public function __construct(private readonly Kernel $kernel, public readonly string $ip)
    {
    }

    /** @return array{0: int, 1: mixed} */
    public function pedir(string $metodo, string $ruta, ?array $cuerpo = null, ?array $formulario = null): array
    {
        $servidor = ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT' => 'application/json'];
        if ($metodo !== 'GET') {
            $servidor['HTTP_X_ZENLI_CSRF'] = $this->csrf;
        }
        if ($cuerpo !== null) {
            $servidor['CONTENT_TYPE'] = 'application/json';
        }
        $peticion = Request::create(
            $ruta,
            $metodo,
            $formulario ?? [],
            $this->cookies,
            [],
            $servidor,
            $cuerpo === null ? null : json_encode($cuerpo, JSON_THROW_ON_ERROR),
        );
        $respuesta = $this->kernel->handle($peticion);
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
        $this->kernel->terminate($peticion, $respuesta);
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

[$anaId, $lauraId, $proId] = $conexion->fetchFirstColumn(
    "INSERT INTO usuarios (email, password_hash, roles) VALUES ('ana@zenli.test', ?, '[\"ROLE_ADMIN\"]'), ('laura@zenli.test', ?, '[\"ROLE_ADMIN\"]'), ('pro@zenli.test', ?, '[]') RETURNING id::text",
    [$hash, $hash, $hash],
);
$anaProfId = (string) $conexion->fetchOne("INSERT INTO profesionales (usuario_id, nombre) VALUES (?, 'Ana') RETURNING id", [$anaId]);
$lauraProfId = (string) $conexion->fetchOne("INSERT INTO profesionales (usuario_id, nombre) VALUES (?, 'Laura') RETURNING id", [$lauraId]);
$proProfId = (string) $conexion->fetchOne("INSERT INTO profesionales (usuario_id, nombre) VALUES (?, 'Camila') RETURNING id", [$proId]);

$anonima = new ClienteAuditoria($kernel, '203.0.113.10');
$ana = new ClienteAuditoria($kernel, '203.0.113.11');
$laura = new ClienteAuditoria($kernel, '203.0.113.12');
$profesional = new ClienteAuditoria($kernel, '203.0.113.13');
verificar($ana->ingresar('ana@zenli.test', $clave) === 200, 'Ana debe ingresar.');
verificar($laura->ingresar('laura@zenli.test', $clave) === 200, 'Laura debe ingresar.');
verificar($profesional->ingresar('pro@zenli.test', $clave) === 200, 'La profesional debe ingresar.');

// 1. Acceso exclusivo de administración.
foreach (['/api/admin/portada', '/api/admin/auditoria/datos', '/api/admin/auditoria/turnos', '/api/admin/auditoria/turnos/999999', '/api/admin/auditoria/accesos'] as $ruta) {
    verificar($anonima->pedir('GET', $ruta)[0] === 401, "Sin sesión, $ruta debe responder 401.");
    verificar($profesional->pedir('GET', $ruta)[0] === 403, "Profesional no debe acceder a $ruta.");
}
verificar($ana->pedir('GET', '/api/admin/auditoria/turnos/999999')[0] === 404, 'Un turno inexistente debe devolver 404.');
fwrite(STDOUT, "Permisos exclusivos de administración verificados.\n");

// 2. Autoría de datos en portada, servicios, promociones, espacios, profesionales, horarios, preparación y cuentas.
[$est, $resp] = $ana->pedir('PATCH', '/api/admin/portada/modo', ['solo_imagen' => false]);
verificar($est === 200 && ($resp['auditoria']['actualizado_por'] ?? null) === 'Ana', 'Portada registra último autor Ana.');
[$est, $portadaAdmin] = $laura->pedir('GET', '/api/admin/portada');
verificar($est === 200 && ($portadaAdmin['auditoria']['actualizado_por'] ?? null) === 'Ana', 'GET /api/admin/portada devuelve auditoría.');
[$est, $portadaPublica] = $anonima->pedir('GET', '/api/portada');
verificar($est === 200 && !array_key_exists('auditoria', $portadaPublica), 'GET /api/portada pública no debe exponer auditoría.');

[$est, $respServ] = $ana->pedir('POST', '/api/admin/servicios', null, [
    'nombre' => 'Masaje Auditado', 'descripcion_corta' => 'Descripción breve de prueba', 'detalle' => 'Detalle completo',
]);
verificar($est === 201, 'Ana crea servicio.');
$servId = (int) $respServ['creado'];
[$est] = $laura->pedir('PATCH', "/api/admin/servicios/$servId/configuracion", ['duracion_minima' => 60, 'profesionales_requeridos' => 1]);
verificar($est === 200, 'Laura configura el servicio.');
[$est, $listaServ] = $ana->pedir('GET', '/api/admin/servicios');
$servAuditado = array_values(array_filter($listaServ['servicios'], static fn ($s) => (int) $s['id'] === $servId))[0];
verificar($servAuditado['auditoria']['creado_por'] === 'Ana' && $servAuditado['auditoria']['actualizado_por'] === 'Laura' && is_string($servAuditado['auditoria']['creado_en']), 'Servicio conserva creador Ana y modificador Laura.');
[$est, $servPublicos] = $anonima->pedir('GET', '/api/servicios');
verificar($est === 200 && !array_key_exists('auditoria', $servPublicos['servicios'][0] ?? []), 'GET /api/servicios público no expone auditoría.');

[$est] = $ana->pedir('POST', '/api/admin/promociones', ['nombre' => 'Promo Auditada', 'descripcion' => 'Descripción válida de promoción']);
verificar($est === 201, 'Ana crea promoción.');
[$est, $promos] = $ana->pedir('GET', '/api/admin/promociones');
$promoId = (int) $promos['promociones'][0]['id'];
verificar($laura->pedir('PATCH', "/api/admin/promociones/$promoId/visibilidad", ['visible' => true])[0] === 200, 'Laura muestra promoción.');
[$est, $promos] = $ana->pedir('GET', '/api/admin/promociones');
verificar($promos['promociones'][0]['auditoria']['creado_por'] === 'Ana' && $promos['promociones'][0]['auditoria']['actualizado_por'] === 'Laura', 'Promoción registra creador y último modificador.');
[$est, $promosPublicas] = $anonima->pedir('GET', '/api/promociones');
verificar($est === 200 && !array_key_exists('auditoria', $promosPublicas['promociones'][0] ?? []), 'GET /api/promociones público no expone auditoría.');

[$est, $cfg] = $ana->pedir('POST', '/api/admin/agenda/espacios', ['nombre' => 'Sala Auditada', 'detalle' => 'Camilla nueva']);
verificar($est === 201, 'Ana crea espacio.');
$espacioCreado = array_values(array_filter($cfg['espacios'], static fn ($e) => $e['nombre'] === 'Sala Auditada'))[0];
$espacioId = $espacioCreado['id'];
verificar($laura->pedir('PUT', "/api/admin/agenda/espacios/$espacioId", ['nombre' => 'Sala Auditada 2', 'detalle' => 'Detalle actualizado'])[0] === 200, 'Laura edita espacio.');
[$est, $cfg] = $ana->pedir('GET', '/api/admin/agenda/configuracion');
$espacioEditado = array_values(array_filter($cfg['espacios'], static fn ($e) => $e['id'] === $espacioId))[0];
verificar($espacioEditado['auditoria']['creado_por'] === 'Ana' && $espacioEditado['auditoria']['actualizado_por'] === 'Laura', 'Espacio registra creador Ana y modificador Laura.');

verificar($ana->pedir('PUT', '/api/admin/agenda/preparacion', ['modo' => 'general', 'minutos' => 15])[0] === 200, 'Ana configura preparación.');
[$est, $cfg] = $laura->pedir('GET', '/api/admin/agenda/configuracion');
verificar(($cfg['preparacion_auditoria']['actualizado_por'] ?? null) === 'Ana', 'Preparación expone autor Ana.');

[$est, $profNueva] = $ana->pedir('POST', '/api/admin/agenda/profesionales', ['nombre' => 'Sofía']);
verificar($est === 201, 'Ana crea perfil profesional.');
$sofiaId = $profNueva['id'];
verificar($laura->pedir('PUT', "/api/admin/agenda/profesionales/$sofiaId", ['nombre' => 'Sofía Gómez'])[0] === 200, 'Laura renombra profesional.');
[$est, $listaProf] = $ana->pedir('GET', '/api/admin/agenda/profesionales');
$sofia = array_values(array_filter($listaProf['profesionales'], static fn ($p) => $p['id'] === $sofiaId))[0];
verificar($sofia['auditoria']['creado_por'] === 'Ana' && $sofia['auditoria']['actualizado_por'] === 'Laura', 'Perfil profesional registra creador Ana y modificador Laura.');

verificar($ana->pedir('PUT', "/api/admin/agenda/profesionales/$sofiaId/horarios", [
    'por_franjas' => false, 'revision' => 0, 'franjas' => [],
    'excepciones' => [['tipo' => 'bloqueo', 'fecha' => '2099-09-10', 'todo_el_dia' => true]],
])[0] === 200, 'Ana guarda horarios y bloqueo.');
[$est, $hor] = $laura->pedir('GET', "/api/admin/agenda/profesionales/$sofiaId/horarios");
verificar($est === 200 && ($hor['auditoria']['actualizado_por'] ?? null) === 'Ana', 'Horarios expone último autor Ana.');
verificar(($hor['excepciones'][0]['creado_por'] ?? null) === 'Ana' && is_string($hor['excepciones'][0]['creado_en'] ?? null), 'Bloqueo expone creador y fecha.');

verificar($ana->pedir('POST', '/api/admin/usuarios', ['email' => 'nueva@zenli.test', 'clave' => 'Clave segura nueva 12'])[0] === 201, 'Ana crea cuenta admin.');
[$est, $listaUs] = $ana->pedir('GET', '/api/admin/usuarios');
$nuevaCuenta = array_values(array_filter($listaUs['usuarios'], static fn ($u) => $u['email'] === 'nueva@zenli.test'))[0];
verificar($laura->pedir('PATCH', '/api/admin/usuarios/'.$nuevaCuenta['id'], ['activo' => false, 'clave' => ''])[0] === 200, 'Laura desactiva cuenta.');
[$est, $listaUs] = $ana->pedir('GET', '/api/admin/usuarios');
$nuevaCuenta = array_values(array_filter($listaUs['usuarios'], static fn ($u) => $u['email'] === 'nueva@zenli.test'))[0];
verificar($nuevaCuenta['auditoria']['creado_por'] === 'Ana' && $nuevaCuenta['auditoria']['actualizado_por'] === 'Laura', 'Cuenta registra creador Ana y modificador Laura.');

[$est, $resumenDatos] = $ana->pedir('GET', '/api/admin/auditoria/datos');
verificar($est === 200 && ($resumenDatos['portada']['actualizado_por'] ?? null) === 'Ana' && ($resumenDatos['preparacion']['actualizado_por'] ?? null) === 'Ana', 'Resumen de auditoría de datos devuelve portada y preparación.');
$sofiaResumen = array_values(array_filter($resumenDatos['profesionales'], static fn ($p) => $p['id'] === $sofiaId))[0];
verificar($sofiaResumen['auditoria']['creado_por'] === 'Ana' && $sofiaResumen['auditoria']['actualizado_por'] === 'Laura' && $sofiaResumen['horarios_auditoria']['actualizado_por'] === 'Ana', 'Resumen de auditoría incluye perfil y horarios de profesionales.');
fwrite(STDOUT, "Auditoría de autoría de datos verificada.\n");

// 3. Historial completo de turnos: creación, seña, edición de horario/profesionales, preparación y cancelación.
[$est, $creado] = $ana->pedir('POST', '/api/admin/agenda/turnos', [
    'cliente' => 'Lucía Cliente',
    'servicio' => (string) $servId,
    'espacio' => $espacioId,
    'profesionales' => [$proProfId],
    'inicio' => '2099-06-03T10:00',
    'duracion' => 60,
    'sena_pagada' => false,
    'sena_importe_centavos' => null,
]);
verificar($est === 201, 'Ana crea turno.');
$turnoId = $creado['id'];

// Verificar que GET /api/admin/agenda para profesional no filtra auditoría de espacios.
[$est, $agendaPro] = $profesional->pedir('GET', '/api/admin/agenda?fecha=2099-06-03');
verificar($est === 200 && !array_key_exists('auditoria', $agendaPro['espacios'][0] ?? []), 'La agenda para profesionales no expone auditoría.');

// Ana carga la seña (pagada + $ 5.000,00).
[$est] = $ana->pedir('PUT', "/api/admin/agenda/turnos/$turnoId", [
    'cliente' => 'Lucía Cliente',
    'servicio' => (string) $servId,
    'espacio' => $espacioId,
    'profesionales' => [$proProfId],
    'inicio' => '2099-06-03T10:00',
    'duracion' => 60,
    'sena_pagada' => true,
    'sena_importe_centavos' => '500000',
    'revision' => 1,
]);
verificar($est === 200, 'Ana registra la seña del turno.');

// Laura mueve el horario y cambia el equipo.
[$est, $agendaDia] = $laura->pedir('GET', '/api/admin/agenda?fecha=2099-06-03');
$prep = $agendaDia['preparaciones'][0];
verificar($laura->pedir('PUT', '/api/admin/agenda/preparaciones/'.$prep['id'], [
    'inicio' => '2099-06-03T13:00', 'fin' => '2099-06-03T13:20', 'revision' => 1,
])[0] === 200, 'Laura edita la preparación.');
[$estEdicion, $respEdicion] = $laura->pedir('PUT', "/api/admin/agenda/turnos/$turnoId", [
    'cliente' => 'Lucía Cliente',
    'servicio' => (string) $servId,
    'espacio' => $espacioId,
    'profesionales' => [$anaProfId, $proProfId],
    'inicio' => '2099-06-03T11:00',
    'duracion' => 90,
    'sena_pagada' => true,
    'sena_importe_centavos' => '500000',
    'revision' => 2,
]);
verificar($estEdicion === 200, 'Laura edita horario y profesionales: '.$estEdicion.' '.json_encode($respEdicion));

// Laura cancela el turno.
verificar($laura->pedir('DELETE', "/api/admin/agenda/turnos/$turnoId", ['revision' => 3])[0] === 200, 'Laura cancela el turno.');

// Consultar historial individual del turno.
[$est, $detalle] = $ana->pedir('GET', "/api/admin/auditoria/turnos/$turnoId");
verificar($est === 200 && $detalle['turno']['cancelado'] === true && $detalle['turno']['creado_por'] === 'Ana', 'Historial individual incluye cabecera y estado cancelado.');
$eventos = $detalle['eventos'];
verificar(count($eventos) === 5, 'Debe incluir creación, edición de seña, edición de preparación, edición de horario y cancelación.');
verificar($eventos[0]['tipo'] === 'CANCELADO' && $eventos[0]['autor'] === 'Laura' && $eventos[0]['resumen'] === 'Turno cancelado', 'El evento más reciente es la cancelación por Laura.');
verificar($eventos[1]['tipo'] === 'EDITADO' && $eventos[1]['autor'] === 'Laura' && str_contains($eventos[1]['resumen'], 'Horario cambiado') && str_contains($eventos[1]['resumen'], 'Profesionales:'), 'La edición describe horario y profesionales.');
verificar($eventos[2]['tipo'] === 'PREP_EDITADA' && $eventos[2]['autor'] === 'Laura' && str_contains($eventos[2]['resumen'], '11:00–11:15') && str_contains($eventos[2]['resumen'], '13:00–13:20'), 'La preparación describe el rango anterior y nuevo.');
verificar($eventos[3]['tipo'] === 'EDITADO' && $eventos[3]['autor'] === 'Ana' && $eventos[3]['cambio_sena'] === true && str_contains($eventos[3]['resumen'], '5.000,00'), 'El cambio de seña indica autora Ana e importe.');
verificar($eventos[4]['tipo'] === 'CREADO' && $eventos[4]['autor'] === 'Ana', 'La creación registra a Ana.');

// Consultar filtros: solo señas, solo cancelaciones, y por período del turno.
[$est, $soloSenas] = $ana->pedir('GET', '/api/admin/auditoria/turnos?tipo=sena&cliente=lucía');
verificar($est === 200 && count($soloSenas['eventos']) === 1 && $soloSenas['eventos'][0]['autor'] === 'Ana', 'El filtro de señas encuentra el cambio de seña.');
[$est, $soloCancelados] = $ana->pedir('GET', '/api/admin/auditoria/turnos?tipo=cancelado');
verificar($est === 200 && count($soloCancelados['eventos']) === 1 && $soloCancelados['eventos'][0]['autor'] === 'Laura', 'El filtro de cancelaciones encuentra la cancelación.');
[$est, $porPeriodo] = $ana->pedir('GET', '/api/admin/auditoria/turnos?por=turno&desde=2099-06-01&hasta=2099-06-07');
verificar($est === 200 && count($porPeriodo['cancelados']) === 1 && $porPeriodo['cancelados'][0]['cancelado_por'] === 'Laura' && $porPeriodo['cancelados'][0]['sena_pagada'] === true, 'La consulta por período de turno incluye cancelados con autora y seña.');

fwrite(STDOUT, "Historial de turnos (señas, ediciones, preparaciones y cancelaciones) verificado.\n");

// 4. Auditoría de accesos y seguridad.
[$est, $accesos] = $ana->pedir('GET', '/api/admin/auditoria/accesos?limite=10');
verificar($est === 200 && is_array($accesos) && count($accesos) >= 1, 'GET /api/admin/auditoria/accesos debe devolver 200 con lista de eventos.');
verificar(isset($accesos[0]['id'], $accesos[0]['tipo'], $accesos[0]['ip'], $accesos[0]['creado_en']), 'Cada evento de acceso debe contener id, tipo, ip y creado_en.');
[$estFiltro, $accesosFiltro] = $ana->pedir('GET', '/api/admin/auditoria/accesos?tipo=INGRESO');
verificar($estFiltro === 200 && is_array($accesosFiltro), 'Filtrar por tipo INGRESO debe responder 200.');
foreach ($accesosFiltro as $ev) {
    verificar($ev['tipo'] === 'INGRESO', 'El filtro por tipo solo debe traer eventos del tipo solicitado.');
}
fwrite(STDOUT, "Auditoría de eventos de acceso verificada.\n");
