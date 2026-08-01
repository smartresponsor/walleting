<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentInstrumentStatus;
use App\Enum\PaymentInstrumentType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'payment_instrument')]
#[ORM\UniqueConstraint(name: 'uniq_payment_instrument_provider_reference', columns: ['provider', 'provider_reference'])]
class PaymentInstrument
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Wallet::class)]
    #[ORM\JoinColumn(name: 'wallet_id', nullable: false, onDelete: 'RESTRICT')]
    private Wallet $wallet;

    #[ORM\Column(enumType: PaymentInstrumentType::class)]
    private PaymentInstrumentType $type;

    #[ORM\Column(length: 64)]
    private string $provider;

    #[ORM\Column(name: 'provider_reference', length: 191)]
    private string $providerReference;

    #[ORM\Column(name: 'display_label', length: 128)]
    private string $displayLabel;

    #[ORM\Column(enumType: PaymentInstrumentStatus::class)]
    private PaymentInstrumentStatus $status;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Wallet $wallet, PaymentInstrumentType $type, string $provider, string $providerReference, string $displayLabel, ?Uuid $id = null)
    {
        $provider = trim($provider);
        $providerReference = trim($providerReference);
        $displayLabel = trim($displayLabel);

        if ('' === $provider || '' === $providerReference || '' === $displayLabel) {
            throw new \InvalidArgumentException('Payment instrument provider, reference, and label are required.');
        }

        $this->id = $id ?? Uuid::v7();
        $this->wallet = $wallet;
        $this->type = $type;
        $this->provider = $provider;
        $this->providerReference = $providerReference;
        $this->displayLabel = $displayLabel;
        $this->status = PaymentInstrumentStatus::Active;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function disable(): void { $this->status = PaymentInstrumentStatus::Disabled; }
    public function expire(): void { $this->status = PaymentInstrumentStatus::Expired; }
    public function id(): Uuid { return $this->id; }
    public function wallet(): Wallet { return $this->wallet; }
    public function type(): PaymentInstrumentType { return $this->type; }
    public function provider(): string { return $this->provider; }
    public function providerReference(): string { return $this->providerReference; }
    public function displayLabel(): string { return $this->displayLabel; }
    public function status(): PaymentInstrumentStatus { return $this->status; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
}
