# Walleting

Symfony 8 / PHP 8.4 walleting component built around an immutable double-entry PostgreSQL ledger.

## Product contract

Walleting owns wallet and account balances, immutable ledger transactions and postings, reservations, funding, withdrawals, payment instruments, provider events, reconciliation, transactional outbox delivery, inbox idempotency, posting health/SLO state, and provider settlement reconciliation.

The host application should use `App\Walleting\Service\WalletingFacade` for read-facing access instead of querying Walleting tables directly. The facade exposes wallet balances, account history, statements, reservation progress, and funding/withdrawal status views.

Financial writes are expressed in integer minor units only. Posted ledger history is immutable. Composite business workflows use `FinancialOperationService`; provider-neutral funding/withdrawal orchestration uses `FundingWithdrawalOrchestrator`.

## Core guarantees

- PostgreSQL-backed immutable double-entry ledger.
- Deferred balanced-transaction constraints and currency/account invariants.
- Atomic account balance projection with non-negative enforcement for asset/reserve accounts.
- Idempotent posting requests and provider events.
- Partial reservation capture/release and partial refunds with cumulative amount enforcement.
- Fee-bearing gross/net settlement as first-class ledger legs.
- Transactional outbox and idempotent inbox processing.
- Provider-neutral funding/withdrawal processing and settlement reconciliation.
- Durable dead-letter inspection, audited requeue, selective dispatch, and health commands.

## Database

PostgreSQL is required. Doctrine entities and migrations are the schema authority.

Apply migrations before starting workers or serving traffic:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

Never use schema-force/update as a production deployment mechanism.

## Messenger and outbox operations

Important commands include:

```bash
php bin/console walleting:outbox:health
php bin/console walleting:outbox:inspect --id=<uuid>
php bin/console walleting:outbox:requeue --id=<uuid> --operator=<name> --reason=<reason>
php bin/console walleting:outbox:dispatch-one --id=<uuid>
php bin/console walleting:balance:reconcile
php bin/console walleting:posting:health
```

See `docs/outbox-events.md` and `docs/messenger-failures.md` for the external event envelope and failure handling contract. See `docs/production.md` for the supported production topology, fresh-install procedure, workers, maintenance schedule, upgrade path, smoke checks, and rollback policy.

## Release gates

The release candidate is expected to pass all of these checks:

```bash
composer validate --no-interaction --strict --check-lock
composer quality
composer test:integration
```

`composer quality` is the aggregate non-destructive repository quality gate. It runs PHP-CS-Fixer in check mode, PHPStan, Symfony/Doctrine linting, and the PHPUnit suite. `composer test:integration` starts an ephemeral PostgreSQL 16 container, applies the complete migration chain, and runs the PostgreSQL integration suite. Do not run multiple integration harnesses concurrently because they share the same Compose project.

## Integration boundary

- Host applications should use `App\Walleting\Service\WalletingFacade` for read-facing wallet/account balance, history, statement, reservation, funding, and withdrawal views rather than querying Walleting tables directly.
- Financial writes must enter through Walleting-owned application services so double-entry, idempotency, reservation, fee, outbox, and reconciliation invariants remain enforceable.
- Generic CRUD controllers/routes are not owned by Walleting. Back-office CRUD composition belongs to Cruding/the host integration layer rather than the ledger core.
- Shared shell, page rendering, and presentation concerns remain outside Walleting; Walleting exposes financial state and operations, not application-wide UI ownership.
- Posted ledger transactions and postings are immutable integration facts. Consumers must correct financial state through explicit compensating/reversal workflows instead of rewriting history.
