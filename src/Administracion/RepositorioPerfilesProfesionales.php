<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Entity\Profesional;
use App\Entity\UsuarioInterno;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
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
        // Se leen solo las columnas necesarias: la cuenta interna no se carga completa en memoria.
        $profesionales = $this->entidades->createQuery(<<<'DQL'
            SELECT p.id AS id, p.nombre AS nombre, p.activa AS activa, u.id AS usuario_id, u.email AS email, u.activo AS cuenta_activa, u.roles AS roles,
                   p.creadoPor AS creado_por, p.creadoEn AS creado_en, p.actualizadoPor AS actualizado_por, p.actualizadoEn AS actualizado_en
            FROM App\Entity\Profesional p LEFT JOIN p.usuario u ORDER BY p.nombre, p.id
            DQL)->getArrayResult();

        $autores = new NombresAutores($this->entidades->getConnection());
        $ids = [];
        foreach ($profesionales as $p) {
            $ids[] = $p['creado_por'];
            $ids[] = $p['actualizado_por'];
        }
        $mapa = $autores->resolver($ids);

        return array_map(static fn (array $p): array => [
            'id' => (string) $p['id'], 'nombre' => $p['nombre'], 'activa' => $p['activa'],
            'tiene_cuenta' => $p['usuario_id'] !== null,
            'admin' => $p['roles'] !== null && UsuarioInterno::rolesAdministrativos($p['roles']),
            'usuario_id' => $p['usuario_id'] === null ? null : (string) $p['usuario_id'],
            'email' => $p['email'],
            'cuenta_activa' => $p['cuenta_activa'],
            'auditoria' => $autores->auditoria($p['creado_por'], $p['creado_en'], $p['actualizado_por'], $p['actualizado_en'], $mapa),
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