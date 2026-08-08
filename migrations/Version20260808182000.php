<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808182000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce mutually exclusive reservation settlement and inverse financial operations';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');

        $this->addSql('DROP INDEX uniq_financial_operation_source_type');
        $this->addSql('DROP INDEX uniq_financial_operation_reservation_type');
        $this->addSql("CREATE UNIQUE INDEX uniq_financial_operation_inverse_source ON financial_operation_link (source_transaction_id) WHERE operation_type IN ('refund', 'reverse')");
        $this->addSql("CREATE UNIQUE INDEX uniq_financial_operation_reservation_settlement ON financial_operation_link (reservation_id) WHERE reservation_id IS NOT NULL AND operation_type IN ('capture', 'release')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_financial_operation_inverse_source');
        $this->addSql('DROP INDEX uniq_financial_operation_reservation_settlement');
        $this->addSql('CREATE UNIQUE INDEX uniq_financial_operation_source_type ON financial_operation_link (source_transaction_id, operation_type)');
        $this->addSql('CREATE UNIQUE INDEX uniq_financial_operation_reservation_type ON financial_operation_link (reservation_id, operation_type) WHERE reservation_id IS NOT NULL');
    }
}
