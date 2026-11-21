<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfesionalCuentaAdministrativa;
use App\Entity\UsuarioAdministrativo;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

final readonly class UsuariosAdministrativosRepository
{
    public function __construct(private EntityManagerInterface $entidades)
    {
    }

    public function listar(): array
    {
        $filas = $this->entidades->createQueryBuilder()
            ->select('u.id AS id, u.email AS email, u.activo AS activo, u.roles AS roles, p.nombre AS profesional')
            ->from(UsuarioAdministrativo::class, 'u')
            ->leftJoin(ProfesionalCuentaAdministrativa::class, 'p', 'WITH', 'p.usuario = u AND p.activa = true')
            ->orderBy('u.email')
            ->getQuery()->getArrayResult();

        $resultado = [];
        foreach ($filas as $fila) {
            if (array_is_list($fila['roles']) ? in_array('ROLE_ADMIN', $fila['roles'], true) : array_key_exists('ROLE_ADMIN', $fila['roles'])) {
                unset($fila['roles']);
                $fila['id'] = (string) $fila['id'];
                $resultado[] = $fila;
            }
        }

        return $resultado;
    }

    public function crear(string $email, string $hash): void
    {
        $usuario = new UsuarioAdministrativo();
        $usuario->email = $email;
        $usuario->passwordHash = $hash;
        $usuario->roles = ['ROLE_ADMIN'];
        $this->entidades->persist($usuario);
        $this->entidades->flush();
    }

    public function actualizar(string $id, bool $activo, ?string $hash, string $autor, string $huella): bool
    {
        return $this->entidades->getConnection()->transactional(function () use ($id, $activo, $hash, $autor, $huella): bool {
            $this->entidades->getConnection()->executeStatement('LOCK TABLE usuarios IN SHARE ROW EXCLUSIVE MODE');
            $usuarioAutor = $this->buscarActualizado($autor);
            if ($usuarioAutor === null || !$usuarioAutor->activo || !$usuarioAutor->esAdministrador()
                || !hash_equals($huella, hash('sha256', $usuarioAutor->passwordHash))) {
                return false;
            }
            $usuario = $this->buscarActualizado($id);
            if ($usuario === null || !$usuario->esAdministrador()) {
                return false;
            }
            $usuario->activo = $activo;
            if ($hash !== null) {
                $usuario->passwordHash = $hash;
            }
            $this->entidades->flush();

            return true;
        });
    }

    private function buscarActualizado(string $id): ?UsuarioAdministrativo
    {
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            return null;
        }

        return $this->entidades->createQuery('SELECT u FROM App\Entity\UsuarioAdministrativo u WHERE u.id = :id')
            ->setParameter('id', $id)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }
}