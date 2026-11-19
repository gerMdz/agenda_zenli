<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enlaces opcionales a Instagram y Facebook en la portada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuracion_portada ADD instagram VARCHAR(2048), ADD facebook VARCHAR(2048)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuracion_portada DROP instagram, DROP facebook');
    }
}
