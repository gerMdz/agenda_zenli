<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orden de profesionales en portada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profesionales ADD orden_portada INTEGER');
        $this->addSql('WITH posiciones AS (SELECT id, row_number() OVER (ORDER BY nombre, id) - 1 AS orden FROM profesionales) UPDATE profesionales p SET orden_portada = posiciones.orden FROM posiciones WHERE p.id = posiciones.id');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se debe conservar el orden configurado.');
    }
}