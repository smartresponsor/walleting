<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce semantic transaction-type rules for financial operation links';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');

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
        $this->addSql('CREATE TRIGGER trg_financial_operation_link_semantics BEFORE INSERT OR UPDATE ON financial_operation_link FOR EACH ROW EXECUTE FUNCTION validate_financial_operation_link_semantics()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_financial_operation_link_semantics ON financial_operation_link');
        $this->addSql('DROP FUNCTION validate_financial_operation_link_semantics()');
    }
}
