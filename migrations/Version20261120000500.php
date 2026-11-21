<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Imágenes opcionales de promociones de portada';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE promociones_portada ADD imagen BYTEA, ADD imagen_tipo VARCHAR(20), ADD imagen_version CHAR(64), ADD CONSTRAINT promociones_portada_imagen_valida CHECK ((imagen IS NULL AND imagen_tipo IS NULL AND imagen_version IS NULL) OR (imagen IS NOT NULL AND imagen_tipo IS NOT NULL AND imagen_version IS NOT NULL AND imagen_tipo IN ('image/jpeg', 'image/png', 'image/webp') AND octet_length(imagen) BETWEEN 1 AND 4194304))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se deben conservar las imágenes de promociones.');
    }
}