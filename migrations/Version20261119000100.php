<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configuración de portada y límite de intentos de acceso administrativo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE configuracion_portada (
                id SMALLINT PRIMARY KEY CHECK (id = 1),
                telefono VARCHAR(16) NOT NULL CHECK (telefono ~ '^\+[1-9][0-9]{7,14}$'),
                imagen BYTEA,
                imagen_tipo VARCHAR(20),
                imagen_version CHAR(64),
                actualizado_por BIGINT NOT NULL REFERENCES usuarios(id),
                actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now(),
                CHECK ((imagen IS NULL AND imagen_tipo IS NULL AND imagen_version IS NULL)
                    OR (imagen IS NOT NULL AND imagen_tipo IS NOT NULL AND imagen_version IS NOT NULL
                        AND imagen_tipo IN ('image/png', 'image/jpeg', 'image/webp')
                        AND octet_length(imagen) BETWEEN 1 AND 4194304))
            )
            SQL);
        $this->addSql('CREATE TABLE intentos_acceso_admin (clave CHAR(64) PRIMARY KEY, intentos INT NOT NULL, vence_en TIMESTAMPTZ NOT NULL)');
        $this->addSql('CREATE INDEX idx_intentos_acceso_admin_vencimiento ON intentos_acceso_admin (vence_en)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE intentos_acceso_admin');
        $this->addSql('DROP TABLE configuracion_portada');
    }
}
