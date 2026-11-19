<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\ConfiguracionPortada;
use App\Administracion\SesionAdministrativa;
use App\Administracion\UsuariosAdministrativos;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
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
        private UsuariosAdministrativos $usuarios,
    ) {
    }

    #[Route('/sesion', methods: ['GET'])]
    public function sesion(Request $peticion): JsonResponse
    {
        $sesion = $peticion->getSession();
        $usuario = $this->sesion->usuario($sesion);

        return $this->respuesta(['usuario' => $usuario, 'csrf' => $this->sesion->token($sesion)]);
    }

    #[Route('/sesion', methods: ['POST'])]
    public function ingresar(Request $peticion): JsonResponse
    {
        $this->sesion->verificarToken($peticion);
        try {
            $datos = $peticion->toArray();
        } catch (JsonException) {
            return $this->error('Ingresá el correo y la contraseña.', 400);
        }
        $email = $datos['email'] ?? null;
        $clave = $datos['clave'] ?? null;
        if (!is_string($email) || strlen($email) > 180 || !is_string($clave) || strlen($clave) > 72
            || str_contains($email, "\0") || str_contains($clave, "\0")
            || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            || !$this->sesion->ingresar($peticion, $email, $clave)) {
            return $this->error('Correo o contraseña incorrectos.', 401);
        }

        return $this->sesion($peticion);
    }

    #[Route('/sesion', methods: ['DELETE'])]
    public function salir(Request $peticion): JsonResponse
    {
        $this->sesion->verificarToken($peticion);
        $peticion->getSession()->invalidate();

        return $this->sesion($peticion);
    }

    #[Route('/portada', methods: ['POST'])]
    public function guardarPortada(Request $peticion): JsonResponse
    {
        $usuario = $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
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
            );
        } catch (InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }

        return $this->respuesta($this->portada->consultar());
    }

    #[Route('/usuarios', methods: ['GET'])]
    public function listarUsuarios(Request $peticion): JsonResponse
    {
        $this->sesion->exigir($peticion);

        return $this->respuesta(['usuarios' => $this->usuarios->listar()]);
    }

    #[Route('/usuarios', methods: ['POST'])]
    public function crearUsuario(Request $peticion): JsonResponse
    {
        $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
        try {
            $datos = $peticion->toArray();
            $email = $datos['email'] ?? null;
            $clave = $datos['clave'] ?? null;
            if (!is_string($email) || !is_string($clave)) {
                return $this->error('Ingresá el correo y la contraseña.', 422);
            }
            $this->usuarios->crear($email, $clave);
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
        $usuario = $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
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
            if (!$this->usuarios->actualizar($id, $activo, $clave, $usuario['id'], $peticion->getSession()->get('admin_credencial'))) {
                return $this->error('No se pudo actualizar la cuenta. Volvé a ingresar si tu acceso cambió.', 409);
            }
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->error($error instanceof JsonException ? 'Revisá los datos de la cuenta.' : $error->getMessage(), 422);
        }

        return $this->respuesta(['usuarios' => $this->usuarios->listar()]);
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
