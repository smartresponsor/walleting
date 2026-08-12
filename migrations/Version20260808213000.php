<?php

declare(strict_types=1);

namespace App\Walleting\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix provider event immutable identity JSON comparison';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'Walleting requires PostgreSQL.');
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION walleting_reject_provider_event_identity_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.provider <> OLD.provider
        OR NEW.external_id <> OLD.external_id
        OR NEW.event_type <> OLD.event_type
        OR NEW.payload::jsonb <> OLD.payload::jsonb
        OR NEW.payload_hash <> OLD.payload_hash THEN
        RAISE EXCEPTION 'provider event identity and payload are immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION walleting_reject_provider_event_identity_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.provider <> OLD.provider OR NEW.external_id <> OLD.external_id OR NEW.event_type <> OLD.event_type OR NEW.payload <> OLD.payload OR NEW.payload_hash <> OLD.payload_hash THEN
        RAISE EXCEPTION 'provider event identity and payload are immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$
SQL);
    }
}
