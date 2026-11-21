<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\ConfiguracionPortada;
use App\Administracion\SesionAdministrativa;
use App\Administracion\ServiciosPortada;
use App\Administracion\UsuariosAdministrativos;
use App\Seguridad\AuditoriaAccesos;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
final readonly class AdministracionController
{
    public function __construct(
        private SesionAdministrativa $sesion,
        private ConfiguracionPortada $portada,
        private ServiciosPortada $servicios,
        private UsuariosAdministrativos $usuarios,
        private Security $seguridad,
        private AuditoriaAccesos $auditoria,
    ) {
    }

    #[Route('/sesion', methods: ['GET'])]
    public function sesion(): JsonResponse
    {
        return $this->respuesta(['usuario' => $this->sesion->usuario(), 'csrf' => $this->sesion->token()]);
    }

    /** El autenticador ya validó el ingreso; si llega acá, la sesión es nueva y está autenticada. */
    #[Route('/sesion', methods: ['POST'])]
    public function ingresar(): JsonResponse
    {
        return $this->sesion();
    }

    #[Route('/sesion', methods: ['DELETE'])]
    public function salir(Request $peticion): JsonResponse
    {
        $cuenta = $this->sesion->cuenta();
        if ($cuenta !== null) {
            $this->auditoria->registrar('SALIDA', $cuenta->id);
            $this->seguridad->logout(false);
        }
        $peticion->getSession()->invalidate();

        return $this->sesion();
    }

    #[Route('/portada/modo', methods: ['PATCH'])]
    public function cambiarModoPortada(Request $peticion): JsonResponse
    {
        $usuario = $this->sesion->actual();
        try {
            $datos = $peticion->toArray();
        } catch (JsonException) {
            $datos = [];
        }
        $soloImagen = $datos['solo_imagen'] ?? null;
        if (!is_bool($soloImagen)) {
            return $this->error('Elegí un modo de portada válido.', 422);
        }

        $this->portada->cambiarModo($soloImagen, $usuario['id']);

        return $this->respuesta(['solo_imagen' => $soloImagen]);
    }

    #[Route('/portada/visibilidad', methods: ['PATCH'])]
    public function cambiarVisibilidadPortada(Request $peticion): JsonResponse
    {
        $usuario = $this->sesion->actual();
        try {
            $datos = $peticion->toArray();
        } catch (JsonException) {
            $datos = [];
        }
        if (!is_string($datos['seccion'] ?? null) || !in_array($datos['seccion'], ConfiguracionPortada::SECCIONES, true) || !is_bool($datos['visible'] ?? null)) {
            return $this->error('Elegí una sección y visibilidad válidas.', 422);
        }
        $this->portada->cambiarVisibilidad($datos['seccion'], $datos['visible'], $usuario['id']);

        return $this->respuesta(['visibilidad' => $this->portada->consultar()['visibilidad']]);
    }

    #[Route('/portada', methods: ['POST'])]
    public function guardarPortada(Request $peticion): JsonResponse
    {
        $usuario = $this->sesion->actual();
        $archivo = $peticion->files->get('imagen');
        if ($archivo !== null && !$archivo instanceof UploadedFile) {
            return $this->error('Elegí un solo archivo.', 422);
        }

        try {
            $this->portada->guardar(
                $peticion->request->getString('telefono'),
                $archivo,
                $peticion->request->getString('restaurar') === '1',
                $usuario['id'],
                $peticion->request->has('instagram') ? $peticion->request->getString('instagram') : null,
                $peticion->request->has('facebook') ? $peticion->request->getString('facebook') : null,
                $peticion->request->has('direccion') ? $peticion->request->getString('direccion') : null,
                $peticion->request->has('horarios') ? $peticion->request->getString('horarios') : null,
            );
        } catch (InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }

        return $this->respuesta($this->portada->consultar());
    }

    #[Route('/usuarios', methods: ['GET'])]
    public function listarUsuarios(Request $peticion): JsonResponse
    {

        return $this->respuesta(['usuarios' => $this->usuarios->listar()]);
    }

    #[Route('/usuarios', methods: ['POST'])]
    public function crearUsuario(Request $peticion): JsonResponse
    {
        try {
            $datos = $peticion->toArray();
            $email = $datos['email'] ?? null;
            $clave = $datos['clave'] ?? null;
            if (!is_string($email) || !is_string($clave)) {
                return $this->error('Ingresá el correo y la contraseña.', 422);
            }
            $this->usuarios->crear($email, $clave, $this->sesion->actual()['id']);
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->error($error instanceof JsonException ? 'Revisá los datos de la cuenta.' : $error->getMessage(), 422);
        } catch (UniqueConstraintViolationException) {
            return $this->error('Ya existe una cuenta con ese correo.', 409);
        }

        return $this->respuesta(['usuarios' => $this->usuarios->listar()], 201);
    }

    #[Route('/usuarios/{id}', requirements: ['id' => '[1-9][0-9]{0,18}'], methods: ['PATCH'])]
    public function actualizarUsuario(Request $peticion, string $id): JsonResponse
    {
        $usuario = $this->sesion->actual();
        try {
            $datos = $peticion->toArray();
            $activo = $datos['activo'] ?? null;
            $clave = $datos['clave'] ?? '';
            if (!is_bool($activo) || !is_string($clave)) {
                return $this->error('Revisá los datos de la cuenta.', 422);
            }
            if (!$activo && $usuario['id'] === $id) {
                return $this->error('No podés desactivar tu propia cuenta.', 422);
            }
            if (!$this->usuarios->actualizar($id, $activo, $clave, $usuario['id'], $this->sesion->cuenta()->huella)) {
                return $this->error('No se pudo actualizar la cuenta. Volvé a ingresar si tu acceso cambió.', 409);
            }
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->error($error instanceof JsonException ? 'Revisá los datos de la cuenta.' : $error->getMessage(), 422);
        }

        return $this->respuesta(['usuarios' => $this->usuarios->listar()]);
    }

    /** Quita la demora de ingreso de una cuenta, administradora o profesional. */
    #[Route('/usuarios/{id}/bloqueo', requirements: ['id' => '[1-9][0-9]{0,18}'], methods: ['DELETE'])]
    public function desbloquearUsuario(string $id): JsonResponse
    {
        if (!$this->usuarios->desbloquear($id, $this->sesion->actual()['id'])) {
            return $this->error('La cuenta no existe.', 404);
        }

        return $this->respuesta(['usuarios' => $this->usuarios->listar()]);
    }

    #[Route('/servicios', methods: ['GET'])]
    public function listarServicios(Request $peticion): JsonResponse
    {
        return $this->respuesta(['servicios' => $this->servicios->listar()]);
    }

    #[Route('/servicios', methods: ['POST'])]
    public function crearServicio(Request $peticion): JsonResponse
    {
        $usuario = $this->sesion->actual();
        $archivo = $peticion->files->get('imagen');
        if ($archivo !== null && !$archivo instanceof UploadedFile) {
            return $this->error('Elegí un solo archivo.', 422);
        }

        try {
            $nombre = $peticion->request->getString('nombre');
            $descripcionCorta = $peticion->request->getString('descripcion_corta');
            $detalle = $peticion->request->getString('detalle');
            if ($nombre === '' || $descripcionCorta === '') {
                return $this->error('Completá nombre y descripción corta.', 422);
            }
            $id = $this->servicios->crear($nombre, $descripcionCorta, $detalle, $archivo, $usuario['id']);
        } catch (InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }

        return $this->respuesta(['servicios' => $this->servicios->listar(), 'creado' => $id], 201);
    }

    #[Route('/servicios/{id}', requirements: ['id' => '[1-9][0-9]{0,18}'], methods: ['POST', 'PATCH'])]
    public function actualizarServicio(Request $peticion, string $id): JsonResponse
    {
        $usuario = $this->sesion->actual();
        $archivo = $peticion->files->get('imagen');
        if ($archivo !== null && !$archivo instanceof UploadedFile) {
            return $this->error('Elegí un solo archivo.', 422);
        }

        try {
            $nombre = $peticion->request->getString('nombre');
            $descripcionCorta = $peticion->request->getString('descripcion_corta');
            $detalle = $peticion->request->getString('detalle');
            $eliminarImagen = $peticion->request->getString('eliminar_imagen') === '1';
            if ($nombre === '' || $descripcionCorta === '') {
                return $this->error('Completá nombre y descripción corta.', 422);
            }
            $this->servicios->actualizar((int)$id, $nombre, $descripcionCorta, $detalle, $archivo, $eliminarImagen, $usuario['id']);
        } catch (InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }

        return $this->respuesta(['servicios' => $this->servicios->listar()]);
    }

    #[Route('/servicios/{id}', requirements: ['id' => '[1-9][0-9]{0,18}'], methods: ['DELETE'])]
    public function eliminarServicio(Request $peticion, string $id): JsonResponse
    {
        $usuario = $this->sesion->actual();

        try {
            $this->servicios->eliminar((int)$id, $usuario['id']);
        } catch (InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }

        return $this->respuesta(['servicios' => $this->servicios->listar()]);
    }

    #[Route('/servicios/{id}/orden', requirements: ['id' => '[1-9][0-9]{0,18}'], methods: ['PATCH'])]
    public function cambiarOrdenServicio(Request $peticion, string $id): JsonResponse
    {
        $usuario = $this->sesion->actual();

        try {
            $datos = $peticion->toArray();
            $direccion = $datos['direccion'] ?? null;
            if (!is_int($direccion)) {
                throw new InvalidArgumentException('La dirección del movimiento no es válida.');
            }
            $servicios = $this->servicios->mover((int)$id, $direccion, $usuario['id']);
        } catch (JsonException) {
            return $this->error('La dirección del movimiento no es válida.', 400);
        } catch (InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }

        return $this->respuesta(['servicios' => $servicios]);
    }

    #[Route('/servicios/{id}/configuracion', requirements: ['id' => '[1-9][0-9]{0,18}'], methods: ['PATCH'])]
    public function configurarServicio(Request $peticion, string $id): JsonResponse
    {
        $usuario = $this->sesion->actual();
        try {
            $datos = $peticion->toArray();
            foreach (['duracion_minima', 'profesionales_requeridos'] as $campo) {
                if (!array_key_exists($campo, $datos) || ($datos[$campo] !== null && (!is_int($datos[$campo]) || $datos[$campo] < 1 || $datos[$campo] > 2147483647))) {
                    throw new InvalidArgumentException('La duración y la cantidad de profesionales deben ser enteros positivos o null.');
                }
            }
            $this->servicios->configurar((int) $id, $datos['duracion_minima'], $datos['profesionales_requeridos'], $usuario['id']);
        } catch (JsonException) {
            return $this->error('Revisá los datos de configuración.', 400);
        } catch (InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }

        return $this->respuesta(['servicios' => $this->servicios->listar()]);
    }

    #[Route('/servicios/{id}/visible', requirements: ['id' => '[1-9][0-9]{0,18}'], methods: ['PATCH'])]
    public function cambiarVisibilidadServicio(Request $peticion, string $id): JsonResponse
    {
        $usuario = $this->sesion->actual();

        try {
            $datos = $peticion->toArray();
            $visible = $datos['visible'] ?? null;
            if (!is_bool($visible)) {
                throw new InvalidArgumentException('Elegí una visibilidad válida.');
            }
            $this->servicios->cambiarVisibilidad((int)$id, $visible, $usuario['id']);
        } catch (JsonException) {
            return $this->error('Elegí una visibilidad válida.', 400);
        } catch (InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }

        return $this->respuesta(['servicios' => $this->servicios->listar()]);
    }

    private function error(string $mensaje, int $estado): JsonResponse
    {
        return $this->respuesta(['error' => $mensaje], $estado);
    }

    private function respuesta(array $datos, int $estado = 200): JsonResponse
    {
        return new JsonResponse($datos, $estado, ['Cache-Control' => 'no-store']);
    }
}
