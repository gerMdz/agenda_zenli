<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estado de asistencia de los turnos internos y auditoría de sus cambios';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE turnos_administrativos ADD asistencia VARCHAR(12) NOT NULL DEFAULT 'programado' CHECK (asistencia IN ('programado', 'atendido', 'ausente'))");
        $this->addSql("ALTER TABLE eventos_turnos_administrativos DROP CONSTRAINT eventos_turnos_administrativos_tipo_check, ADD CHECK (tipo IN ('EDITADO', 'CANCELADO', 'PREP_EDITADA', 'PREP_ELIMINADA', 'ASISTENCIA'))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se debe conservar la asistencia y su auditoría.');
    }
}
