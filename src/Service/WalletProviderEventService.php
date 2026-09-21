<?php

declare(strict_types=1);

namespace App\Walleting\Service;

use App\Walleting\Entity\WalletFunding;
use App\Walleting\Entity\WalletProviderEventEntity;
use App\Walleting\Entity\WalletWithdrawal;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WalletProviderEventService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?WalletOutboxService $outboxService = null,
    ) {
    }

    public function receive(string $provider, string $externalId, string $eventType, array $payload): WalletProviderEventEntity
    {
        return $this->entityManager->wrapInTransaction(function () use ($provider, $externalId, $eventType, $payload): WalletProviderEventEntity {
            $existing = $this->entityManager->getRepository(WalletProviderEventEntity::class)->findOneBy(['provider' => trim($provider), 'externalId' => trim($externalId)]);
            if ($existing instanceof WalletProviderEventEntity) {
                $candidate = new WalletProviderEventEntity($provider, $externalId, $eventType, $payload);
                if ($existing->eventType() !== $candidate->eventType() || !hash_equals($existing->payloadHash(), $candidate->payloadHash())) {
                    throw new \DomainException('Provider event identity is already bound to different event content.');
                }

                return $existing;
            }

            $event = new WalletProviderEventEntity($provider, $externalId, $eventType, $payload);
            $this->entityManager->persist($event);
            $this->emit('provider.event.received', $event);
            $this->entityManager->flush();

            return $event;
        });
    }

    public function processFunding(WalletProviderEventEntity $event, WalletFunding $funding): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $funding): void {
            $event->processFunding($funding);
            $this->emit('provider.event.processed', $event);
            $this->entityManager->flush();
        });
    }

    public function processWithdrawal(WalletProviderEventEntity $event, WalletWithdrawal $withdrawal): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $withdrawal): void {
            $event->processWithdrawal($withdrawal);
            $this->emit('provider.event.processed', $event);
            $this->entityManager->flush();
        });
    }

    private function emit(string $messageType, WalletProviderEventEntity $event): void
    {
        $this->outboxService?->enqueueManaged(
            $messageType,
            $messageType.':'.$event->provider().':'.$event->externalId(),
            [
                'provider' => $event->provider(),
                'external_id' => $event->externalId(),
                'event_type' => $event->eventType(),
                'status' => $event->status()->value,
                'payload_hash' => $event->payloadHash(),
            ],
            providerEvent: $event,
        );
    }
}
