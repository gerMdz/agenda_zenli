<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Entity\MensajeAgenda;
use App\Entity\Profesional;
use App\Entity\ProfesionalCuentaAdministrativa;
use App\Entity\UsuarioAdministrativo;
use App\Entity\UsuarioPerfil;
use App\Seguridad\AuditoriaAccesos;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use InvalidArgumentException;

final readonly class ComunicacionAgenda
{
    public function __construct(private EntityManagerInterface $entidades, private AuditoriaAccesos $auditoria)
    {
    }

    public function consultar(string $usuario): array
    {
        $mensajes = $this->entidades->createQuery(<<<'DQL'
            SELECT m.id AS id, m.texto AS texto, m.creadoEn AS creado_en, IDENTITY(m.remitente) AS remitente,
                   COALESCE(pr.nombre, r.email) AS nombre_remitente, COALESCE(pd.nombre, d.email) AS nombre_destinatario
            FROM App\Entity\MensajeAgenda m JOIN m.remitente r JOIN m.destinatario d
            LEFT JOIN App\Entity\ProfesionalCuentaAdministrativa pr WITH pr.usuario = r
            LEFT JOIN App\Entity\ProfesionalCuentaAdministrativa pd WITH pd.usuario = d
            WHERE m.remitente = :usuario OR m.destinatario = :usuario
            ORDER BY m.id DESC
            DQL)->setParameter('usuario', $usuario)->setMaxResults(100)->getArrayResult();

        return [
            'contactos' => array_values(array_filter($this->contactos(), static fn (array $c): bool => $c['id'] !== $usuario)),
            'mensajes' => array_map(static function (array $m) use ($usuario): array {
                $enviado = (string) $m['remitente'] === $usuario;

                return [
                    'id' => (string) $m['id'], 'texto' => $m['texto'],
                    'creado_en' => $m['creado_en']->format(DateTimeInterface::ATOM),
                    'enviado' => $enviado ? 1 : 0,
                    'contacto' => $enviado ? $m['nombre_destinatario'] : $m['nombre_remitente'],
                ];
            }, $mensajes),
        ];
    }

    public function enviar(string $autor, mixed $destinatario, mixed $texto): void
    {
        if (!is_string($destinatario) || preg_match('/\A[1-9][0-9]{0,17}\z/', $destinatario) !== 1 || $destinatario === $autor
            || !is_string($texto) || preg_match('/\A[^\x00]{1,2000}\z/u', trim($texto)) !== 1) {
            throw new InvalidArgumentException('Elegí un contacto y escribí un mensaje de hasta 2000 caracteres.');
        }

        if (!in_array($destinatario, array_column($this->contactos(), 'id'), true)) {
            throw new InvalidArgumentException('El contacto ya no está disponible.');
        }

        $mensaje = new MensajeAgenda();
        $mensaje->remitente = $this->entidades->getReference(UsuarioAdministrativo::class, $autor);
        $mensaje->destinatario = $this->entidades->getReference(UsuarioAdministrativo::class, $destinatario);
        $mensaje->texto = trim($texto);
        $this->entidades->persist($mensaje);
        $this->entidades->flush();
    }

    public function vincularCuenta(
        mixed $profesional, mixed $email, mixed $clave, mixed $admin = false,
        mixed $nombre = null, mixed $cuentaExistente = false, ?string $autor = null,
    ): void {
        if (!is_string($email) || !is_bool($admin) || !is_bool($cuentaExistente)
            || ($profesional !== null && (!is_string($profesional) || preg_match('/\A[1-9][0-9]{0,17}\z/', $profesional) !== 1))
            || ($profesional === null && (!is_string($nombre) || preg_match('/\A[^\x00]{1,120}\z/u', trim($nombre)) !== 1))) {
            throw new InvalidArgumentException('Completá la profesional y el correo de la cuenta.');
        }

        $email = UsuariosAdministrativos::normalizarEmail($email);
        if (!$cuentaExistente && !is_string($clave)) {
            throw new InvalidArgumentException('Ingresá una contraseña para la cuenta nueva.');
        }
        $hash = $cuentaExistente ? null : UsuariosAdministrativos::hashClave($clave);

        TransaccionEntidades::ejecutar($this->entidades, function () use ($profesional, $email, $hash, $nombre, $admin, $autor): void {
            if ($hash === null) {
                $usuario = $this->entidades->createQuery('SELECT u FROM App\Entity\UsuarioAdministrativo u WHERE u.email = :email AND u.activo = true')
                    ->setParameter('email', $email)->setLockMode(LockMode::PESSIMISTIC_WRITE)
                    ->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
                if ($usuario === null || !$usuario->esAdministrador()) {
                    throw new InvalidArgumentException('Elegí el correo de una cuenta administradora activa.');
                }
            } else {
                $usuario = new UsuarioAdministrativo();
                $usuario->email = $email;
                $usuario->passwordHash = $hash;
                $usuario->roles = $admin ? [UsuariosAdministrativos::ROL] : [];
                $this->entidades->persist($usuario);
                // La profesional referencia la cuenta: debe existir antes de vincularla.
                $this->entidades->flush();
                $this->auditoria->registrar('CUENTA_CREADA', $usuario->id, $autor);
            }
            $this->auditoria->registrar('CUENTA_VINCULADA', $usuario->id, $autor);
            $cuenta = $this->entidades->getReference(UsuarioPerfil::class, $usuario->id);
            if ($profesional === null) {
                $perfil = new Profesional(trim($nombre));
                $perfil->vincularCuenta($cuenta);
                $this->entidades->persist($perfil);

                return;
            }
            $perfil = $this->entidades->createQuery('SELECT p FROM App\Entity\Profesional p WHERE p.id = :id')
                ->setParameter('id', $profesional)->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
            if ($perfil === null || !$perfil->activa() || $perfil->usuario() !== null) {
                throw new InvalidArgumentException('La profesional no está disponible o ya tiene una cuenta.');
            }
            $perfil->vincularCuenta($cuenta);
        });
    }

    /**
     * Cuentas activas con un perfil profesional activo o con permisos de administración.
     *
     * @return list<array{id: string, nombre: string}>
     */
    private function contactos(): array
    {
        $filas = $this->entidades->createQueryBuilder()
            ->select('u.id AS id, COALESCE(p.nombre, u.email) AS nombre, p.id AS profesional, u.roles AS roles')
            ->from(UsuarioAdministrativo::class, 'u')
            ->leftJoin(ProfesionalCuentaAdministrativa::class, 'p', 'WITH', 'p.usuario = u AND p.activa = true')
            ->where('u.activo = true')
            ->orderBy('nombre')
            ->getQuery()->getArrayResult();
        $contactos = [];
        foreach ($filas as $fila) {
            if ($fila['profesional'] !== null || UsuarioAdministrativo::rolesAdministrativos($fila['roles'])) {
                $contactos[] = ['id' => (string) $fila['id'], 'nombre' => $fila['nombre']];
            }
        }

        return $contactos;
    }
}
