<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Auditoría de autoría (creación y última modificación) e índices de historial de turnos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE servicios_portada ADD creado_por VARCHAR(100), ADD creado_en TIMESTAMPTZ');
        $this->addSql('ALTER TABLE promociones_portada ADD creado_por VARCHAR(100), ADD creado_en TIMESTAMPTZ');
        $this->addSql('ALTER TABLE recursos ADD creado_por BIGINT REFERENCES usuarios(id), ADD creado_en TIMESTAMPTZ, ADD actualizado_por BIGINT REFERENCES usuarios(id), ADD actualizado_en TIMESTAMPTZ');
        $this->addSql('ALTER TABLE profesionales ADD creado_por BIGINT REFERENCES usuarios(id), ADD creado_en TIMESTAMPTZ, ADD actualizado_por BIGINT REFERENCES usuarios(id), ADD actualizado_en TIMESTAMPTZ, ADD horarios_actualizado_por BIGINT REFERENCES usuarios(id), ADD horarios_actualizado_en TIMESTAMPTZ');
        $this->addSql('ALTER TABLE politicas_reservas ADD actualizado_por BIGINT REFERENCES usuarios(id)');
        $this->addSql('ALTER TABLE usuarios ADD creado_por BIGINT REFERENCES usuarios(id), ADD creado_en TIMESTAMPTZ, ADD actualizado_por BIGINT REFERENCES usuarios(id), ADD actualizado_en TIMESTAMPTZ');
        $this->addSql('ALTER TABLE bloqueos ADD creado_en TIMESTAMPTZ');
        $this->addSql('CREATE INDEX idx_eventos_turnos_admin_turno ON eventos_turnos_administrativos (turno_id, id)');
        $this->addSql('CREATE INDEX idx_eventos_turnos_admin_creado ON eventos_turnos_administrativos (creado_en DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_turnos_administrativos_creado ON turnos_administrativos (creado_en DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se debe conservar la auditoría de datos.');
    }
}
