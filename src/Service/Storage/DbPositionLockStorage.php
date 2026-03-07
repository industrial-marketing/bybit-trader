<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\PositionLock;
use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;

class DbPositionLockStorage implements PositionLockStorageInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProfileContext $profileContext,
    ) {
    }

    private function getProfileId(): ?int
    {
        return $this->profileContext->getActiveProfileId();
    }

    private function getProfile(): ?TradingProfile
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            return null;
        }
        return $this->em->getRepository(TradingProfile::class)->find($profileId);
    }

    private function key(string $symbol, string $side): string
    {
        return strtoupper($symbol) . '|' . ucfirst(strtolower($side));
    }

    public function isLocked(string $symbol, string $side): bool
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            return false;
        }

        $lock = $this->em->getRepository(PositionLock::class)->findOneBy([
            'tradingProfile' => $profile,
            'symbol' => strtoupper($symbol),
            'side' => ucfirst(strtolower($side)),
        ]);

        return $lock !== null && $lock->isLocked();
    }

    public function setLock(string $symbol, string $side, bool $locked): void
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            throw new \RuntimeException('Cannot set position lock: no active profile in context.');
        }

        $symbolNorm = strtoupper($symbol);
        $sideNorm = ucfirst(strtolower($side));

        $lock = $this->em->getRepository(PositionLock::class)->findOneBy([
            'tradingProfile' => $profile,
            'symbol' => $symbolNorm,
            'side' => $sideNorm,
        ]);

        if ($locked) {
            if ($lock === null) {
                $lock = new PositionLock();
                $lock->setTradingProfile($profile);
                $lock->setSymbol($symbolNorm);
                $lock->setSide($sideNorm);
                $this->em->persist($lock);
            }
            $lock->setLocked(true);
            $lock->touch();
        } else {
            if ($lock !== null) {
                $this->em->remove($lock);
            }
        }

        $this->em->flush();
    }

    public function getLocks(): array
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            return [];
        }

        $locks = $this->em->getRepository(PositionLock::class)->findBy(
            ['tradingProfile' => $profile, 'locked' => true],
            ['symbol' => 'ASC', 'side' => 'ASC']
        );

        $result = [];
        foreach ($locks as $lock) {
            $k = $this->key($lock->getSymbol(), $lock->getSide());
            $result[$k] = true;
        }
        return $result;
    }
}
