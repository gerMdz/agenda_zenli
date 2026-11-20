<?php

declare(strict_types=1);

namespace App\Administracion;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

final readonly class ComunicacionAgenda
{
    private const CONTACTOS = <<<'SQL'
        SELECT u.id::text, COALESCE(p.nombre, u.email) AS nombre
        FROM usuarios u LEFT JOIN profesionales p ON p.usuario_id = u.id AND p.activa
        WHERE u.activo AND (p.id IS NOT NULL OR jsonb_exists(u.roles, 'ROLE_ADMIN'))
        SQL;

    public function __construct(private Connection $conexion)
    {
    }

    public function consultar(string $usuario): array
    {
        return [
            'contactos' => $this->conexion->fetchAllAssociative(self::CONTACTOS.' AND u.id <> ? ORDER BY nombre', [$usuario]),
            'mensajes' => $this->conexion->fetchAllAssociative(<<<'SQL'
                SELECT m.id::text, m.texto, m.creado_en, (m.remitente_id = ?)::int AS enviado,
                       COALESCE(p.nombre, u.email) AS contacto
                FROM mensajes_agenda m
                JOIN usuarios u ON u.id = CASE WHEN m.remitente_id = ? THEN m.destinatario_id ELSE m.remitente_id END
                LEFT JOIN profesionales p ON p.usuario_id = u.id
                WHERE m.remitente_id = ? OR m.destinatario_id = ?
                ORDER BY m.id DESC LIMIT 100
                SQL, [$usuario, $usuario, $usuario, $usuario]),
        ];
    }

    public function enviar(string $autor, mixed $destinatario, mixed $texto): void
    {
        if (!is_string($destinatario) || preg_match('/\A[1-9][0-9]{0,17}\z/', $destinatario) !== 1 || $destinatario === $autor
            || !is_string($texto) || preg_match('/\A[^\x00]{1,2000}\z/u', trim($texto)) !== 1) {
            throw new InvalidArgumentException('Elegí un contacto y escribí un mensaje de hasta 2000 caracteres.');
        }

        if ($this->conexion->fetchOne(self::CONTACTOS.' AND u.id = ?', [$destinatario]) === false) {
            throw new InvalidArgumentException('El contacto ya no está disponible.');
        }

        $this->conexion->insert('mensajes_agenda', [
            'remitente_id' => $autor, 'destinatario_id' => $destinatario, 'texto' => trim($texto),
        ]);
    }

    public function vincularCuenta(mixed $profesional, mixed $email, mixed $clave): void
    {
        if (!is_string($profesional) || preg_match('/\A[1-9][0-9]{0,17}\z/', $profesional) !== 1
            || !is_string($email) || !is_string($clave)) {
            throw new InvalidArgumentException('Completá la profesional, el correo y la contraseña.');
        }

        $email = UsuariosAdministrativos::normalizarEmail($email);
        $hash = UsuariosAdministrativos::hashClave($clave);
        $this->conexion->transactional(static function (Connection $conexion) use ($profesional, $email, $hash): void {
            $fila = $conexion->fetchAssociative('SELECT usuario_id FROM profesionales WHERE id = ? AND activa FOR UPDATE', [$profesional]);
            if ($fila === false || $fila['usuario_id'] !== null) {
                throw new InvalidArgumentException('La profesional no está disponible o ya tiene una cuenta.');
            }
            $id = $conexion->fetchOne('INSERT INTO usuarios (email, password_hash) VALUES (?, ?) RETURNING id', [$email, $hash]);
            $conexion->executeStatement('UPDATE profesionales SET usuario_id = ? WHERE id = ?', [$id, $profesional]);
        });
    }
}
