<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801030345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reservation, funding, withdrawal, and tokenized payment instruments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE payment_instrument (id UUID NOT NULL, wallet_id UUID NOT NULL, type VARCHAR(255) NOT NULL, provider VARCHAR(64) NOT NULL, provider_reference VARCHAR(191) NOT NULL, display_label VARCHAR(128) NOT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql("CREATE UNIQUE INDEX uniq_payment_instrument_provider_reference ON payment_instrument (provider, provider_reference)");
        $this->addSql("CREATE INDEX idx_payment_instrument_wallet ON payment_instrument (wallet_id)");
        $this->addSql("ALTER TABLE payment_instrument ADD FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE RESTRICT");

        $this->addSql("CREATE TABLE reservation (id UUID NOT NULL, wallet_id UUID NOT NULL, account_id UUID NOT NULL, reserve_transaction_id UUID NOT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, status VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_reservation_amount_positive CHECK (amount_minor > 0))");
        $this->addSql("CREATE UNIQUE INDEX uniq_reservation_idempotency_key ON reservation (idempotency_key)");
        $this->addSql("ALTER TABLE reservation ADD FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE reservation ADD FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE reservation ADD FOREIGN KEY (reserve_transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT");

        $this->addSql("CREATE TABLE funding (id UUID NOT NULL, wallet_id UUID NOT NULL, payment_instrument_id UUID NOT NULL, transaction_id UUID DEFAULT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_funding_amount_positive CHECK (amount_minor > 0))");
        $this->addSql("CREATE UNIQUE INDEX uniq_funding_idempotency_key ON funding (idempotency_key)");
        $this->addSql("ALTER TABLE funding ADD FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE funding ADD FOREIGN KEY (payment_instrument_id) REFERENCES payment_instrument (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE funding ADD FOREIGN KEY (transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT");

        $this->addSql("CREATE TABLE withdrawal (id UUID NOT NULL, wallet_id UUID NOT NULL, payment_instrument_id UUID NOT NULL, transaction_id UUID DEFAULT NULL, amount_minor BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_withdrawal_amount_positive CHECK (amount_minor > 0))");
        $this->addSql("CREATE UNIQUE INDEX uniq_withdrawal_idempotency_key ON withdrawal (idempotency_key)");
        $this->addSql("ALTER TABLE withdrawal ADD FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE withdrawal ADD FOREIGN KEY (payment_instrument_id) REFERENCES payment_instrument (id) ON DELETE RESTRICT");
        $this->addSql("ALTER TABLE withdrawal ADD FOREIGN KEY (transaction_id) REFERENCES ledger_transaction (id) ON DELETE RESTRICT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE withdrawal');
        $this->addSql('DROP TABLE funding');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('DROP TABLE payment_instrument');
    }
}
