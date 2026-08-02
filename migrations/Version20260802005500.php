<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802005500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add concurrency-safe account balance projection maintained from immutable postings';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Walleting requires PostgreSQL.');

        $this->addSql("CREATE TABLE account_balance (account_id UUID NOT NULL, balance_minor BIGINT NOT NULL DEFAULT 0, currency VARCHAR(3) NOT NULL, posting_count BIGINT NOT NULL DEFAULT 0, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(account_id), CONSTRAINT chk_account_balance_posting_count_nonnegative CHECK (posting_count >= 0))");
        $this->addSql("ALTER TABLE account_balance ADD CONSTRAINT fk_account_balance_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE");
        $this->addSql("INSERT INTO account_balance (account_id, balance_minor, currency, posting_count, updated_at) SELECT a.id, COALESCE(SUM(p.amount_minor), 0), a.currency, COUNT(p.id), CURRENT_TIMESTAMP FROM account a LEFT JOIN posting p ON p.account_id = a.id GROUP BY a.id, a.currency");

        $this->addSql("CREATE FUNCTION walleting_reject_account_balance_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF pg_trigger_depth() < 2 THEN RAISE EXCEPTION 'account balance is derived from postings'; END IF; RETURN NEW; END; $$");
        $this->addSql("CREATE TRIGGER account_balance_derived BEFORE UPDATE OR DELETE ON account_balance FOR EACH ROW EXECUTE FUNCTION walleting_reject_account_balance_mutation()");
        $this->addSql("CREATE FUNCTION walleting_apply_posting_to_balance() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN INSERT INTO account_balance (account_id, balance_minor, currency, posting_count, updated_at) VALUES (NEW.account_id, NEW.amount_minor, NEW.currency, 1, CURRENT_TIMESTAMP) ON CONFLICT (account_id) DO UPDATE SET balance_minor = account_balance.balance_minor + EXCLUDED.balance_minor, posting_count = account_balance.posting_count + 1, updated_at = CURRENT_TIMESTAMP WHERE account_balance.currency = EXCLUDED.currency; IF NOT FOUND THEN RAISE EXCEPTION 'account balance currency mismatch for account %', NEW.account_id; END IF; RETURN NEW; END; $$");
        $this->addSql("CREATE TRIGGER posting_account_balance AFTER INSERT ON posting FOR EACH ROW EXECUTE FUNCTION walleting_apply_posting_to_balance()");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER posting_account_balance ON posting');
        $this->addSql('DROP FUNCTION walleting_apply_posting_to_balance()');
        $this->addSql('DROP TRIGGER account_balance_derived ON account_balance');
        $this->addSql('DROP FUNCTION walleting_reject_account_balance_mutation()');
        $this->addSql('DROP TABLE account_balance');
    }
}
