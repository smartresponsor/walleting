<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801021000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create immutable double-entry ledger core with PostgreSQL-enforced append-only and balance invariants';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Walleting requires PostgreSQL.');

        $this->addSql("CREATE TABLE wallet (id UUID NOT NULL, owner_type VARCHAR(64) NOT NULL, owner_id VARCHAR(128) NOT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql("CREATE UNIQUE INDEX uniq_wallet_owner ON wallet (owner_type, owner_id)");

        $this->addSql("CREATE TABLE account (id UUID NOT NULL, wallet_id UUID NOT NULL, code VARCHAR(64) NOT NULL, currency VARCHAR(3) NOT NULL, category VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql("CREATE UNIQUE INDEX uniq_account_wallet_code_currency ON account (wallet_id, code, currency)");
        $this->addSql("CREATE INDEX idx_account_wallet ON account (wallet_id)");
        $this->addSql("ALTER TABLE account ADD CONSTRAINT fk_account_wallet FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE");

        $this->addSql("CREATE TABLE ledger_transaction (id UUID NOT NULL, type VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, metadata JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT chk_transaction_posted_at CHECK ((status = 'posted' AND posted_at IS NOT NULL) OR (status <> 'posted' AND posted_at IS NULL)))");
        $this->addSql("CREATE UNIQUE INDEX uniq_ledger_transaction_idempotency_key ON ledger_transaction (idempotency_key)");

        $this->addSql("CREATE TABLE posting (id UUID NOT NULL, transaction_id UUID NOT NULL, account_id UUID NOT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, sequence INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_posting_amount_nonzero CHECK (amount_minor <> 0), CONSTRAINT chk_posting_sequence_positive CHECK (sequence > 0))");
        $this->addSql("CREATE UNIQUE INDEX uniq_posting_transaction_sequence ON posting (transaction_id, sequence)");
        $this->addSql("CREATE INDEX idx_posting_transaction ON posting (transaction_id)");
        $this->addSql("CREATE INDEX idx_posting_account ON posting (account_id)");
        $this->addSql("ALTER TABLE posting ADD CONSTRAINT fk_posting_transaction FOREIGN KEY (transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE");
        $this->addSql("ALTER TABLE posting ADD CONSTRAINT fk_posting_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE");

        $this->addSql("CREATE FUNCTION walleting_reject_posting_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'posting is append-only'; END; $$");
        $this->addSql("CREATE TRIGGER posting_append_only BEFORE UPDATE OR DELETE ON posting FOR EACH ROW EXECUTE FUNCTION walleting_reject_posting_mutation()");

        $this->addSql("CREATE FUNCTION walleting_guard_transaction_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'ledger transaction cannot be deleted'; END IF; IF OLD.status = 'posted' THEN RAISE EXCEPTION 'posted ledger transaction is immutable'; END IF; IF NEW.id <> OLD.id OR NEW.type <> OLD.type OR NEW.idempotency_key <> OLD.idempotency_key OR NEW.request_hash <> OLD.request_hash OR NEW.metadata::text <> OLD.metadata::text OR NEW.created_at <> OLD.created_at THEN RAISE EXCEPTION 'ledger transaction identity and history are immutable'; END IF; IF NOT (OLD.status = 'pending' AND NEW.status = 'posted' AND OLD.posted_at IS NULL AND NEW.posted_at IS NOT NULL) THEN RAISE EXCEPTION 'invalid ledger transaction state transition'; END IF; RETURN NEW; END; $$");
        $this->addSql("CREATE TRIGGER ledger_transaction_immutable BEFORE UPDATE OR DELETE ON ledger_transaction FOR EACH ROW EXECUTE FUNCTION walleting_guard_transaction_mutation()");

        $this->addSql("CREATE FUNCTION walleting_validate_posting_currency() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE account_currency VARCHAR(3); BEGIN SELECT currency INTO account_currency FROM account WHERE id = NEW.account_id; IF account_currency IS NULL OR account_currency <> NEW.currency THEN RAISE EXCEPTION 'posting currency % does not match account currency %', NEW.currency, account_currency; END IF; RETURN NEW; END; $$");
        $this->addSql("CREATE TRIGGER posting_currency_guard BEFORE INSERT ON posting FOR EACH ROW EXECUTE FUNCTION walleting_validate_posting_currency()");

        $this->addSql("CREATE FUNCTION walleting_assert_transaction_balanced(transaction_uuid UUID) RETURNS void LANGUAGE plpgsql AS $$ DECLARE transaction_status VARCHAR(255); posting_count BIGINT; posting_sum NUMERIC; currency_count BIGINT; BEGIN SELECT status INTO transaction_status FROM ledger_transaction WHERE id = transaction_uuid; IF transaction_status = 'posted' THEN SELECT COUNT(*), COALESCE(SUM(amount_minor), 0), COUNT(DISTINCT currency) INTO posting_count, posting_sum, currency_count FROM posting WHERE transaction_id = transaction_uuid; IF posting_count < 2 OR posting_sum <> 0 OR currency_count <> 1 THEN RAISE EXCEPTION 'ledger transaction % must contain at least two balanced postings in one currency', transaction_uuid; END IF; END IF; END; $$");
        $this->addSql("CREATE FUNCTION walleting_assert_posting_transaction_balanced() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM walleting_assert_transaction_balanced(COALESCE(NEW.transaction_id, OLD.transaction_id)); RETURN NULL; END; $$");
        $this->addSql("CREATE CONSTRAINT TRIGGER posting_balanced AFTER INSERT ON posting DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION walleting_assert_posting_transaction_balanced()");
        $this->addSql("CREATE FUNCTION walleting_assert_ledger_transaction_balanced() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM walleting_assert_transaction_balanced(NEW.id); RETURN NULL; END; $$");
        $this->addSql("CREATE CONSTRAINT TRIGGER ledger_transaction_balanced AFTER INSERT OR UPDATE OF status ON ledger_transaction DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION walleting_assert_ledger_transaction_balanced()");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE posting');
        $this->addSql('DROP TABLE ledger_transaction');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE wallet');
        $this->addSql('DROP FUNCTION walleting_assert_ledger_transaction_balanced()');
        $this->addSql('DROP FUNCTION walleting_assert_posting_transaction_balanced()');
        $this->addSql('DROP FUNCTION walleting_assert_transaction_balanced(UUID)');
        $this->addSql('DROP FUNCTION walleting_validate_posting_currency()');
        $this->addSql('DROP FUNCTION walleting_guard_transaction_mutation()');
        $this->addSql('DROP FUNCTION walleting_reject_posting_mutation()');
    }
}
