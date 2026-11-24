<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fichas de clientes vinculadas a turnos internos, con observaciones y baja lógica';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clientes ADD creado_por BIGINT REFERENCES usuarios(id), ADD actualizado_por BIGINT REFERENCES usuarios(id), ADD actualizado_en TIMESTAMPTZ, ADD archivado_en TIMESTAMPTZ');
        $this->addSql('ALTER TABLE turnos_administrativos ADD cliente_id BIGINT REFERENCES clientes(id)');
        $this->addSql('CREATE INDEX idx_turnos_admin_cliente ON turnos_administrativos (cliente_id, inicio DESC)');
        $this->addSql('CREATE INDEX idx_clientes_creado_por ON clientes (creado_por)');
        $this->addSql('CREATE TABLE observaciones_clientes (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, cliente_id BIGINT NOT NULL REFERENCES clientes(id), autor_id BIGINT NOT NULL REFERENCES usuarios(id), texto VARCHAR(2000) NOT NULL, creado_en TIMESTAMPTZ NOT NULL DEFAULT now())');
        $this->addSql('CREATE INDEX idx_observaciones_clientes_ficha ON observaciones_clientes (cliente_id, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Se deben conservar los vínculos, las fichas y sus observaciones.');
    }
}
