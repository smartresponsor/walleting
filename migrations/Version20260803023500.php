<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803023500 extends AbstractMigration
{
    public function getDescription(): string { return 'Add terminal outbox dead-letter state'; }

    public function up(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Walleting requires PostgreSQL.');
        $this->addSql('ALTER TABLE outbox_message DROP CONSTRAINT chk_outbox_status');
        $this->addSql('ALTER TABLE outbox_message DROP CONSTRAINT chk_outbox_lifecycle');
        $this->addSql("ALTER TABLE outbox_message ADD CONSTRAINT chk_outbox_status CHECK (status IN ('pending', 'claimed', 'dispatched', 'failed', 'dead'))");
        $this->addSql("ALTER TABLE outbox_message ADD CONSTRAINT chk_outbox_lifecycle CHECK ((status = 'pending' AND claimed_at IS NULL AND dispatched_at IS NULL AND last_error IS NULL) OR (status = 'claimed' AND claimed_at IS NOT NULL AND dispatched_at IS NULL) OR (status IN ('failed', 'dead') AND claimed_at IS NOT NULL AND dispatched_at IS NULL AND last_error IS NOT NULL) OR (status = 'dispatched' AND claimed_at IS NOT NULL AND dispatched_at IS NOT NULL AND last_error IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE outbox_message SET status = 'failed' WHERE status = 'dead'");
        $this->addSql('ALTER TABLE outbox_message DROP CONSTRAINT chk_outbox_status');
        $this->addSql('ALTER TABLE outbox_message DROP CONSTRAINT chk_outbox_lifecycle');
        $this->addSql("ALTER TABLE outbox_message ADD CONSTRAINT chk_outbox_status CHECK (status IN ('pending', 'claimed', 'dispatched', 'failed'))");
        $this->addSql("ALTER TABLE outbox_message ADD CONSTRAINT chk_outbox_lifecycle CHECK ((status = 'pending' AND claimed_at IS NULL AND dispatched_at IS NULL AND last_error IS NULL) OR (status = 'claimed' AND claimed_at IS NOT NULL AND dispatched_at IS NULL) OR (status = 'failed' AND claimed_at IS NOT NULL AND dispatched_at IS NULL AND last_error IS NOT NULL) OR (status = 'dispatched' AND claimed_at IS NOT NULL AND dispatched_at IS NOT NULL AND last_error IS NULL))");
    }
}
