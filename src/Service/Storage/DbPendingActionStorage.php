<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\PendingAction;
use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;

class DbPendingActionStorage implements PendingActionStorageInterface
{
    private const TTL_MINUTES = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext $profileContext,
    ) {
    }

    private function getProfile(): ?TradingProfile
    {
        $profileId = $this->profileContext->getActiveProfileId();
        if ($profileId === null) {
            return null;
        }
        return $this->em->getRepository(TradingProfile::class)->find($profileId);
    }

    public function getAll(): array
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            return [];
        }

        $cutoff = new \DateTimeImmutable('-' . self::TTL_MINUTES . ' minutes');
        $actions = $this->em->getRepository(PendingAction::class)->findBy(
            ['tradingProfile' => $profile, 'status' => PendingAction::STATUS_PENDING],
            ['createdAt' => 'ASC']
        );

        $result = [];
        foreach ($actions as $a) {
            if ($a->getCreatedAt() < $cutoff) {
                $this->em->remove($a);
                continue;
            }
            $result[] = $this->toArray($a);
        }
        $this->em->flush();

        return $result;
    }

    public function add(array $action): string
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            throw new \RuntimeException('Cannot add pending action: no active profile in context.');
        }

        $id = uniqid('pa_', true);
        $entity = new PendingAction();
        $entity->setTradingProfile($profile);
        $entity->setExternalId($id);
        $entity->setSymbol($action['symbol'] ?? '');
        $entity->setAction($action['action'] ?? '');
        $entity->setPayload($action);
        $entity->setStatus(PendingAction::STATUS_PENDING);

        $this->em->persist($entity);
        $this->em->flush();

        return $id;
    }

    public function resolve(string $id, bool $confirm): ?array
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            return null;
        }

        $entity = $this->em->getRepository(PendingAction::class)->findOneBy([
            'tradingProfile' => $profile,
            'externalId' => $id,
        ]);

        if ($entity === null) {
            return null;
        }

        $result = $this->toArray($entity);
        $entity->setStatus($confirm ? PendingAction::STATUS_CONFIRMED : PendingAction::STATUS_REJECTED);
        $this->em->remove($entity);
        $this->em->flush();

        return $result;
    }

    public function hasPending(string $symbol, string $action): bool
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            return false;
        }

        $cutoff = new \DateTimeImmutable('-' . self::TTL_MINUTES . ' minutes');
        $found = $this->em->getRepository(PendingAction::class)->findOneBy([
            'tradingProfile' => $profile,
            'symbol' => $symbol,
            'action' => $action,
            'status' => PendingAction::STATUS_PENDING,
        ]);

        return $found !== null && $found->getCreatedAt() >= $cutoff;
    }

    private function toArray(PendingAction $a): array
    {
        $payload = $a->getPayload();
        return array_merge($payload, [
            'id'         => $a->getExternalId(),
            'created_at' => $a->getCreatedAt()->format('Y-m-d H:i:s'),
            'status'     => $a->getStatus(),
        ]);
    }
}
