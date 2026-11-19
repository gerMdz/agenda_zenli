<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118000200 extends AbstractMigration
{
    public function getDescription(): string { return 'Zenli v2: profesionales, recurso, tratamientos y políticas iniciales'; }
    public function up(Schema $schema): void
    {
        $this->addSql('INSERT INTO profesionales (nombre) VALUES (\'Nataly\'), (\'Micaela\')');
        $this->addSql('INSERT INTO recursos (codigo,nombre) VALUES (\'ESPACIO_1\',\'Espacio de atención compartido\')');
        $this->addSql('INSERT INTO tratamientos (codigo,nombre) VALUES (\'DESCONTRACTURANTE\',\'Descontracturante\'),(\'RELAJANTE\',\'Relajante\'),(\'MIXTO\',\'Mixto\'),(\'TAILANDES\',\'Tailandés\'),(\'BRUXISMO\',\'Bruxismo cervicocraneofacial\'),(\'REFLEXOLOGIA\',\'Reflexología de pies\'),(\'LOMI_LOMI\',\'Lomi Lomi\')');
        $this->addSql('INSERT INTO politicas_reservas (clave,valor) VALUES (\'vencimiento_pendiente_horas\',\'24\'::jsonb),(\'zona_horaria\',\'"America/Argentina/Mendoza"\'::jsonb)');
        $this->addSql('INSERT INTO servicios (codigo,nombre,participantes,duracion_minutos,modo_profesional,publico) VALUES (\'PAREJA\',\'Masaje en pareja\',2,60,\'POR_DEFINIR\',FALSE)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM servicios WHERE codigo = 'PAREJA'");
        $this->addSql("DELETE FROM politicas_reservas WHERE clave IN ('vencimiento_pendiente_horas','zona_horaria')");
        $this->addSql("DELETE FROM tratamientos WHERE codigo IN ('DESCONTRACTURANTE','RELAJANTE','MIXTO','TAILANDES','BRUXISMO','REFLEXOLOGIA','LOMI_LOMI')");
        $this->addSql("DELETE FROM recursos WHERE codigo = 'ESPACIO_1'");
        $this->addSql("DELETE FROM profesionales WHERE nombre IN ('Nataly','Micaela')");
    }
}
