<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable financial operation amounts and bounded partial settlement/refund semantics';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');

        $this->addSql('ALTER TABLE financial_operation_link ADD amount_minor BIGINT DEFAULT NULL');
        $this->addSql("UPDATE financial_operation_link l SET amount_minor = CASE WHEN l.reservation_id IS NOT NULL THEN (SELECT r.amount_minor FROM reservation r WHERE r.id = l.reservation_id) ELSE (SELECT COALESCE(SUM(CASE WHEN p.amount_minor > 0 THEN p.amount_minor ELSE 0 END), 0) FROM posting p WHERE p.transaction_id = l.result_transaction_id) END");
        $this->addSql('ALTER TABLE financial_operation_link ALTER amount_minor SET NOT NULL');
        $this->addSql('ALTER TABLE financial_operation_link ADD CONSTRAINT chk_financial_operation_amount_positive CHECK (amount_minor > 0)');

        $this->addSql('DROP INDEX uniq_financial_operation_inverse_source');
        $this->addSql('DROP INDEX uniq_financial_operation_reservation_settlement');
        $this->addSql('CREATE INDEX idx_financial_operation_source_type ON financial_operation_link (source_transaction_id, operation_type)');
        $this->addSql('CREATE INDEX idx_financial_operation_reservation_type ON financial_operation_link (reservation_id, operation_type)');

        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_financial_operation_link_semantics() RETURNS trigger AS $$
DECLARE
    source_type VARCHAR(32);
    result_type VARCHAR(32);
    source_amount BIGINT;
    result_amount BIGINT;
    reservation_amount BIGINT;
    reservation_source UUID;
    settled_amount BIGINT;
    refunded_amount BIGINT;
    reverse_count BIGINT;
BEGIN
    SELECT type INTO source_type FROM ledger_transaction WHERE id = NEW.source_transaction_id FOR UPDATE;
    SELECT type INTO result_type FROM ledger_transaction WHERE id = NEW.result_transaction_id;
    SELECT COALESCE(SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END), 0) INTO result_amount FROM posting WHERE transaction_id = NEW.result_transaction_id;

    IF result_type IS DISTINCT FROM NEW.operation_type THEN
        RAISE EXCEPTION 'financial operation result type must match operation type' USING ERRCODE = '23514';
    END IF;

    IF NEW.operation_type IN ('capture', 'release') THEN
        IF source_type IS DISTINCT FROM 'reserve' THEN
            RAISE EXCEPTION 'capture and release must originate from a reserve transaction' USING ERRCODE = '23514';
        END IF;
        IF result_amount IS DISTINCT FROM NEW.amount_minor THEN
            RAISE EXCEPTION 'financial operation amount must match result transaction amount' USING ERRCODE = '23514';
        END IF;
        SELECT amount_minor, reserve_transaction_id INTO reservation_amount, reservation_source FROM reservation WHERE id = NEW.reservation_id FOR UPDATE;
        IF reservation_source IS DISTINCT FROM NEW.source_transaction_id THEN
            RAISE EXCEPTION 'reservation source transaction does not match' USING ERRCODE = '23514';
        END IF;
        SELECT COALESCE(SUM(amount_minor), 0) INTO settled_amount FROM financial_operation_link WHERE reservation_id = NEW.reservation_id AND operation_type IN ('capture', 'release') AND id <> NEW.id;
        IF settled_amount + NEW.amount_minor > reservation_amount THEN
            RAISE EXCEPTION 'reservation settlement exceeds reserved amount' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF NEW.operation_type IN ('refund', 'reverse') THEN
        IF source_type IN ('refund', 'reverse') THEN
            RAISE EXCEPTION 'refund and reverse cannot originate from an inverse transaction' USING ERRCODE = '23514';
        END IF;
        IF result_amount IS DISTINCT FROM NEW.amount_minor THEN
            RAISE EXCEPTION 'financial operation amount must match result transaction amount' USING ERRCODE = '23514';
        END IF;
        SELECT COALESCE(SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END), 0) INTO source_amount FROM posting WHERE transaction_id = NEW.source_transaction_id;
        SELECT COALESCE(SUM(amount_minor), 0) INTO refunded_amount FROM financial_operation_link WHERE source_transaction_id = NEW.source_transaction_id AND operation_type = 'refund' AND id <> NEW.id;
        SELECT COUNT(*) INTO reverse_count FROM financial_operation_link WHERE source_transaction_id = NEW.source_transaction_id AND operation_type = 'reverse' AND id <> NEW.id;

        IF NEW.operation_type = 'refund' AND (reverse_count > 0 OR refunded_amount + NEW.amount_minor > source_amount) THEN
            RAISE EXCEPTION 'refund exceeds remaining refundable amount or source was reversed' USING ERRCODE = '23514';
        END IF;
        IF NEW.operation_type = 'reverse' AND (NEW.amount_minor <> source_amount OR reverse_count > 0 OR refunded_amount > 0) THEN
            RAISE EXCEPTION 'reverse requires the full untouched source transaction' USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_financial_operation_link_semantics() RETURNS trigger AS $$
DECLARE
    source_type VARCHAR(32);
    result_type VARCHAR(32);
BEGIN
    SELECT type INTO source_type FROM ledger_transaction WHERE id = NEW.source_transaction_id;
    SELECT type INTO result_type FROM ledger_transaction WHERE id = NEW.result_transaction_id;

    IF result_type IS DISTINCT FROM NEW.operation_type THEN
        RAISE EXCEPTION 'financial operation result type must match operation type' USING ERRCODE = '23514';
    END IF;
    IF NEW.operation_type IN ('capture', 'release') AND source_type IS DISTINCT FROM 'reserve' THEN
        RAISE EXCEPTION 'capture and release must originate from a reserve transaction' USING ERRCODE = '23514';
    END IF;
    IF NEW.operation_type IN ('refund', 'reverse') AND source_type IN ('refund', 'reverse') THEN
        RAISE EXCEPTION 'refund and reverse cannot originate from an inverse transaction' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        $this->addSql('DROP INDEX idx_financial_operation_source_type');
        $this->addSql('DROP INDEX idx_financial_operation_reservation_type');
        $this->addSql("CREATE UNIQUE INDEX uniq_financial_operation_inverse_source ON financial_operation_link (source_transaction_id) WHERE operation_type IN ('refund', 'reverse')");
        $this->addSql("CREATE UNIQUE INDEX uniq_financial_operation_reservation_settlement ON financial_operation_link (reservation_id) WHERE reservation_id IS NOT NULL AND operation_type IN ('capture', 'release')");
        $this->addSql('ALTER TABLE financial_operation_link DROP CONSTRAINT chk_financial_operation_amount_positive');
        $this->addSql('ALTER TABLE financial_operation_link DROP COLUMN amount_minor');
    }
}
