<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923102500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move Walleting outbox persistence to wallet-prefixed physical table and FK-column names.';
    }

    public function up(Schema $schema): void
    {
        $this->renameTable('outbox_message', 'wallet_outbox_message');
        $this->renameTable('outbox_requeue_audit', 'wallet_outbox_requeue_audit');
        $this->renameColumn('wallet_outbox_requeue_audit', 'outbox_message_id', 'wallet_outbox_message_id');
    }

    public function down(Schema $schema): void
    {
        $this->renameColumn('wallet_outbox_requeue_audit', 'wallet_outbox_message_id', 'outbox_message_id');
        $this->renameTable('wallet_outbox_requeue_audit', 'outbox_requeue_audit');
        $this->renameTable('wallet_outbox_message', 'outbox_message');
    }

    private function renameTable(string $legacy, string $canonical): void
    {
        $this->addSql(sprintf(
            <<<'SQL'
DO $$
BEGIN
    IF to_regclass('public.%1$s') IS NOT NULL AND to_regclass('public.%2$s') IS NULL THEN
        EXECUTE 'ALTER TABLE %1$s RENAME TO %2$s';
    ELSIF to_regclass('public.%1$s') IS NOT NULL AND to_regclass('public.%2$s') IS NOT NULL THEN
        RAISE EXCEPTION 'Both legacy table %1$s and canonical table %2$s exist; manual reconciliation is required.';
    END IF;
END
$$
SQL,
            $legacy,
            $canonical,
        ));
    }

    private function renameColumn(string $table, string $legacy, string $canonical): void
    {
        $this->addSql(sprintf(
            <<<'SQL'
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = '%1$s' AND column_name = '%2$s'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = '%1$s' AND column_name = '%3$s'
    ) THEN
        EXECUTE 'ALTER TABLE %1$s RENAME COLUMN %2$s TO %3$s';
    ELSIF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = '%1$s' AND column_name = '%2$s'
    ) AND EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = '%1$s' AND column_name = '%3$s'
    ) THEN
        RAISE EXCEPTION 'Both legacy column %1$s.%2$s and canonical column %1$s.%3$s exist; manual reconciliation is required.';
    END IF;
END
$$
SQL,
            $table,
            $legacy,
            $canonical,
        ));
    }
}
