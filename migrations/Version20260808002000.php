<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add operational posting metric samples for health aggregation';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');

        $this->addSql("CREATE TABLE posting_metric_sample (id UUID NOT NULL, event VARCHAR(16) NOT NULL, transaction_type VARCHAR(32) NOT NULL, attempt INT NOT NULL, retry_count INT NOT NULL, retry_reason VARCHAR(64) DEFAULT NULL, attempt_duration_ms INT NOT NULL, total_duration_ms INT NOT NULL, lock_wait_ms INT DEFAULT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_posting_metric_recorded_at ON posting_metric_sample (recorded_at)');
        $this->addSql('CREATE INDEX idx_posting_metric_event_recorded_at ON posting_metric_sample (event, recorded_at)');
        $this->addSql('CREATE INDEX idx_posting_metric_retry_reason_recorded_at ON posting_metric_sample (retry_reason, recorded_at)');
        $this->addSql("ALTER TABLE posting_metric_sample ADD CONSTRAINT chk_posting_metric_event CHECK (event IN ('retry', 'completed', 'failed'))");
        $this->addSql('ALTER TABLE posting_metric_sample ADD CONSTRAINT chk_posting_metric_attempt CHECK (attempt > 0)');
        $this->addSql('ALTER TABLE posting_metric_sample ADD CONSTRAINT chk_posting_metric_retry_count CHECK (retry_count >= 0)');
        $this->addSql('ALTER TABLE posting_metric_sample ADD CONSTRAINT chk_posting_metric_durations CHECK (attempt_duration_ms >= 0 AND total_duration_ms >= 0 AND (lock_wait_ms IS NULL OR lock_wait_ms >= 0))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE posting_metric_sample');
    }
}
