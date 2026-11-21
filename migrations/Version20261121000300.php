<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Visibilidad individual de profesionales en portada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profesionales ADD visible_portada BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('UPDATE profesionales SET visible_portada = true WHERE activa');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se debe conservar la visibilidad configurada.');
    }
}