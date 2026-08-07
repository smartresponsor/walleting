<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802013500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce concurrency-safe overdraft policy on account balances';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');

        $this->addSql("ALTER TABLE account ADD allow_negative BOOLEAN NOT NULL DEFAULT TRUE");
        $this->addSql("UPDATE account SET allow_negative = FALSE WHERE category IN ('asset', 'reserve')");
        $this->addSql("CREATE OR REPLACE FUNCTION walleting_apply_posting_to_balance() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE resulting_balance BIGINT; overdraft_allowed BOOLEAN; BEGIN INSERT INTO account_balance (account_id, balance_minor, currency, posting_count, updated_at) VALUES (NEW.account_id, NEW.amount_minor, NEW.currency, 1, CURRENT_TIMESTAMP) ON CONFLICT (account_id) DO UPDATE SET balance_minor = account_balance.balance_minor + EXCLUDED.balance_minor, posting_count = account_balance.posting_count + 1, updated_at = CURRENT_TIMESTAMP WHERE account_balance.currency = EXCLUDED.currency RETURNING balance_minor INTO resulting_balance; IF NOT FOUND THEN RAISE EXCEPTION 'account balance currency mismatch for account %', NEW.account_id; END IF; SELECT allow_negative INTO overdraft_allowed FROM account WHERE id = NEW.account_id; IF NOT overdraft_allowed AND resulting_balance < 0 THEN RAISE EXCEPTION 'insufficient available balance for account %', NEW.account_id USING ERRCODE = '23514'; END IF; RETURN NEW; END; $$");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("CREATE OR REPLACE FUNCTION walleting_apply_posting_to_balance() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN INSERT INTO account_balance (account_id, balance_minor, currency, posting_count, updated_at) VALUES (NEW.account_id, NEW.amount_minor, NEW.currency, 1, CURRENT_TIMESTAMP) ON CONFLICT (account_id) DO UPDATE SET balance_minor = account_balance.balance_minor + EXCLUDED.balance_minor, posting_count = account_balance.posting_count + 1, updated_at = CURRENT_TIMESTAMP WHERE account_balance.currency = EXCLUDED.currency; IF NOT FOUND THEN RAISE EXCEPTION 'account balance currency mismatch for account %', NEW.account_id; END IF; RETURN NEW; END; $$");
        $this->addSql('ALTER TABLE account DROP allow_negative');
    }
}
