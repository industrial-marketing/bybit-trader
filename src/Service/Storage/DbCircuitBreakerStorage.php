<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\CircuitBreakerState;
use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;

class DbCircuitBreakerStorage implements CircuitBreakerStorageInterface
{
    private const ALL_TYPES = [
        CircuitBreakerState::TYPE_BYBIT,
        CircuitBreakerState::TYPE_LLM,
        CircuitBreakerState::TYPE_LLM_INVALID,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext $profileContext,
    ) {
    }

    private function getProfileId(): ?int
    {
        return $this->profileContext->getActiveProfileId();
    }

    public function getState(): array
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            return [];
        }

        $profile = $this->em->getReference(TradingProfile::class, $profileId);
        $entities = $this->em->getRepository(CircuitBreakerState::class)->findBy(
            ['tradingProfile' => $profile],
            ['breakerType' => 'ASC']
        );

        $state = [];
        foreach ($entities as $e) {
            $state[$e->getBreakerType()] = [
                'consecutive'     => $e->getConsecutive(),
                'tripped_at'      => $e->getTrippedAt(),
                'cooldown_until'  => $e->getCooldownUntil(),
                'reason'         => $e->getReason() ?? '',
                'last_failure_at' => $e->getLastFailureAt(),
            ];
        }
        return $state;
    }

    public function updateState(callable $callback): array
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            throw new \RuntimeException('Cannot update circuit breaker: no active profile in context.');
        }

        $profile = $this->em->getReference(TradingProfile::class, $profileId);
        $current = $this->getState();
        $newState = $callback($current);

        foreach (self::ALL_TYPES as $type) {
            $entry = $newState[$type] ?? null;
            if ($entry === null) {
                continue;
            }

            $entity = $this->em->getRepository(CircuitBreakerState::class)->findOneBy([
                'tradingProfile' => $profile,
                'breakerType'   => $type,
            ]);

            if ($entity === null) {
                $entity = new CircuitBreakerState();
                $entity->setTradingProfile($profile);
                $entity->setBreakerType($type);
                $this->em->persist($entity);
            }

            $entity->setConsecutive((int) ($entry['consecutive'] ?? 0));
            $entity->setTrippedAt(isset($entry['tripped_at']) ? (int) $entry['tripped_at'] : null);
            $entity->setCooldownUntil(isset($entry['cooldown_until']) ? (int) $entry['cooldown_until'] : null);
            $entity->setReason($entry['reason'] ?? null);
            $entity->setLastFailureAt(isset($entry['last_failure_at']) ? (int) $entry['last_failure_at'] : null);
            $entity->touch();
        }

        $this->em->flush();
        return $newState;
    }
}
