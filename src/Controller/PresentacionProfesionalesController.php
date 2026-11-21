<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\PresentacionProfesionales;
use App\Administracion\SesionAdministrativa;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PresentacionProfesionalesController
{
    public function __construct(private PresentacionProfesionales $presentacion, private SesionAdministrativa $sesion)
    {
    }

    #[Route('/api/profesionales-portada', methods: ['GET'])]
    public function publicos(): JsonResponse
    {
        return $this->respuesta(['profesionales' => $this->presentacion->publicos()]);
    }

    #[Route('/api/admin/profesionales-portada', methods: ['GET'])]
    public function configuracion(Request $peticion): JsonResponse
    {
        $this->sesion->exigir($peticion);
        return $this->respuesta($this->presentacion->configuracion());
    }

    #[Route('/api/admin/profesionales-portada/modo', methods: ['PATCH'])]
    public function modo(Request $peticion): JsonResponse
    {
        $usuario = $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
        try {
            $this->presentacion->cambiarModo($peticion->toArray()['modo'] ?? null, $usuario['id']);
        } catch (JsonException|InvalidArgumentException $error) {
            return $this->respuesta(['error' => 'Elegí un modo de presentación válido.'], 422);
        }
        return $this->respuesta($this->presentacion->configuracion());
    }

    #[Route('/api/admin/profesionales-portada/{id}/imagen', requirements: ['id' => '[1-9][0-9]{0,18}'], methods: ['POST'])]
    public function guardarImagen(Request $peticion, string $id): JsonResponse
    {
        $usuario = $this->sesion->exigir($peticion);
        $this->sesion->verificarToken($peticion);
        $archivo = $peticion->files->get('imagen');
        if ($archivo !== null && !$archivo instanceof UploadedFile) {
            return $this->respuesta(['error' => 'Elegí un solo archivo.'], 422);
        }
        try {
            $this->presentacion->guardarImagen($id, $archivo, $peticion->request->getString('eliminar_imagen') === '1', $usuario['id']);
        } catch (InvalidArgumentException $error) {
            return $this->respuesta(['error' => $error->getMessage()], 422);
        }
        return $this->respuesta($this->presentacion->configuracion());
    }

    #[Route('/api/profesionales-portada/{id}/imagen/{version}', requirements: ['id' => '[1-9][0-9]{0,18}', 'version' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function imagenPublica(string $id, string $version): Response
    {
        return $this->imagen($id, $version, true);
    }

    #[Route('/api/admin/profesionales-portada/{id}/imagen/{version}', requirements: ['id' => '[1-9][0-9]{0,18}', 'version' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function imagenPrivada(Request $peticion, string $id, string $version): Response
    {
        $this->sesion->exigir($peticion);
        return $this->imagen($id, $version, false);
    }

    private function imagen(string $id, string $version, bool $publica): Response
    {
        $imagen = $this->presentacion->imagen($id, $version, $publica);
        if ($imagen === false) {
            throw new NotFoundHttpException();
        }
        return new Response(base64_decode($imagen['contenido'], true), headers: ['Content-Type' => $imagen['imagen_tipo'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }

    private function respuesta(array $datos, int $estado = 200): JsonResponse
    {
        return new JsonResponse($datos, $estado, ['Cache-Control' => 'no-store']);
    }
}