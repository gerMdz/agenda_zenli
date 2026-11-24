<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identificadores de altas sincronizadas para evitar turnos duplicados';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE altas_turnos_sincronizadas (usuario_id BIGINT NOT NULL REFERENCES usuarios(id), operacion UUID NOT NULL, huella VARCHAR(64) NOT NULL, turno_id BIGINT NOT NULL REFERENCES turnos_administrativos(id), PRIMARY KEY (usuario_id, operacion))');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se deben conservar los identificadores de las altas sincronizadas.');
    }
}
