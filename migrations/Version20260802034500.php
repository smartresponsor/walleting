<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802034500 extends AbstractMigration
{
    public function getDescription(): string { return 'Harden provider event identity and financial operation links'; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql('ALTER TABLE provider_event ADD payload_hash VARCHAR(64) DEFAULT NULL');
        foreach ($this->connection->fetchAllAssociative('SELECT id, payload FROM provider_event') as $row) {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $payloadHash = hash('sha256', json_encode($this->normalize($payload), JSON_THROW_ON_ERROR));
            $this->connection->update('provider_event', ['payload_hash' => $payloadHash], ['id' => $row['id']]);
        }
        $this->addSql('ALTER TABLE provider_event ALTER payload_hash SET NOT NULL');
        $this->addSql('ALTER TABLE provider_event ADD funding_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE provider_event ADD withdrawal_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE provider_event ADD CONSTRAINT fk_provider_event_funding FOREIGN KEY (funding_id) REFERENCES funding (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE provider_event ADD CONSTRAINT fk_provider_event_withdrawal FOREIGN KEY (withdrawal_id) REFERENCES withdrawal (id) ON DELETE RESTRICT');
        $this->addSql("ALTER TABLE provider_event ADD CONSTRAINT chk_provider_event_target CHECK (NOT (funding_id IS NOT NULL AND withdrawal_id IS NOT NULL))");
        $this->addSql("CREATE FUNCTION walleting_reject_provider_event_identity_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.provider <> OLD.provider OR NEW.external_id <> OLD.external_id OR NEW.event_type <> OLD.event_type OR NEW.payload <> OLD.payload OR NEW.payload_hash <> OLD.payload_hash THEN RAISE EXCEPTION 'provider event identity is immutable'; END IF; RETURN NEW; END; $$");
        $this->addSql("CREATE TRIGGER provider_event_identity_immutable BEFORE UPDATE ON provider_event FOR EACH ROW EXECUTE FUNCTION walleting_reject_provider_event_identity_mutation()");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER provider_event_identity_immutable ON provider_event');
        $this->addSql('DROP FUNCTION walleting_reject_provider_event_identity_mutation()');
        $this->addSql('ALTER TABLE provider_event DROP COLUMN withdrawal_id, DROP COLUMN funding_id, DROP COLUMN payload_hash');
    }

    private function normalize(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->normalize($item);
            }
        }
        unset($item);

        return $value;
    }
}
