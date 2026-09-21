<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align Walleting Objecting field-pack columns with canonical unprefixed names';
    }

    public function up(Schema $schema): void
    {
        foreach (['wallet', 'account', 'payment_instrument'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN object_uuid TO uuid', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN object_slug TO slug', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN object_first_title TO first_title', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN object_middle_title TO middle_title', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN object_last_title TO last_title', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN object_created_at TO created_at', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN object_modified_at TO modified_at', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN object_created_by TO created_by', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN object_modified_by TO modified_by', $table));
            $this->addSql(sprintf('ALTER INDEX uniq_%s_object_uuid RENAME TO uniq_%s_uuid', $table, $table));
            $this->addSql(sprintf('ALTER INDEX uniq_%s_object_slug RENAME TO uniq_%s_slug', $table, $table));
        }

        $this->addSql('ALTER TABLE account RENAME COLUMN object_active TO active');
        $this->addSql('ALTER TABLE account RENAME COLUMN object_enabled TO enabled');
        $this->addSql('ALTER TABLE account RENAME COLUMN object_status TO status');

        foreach (['wallet', 'payment_instrument'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_active', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_enabled', $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_status', $table));
        }

        $this->addSql('ALTER TABLE financial_operation_link RENAME COLUMN object_created_at TO created_at');
        $this->addSql('ALTER TABLE financial_operation_link RENAME COLUMN object_modified_at TO modified_at');
        $this->addSql('ALTER TABLE financial_operation_link RENAME COLUMN object_created_by TO created_by');
        $this->addSql('ALTER TABLE financial_operation_link RENAME COLUMN object_modified_by TO modified_by');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_operation_link RENAME COLUMN created_at TO object_created_at');
        $this->addSql('ALTER TABLE financial_operation_link RENAME COLUMN modified_at TO object_modified_at');
        $this->addSql('ALTER TABLE financial_operation_link RENAME COLUMN created_by TO object_created_by');
        $this->addSql('ALTER TABLE financial_operation_link RENAME COLUMN modified_by TO object_modified_by');

        foreach (['wallet', 'payment_instrument'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_active BOOLEAN DEFAULT TRUE NOT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_enabled BOOLEAN DEFAULT TRUE NOT NULL', $table));
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_status VARCHAR(64) DEFAULT NULL', $table));
        }

        $this->addSql('ALTER TABLE account RENAME COLUMN active TO object_active');
        $this->addSql('ALTER TABLE account RENAME COLUMN enabled TO object_enabled');
        $this->addSql('ALTER TABLE account RENAME COLUMN status TO object_status');

        foreach (['wallet', 'account', 'payment_instrument'] as $table) {
            $this->addSql(sprintf('ALTER INDEX uniq_%s_uuid RENAME TO uniq_%s_object_uuid', $table, $table));
            $this->addSql(sprintf('ALTER INDEX uniq_%s_slug RENAME TO uniq_%s_object_slug', $table, $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN uuid TO object_uuid', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN slug TO object_slug', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN first_title TO object_first_title', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN middle_title TO object_middle_title', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN last_title TO object_last_title', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN created_at TO object_created_at', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN modified_at TO object_modified_at', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN created_by TO object_created_by', $table));
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN modified_by TO object_modified_by', $table));
        }
    }
}
