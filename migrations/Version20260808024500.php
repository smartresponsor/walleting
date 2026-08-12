<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808024500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow operational outbox messages that are not bound to ledger transactions or provider events';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql('ALTER TABLE outbox_message DROP CONSTRAINT chk_outbox_reference');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql('DELETE FROM outbox_message WHERE ledger_transaction_id IS NULL AND provider_event_id IS NULL');
        $this->addSql('ALTER TABLE outbox_message ADD CONSTRAINT chk_outbox_reference CHECK (ledger_transaction_id IS NOT NULL OR provider_event_id IS NOT NULL)');
    }
}
