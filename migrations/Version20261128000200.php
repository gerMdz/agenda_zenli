<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega columna no_contactar a la tabla clientes para respetar la preferencia explícita del cliente.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clientes ADD COLUMN no_contactar BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clientes DROP COLUMN IF EXISTS no_contactar');
    }
}
