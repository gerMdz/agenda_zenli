<?php

declare(strict_types=1);

namespace App\Seguridad;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Registro de ingresos, fallos, demoras, salidas y cambios de cuentas.
 *
 * Usa la misma conexión que el ORM: dentro de una transacción, el evento se confirma o se revierte
 * junto con el cambio que registra. No guarda el correo tipeado en un fallo, porque puede contener
 * una contraseña escrita en el campo equivocado; si el correo pertenece a una cuenta, guarda su id.
 */
final readonly class AuditoriaAccesos
{
    public function __construct(private Connection $conexion, private RequestStack $peticiones)
    {
    }

    public function registrar(string $tipo, ?string $usuario, ?string $autor = null): void
    {
        $this->conexion->insert('eventos_acceso', [
            'tipo' => $tipo, 'usuario_id' => $usuario, 'autor_id' => $autor,
            'ip' => $this->peticiones->getMainRequest()?->getClientIp(),
        ]);
    }

    public function registrarPorEmail(string $tipo, string $email): void
    {
        $usuario = $this->conexion->fetchOne('SELECT id::text FROM usuarios WHERE email = ?', [strtolower(trim($email))]);
        $this->registrar($tipo, $usuario === false ? null : $usuario);
    }

    /**
     * @return list<array{id: string, tipo: string, ip: string, usuario_id: ?string, usuario: string, usuario_email: ?string, autor: ?string, creado_en: string}>
     */
    public function listar(int $limite = 100, ?string $tipo = null): array
    {
        $limite = max(1, min(500, $limite));
        $condicion = '';
        $params = [];
        if ($tipo !== null && $tipo !== '' && $tipo !== 'todos') {
            $condicion = 'WHERE e.tipo = ?';
            $params[] = $tipo;
        }
        $filas = $this->conexion->fetchAllAssociative(<<<SQL
            SELECT e.id::text AS id, e.tipo, host(e.ip) AS ip, e.usuario_id::text AS usuario_id,
                   u.email AS usuario_email, p.nombre AS profesional_nombre,
                   e.autor_id::text AS autor_id, a.email AS autor_email,
                   e.creado_en
            FROM eventos_acceso e
            LEFT JOIN usuarios u ON u.id = e.usuario_id
            LEFT JOIN profesionales p ON p.usuario_id = u.id
            LEFT JOIN usuarios a ON a.id = e.autor_id
            $condicion
            ORDER BY e.id DESC
            LIMIT $limite
            SQL, $params);

        return array_map(static fn (array $f): array => [
            'id' => $f['id'],
            'tipo' => $f['tipo'],
            'ip' => $f['ip'] ?? '—',
            'usuario_id' => $f['usuario_id'],
            'usuario' => $f['profesional_nombre'] ?? $f['usuario_email'] ?? ($f['usuario_id'] ? "Usuario #{$f['usuario_id']}" : 'Anónimo / No registrado'),
            'usuario_email' => $f['usuario_email'],
            'autor' => $f['autor_email'] ?? ($f['autor_id'] ? "Usuario #{$f['autor_id']}" : null),
            'creado_en' => (new \DateTimeImmutable($f['creado_en']))->format(\DATE_ATOM),
        ], $filas);
    }
}
