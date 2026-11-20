<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configuración flexible de horarios profesionales y nombre inicial de la sala';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profesionales ADD horario_por_franjas BOOLEAN, ADD horarios_revision INT NOT NULL DEFAULT 0');
        $this->addSql("UPDATE recursos SET nombre = 'Sala principal' WHERE codigo = 'ESPACIO_1' AND nombre = 'Espacio de atención compartido'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profesionales DROP horario_por_franjas, DROP horarios_revision');
    }
}
