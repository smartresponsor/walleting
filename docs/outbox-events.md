# Outbox event contract

Walleting publishes external events through Symfony Messenger using a versioned JSON envelope.

## Version policy

- Current schema version: `1`.
- Producers emit only the current schema version.
- Consumers must treat `schema_version` as authoritative.
- Walleting rejects unsupported schema versions during decode instead of attempting best-effort coercion.
- A breaking wire-format change requires a new schema version and explicit compatibility handling.

## Envelope fields

Required fields:

- `schema_version` integer
- `source` non-empty string
- `message_id` non-empty string
- `type` non-empty string
- `deduplication_key` non-empty string
- `payload` object or array

Nullable metadata fields:

- `occurred_at`
- `correlation_id`
- `causation_id`
- `ledger_transaction_id`
- `provider_event_external_id`

Transport headers include `Content-Type: application/json`, `X-Walleting-Event-Schema`, and `X-Walleting-Event-Type`.

The canonical v1 example is stored in `tests/Fixtures/outbox-event-v1.json` and is covered by serializer contract tests.
