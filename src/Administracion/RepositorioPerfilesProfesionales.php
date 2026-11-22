<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Entity\Profesional;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class RepositorioPerfilesProfesionales
{
    public function __construct(private EntityManagerInterface $entidades) {}

    public function crear(string $nombre, ?string $autor = null): string
    {
        $profesional = new Profesional($nombre, $autor);
        $this->entidades->persist($profesional);
        $this->entidades->flush();

        return $profesional->id();
    }

    public function listar(): array
    {
        $profesionales = $this->entidades->createQuery('SELECT p, u FROM App\Entity\Profesional p LEFT JOIN p.usuario u ORDER BY p.nombre, p.id')
            ->setHint(Query::HINT_REFRESH, true)->getResult();

        $autores = new NombresAutores($this->entidades->getConnection());
        $ids = [];
        foreach ($profesionales as $p) {
            $ids[] = $p->creadoPor();
            $ids[] = $p->actualizadoPor();
        }
        $mapa = $autores->resolver($ids);

        return array_map(static fn (Profesional $p): array => [
            'id' => $p->id(), 'nombre' => $p->nombre(), 'activa' => $p->activa(),
            'tiene_cuenta' => $p->usuario() !== null, 'admin' => $p->usuario()?->esAdmin() ?? false,
            'usuario_id' => $p->usuario()?->id(),
            'auditoria' => $autores->auditoria($p->creadoPor(), $p->creadoEn(), $p->actualizadoPor(), $p->actualizadoEn(), $mapa),
        ], $profesionales);
    }

    public function renombrar(string $id, string $nombre, ?string $autor = null): void
    {
        $profesional = $this->buscar($id);
        $profesional->renombrar($nombre, $autor);
        $this->entidades->flush();
    }

    public function cambiarEstado(string $id, bool $activa, ?string $autor = null): void
    {
        $conexion = $this->entidades->getConnection();
        $conexion->transactional(function () use ($conexion, $id, $activa, $autor): void {
            $conexion->executeStatement('LOCK TABLE recursos IN EXCLUSIVE MODE');
            $profesional = $this->buscar($id, true);
            if (!$activa && $conexion->fetchOne('SELECT 1 FROM ocupaciones_profesionales WHERE profesional_id = ? AND fin > statement_timestamp() LIMIT 1', [$id]) !== false) {
                throw new HttpException(409, 'La profesional tiene ocupaciones en curso o futuras.');
            }
            $profesional->cambiarEstado($activa, $autor);
            $this->entidades->flush();
        });
    }

    private function buscar(string $id, bool $bloquear = false): Profesional
    {
        $profesional = $this->entidades->find(Profesional::class, $id);
        if ($profesional === null) {
            throw new HttpException(404, 'La profesional no existe.');
        }
        $this->entidades->refresh($profesional, $bloquear ? LockMode::PESSIMISTIC_WRITE : null);

        return $profesional;
    }
}