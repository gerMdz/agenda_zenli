<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Horarios especiales por fecha y bloqueos editables de profesionales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE horarios_profesionales_especiales (
                profesional_id BIGINT NOT NULL REFERENCES profesionales(id),
                fecha DATE NOT NULL,
                hora_inicio TIME NOT NULL,
                hora_fin TIME NOT NULL CHECK (hora_fin > hora_inicio),
                PRIMARY KEY (profesional_id, fecha, hora_inicio, hora_fin)
            )
            SQL);
        $this->addSql('ALTER TABLE bloqueos ADD gestion_horarios BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('CREATE INDEX idx_bloqueos_horarios ON bloqueos (profesional_id, inicio) WHERE gestion_horarios');
        $this->addSql('CREATE UNIQUE INDEX unq_bloqueos_horarios ON bloqueos (profesional_id, inicio, fin) WHERE gestion_horarios');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se deben conservar los horarios especiales y los bloqueos ya cargados.');
    }
}
