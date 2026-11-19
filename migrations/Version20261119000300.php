<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla servicios_portada para gestión dinámica de masajes en la portada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE servicios_portada (
                id SERIAL PRIMARY KEY,
                nombre VARCHAR(100) NOT NULL,
                descripcion_corta VARCHAR(255) NOT NULL,
                detalle TEXT NOT NULL,
                imagen BYTEA,
                imagen_tipo VARCHAR(50),
                imagen_version VARCHAR(64),
                orden INT NOT NULL DEFAULT 0,
                visible BOOLEAN NOT NULL DEFAULT true,
                actualizado_por VARCHAR(100) NOT NULL,
                actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS servicios_portada');
    }
}
