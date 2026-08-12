<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807063100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add inbox receipts for idempotent external event consumption';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql("CREATE TABLE inbox_receipt (id UUID NOT NULL, source VARCHAR(64) NOT NULL, message_id VARCHAR(191) NOT NULL, schema_version INT NOT NULL, event_type VARCHAR(191) NOT NULL, deduplication_key VARCHAR(191) NOT NULL, payload_hash VARCHAR(64) NOT NULL, status VARCHAR(255) NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_receipt_source_message ON inbox_receipt (source, message_id)');
        $this->addSql('CREATE INDEX idx_inbox_receipt_status_received ON inbox_receipt (status, received_at)');
        $this->addSql('ALTER TABLE inbox_receipt ADD CONSTRAINT chk_inbox_receipt_schema_version CHECK (schema_version >= 1)');
        $this->addSql("ALTER TABLE inbox_receipt ADD CONSTRAINT chk_inbox_receipt_status CHECK (status IN ('processing', 'processed'))");
        $this->addSql("ALTER TABLE inbox_receipt ADD CONSTRAINT chk_inbox_receipt_lifecycle CHECK ((status = 'processing' AND processed_at IS NULL) OR (status = 'processed' AND processed_at IS NOT NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inbox_receipt');
    }
}
