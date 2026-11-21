<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orden de promociones de portada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promociones_portada ADD orden INTEGER');
        $this->addSql('WITH posiciones AS (SELECT id, row_number() OVER (ORDER BY id DESC) - 1 AS orden FROM promociones_portada) UPDATE promociones_portada p SET orden = posiciones.orden FROM posiciones WHERE p.id = posiciones.id');
        $this->addSql('ALTER TABLE promociones_portada ALTER COLUMN orden SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se debe conservar el orden configurado.');
    }
}