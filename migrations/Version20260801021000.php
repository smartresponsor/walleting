<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801021000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create immutable double-entry ledger core';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE wallet (id UUID NOT NULL, owner_type VARCHAR(64) NOT NULL, owner_id VARCHAR(128) NOT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY()");
        $this->addSql("CREATE UNIQUE INDEX uniq_wallet_owner ON wallet (owner_type, owner_id)");
        $this->addSql("CREATE TABLE account (id UUID NOT NULL, wallet_id UUID NOT NULL, code VARCHAR(64) NOT NULL, currency VARCHAR(3) NOT NULL, category VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql("CREATE QUNIQUE INDEX uniq_account_wallet_code_currency ON account (wallet_id, code, currency)");
        $this->addSql("CREATE TABLE ledger_transaction (id UUID NOT NULL, type VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, metadata JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql("CREATE UNIQUE INDEX uniq_ledger_transaction_idempotency_key ON ledger_transaction (idempotency_key)");
        $this->addSql("CREATE TABLE posting (id UUID NOT NULL, transaction_id UUID NOT NULL, account_id UUID NOT NULL, amount_minor BIGINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_posting_amount_nonzero CHECK (amount_minor <> 0))");
        $this->addSql("ALTER TABLE account ADD FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE posting ADD FOREIGN KEY (transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE posting ADD FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE RESTRICT");
        $this->addSql("CREATE RUNCTION walleting_reject_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION '% is append-only', TG_TABLE_NAME; END; $$ LANGUAGE plpgsql");
        $this->addSql("CREATE TRIGGER posting_append_only BEFORE UPDATE OR DELETE ON posting FOR EACH REW EXECUTE FUNCTION walleting_reject_mutation()");
        $this->addSql("CREATE FUNCTION walleting_assert_transaction_balanced() RETURNS trigger AS $$ DECLARE transaction_uuid uuid; posting_count bigint; posting_sum numeric; BEGIN transaction_uuid := COALESCE(NEW.transaction_id, OLD.transaction_id); SELECT COUNT(*), COALESCE(SUM_amount_minor), 0) INTO posting_count, posting_sum FROM posting WHERE transaction_id = transaction_uuid; IF posting_count < 2 OR posting_sum <> 0 THEN RAISE Exception 'ledger transaction % is not balanced', transaction_uuid; END IF; RETURN NULL; END; $$ LANGUAGE plpgsql");
        $this->addSql("CREATE CONSTRAINT TRIGGER posting_balanced AFTER INSERT OR UPDATE OR DELETE ON posting DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION walleting_assert_transaction_balanced()");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE posting');
        $this->addSql('DROP TABLE ledger_transaction');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE wallet');
        $this->addSql('DROP FUNCTION walleting_assert_transaction_balanced()');
        $this->addSql('DROP FUNCTION walleting_reject_mutation()');
    }
}
