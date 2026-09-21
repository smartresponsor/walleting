<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914123500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align PostgreSQL physical schema with current Doctrine metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ALTER allow_negative DROP DEFAULT');
        $this->addSql('ALTER INDEX uniq_account_uuid RENAME TO UNIQ_7D3656A4D17F50A6');
        $this->addSql('ALTER INDEX uniq_account_slug RENAME TO UNIQ_7D3656A4989D9B62');
        $this->addSql('ALTER INDEX idx_account_wallet RENAME TO IDX_7D3656A4712520F3');
        $this->addSql('ALTER TABLE account_balance ALTER balance_minor DROP DEFAULT');
        $this->addSql('ALTER TABLE account_balance ALTER posting_count DROP DEFAULT');
        $this->addSql('DROP INDEX IF EXISTS uniq_funding_reversal_transaction');
        $this->addSql('DROP INDEX IF EXISTS IDX_D30DD1D62B93C404');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D30DD1D62B93C404 ON funding (reversal_transaction_id)');
        $this->addSql('DROP INDEX idx_ledger_transaction_posted_chronology');
        $this->addSql("CREATE INDEX idx_ledger_transaction_posted_chronology ON ledger_transaction (posted_at, id) WHERE status = 'posted'");
        $this->addSql('ALTER INDEX uniq_payment_instrument_uuid RENAME TO UNIQ_949E808D17F50A6');
        $this->addSql('ALTER INDEX uniq_payment_instrument_slug RENAME TO UNIQ_949E808989D9B62');
        $this->addSql('ALTER INDEX idx_payment_instrument_wallet RENAME TO IDX_949E808712520F3');
        $this->addSql('ALTER TABLE posting_slo_state ALTER revision DROP DEFAULT');
        $this->addSql('ALTER TABLE reconciliation_run ALTER checked_count DROP DEFAULT');
        $this->addSql('ALTER TABLE reconciliation_run ALTER matched_count DROP DEFAULT');
        $this->addSql('ALTER TABLE reconciliation_run ALTER mismatch_count DROP DEFAULT');
        $this->addSql('ALTER INDEX uniq_wallet_uuid RENAME TO UNIQ_7C68921FD17F50A6');
        $this->addSql('ALTER INDEX uniq_wallet_slug RENAME TO UNIQ_7C68921F989D9B62');
        $this->addSql('DROP INDEX IF EXISTS uniq_withdrawal_reversal_transaction');
        $this->addSql('DROP INDEX IF EXISTS IDX_6D2D3B452B93C404');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6D2D3B452B93C404 ON withdrawal (reversal_transaction_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_6D2D3B452B93C404');
        $this->addSql('CREATE UNIQUE INDEX IDX_6D2D3B452B93C404 ON withdrawal (reversal_transaction_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_withdrawal_reversal_transaction ON withdrawal (reversal_transaction_id)');
        $this->addSql('ALTER INDEX UNIQ_7C68921F989D9B62 RENAME TO uniq_wallet_slug');
        $this->addSql('ALTER INDEX UNIQ_7C68921FD17F50A6 RENAME TO uniq_wallet_uuid');
        $this->addSql('ALTER TABLE reconciliation_run ALTER mismatch_count SET DEFAULT 0');
        $this->addSql('ALTER TABLE reconciliation_run ALTER matched_count SET DEFAULT 0');
        $this->addSql('ALTER TABLE reconciliation_run ALTER checked_count SET DEFAULT 0');
        $this->addSql('ALTER TABLE posting_slo_state ALTER revision SET DEFAULT 0');
        $this->addSql('ALTER INDEX IDX_949E808712520F3 RENAME TO idx_payment_instrument_wallet');
        $this->addSql('ALTER INDEX UNIQ_949E808989D9B62 RENAME TO uniq_payment_instrument_slug');
        $this->addSql('ALTER INDEX UNIQ_949E808D17F50A6 RENAME TO uniq_payment_instrument_uuid');
        $this->addSql('DROP INDEX idx_ledger_transaction_posted_chronology');
        $this->addSql("CREATE INDEX idx_ledger_transaction_posted_chronology ON ledger_transaction (posted_at DESC, id DESC) WHERE status = 'posted'");
        $this->addSql('DROP INDEX UNIQ_D30DD1D62B93C404');
        $this->addSql('CREATE UNIQUE INDEX IDX_D30DD1D62B93C404 ON funding (reversal_transaction_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_funding_reversal_transaction ON funding (reversal_transaction_id)');
        $this->addSql('ALTER TABLE account_balance ALTER posting_count SET DEFAULT 0');
        $this->addSql('ALTER TABLE account_balance ALTER balance_minor SET DEFAULT 0');
        $this->addSql('ALTER INDEX IDX_7D3656A4712520F3 RENAME TO idx_account_wallet');
        $this->addSql('ALTER INDEX UNIQ_7D3656A4989D9B62 RENAME TO uniq_account_slug');
        $this->addSql('ALTER INDEX UNIQ_7D3656A4D17F50A6 RENAME TO uniq_account_uuid');
        $this->addSql('ALTER TABLE account ALTER allow_negative SET DEFAULT TRUE');
    }
}
