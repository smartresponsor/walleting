<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808012000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent posting SLO hysteresis state';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');

        $this->addSql("CREATE TABLE posting_slo_state (scope VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, pending_status VARCHAR(16) DEFAULT NULL, pending_count INT NOT NULL, reasons JSON NOT NULL, evaluated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(scope))");
        $this->addSql("ALTER TABLE posting_slo_state ADD CONSTRAINT chk_posting_slo_state_status CHECK (status IN ('healthy', 'degraded', 'critical'))");
        $this->addSql("ALTER TABLE posting_slo_state ADD CONSTRAINT chk_posting_slo_state_pending_status CHECK (pending_status IS NULL OR pending_status IN ('healthy', 'degraded', 'critical'))");
        $this->addSql('ALTER TABLE posting_slo_state ADD CONSTRAINT chk_posting_slo_state_pending_count CHECK (pending_count >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE posting_slo_state');
    }
}
