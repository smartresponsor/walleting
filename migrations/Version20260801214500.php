<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801214500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable financial operation links and uniqueness guarantees';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql("CREATE TABLE financial_operation_link (id UUID NOT NULL, operation_type VARCHAR(255) NOT NULL, source_transaction_id UUID NOT NULL, result_transaction_id UUID NOT NULL, reservation_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_financial_operation_type CHECK (operation_type IN ('capture', 'release', 'refund', 'reverse')), CONSTRAINT chk_financial_operation_reservation CHECK ((operation_type IN ('capture', 'release') AND reservation_id IS NOT NULL) OR (operation_type IN ('refund', 'reverse') AND reservation_id IS NULL)))");
        $this->addSql("CREATE UNIQUE INDEX uniq_financial_operation_source_type ON financial_operation_link (source_transaction_id, operation_type)");
        $this->addSql("CREATE UNIQUE INDEX uniq_financial_operation_result ON financial_operation_link (result_transaction_id)");
        $this->addSql("CREATE UNIQUE INDEX uniq_financial_operation_reservation_type ON financial_operation_link (reservation_id, operation_type) WHERE reservation_id IS NOT NULL");
        $this->addSql("ALTER TABLE financial_operation_link ADD CONSTRAINT fk_financial_operation_source FOREIGN KEY (source_transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE financial_operation_link ADD CONSTRAINT fk_financial_operation_result FOREIGN KEY (result_transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE financial_operation_link ADD CONSTRAINT fk_financial_operation_reservation FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE RESTRICT");
        $this->addSql("CREATE FUNCTION walleting_reject_financial_operation_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'financial operation links are immutable'; END; $$");
        $this->addSql("CREATE TRIGGER financial_operation_link_immutable BEFORE UPDATE OR DELETE ON financial_operation_link FOR EACH ROW EXECUTE FUNCTION walleting_reject_financial_operation_mutation()");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE financial_operation_link');
        $this->addSql('DROP FUNCTION walleting_reject_financial_operation_mutation()');
    }
}
