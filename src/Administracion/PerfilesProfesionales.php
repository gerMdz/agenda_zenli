<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class PerfilesProfesionales
{
    public function __construct(private Connection $conexion)
    {
    }

    public function crear(mixed $nombre): string
    {
        $nombre = self::nombre($nombre);

        return (string) $this->conexion->fetchOne(
            'INSERT INTO profesionales (nombre) VALUES (?) RETURNING id::text',
            [$nombre],
        );
    }

    public function listar(): array
    {
        $perfiles = $this->conexion->fetchAllAssociative(<<<'SQL'
            SELECT p.id::text, p.nombre, p.activa::int AS activa,
                   (p.usuario_id IS NOT NULL)::int AS tiene_cuenta,
                   COALESCE(jsonb_exists(u.roles, 'ROLE_ADMIN'), false)::int AS admin
            FROM profesionales p LEFT JOIN usuarios u ON u.id = p.usuario_id
            ORDER BY p.nombre, p.id
            SQL);
        foreach ($perfiles as &$perfil) {
            foreach (['activa', 'tiene_cuenta', 'admin'] as $campo) {
                $perfil[$campo] = (bool) $perfil[$campo];
            }
        }

        return $perfiles;
    }

    public function renombrar(string $id, mixed $nombre): void
    {
        if ($this->conexion->executeStatement('UPDATE profesionales SET nombre = ? WHERE id = ?', [self::nombre($nombre), $id]) === 0) {
            throw new HttpException(404, 'La profesional no existe.');
        }
    }

    public function cambiarEstado(string $id, mixed $activa): void
    {
        if (!is_bool($activa)) {
            throw new InvalidArgumentException('Indicá si la profesional está activa.');
        }
        $this->conexion->transactional(function () use ($id, $activa): void {
            $this->conexion->executeStatement('LOCK TABLE recursos IN EXCLUSIVE MODE');
            if ($this->conexion->fetchOne('SELECT id FROM profesionales WHERE id = ? FOR UPDATE', [$id]) === false) {
                throw new HttpException(404, 'La profesional no existe.');
            }
            if (!$activa && $this->conexion->fetchOne('SELECT 1 FROM ocupaciones_profesionales WHERE profesional_id = ? AND fin > statement_timestamp() LIMIT 1', [$id]) !== false) {
                throw new HttpException(409, 'La profesional tiene ocupaciones en curso o futuras.');
            }
            $this->conexion->executeStatement('UPDATE profesionales SET activa = ? WHERE id = ?', [$activa ? 'true' : 'false', $id]);
        });
    }

    private static function nombre(mixed $nombre): string
    {
        if (!is_string($nombre) || preg_match('/\A[^\x00]{1,120}\z/u', trim($nombre)) !== 1) {
            throw new InvalidArgumentException('Ingresá un nombre profesional válido.');
        }

        return trim($nombre);
    }
}