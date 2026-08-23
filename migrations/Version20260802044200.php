<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802044200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reconciliation summary counters and lifecycle constraints';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql('ALTER TABLE reconciliation_run ADD checked_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE reconciliation_run ADD matched_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE reconciliation_run ADD mismatch_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE reconciliation_run ADD CONSTRAINT chk_reconciliation_counts_nonnegative CHECK (checked_count >= 0 AND matched_count >= 0 AND mismatch_count >= 0)');
        $this->addSql('ALTER TABLE reconciliation_run ADD CONSTRAINT chk_reconciliation_counts_total CHECK (checked_count = matched_count + mismatch_count)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reconciliation_run DROP COLUMN mismatch_count, DROP COLUMN matched_count, DROP COLUMN checked_count');
    }
}
