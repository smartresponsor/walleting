# Walleting

Symfony 8 / PHP 8.4 walleting component built around an immutable double-entry PostgreSQL ledger.

## Product contract

Walleting owns wallet and account balances, immutable ledger transactions and postings, reservations, funding, withdrawals, payment instruments, provider events, reconciliation, transactional outbox delivery, inbox idempotency, posting health/SLO state, and provider settlement reconciliation.

The host application should use `App\Service\WalletingFacade` for read-facing access instead of querying Walleting tables directly. The facade exposes wallet balances, account history, statements, reservation progress, and funding/withdrawal status views.

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

See `docs/outbox-events.md` and `docs/messenger-failures.md` for the external event envelope and failure handling contract.

## Release gates

The release candidate is expected to pass all of these checks:

```bash
composer validate --no-interaction --strict --check-lock
composer lint
composer test
composer test:integration
```

`composer test:integration` starts an ephemeral PostgreSQL 16 container, applies the complete migration chain, and runs the PostgreSQL integration suite. Do not run multiple integration harnesses concurrently because they share the same Compose project.

## Integration boundary
