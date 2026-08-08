<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808015500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add monotonic posting SLO state revision for transition alert deduplication';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql('ALTER TABLE posting_slo_state ADD revision INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE posting_slo_state ADD CONSTRAINT chk_posting_slo_state_revision CHECK (revision >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE posting_slo_state DROP CONSTRAINT chk_posting_slo_state_revision');
        $this->addSql('ALTER TABLE posting_slo_state DROP revision');
    }
}
