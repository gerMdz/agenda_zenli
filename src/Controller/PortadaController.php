<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\ConfiguracionPortada;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PortadaController
{
    public function __construct(private ConfiguracionPortada $portada)
    {
    }

    #[Route('/api/portada', methods: ['GET'])]
    public function consultar(): JsonResponse
    {
        return new JsonResponse($this->portada->consultar(), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/portada/imagen/{version}', requirements: ['version' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function imagen(string $version): Response
    {
        $imagen = $this->portada->imagen($version);
        if ($imagen === false) {
            throw new NotFoundHttpException();
        }

        return new Response(base64_decode($imagen['contenido'], true), headers: [
            'Content-Type' => $imagen['imagen_tipo'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400, immutable',
        ]);
    }
}
