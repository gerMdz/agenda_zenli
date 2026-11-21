<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119001200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Equipo requerido y seña de turnos internos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE turnos_administrativos ADD profesionales_requeridos INT CHECK (profesionales_requeridos > 0), ADD sena_pagada BOOLEAN NOT NULL DEFAULT FALSE, ADD sena_importe_centavos BIGINT CHECK (sena_importe_centavos >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se deben conservar los datos de los turnos y sus señas.');
    }
}