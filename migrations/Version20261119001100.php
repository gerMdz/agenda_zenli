<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119001100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configuración operativa y tratamientos documentados de portada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE servicios_portada
                ADD duracion_minima INT CHECK (duracion_minima > 0),
                ADD profesionales_requeridos INT CHECK (profesionales_requeridos > 0)
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO servicios_portada (nombre, descripcion_corta, detalle, orden, visible, actualizado_por)
            SELECT catalogo.nombre, catalogo.descripcion, catalogo.descripcion,
                   (SELECT COALESCE(MAX(orden), 0) FROM servicios_portada) + catalogo.posicion,
                   false, 'migracion'
            FROM (VALUES
                (1, 'Descontracturante', 'Presión intensa combinada con técnicas de masaje tailandés.', '%descontracturante%'),
                (2, 'Relajante', 'Presión suave y técnicas Lomi Lomi.', '%relajante%'),
                (3, 'Mixto', 'Relajante y descontracturante.', '%mixto%'),
                (4, 'Tailandés', 'Trabajo corporal y estiramientos.', '%tailand%'),
                (5, 'Bruxismo cervicocraneofacial', 'Masaje cervicocraneofacial.', '%bruxismo%'),
                (6, 'Reflexología de pies', 'Reflexología de pies.', '%reflexolog%'),
                (7, 'Lomi Lomi', 'Masaje hawaiano.', '%lomi%'),
                (8, 'Experiencia Zenli', 'Descontracturante y tailandés.', '%experiencia zenli%'),
                (9, 'Masaje en pareja', 'Dos sesiones consecutivas; asignación profesional pendiente de confirmación.', '%pareja%')
            ) AS catalogo(posicion, nombre, descripcion, patron)
            WHERE NOT EXISTS (SELECT 1 FROM servicios_portada existente WHERE lower(existente.nombre) LIKE catalogo.patron)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('No se eliminan servicios que podrían haber sido modificados.');
    }
}