<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808023000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adopt executable Objecting kinds for wallet, account, payment instrument, and financial operation links';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Walleting requires PostgreSQL.');

        $this->addObjectColumns('wallet');
        $this->addSql("UPDATE wallet SET object_uuid = decode(replace(id::text, '-', ''), 'hex'), object_slug = 'wallet:' || id::text, object_first_title = owner_type || ':' || owner_id, object_created_at = created_at, object_active = CASE WHEN status = 'closed' THEN FALSE ELSE TRUE END, object_enabled = CASE WHEN status = 'closed' THEN FALSE ELSE TRUE END, object_status = status");
        $this->finalizeObjectColumns('wallet');
        $this->addSql('ALTER TABLE wallet DROP COLUMN created_at');

        $this->addObjectColumns('account');
        $this->addSql("UPDATE account SET object_uuid = decode(replace(id::text, '-', ''), 'hex'), object_slug = 'account:' || id::text, object_first_title = code, object_created_at = created_at, object_active = TRUE, object_enabled = TRUE, object_status = 'active'");
        $this->finalizeObjectColumns('account');
        $this->addSql('ALTER TABLE account DROP COLUMN created_at');

        $this->addObjectColumns('payment_instrument');
        $this->addSql("UPDATE payment_instrument SET object_uuid = decode(replace(id::text, '-', ''), 'hex'), object_slug = 'payment-instrument:' || id::text, object_first_title = display_label, object_created_at = created_at, object_active = CASE WHEN status = 'active' THEN TRUE ELSE FALSE END, object_enabled = CASE WHEN status = 'active' THEN TRUE ELSE FALSE END, object_status = status");
        $this->finalizeObjectColumns('payment_instrument');
        $this->addSql('ALTER TABLE payment_instrument DROP COLUMN created_at');

        $this->addAuditColumns('financial_operation_link');
        $this->addSql('UPDATE financial_operation_link SET object_created_at = created_at');
        $this->addSql('ALTER TABLE financial_operation_link ALTER COLUMN object_created_at SET NOT NULL');
        $this->addSql('ALTER TABLE financial_operation_link DROP COLUMN created_at');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Walleting requires PostgreSQL.');

        $this->addSql('ALTER TABLE financial_operation_link ADD COLUMN created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE financial_operation_link SET created_at = object_created_at');
        $this->addSql('ALTER TABLE financial_operation_link ALTER COLUMN created_at SET NOT NULL');
        $this->dropAuditColumns('financial_operation_link');

        foreach (['payment_instrument', 'account', 'wallet'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL', $table));
            $this->addSql(sprintf('UPDATE %s SET created_at = object_created_at', $table));
            $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN created_at SET NOT NULL', $table));
            $this->dropObjectColumns($table);
        }
    }

    private function addObjectColumns(string $table): void
    {
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_uuid BYTEA DEFAULT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_slug VARCHAR(190) DEFAULT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_first_title VARCHAR(255) DEFAULT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_middle_title TEXT DEFAULT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_last_title TEXT DEFAULT NULL', $table));
        $this->addAuditColumns($table);
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_active BOOLEAN DEFAULT TRUE NOT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_enabled BOOLEAN DEFAULT TRUE NOT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_status VARCHAR(64) DEFAULT NULL', $table));
    }

    private function finalizeObjectColumns(string $table): void
    {
        $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN object_uuid SET NOT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN object_slug SET NOT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ALTER COLUMN object_created_at SET NOT NULL', $table));
        $this->addSql(sprintf('CREATE UNIQUE INDEX uniq_%s_object_uuid ON %s (object_uuid)', $table, $table));
        $this->addSql(sprintf('CREATE UNIQUE INDEX uniq_%s_object_slug ON %s (object_slug)', $table, $table));
    }

    private function addAuditColumns(string $table): void
    {
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_modified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_created_by VARCHAR(190) DEFAULT NULL', $table));
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN object_modified_by VARCHAR(190) DEFAULT NULL', $table));
    }

    private function dropAuditColumns(string $table): void
    {
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_created_at', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_modified_at', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_created_by', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_modified_by', $table));
    }

    private function dropObjectColumns(string $table): void
    {
        $this->addSql(sprintf('DROP INDEX uniq_%s_object_uuid', $table));
        $this->addSql(sprintf('DROP INDEX uniq_%s_object_slug', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_uuid', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_slug', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_first_title', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_middle_title', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_last_title', $table));
        $this->dropAuditColumns($table);
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_active', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_enabled', $table));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN object_status', $table));
    }
}
