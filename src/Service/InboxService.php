<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InboxReceipt;
use App\Message\OutboxEvent;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class InboxService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Executes the handler once for a unique external event identity.
     *
     * The receipt insert is flushed before the handler inside the same transaction, so the database unique constraint
     * guards the side effect against concurrent duplicate deliveries of the same source/message_id.
     *
     * @param callable(OutboxEvent): void $handler
     */
    public function processOnce(OutboxEvent $event, callable $handler): bool
    {
        try {
            return $this->entityManager->wrapInTransaction(function () use ($event, $handler): bool {
                $existing = $this->findReceipt($event);
                if ($existing instanceof InboxReceipt) {
                    $existing->assertSameEvent($event);

                    return false;
                }

                $receipt = new InboxReceipt($event);
                $this->entityManager->persist($receipt);
                $this->entityManager->flush();

                $handler($event);

                $receipt->markProcessed();
                $this->entityManager->flush();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $this->findReceipt($event);
            if (!$existing instanceof InboxReceipt) {
                throw new \RuntimeException('Duplicate inbox receipt conflict could not be resolved.');
            }
            $existing->assertSameEvent($event);

            return false;
        }
    }

    private function findReceipt(OutboxEvent $event): ?InboxReceipt
    {
        $receipt = $this->entityManager->getRepository(InboxReceipt::class)->findOneBy([
            'source' => $event->source,
            'messageId' => $event->messageId,
        ]);

        return $receipt instanceof InboxReceipt ? $receipt : null;
    }
}
