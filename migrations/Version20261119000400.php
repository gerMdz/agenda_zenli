<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dirección y horarios públicos del centro';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE configuracion_portada ADD direccion VARCHAR(255) NOT NULL DEFAULT 'Espejo 167, Ciudad de Mendoza', ADD horarios TEXT NOT NULL DEFAULT E'Lun, mié y vie · 10:00 a 20:00\\nMar, jue y sáb · 11:00 a 19:00'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuracion_portada DROP direccion, DROP horarios');
    }
}