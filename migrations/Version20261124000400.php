<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Claves de acceso WebAuthn y auditoría de alta y revocación';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE usuarios ADD identificador_webauthn UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE');
        $this->addSql('CREATE TABLE claves_acceso (id VARCHAR(64) PRIMARY KEY, usuario_id BIGINT NOT NULL REFERENCES usuarios(id), credencial JSONB NOT NULL, creada_en TIMESTAMPTZ NOT NULL DEFAULT now(), ultimo_uso TIMESTAMPTZ)');
        $this->addSql('CREATE INDEX idx_claves_acceso_usuario ON claves_acceso (usuario_id)');
        $this->addSql("ALTER TABLE eventos_acceso DROP CONSTRAINT eventos_acceso_tipo_check, ADD CHECK (tipo IN ('INGRESO', 'FALLO', 'DEMORA', 'SALIDA', 'DESBLOQUEO', 'CUENTA_CREADA', 'CUENTA_ACTIVADA', 'CUENTA_DESACTIVADA', 'CLAVE_CAMBIADA', 'CUENTA_VINCULADA', 'PASSKEY_CREADA', 'PASSKEY_REVOCADA'))");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se deben conservar las claves de acceso y su auditoría.');
    }
}
