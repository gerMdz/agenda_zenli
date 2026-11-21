<?php

declare(strict_types=1);

namespace App\Administracion;

use App\Doctrine\InstanteType;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Transacciones de los ABM con Doctrine ORM.
 *
 * No usa EntityManager::wrapInTransaction() porque cierra el EntityManager ante cualquier
 * excepción, incluidas las validaciones de negocio esperadas. Si la operación falla, se revierte
 * la transacción y se descarta el estado en memoria: las entidades modificadas o persistidas
 * dentro de la transacción revertida no deben escribirse en un flush posterior.
 *
 * Doctrine ejecuta las inserciones antes que las eliminaciones dentro de un mismo flush. Las
 * operaciones que reemplazan ocupaciones o bloqueos deben hacer flush de las eliminaciones antes
 * de persistir los reemplazos; si no, chocan con las exclusiones GiST o los índices únicos.
 */
final class TransaccionEntidades
{
    /** Tipo de los parámetros DQL con instantes: sin él, Doctrine omite la zona horaria. */
    public const INSTANTE = InstanteType::NOMBRE;

    /**
     * @template T
     * @param callable(): T $operacion
     * @return T
     */
    public static function ejecutar(EntityManagerInterface $entidades, callable $operacion): mixed
    {
        try {
            return $entidades->getConnection()->transactional(static function () use ($entidades, $operacion): mixed {
                $resultado = $operacion();
                $entidades->flush();

                return $resultado;
            });
        } catch (Throwable $error) {
            // Un flush fallido ya cerró el EntityManager; en ese caso no queda estado reutilizable.
            if ($entidades->isOpen()) {
                $entidades->clear();
            }
            throw $error;
        }
    }
}
