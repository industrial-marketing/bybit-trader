<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Storage\PositionPlanStorageInterface;

/**
 * Rotational Grid Mode — управление позицией через фиксированное число слоёв на сетке уровней.
 *
 * Принципы:
 *   - Уровни существуют постоянно (L0, L1, L2…)
 *   - Слои открываются на уровнях при движении против позиции
 *   - При возврате цены вверх нижние слои разгружаются
 *   - Освобождённые слои могут быть повторно открыты ниже
 *
 * Storage: file (var/position_plans.json) or MySQL (position_plan) depending on ProfileContext.
 */
class RotationalGridService
{
    public function __construct(
        private readonly PositionPlanStorageInterface $storage,
    ) {
    }

    private function key(string $symbol, string $side): string
    {
        return strtoupper($symbol) . '|' . ucfirst(strtolower($side));
    }

    /** @return array<string, array> */
    public function getAllPlans(): array
    {
        return $this->storage->getAllPlans();
    }

    public function getPlan(string $symbol, string $side): ?array
    {
        return $this->storage->getPlan($symbol, $side);
    }

    /**
     * Создаёт план при открытии базового слоя.
     *
     * @param float $anchorPrice Цена входа базового слоя
     */
    public function createPlan(
        string $symbol,
        string $side,
        float  $anchorPrice,
        int    $maxLayers,
        float  $layerSizeUsdt,
        float  $gridStepPct
    ): array {
        $levels = $this->computeLevels($anchorPrice, $gridStepPct, $side, 20);
        $plan   = [
            'symbol'          => $symbol,
            'side'            => $side,
            'mode'            => 'rotational_grid',
            'max_layers'      => $maxLayers,
            'layer_size_usdt' => $layerSizeUsdt,
            'grid_step_pct'   => $gridStepPct,
            'anchor_price'    => $anchorPrice,
            'levels'          => $levels,
            'active_layers'   => [
                [
                    'layer_id'    => 'A',
                    'entry_level' => $levels[0],
                    'entry_price' => $anchorPrice,
                    'status'      => 'open',
                    'created_at'  => time(),
                ],
            ],
            'created_at' => time(),
        ];

        $this->savePlan($plan);
        return $plan;
    }

    /**
     * Генерирует уровни сетки: для long — сверху вниз (10, 9, 8…), для short — снизу вверх.
     */
    public function computeLevels(float $anchorPrice, float $gridStepPct, string $side, int $count = 20): array
    {
        $step   = $anchorPrice * ($gridStepPct / 100);
        $levels = [$anchorPrice];
        $isLong = strtoupper($side) === 'BUY';

        for ($i = 1; $i < $count; $i++) {
            if ($isLong) {
                $levels[] = round($anchorPrice - $i * $step, 8);
            } else {
                $levels[] = round($anchorPrice + $i * $step, 8);
            }
        }
        return $levels;
    }

    /**
     * Добавляет слой на указанном уровне (при доборе).
     */
    public function addLayer(array $plan, float $level, float $entryPrice): array
    {
        $active = $plan['active_layers'] ?? [];
        $ids    = array_column($active, 'layer_id');
        $nextId = $this->nextLayerId($ids);

        $active[] = [
            'layer_id'    => $nextId,
            'entry_level' => $level,
            'entry_price' => $entryPrice,
            'status'      => 'open',
            'created_at'  => time(),
        ];

        $plan['active_layers'] = $active;
        $this->storage->savePlan($plan);
        return $plan;
    }

    /**
     * Удаляет самый нижний слой (при разгрузке). Для long — с наименьшим entry_level.
     */
    public function removeLowestLayer(array $plan): ?array
    {
        $active = $plan['active_layers'] ?? [];
        if (count($active) <= 1) {
            return null;
        }

        $isLong = strtoupper($plan['side'] ?? 'BUY') === 'BUY';
        usort($active, function ($a, $b) use ($isLong) {
            $la = (float)($a['entry_level'] ?? 0);
            $lb = (float)($b['entry_level'] ?? 0);
            return $isLong ? $la <=> $lb : $lb <=> $la;
        });

        $removed = array_shift($active);
        $plan['active_layers'] = $active;
        $this->storage->savePlan($plan);
        return $removed;
    }

    /**
     * Следующий уровень для добора: для long — высший незаполненный уровень <= текущей цены.
     */
    public function getNextAddLevel(array $plan, float $currentPrice): ?float
    {
        $levels  = $plan['levels'] ?? [];
        $active  = $plan['active_layers'] ?? [];
        $filled  = array_map('floatval', array_column($active, 'entry_level'));
        $isLong  = strtoupper($plan['side'] ?? 'BUY') === 'BUY';
        $maxLay  = (int)($plan['max_layers'] ?? 3);

        if (count($active) >= $maxLay) {
            return null;
        }

        $candidate = null;
        foreach ($levels as $lvl) {
            $lvlF = (float)$lvl;
            if (in_array($lvlF, $filled, true)) {
                continue;
            }
            if ($isLong && $lvlF <= $currentPrice) {
                $candidate = $candidate === null ? $lvlF : max($candidate, $lvlF);
            }
            if (!$isLong && $lvlF >= $currentPrice) {
                $candidate = $candidate === null ? $lvlF : min($candidate, $lvlF);
            }
        }
        return $candidate;
    }

    /**
     * Уровень для разгрузки: цена вернулась на уровень выше самого нижнего активного слоя.
     */
    public function getUnloadLevel(array $plan): ?float
    {
        $active = $plan['active_layers'] ?? [];
        if (count($active) <= 1) {
            return null;
        }

        $isLong = strtoupper($plan['side'] ?? 'BUY') === 'BUY';
        usort($active, function ($a, $b) use ($isLong) {
            $la = (float)($a['entry_level'] ?? 0);
            $lb = (float)($b['entry_level'] ?? 0);
            return $isLong ? $la <=> $lb : $lb <=> $la;
        });

        $lowest = $active[0];
        $lowestLevel = (float)($lowest['entry_level'] ?? 0);
        $levels = $plan['levels'] ?? [];

        foreach ($levels as $lvl) {
            $lvlF = (float)$lvl;
            if ($isLong && $lvlF > $lowestLevel) {
                return $lvlF;
            }
            if (!$isLong && $lvlF < $lowestLevel) {
                return $lvlF;
            }
        }
        return null;
    }

    /**
     * Нужно ли добавить слой: цена достигла следующего уровня, есть свободный слот.
     */
    public function shouldAddLayer(array $plan, float $currentPrice, float $tolerancePct = 0.5): bool
    {
        $nextLevel = $this->getNextAddLevel($plan, $currentPrice);
        if ($nextLevel === null) {
            return false;
        }
        $tolerance = $nextLevel * ($tolerancePct / 100);
        $isLong   = strtoupper($plan['side'] ?? 'BUY') === 'BUY';
        return $isLong
            ? ($currentPrice <= $nextLevel + $tolerance)
            : ($currentPrice >= $nextLevel - $tolerance);
    }

    /**
     * Нужно ли разгрузить: цена вернулась на уровень выше нижнего слоя.
     */
    public function shouldUnloadLayer(array $plan, float $currentPrice, float $tolerancePct = 0.5): bool
    {
        $unloadLevel = $this->getUnloadLevel($plan);
        if ($unloadLevel === null) {
            return false;
        }
        $tolerance = $unloadLevel * ($tolerancePct / 100);
        $isLong    = strtoupper($plan['side'] ?? 'BUY') === 'BUY';
        return $isLong
            ? ($currentPrice >= $unloadLevel - $tolerance)
            : ($currentPrice <= $unloadLevel + $tolerance);
    }

    /**
     * Доля одного слоя от общей позиции (для closePositionMarket fraction).
     */
    public function getOneLayerFraction(array $plan, float $totalSize, float $markPrice): float
    {
        $layerUsdt = (float)($plan['layer_size_usdt'] ?? 50);
        $active    = count($plan['active_layers'] ?? []);
        if ($active <= 0 || $totalSize <= 0 || $markPrice <= 0) {
            return 0.0;
        }
        $notional = $totalSize * $markPrice;
        $fraction = $layerUsdt / $notional;
        return max(0.05, min(1.0, round($fraction, 4)));
    }

    /**
     * Удаляет план (при полном закрытии позиции).
     */
    public function removePlan(string $symbol, string $side): void
    {
        $this->storage->removePlan($symbol, $side);
    }

    private function nextLayerId(array $existingIds): string
    {
        $letters = range('A', 'Z');
        foreach ($letters as $c) {
            if (!in_array($c, $existingIds, true)) {
                return $c;
            }
        }
        return 'L' . (count($existingIds) + 1);
    }

    private function savePlan(array $plan): void
    {
        $this->storage->savePlan($plan);
    }
}
