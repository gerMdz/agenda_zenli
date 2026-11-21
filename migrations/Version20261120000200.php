<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Presentación pública opcional de profesionales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE presentacion_profesionales_config (id SMALLINT PRIMARY KEY CHECK (id = 1), modo VARCHAR(10) NOT NULL DEFAULT 'ocultos' CHECK (modo IN ('ocultos', 'nombres', 'imagenes')), actualizado_por BIGINT REFERENCES usuarios(id), actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now())");
        $this->addSql("INSERT INTO presentacion_profesionales_config (id) VALUES (1)");
        $this->addSql("CREATE TABLE presentacion_profesionales_imagenes (profesional_id BIGINT PRIMARY KEY REFERENCES profesionales(id), imagen BYTEA, imagen_tipo VARCHAR(20), imagen_version CHAR(64), actualizado_por BIGINT NOT NULL REFERENCES usuarios(id), actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now(), CHECK ((imagen IS NULL AND imagen_tipo IS NULL AND imagen_version IS NULL) OR (imagen IS NOT NULL AND imagen_tipo IS NOT NULL AND imagen_version IS NOT NULL AND imagen_tipo IN ('image/jpeg', 'image/png', 'image/webp') AND octet_length(imagen) BETWEEN 1 AND 4194304)))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se deben conservar las imágenes y su auditoría.');
    }
}