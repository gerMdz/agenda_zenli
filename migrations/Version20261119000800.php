<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Detalle de los espacios de atención';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recursos ADD detalle VARCHAR(1000)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recursos DROP detalle');
    }
}
