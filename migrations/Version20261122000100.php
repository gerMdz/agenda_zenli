<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Feriados informativos del calendario interno';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feriados_agenda (fecha DATE PRIMARY KEY, activo BOOLEAN NOT NULL, creado_por BIGINT NOT NULL REFERENCES usuarios(id), creado_en TIMESTAMPTZ NOT NULL DEFAULT now(), actualizado_por BIGINT NOT NULL REFERENCES usuarios(id), actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now())');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se debe conservar la auditoría de feriados.');
    }
}
