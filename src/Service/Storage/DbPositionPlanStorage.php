<?php

declare(strict_types=1);

namespace App\Service\Storage;

use App\Entity\PositionPlan;
use App\Entity\TradingProfile;
use App\Service\Settings\ProfileContext;
use Doctrine\ORM\EntityManagerInterface;

class DbPositionPlanStorage implements PositionPlanStorageInterface
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

    private function key(string $symbol, string $side): string
    {
        return strtoupper($symbol) . '|' . ucfirst(strtolower($side));
    }

    public function getAllPlans(): array
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            return [];
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($profileId);
        if ($profile === null) {
            return [];
        }

        $plans = $this->em->getRepository(PositionPlan::class)->findBy(
            ['tradingProfile' => $profile],
            ['symbol' => 'ASC', 'side' => 'ASC']
        );

        $result = [];
        foreach ($plans as $p) {
            $k = $this->key($p->getSymbol(), $p->getSide());
            $result[$k] = array_merge(
                ['symbol' => $p->getSymbol(), 'side' => $p->getSide()],
                $p->getPlanData()
            );
        }
        return $result;
    }

    public function getPlan(string $symbol, string $side): ?array
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            return null;
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($profileId);
        if ($profile === null) {
            return null;
        }

        $plan = $this->em->getRepository(PositionPlan::class)->findOneBy([
            'tradingProfile' => $profile,
            'symbol' => strtoupper($symbol),
            'side' => ucfirst(strtolower($side)),
        ]);

        if ($plan === null) {
            return null;
        }

        return array_merge(
            ['symbol' => $plan->getSymbol(), 'side' => $plan->getSide()],
            $plan->getPlanData()
        );
    }

    public function savePlan(array $plan): void
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            throw new \RuntimeException('Cannot save position plan: no active profile in context.');
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($profileId);
        if ($profile === null) {
            throw new \RuntimeException('Trading profile not found: ' . $profileId);
        }

        $symbolNorm = strtoupper($plan['symbol'] ?? '');
        $sideNorm = ucfirst(strtolower($plan['side'] ?? ''));

        $entity = $this->em->getRepository(PositionPlan::class)->findOneBy([
            'tradingProfile' => $profile,
            'symbol' => $symbolNorm,
            'side' => $sideNorm,
        ]);

        if ($entity === null) {
            $entity = new PositionPlan();
            $entity->setTradingProfile($profile);
            $entity->setSymbol($symbolNorm);
            $entity->setSide($sideNorm);
            $this->em->persist($entity);
        }

        $planData = $plan;
        unset($planData['symbol'], $planData['side']);
        $entity->setPlanData($planData);
        $entity->touch();
        $this->em->flush();
    }

    public function removePlan(string $symbol, string $side): void
    {
        $profileId = $this->getProfileId();
        if ($profileId === null) {
            return;
        }

        $profile = $this->em->getRepository(TradingProfile::class)->find($profileId);
        if ($profile === null) {
            return;
        }

        $plan = $this->em->getRepository(PositionPlan::class)->findOneBy([
            'tradingProfile' => $profile,
            'symbol' => strtoupper($symbol),
            'side' => ucfirst(strtolower($side)),
        ]);

        if ($plan !== null) {
            $this->em->remove($plan);
            $this->em->flush();
        }
    }
}
