<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807071100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for account statements and posted ledger chronology';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');

        $this->addSql('CREATE INDEX idx_posting_account_transaction ON posting (account_id, transaction_id)');
        $this->addSql('CREATE INDEX idx_posting_transaction_account ON posting (transaction_id, account_id)');
        $this->addSql("CREATE INDEX idx_ledger_transaction_posted_chronology ON ledger_transaction (posted_at DESC, id DESC) WHERE status = 'posted'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ledger_transaction_posted_chronology');
        $this->addSql('DROP INDEX idx_posting_transaction_account');
        $this->addSql('DROP INDEX idx_posting_account_transaction');
    }
}
