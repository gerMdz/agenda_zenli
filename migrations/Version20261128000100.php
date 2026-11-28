<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla de sesiones PHP persistentes en PostgreSQL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sesiones_php (
                sess_id VARCHAR(128) PRIMARY KEY,
                sess_data BYTEA NOT NULL,
                sess_lifetime INTEGER NOT NULL,
                sess_time INTEGER NOT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_sesiones_php_time ON sesiones_php (sess_time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sesiones_php');
    }
}
