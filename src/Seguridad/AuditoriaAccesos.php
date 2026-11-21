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
}
