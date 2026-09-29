<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929094320 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore deterministic Objecting identity unique-constraint names.';
    }

    public function up(Schema $schema): void
    {
        $this->renameIndex('uniq_7c68921fd17f50a6', 'uniq_wallet_uuid');
        $this->renameIndex('uniq_7c68921f989d9b62', 'uniq_wallet_slug');
        $this->renameIndex('uniq_7d3656a4d17f50a6', 'uniq_account_uuid');
        $this->renameIndex('uniq_7d3656a4989d9b62', 'uniq_account_slug');
        $this->renameIndex('uniq_949e808d17f50a6', 'uniq_payment_instrument_uuid');
        $this->renameIndex('uniq_949e808989d9b62', 'uniq_payment_instrument_slug');
    }

    public function down(Schema $schema): void
    {
        $this->renameIndex('uniq_wallet_uuid', 'uniq_7c68921fd17f50a6');
        $this->renameIndex('uniq_wallet_slug', 'uniq_7c68921f989d9b62');
        $this->renameIndex('uniq_account_uuid', 'uniq_7d3656a4d17f50a6');
        $this->renameIndex('uniq_account_slug', 'uniq_7d3656a4989d9b62');
        $this->renameIndex('uniq_payment_instrument_uuid', 'uniq_949e808d17f50a6');
        $this->renameIndex('uniq_payment_instrument_slug', 'uniq_949e808989d9b62');
    }

    private function renameIndex(string $from, string $to): void
    {
        $this->addSql(sprintf(
            'ALTER INDEX %s RENAME TO %s',
            $from,
            $to,
        ));
    }
}
