<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modo solo imagen para la portada pública';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuracion_portada ADD solo_imagen BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuracion_portada DROP solo_imagen');
    }
}
