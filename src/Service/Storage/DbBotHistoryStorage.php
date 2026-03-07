<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\BotHistoryEvent;
use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;

class DbBotHistoryStorage implements BotHistoryStorageInterface
{
    private const MAX_EVENTS = 1000;
    private const RETENTION_DAYS = 14;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext $profileContext,
    ) {
    }

    private function getProfileId(): ?int
    {
        return $this->profileContext->getActiveProfileId();
    }

    public function getDataFilePath(): string
    {
        return '';
    }

    public function log(string $type, array $payload): void
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            throw new \RuntimeException('Cannot log bot history: no active profile in context.');
        }

        $profile = $this->em->getReference(TradingProfile::class, $profileId);
        $eventId = uniqid($type . '_', true);

        $event = new BotHistoryEvent();
        $event->setTradingProfile($profile);
        $event->setType($type);
        $event->setEventId($eventId);
        $event->setPayload($payload);

        $this->em->persist($event);

        // Trim old events for this profile
        $cutoff = new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days');
        $old = $this->em->getRepository(BotHistoryEvent::class)->createQueryBuilder('e')
            ->where('e.tradingProfile = :profile')
            ->andWhere('e.createdAt < :cutoff')
            ->setParameter('profile', $profile)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $toRemove = count($old) > 0 ? array_slice($old, 0, -self::MAX_EVENTS + 1) : [];
        foreach ($toRemove as $e) {
            $this->em->remove($e);
        }

        $this->em->flush();
    }

    public function getRecentEvents(int $days = 7): array
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            return [];
        }

        $profile = $this->em->getReference(TradingProfile::class, $profileId);
        $since = new \DateTimeImmutable("-{$days} days");

        $entities = $this->em->getRepository(BotHistoryEvent::class)->createQueryBuilder('e')
            ->where('e.tradingProfile = :profile')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('profile', $profile)
            ->setParameter('since', $since)
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($entities as $e) {
            $result[] = array_merge($e->getPayload(), [
                'id'        => $e->getEventId(),
                'type'      => $e->getType(),
                'timestamp' => $e->getCreatedAt()->format('c'),
            ]);
        }
        return $result;
    }

    public function getLastEventOfType(string $type): ?array
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            return null;
        }

        $profile = $this->em->getReference(TradingProfile::class, $profileId);
        $e = $this->em->getRepository(BotHistoryEvent::class)->findOneBy(
            ['tradingProfile' => $profile, 'type' => $type],
            ['createdAt' => 'DESC']
        );

        if ($e === null) {
            return null;
        }

        return array_merge($e->getPayload(), [
            'id'        => $e->getEventId(),
            'type'      => $e->getType(),
            'timestamp' => $e->getCreatedAt()->format('c'),
        ]);
    }
}
