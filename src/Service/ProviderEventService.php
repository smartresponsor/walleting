<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Funding;
use App\Entity\ProviderEvent;
use App\Entity\Withdrawal;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProviderEventService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function receive(string $provider, string $externalId, string $eventType, array $payload): ProviderEvent
    {
        $existing = $this->entityManager->getRepository(ProviderEvent::class)->findOneBy(['provider' => trim($provider), 'externalId' => trim($externalId)]);
        if ($existing instanceof ProviderEvent) {
            $candidate = new ProviderEvent($provider, $externalId, $eventType, $payload);
            if (!hash_equals($existing->payloadHash(), $candidate->payloadHash())) {
                throw new \DomainException('Provider event identity is already bound to a different payload.');
            }

            return $existing;
        }

        $event = new ProviderEvent($provider, $externalId, $eventType, $payload);
        $this->entityManager->persist($event);

        return $event;
    }

    public function processFunding(ProviderEvent $event, Funding $funding): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $funding): void { $event->processFunding($funding); $this->entityManager->flush(); });
    }

    public function processWithdrawal(ProviderEvent $event, Withdrawal $withdrawal): void
    {
        $this->entityManager->wrapInTransaction(function () use ($event, $withdrawal): void { $event->processWithdrawal($withdrawal); $this->entityManager->flush(); });
    }
}
