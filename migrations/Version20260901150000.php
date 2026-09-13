<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provider operation identity to funding and withdrawal';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql('ALTER TABLE funding ADD provider_operation_reference VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE withdrawal ADD provider_operation_reference VARCHAR(191) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_funding_provider_operation_reference ON funding (provider_operation_reference)');
        $this->addSql('CREATE UNIQUE INDEX uniq_withdrawal_provider_operation_reference ON withdrawal (provider_operation_reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_funding_provider_operation_reference');
        $this->addSql('DROP INDEX uniq_withdrawal_provider_operation_reference');
        $this->addSql('ALTER TABLE funding DROP provider_operation_reference');
        $this->addSql('ALTER TABLE withdrawal DROP provider_operation_reference');
    }
}
