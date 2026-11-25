<?php

declare(strict_types=1);

namespace App\Repository;

use App\Administracion\NombresAutores;
use App\Entity\Profesional;
use App\Entity\UsuarioInterno;
use App\Seguridad\AuditoriaAccesos;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

final readonly class UsuariosAdministrativosRepository
{
    public function __construct(private EntityManagerInterface $entidades, private AuditoriaAccesos $auditoria)
    {
    }

    public function listar(): array
    {
        $filas = $this->entidades->createQueryBuilder()
            ->select('u.id AS id, u.email AS email, u.activo AS activo, u.roles AS roles, p.nombre AS profesional, u.creadoPor AS creado_por, u.creadoEn AS creado_en, u.actualizadoPor AS actualizado_por, u.actualizadoEn AS actualizado_en')
            ->from(UsuarioInterno::class, 'u')
            ->leftJoin(Profesional::class, 'p', 'WITH', 'p.usuario = u AND p.activa = true')
            ->orderBy('u.email')
            ->getQuery()->getArrayResult();

        $autores = new NombresAutores($this->entidades->getConnection());
        $ids = [];
        foreach ($filas as $fila) {
            $ids[] = $fila['creado_por'];
            $ids[] = $fila['actualizado_por'];
        }
        $mapa = $autores->resolver($ids);

        $resultado = [];
        foreach ($filas as $fila) {
            if (array_is_list($fila['roles']) ? in_array('ROLE_ADMIN', $fila['roles'], true) : array_key_exists('ROLE_ADMIN', $fila['roles'])) {
                $resultado[] = [
                    'id' => (string) $fila['id'],
                    'email' => $fila['email'],
                    'activo' => $fila['activo'],
                    'profesional' => $fila['profesional'],
                    'auditoria' => $autores->auditoria($fila['creado_por'], $fila['creado_en'], $fila['actualizado_por'], $fila['actualizado_en'], $mapa),
                ];
            }
        }

        return $resultado;
    }

    public function crear(string $email, string $hash, ?string $autor = null): void
    {
        $this->entidades->getConnection()->transactional(function () use ($email, $hash, $autor): void {
            $ahora = new DateTimeImmutable();
            $usuario = new UsuarioInterno();
            $usuario->email = $email;
            $usuario->passwordHash = $hash;
            $usuario->roles = ['ROLE_ADMIN'];
            $usuario->creadoPor = $autor;
            $usuario->creadoEn = $ahora;
            $usuario->actualizadoPor = $autor;
            $usuario->actualizadoEn = $ahora;
            $this->entidades->persist($usuario);
            $this->entidades->flush();
            $this->auditoria->registrar('CUENTA_CREADA', $usuario->id, $autor);
        });
    }

    public function email(string $id): ?string
    {
        return $this->buscarActualizado($id)?->email;
    }

    public function idPorEmail(string $email): ?string
    {
        $id = $this->entidades->createQuery('SELECT u.id FROM App\Entity\UsuarioInterno u WHERE u.email = :email')
            ->setParameter('email', $email)->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR);

        return $id === null ? null : (string) $id;
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
            // Se gestionan las cuentas internas: administradoras o vinculadas a un perfil profesional.
            $usuario = $this->buscarActualizado($id);
            if ($usuario === null || (!$usuario->esAdministrador() && !$this->tienePerfilProfesional($usuario->id))) {
                return false;
            }
            if ($usuario->activo !== $activo) {
                $this->auditoria->registrar($activo ? 'CUENTA_ACTIVADA' : 'CUENTA_DESACTIVADA', $usuario->id, $autor);
            }
            $usuario->activo = $activo;
            if ($hash !== null) {
                $usuario->passwordHash = $hash;
                $this->auditoria->registrar('CLAVE_CAMBIADA', $usuario->id, $autor);
            }
            $usuario->actualizadoPor = $autor;
            $usuario->actualizadoEn = new DateTimeImmutable();
            $this->entidades->flush();

            return true;
        });
    }

    private function tienePerfilProfesional(string $id): bool
    {
        return (int) $this->entidades->createQuery('SELECT COUNT(p.id) FROM App\Entity\Profesional p WHERE p.usuario = :id')
            ->setParameter('id', $id)->getSingleScalarResult() > 0;
    }

    private function buscarActualizado(string $id): ?UsuarioInterno
    {
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            return null;
        }

        return $this->entidades->createQuery('SELECT u FROM App\Entity\UsuarioInterno u WHERE u.id = :id')
            ->setParameter('id', $id)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }
}