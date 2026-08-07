<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801232000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link funding and withdrawal reversals to immutable ledger transactions';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql('ALTER TABLE funding ADD reversal_transaction_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE withdrawal ADD reversal_transaction_id UUID DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_funding_reversal_transaction ON funding (reversal_transaction_id) WHERE reversal_transaction_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_withdrawal_reversal_transaction ON withdrawal (reversal_transaction_id) WHERE reversal_transaction_id IS NOT NULL');
        $this->addSql('ALTER TABLE funding ADD CONSTRAINT fk_funding_reversal_transaction FOREIGN KEY (reversal_transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE withdrawal ADD CONSTRAINT fk_withdrawal_reversal_transaction FOREIGN KEY (reversal_transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT');
        $this->addSql("ALTER TABLE funding ADD CONSTRAINT chk_funding_reversal_state CHECK ((status = 'reversed' AND reversal_transaction_id IS NOT NULL) OR (status <> 'reversed' AND reversal_transaction_id IS NULL))");
        $this->addSql("ALTER TABLE withdrawal ADD CONSTRAINT chk_withdrawal_reversal_state CHECK ((status = 'reversed' AND reversal_transaction_id IS NOT NULL) OR (status <> 'reversed' AND reversal_transaction_id IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE funding DROP COLUMN reversal_transaction_id');
        $this->addSql('ALTER TABLE withdrawal DROP COLUMN reversal_transaction_id');
    }
}
