<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802051800 extends AbstractMigration
{
    public function getDescription(): string { return 'Add transactional outbox'; }

    public function up(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Walleting requires PostgreSQL.');
        $this->addSql("CREATE TABLE outbox_message (id UUID NOT NULL, ledger_transaction_id UUID DEFAULT NULL, provider_event_id UUID DEFAULT NULL, message_type VARCHAR(191) NOT NULL, deduplication_key VARCHAR(191) NOT NULL, payload JSON NOT NULL, payload_hash VARCHAR(64) NOT NULL, status VARCHAR(255) NOT NULL, attempt_count INT NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, dispatched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_outbox_deduplication_key ON outbox_message (deduplication_key)');
        $this->addSql('CREATE INDEX idx_outbox_dispatchable ON outbox_message (status, available_at, created_at)');
        $this->addSql('ALTER TABLE outbox_message ADD CONSTRAINT fk_outbox_ledger_transaction FOREIGN KEY (ledger_transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE outbox_message ADD CONSTRAINT fk_outbox_provider_event FOREIGN KEY (provider_event_id) REFERENCES provider_event (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE outbox_message ADD CONSTRAINT chk_outbox_reference CHECK (ledger_transaction_id IS NOT NULL OR provider_event_id IS NOT NULL)');
        $this->addSql('ALTER TABLE outbox_message ADD CONSTRAINT chk_outbox_attempt_count CHECK (attempt_count >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE outbox_message');
    }
}
