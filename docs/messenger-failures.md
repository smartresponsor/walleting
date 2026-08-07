# Messenger failure handling

Walleting uses two distinct retry layers.

The transactional outbox retries failures that occur while publishing an `OutboxEvent` into the configured Messenger transport. After the transport accepts the message, the outbox row is marked dispatched and its retry lifecycle is complete.

Messenger retry strategy applies only when a consumer receives an event and message handling fails. `outbox_events` uses three retries with delays of 1, 2, and 4 seconds, capped at 30 seconds. Exhausted messages are moved to the durable `outbox_failed` Doctrine transport.

Operational commands:

- `php bin/console messenger:failed:show --transport=outbox_failed`
- `php bin/console messenger:failed:retry --transport=outbox_failed`
- `php bin/console messenger:failed:remove --transport=outbox_failed`

Failed messages must be inspected before retry or removal. The external event `message_id` and `deduplication_key` remain the consumer-side idempotency anchors.
