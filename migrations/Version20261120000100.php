<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Promociones administrables de portada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE promociones_portada (
                id SERIAL PRIMARY KEY,
                nombre VARCHAR(100) NOT NULL,
                descripcion TEXT NOT NULL,
                visible BOOLEAN NOT NULL DEFAULT false,
                eliminado BOOLEAN NOT NULL DEFAULT false,
                actualizado_por VARCHAR(100) NOT NULL,
                actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE promociones_portada');
    }
}