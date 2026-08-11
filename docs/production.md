# Walleting production profile

## Supported topology

Walleting 1.0 is supported inside the SmartResponsor workspace topology where `Walleting` and `Objecting` are sibling repositories. Composer currently resolves `objecting/object` from `../Objecting` through a path repository. A standalone installation without that sibling repository is not a supported 1.0 deployment topology.

Runtime requirements:

- PHP 8.4 or newer within the supported 8.4 line.
- PostgreSQL 16.
- `pdo_pgsql` enabled.
- Composer 2.
- A durable Symfony Messenger transport for `MESSENGER_TRANSPORT_DSN`.

Required application environment variables:

- `APP_SECRET`
- `DATABASE_URL`
- `MESSENGER_TRANSPORT_DSN`

Do not commit production values for these variables to the Walleting repository.

## Fresh installation

From the supported sibling-repository workspace:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:up-to-date
APP_ENV=prod APP_DEBUG=0 php bin/console walleting:production:check
```

`composer test:integration` is the canonical clean-database installation proof in CI/development: it creates an ephemeral PostgreSQL 16 database and applies the full migration chain from zero before running the integration suite.

## Upgrade procedure

Walleting migrations are forward schema contracts. Never deploy with `doctrine:schema:update --force`, schema-force helpers, or manual table recreation.

Recommended order:

1. Back up the production database according to the host application's database policy.
2. Deploy code and vendor dependencies.
3. Stop/restart Walleting outbox dispatch loops and Messenger consumers around the migration window when required by the host release procedure.
4. Run `doctrine:migrations:migrate --no-interaction`.
5. Run `doctrine:migrations:up-to-date` and `walleting:production:check`.
6. Start/reload workers.
7. Run `walleting:balance:reconcile`, `walleting:outbox:health`, and `walleting:inbox:health`.

Posted ledger transactions and postings are immutable financial history and are never rebuilt as an upgrade mechanism.

## Async workers

Walleting has two distinct asynchronous stages.

### Transactional outbox dispatcher

Run bounded batches continuously under the host process supervisor, for example:

```bash
php bin/console walleting:outbox:dispatch --limit=100 --no-interaction
```

The command is intentionally bounded. The process supervisor should invoke it repeatedly rather than treating a single invocation as a permanent daemon.

### Messenger consumer

Run Symfony Messenger consumers under a process supervisor:

```bash
php bin/console messenger:consume outbox_events --time-limit=3600 --memory-limit=256M --no-interaction
```

Restart consumers after deployments so they load the current code and serializer contract. The `outbox_failed` transport is the configured Messenger failure transport.

## Scheduled maintenance

Exact scheduling is owned by the host environment. Recommended baseline:

- Every minute: `walleting:outbox:recover-claims --timeout=300 --limit=100`.
- Every minute: `walleting:posting:slo:state --scope=default`.
- Every 5 minutes: `walleting:outbox:health --max-age=300` and `walleting:inbox:health` for monitoring/alert ingestion.
- Hourly: `walleting:balance:reconcile`.
- Daily: `walleting:posting:metrics:cleanup --retention-days=30 --limit=5000` until no rows remain eligible.
- Daily: `walleting:inbox:cleanup --retention-days=90 --limit=500` until no rows remain eligible.

Dead outbox messages are not automatically deleted. They require inspection and an audited operator requeue when appropriate.

## Deployment smoke checks

After migration and worker restart:

```bash
php bin/console doctrine:migrations:up-to-date
php bin/console walleting:production:check
php bin/console walleting:balance:reconcile
php bin/console walleting:outbox:health --max-age=300
php bin/console walleting:inbox:health
```

A balance reconciliation mismatch, stale outbox backlog, dead letters, or stuck inbox processing must be treated as an operational failure requiring investigation.

## Rollback policy

Application code may be rolled back only when the target code is compatible with the already-applied database schema. Do not automatically execute Doctrine migration `down()` methods in production as part of application rollback. Financial history must not be deleted or rewritten to restore an older release.

When schema compatibility is uncertain, roll forward with a corrective migration instead.
