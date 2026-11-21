<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Visibilidad global de secciones de portada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuracion_portada ADD servicios_visible BOOLEAN NOT NULL DEFAULT TRUE, ADD promociones_visible BOOLEAN NOT NULL DEFAULT TRUE, ADD ubicacion_visible BOOLEAN NOT NULL DEFAULT TRUE, ADD horarios_visible BOOLEAN NOT NULL DEFAULT TRUE, ADD consultas_visible BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se debe conservar la configuración de visibilidad.');
    }
}