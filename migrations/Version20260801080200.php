<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801080200 extends AbstractMigration
{
    public function getDescription(): string { return 'Add provider events and reconciliation model'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE provider_event (id UUID NOT NULL, provider VARCHAR(64) NOT NULL, external_id VARCHAR(191) NOT NULL, event_type VARCHAR(128) NOT NULL, payload JSON NOT NULL, status VARCHAR(255) NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failure_message TEXT DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql("CREATE UNIQUE INDEX uniq_provider_event_external ON provider_event (provider, external_id)");
        $this->addSql("CREATE TABLE reconciliation_run (id UUID NOT NULL, provider VARCHAR(64) NOT NULL, run_key VARCHAR(128) NOT NULL, status VARCHAR(255) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, failure_message TEXT DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql("CREATE UNIQUE INDEX uniq_reconciliation_run_key ON reconciliation_run (provider, run_key)");
        $this->addSql("CREATE TABLE reconciliation_mismatch (id UUID NOT NULL, run_id UUID NOT NULL, type VARCHAR(255) NOT NULL, external_reference VARCHAR(191) NOT NULL, details JSON NOT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql("CREATE UNIQUE INDEX uniq_reconciliation_mismatch_reference ON reconciliation_mismatch (run_id, external_reference, type)");
        $this->addSql("ALTER TABLE reconciliation_mismatch ADD FOREIGN KEY (run_id) REFERENCES reconciliation_run (id) ON DELETE RESTRICT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reconciliation_mismatch');
        $this->addSql('DROP TABLE reconciliation_run');
        $this->addSql('DROP TABLE provider_event');
    }
}
