<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administracion\ClientesAgenda;
use App\Administracion\SesionAdministrativa;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/agenda/clientes')]
final readonly class ClientesAgendaController
{
    public function __construct(private ClientesAgenda $clientes, private SesionAdministrativa $sesion)
    {
    }

    #[Route('', methods: ['GET', 'POST'])]
    #[Route('/{id}', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['GET', 'PUT', 'DELETE'])]
    public function clientes(Request $request, ?string $id = null): JsonResponse
    {
        $cuenta = $this->sesion->actual();
        try {
            if ($request->isMethod('GET')) {
                $datos = $id === null
                    ? $this->clientes->listar($cuenta, incluirArchivados: $request->query->getBoolean('archivados'))
                    : $this->clientes->ficha($id, $cuenta);

                return self::respuesta($datos);
            }
            if ($request->isMethod('DELETE')) {
                $this->clientes->cambiarArchivo($id, true, $cuenta);

                return self::respuesta(['archivada' => true]);
            }
            $idNuevo = $this->clientes->guardar($id, $request->toArray(), $cuenta);

            return self::respuesta($this->clientes->ficha($idNuevo, $cuenta), $id === null ? 201 : 200);
        } catch (JsonException|InvalidArgumentException|HttpException $error) {
            return self::error($error);
        }
    }

    #[Route('/buscar', methods: ['POST'])]
    public function buscar(Request $request): JsonResponse
    {
        try {
            $cuerpo = $request->toArray();
            $nombre = $cuerpo['nombre'] ?? '';
            if (!is_string($nombre)) {
                throw new InvalidArgumentException('Ingresá un nombre válido.');
            }

            return self::respuesta($this->clientes->listar($this->sesion->actual(), $nombre, ($cuerpo['archivados'] ?? false) === true));
        } catch (JsonException|InvalidArgumentException|HttpException $error) {
            return self::error($error);
        }
    }

    #[Route('/{id}/reactivar', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['POST'])]
    public function reactivar(string $id): JsonResponse
    {
        try {
            $this->clientes->cambiarArchivo($id, false, $this->sesion->actual());

            return self::respuesta(['reactivada' => true]);
        } catch (HttpException $error) {
            return self::error($error);
        }
    }

    #[Route('/{id}/observaciones', requirements: ['id' => '[1-9][0-9]{0,17}'], methods: ['POST'])]
    public function observar(Request $request, string $id): JsonResponse
    {
        $cuenta = $this->sesion->actual();
        try {
            $this->clientes->agregarObservacion($id, $request->toArray()['texto'] ?? null, $cuenta);

            return self::respuesta($this->clientes->ficha($id, $cuenta), 201);
        } catch (JsonException|InvalidArgumentException|HttpException $error) {
            return self::error($error);
        }
    }

    private static function respuesta(array $datos, int $estado = 200): JsonResponse
    {
        return new JsonResponse($datos, $estado, ['Cache-Control' => 'no-store']);
    }

    private static function error(\Throwable $error): JsonResponse
    {
        return self::respuesta(
            ['error' => $error instanceof JsonException ? 'Revisá los datos de la ficha.' : $error->getMessage()],
            $error instanceof HttpException ? $error->getStatusCode() : 422,
        );
    }
}
