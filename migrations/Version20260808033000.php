<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808033000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable outbox dead-letter requeue audit trail';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql('CREATE TABLE outbox_requeue_audit (id UUID NOT NULL, outbox_message_id UUID NOT NULL, attempt_count INT NOT NULL, operator VARCHAR(191) NOT NULL, reason TEXT NOT NULL, previous_error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_outbox_requeue_message_created ON outbox_requeue_audit (outbox_message_id, created_at)');
        $this->addSql('ALTER TABLE outbox_requeue_audit ADD CONSTRAINT FK_OUTBOX_REQUEUE_MESSAGE FOREIGN KEY (outbox_message_id) REFERENCES outbox_message (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE outbox_requeue_audit ADD CONSTRAINT chk_outbox_requeue_attempt CHECK (attempt_count > 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE outbox_requeue_audit');
    }
}
