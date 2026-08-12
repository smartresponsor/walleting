<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce per-account refund and reversal leg limits for multi-leg transactions';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
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
    invalid_leg_count BIGINT;
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

        IF NEW.operation_type = 'refund' THEN
            WITH source_legs AS (
                SELECT account_id, SUM(amount_minor) AS source_minor FROM posting WHERE transaction_id = NEW.source_transaction_id GROUP BY account_id
            ), current_legs AS (
                SELECT account_id, SUM(amount_minor) AS refund_minor FROM posting WHERE transaction_id = NEW.result_transaction_id GROUP BY account_id
            )
            SELECT COUNT(*) INTO invalid_leg_count FROM current_legs r LEFT JOIN source_legs s USING (account_id)
            WHERE s.account_id IS NULL OR s.source_minor = 0 OR r.refund_minor = 0 OR (s.source_minor > 0 AND (r.refund_minor > 0 OR -r.refund_minor > s.source_minor)) OR (s.source_minor < 0 AND (r.refund_minor < 0 OR r.refund_minor > -s.source_minor));
            IF invalid_leg_count > 0 THEN
                RAISE EXCEPTION 'refund must invert only original transaction account legs' USING ERRCODE = '23514';
            END IF;

            WITH source_legs AS (
                SELECT account_id, SUM(amount_minor) AS source_minor FROM posting WHERE transaction_id = NEW.source_transaction_id GROUP BY account_id
            ), prior_refunds AS (
                SELECT p.account_id, SUM(p.amount_minor) AS refund_minor
                FROM financial_operation_link l JOIN posting p ON p.transaction_id = l.result_transaction_id
                WHERE l.source_transaction_id = NEW.source_transaction_id AND l.operation_type = 'refund' AND l.id <> NEW.id
                GROUP BY p.account_id
            ), current_refund AS (
                SELECT account_id, SUM(amount_minor) AS refund_minor FROM posting WHERE transaction_id = NEW.result_transaction_id GROUP BY account_id
            ), cumulative AS (
                SELECT COALESCE(p.account_id, c.account_id) AS account_id, COALESCE(p.refund_minor, 0) + COALESCE(c.refund_minor, 0) AS refund_minor
                FROM prior_refunds p FULL JOIN current_refund c USING (account_id)
            )
            SELECT COUNT(*) INTO invalid_leg_count FROM cumulative r LEFT JOIN source_legs s USING (account_id)
            WHERE s.account_id IS NULL OR s.source_minor = 0 OR (s.source_minor > 0 AND (r.refund_minor > 0 OR -r.refund_minor > s.source_minor)) OR (s.source_minor < 0 AND (r.refund_minor < 0 OR r.refund_minor > -s.source_minor));
            IF invalid_leg_count > 0 THEN
                RAISE EXCEPTION 'cumulative refund exceeds original transaction account leg' USING ERRCODE = '23514';
            END IF;
        END IF;

        IF NEW.operation_type = 'reverse' THEN
            WITH source_legs AS (
                SELECT account_id, SUM(amount_minor) AS source_minor FROM posting WHERE transaction_id = NEW.source_transaction_id GROUP BY account_id
            ), result_legs AS (
                SELECT account_id, SUM(amount_minor) AS result_minor FROM posting WHERE transaction_id = NEW.result_transaction_id GROUP BY account_id
            )
            SELECT COUNT(*) INTO invalid_leg_count FROM source_legs s FULL JOIN result_legs r USING (account_id)
            WHERE s.account_id IS NULL OR r.account_id IS NULL OR r.result_minor IS DISTINCT FROM -s.source_minor;
            IF invalid_leg_count > 0 THEN
                RAISE EXCEPTION 'reverse must exactly invert every original transaction account leg' USING ERRCODE = '23514';
            END IF;
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
    IF result_type IS DISTINCT FROM NEW.operation_type THEN RAISE EXCEPTION 'financial operation result type must match operation type' USING ERRCODE = '23514'; END IF;
    IF NEW.operation_type IN ('capture', 'release') THEN
        IF source_type IS DISTINCT FROM 'reserve' THEN RAISE EXCEPTION 'capture and release must originate from a reserve transaction' USING ERRCODE = '23514'; END IF;
        IF result_amount IS DISTINCT FROM NEW.amount_minor THEN RAISE EXCEPTION 'financial operation amount must match result transaction amount' USING ERRCODE = '23514'; END IF;
        SELECT amount_minor, reserve_transaction_id INTO reservation_amount, reservation_source FROM reservation WHERE id = NEW.reservation_id FOR UPDATE;
        IF reservation_source IS DISTINCT FROM NEW.source_transaction_id THEN RAISE EXCEPTION 'reservation source transaction does not match' USING ERRCODE = '23514'; END IF;
        SELECT COALESCE(SUM(amount_minor), 0) INTO settled_amount FROM financial_operation_link WHERE reservation_id = NEW.reservation_id AND operation_type IN ('capture', 'release') AND id <> NEW.id;
        IF settled_amount + NEW.amount_minor > reservation_amount THEN RAISE EXCEPTION 'reservation settlement exceeds reserved amount' USING ERRCODE = '23514'; END IF;
    END IF;
    IF NEW.operation_type IN ('refund', 'reverse') THEN
        IF source_type IN ('refund', 'reverse') THEN RAISE EXCEPTION 'refund and reverse cannot originate from an inverse transaction' USING ERRCODE = '23514'; END IF;
        IF result_amount IS DISTINCT FROM NEW.amount_minor THEN RAISE EXCEPTION 'financial operation amount must match result transaction amount' USING ERRCODE = '23514'; END IF;
        SELECT COALESCE(SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END), 0) INTO source_amount FROM posting WHERE transaction_id = NEW.source_transaction_id;
        SELECT COALESCE(SUM(amount_minor), 0) INTO refunded_amount FROM financial_operation_link WHERE source_transaction_id = NEW.source_transaction_id AND operation_type = 'refund' AND id <> NEW.id;
        SELECT COUNT(*) INTO reverse_count FROM financial_operation_link WHERE source_transaction_id = NEW.source_transaction_id AND operation_type = 'reverse' AND id <> NEW.id;
        IF NEW.operation_type = 'refund' AND (reverse_count > 0 OR refunded_amount + NEW.amount_minor > source_amount) THEN RAISE EXCEPTION 'refund exceeds remaining refundable amount or source was reversed' USING ERRCODE = '23514'; END IF;
        IF NEW.operation_type = 'reverse' AND (NEW.amount_minor <> source_amount OR reverse_count > 0 OR refunded_amount > 0) THEN RAISE EXCEPTION 'reverse requires the full untouched source transaction' USING ERRCODE = '23514'; END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
    }
}
