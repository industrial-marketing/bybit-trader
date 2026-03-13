<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\BotHistoryService;
use App\Service\Settings\ProfileContext;

/**
 * Управление limit-ордерами для Rotational Grid: добор и разгрузка через limit-ордера.
 *
 * - Add orders: limit на уровнях L1, L2… для добора при движении цены против позиции
 * - Unload order: limit reduce-only на уровне выше нижнего слоя для разгрузки
 *
 * orderLinkId включает profile ID для уникальности при нескольких профилях (110072 = OrderLinkedID is duplicate).
 */
class RotationalGridLimitOrderManager
{
    private const ORDER_LINK_PREFIX = 'grid_';

    public function __construct(
        private readonly RotationalGridService $grid,
        private readonly BybitService $bybit,
        private readonly BotHistoryService $botHistory,
        private readonly ProfileContext $profileContext,
    ) {
    }

    /**
     * Синхронизирует limit-ордера: проверяет исполнения, ставит недостающие, отменяет лишние.
     *
     * @param array $plan План позиции
     * @param array $position Текущая позиция (symbol, side, size, markPrice, leverage)
     * @param bool  $rotationAllowed Разрешён ли ротационный grid в текущем режиме
     * @return array|null Обновлённый план или null
     */
    public function sync(array $plan, array $position, bool $rotationAllowed): ?array
    {
        $symbol = $plan['symbol'] ?? '';
        $side   = $plan['side'] ?? '';

        if ($symbol === '') {
            return $plan;
        }

        $openOrders = $this->bybit->getOpenOrders($symbol);
        $openIds    = [];
        foreach ($openOrders as $o) {
            $oid = $o['orderId'] ?? '';
            if ($oid !== '') {
                $openIds[$oid] = $o;
            }
        }

        // 1. Проверить исполненные ордера (add/unload)
        $plan = $this->processFilledOrders($plan, $position, $openIds);

        if ($plan === null) {
            return null;
        }

        // 2. Отменить ордера на уже заполненных уровнях
        $plan = $this->cancelStaleOrders($plan, $openIds);

        // 3. Выставить недостающие add-ордера (если rotationAllowed)
        if ($rotationAllowed) {
            $plan = $this->ensureAddOrders($plan, $position);
        } else {
            $plan = $this->cancelAllAddOrders($plan);
        }

        // 4. Выставить или заменить unload-ордер
        $plan = $this->ensureUnloadOrder($plan, $position);

        $this->grid->getAllPlans(); // ensure storage is used via savePlan
        $this->savePlan($plan);

        return $plan;
    }

    /**
     * Отменяет все limit-ордера плана (при закрытии позиции).
     */
    public function cancelPlanOrders(array $plan): void
    {
        $symbol = $plan['symbol'] ?? '';
        foreach ($plan['limit_add_orders'] ?? [] as $ao) {
            $orderId = $ao['orderId'] ?? '';
            $linkId  = $ao['orderLinkId'] ?? null;
            if ($orderId !== '' || $linkId !== null) {
                $this->bybit->cancelOrder($symbol, $orderId ?: null, $linkId);
            }
        }
        $unload = $plan['limit_unload_order'] ?? null;
        if ($unload !== null) {
            $orderId = $unload['orderId'] ?? '';
            $linkId  = $unload['orderLinkId'] ?? null;
            if ($orderId !== '' || $linkId !== null) {
                $this->bybit->cancelOrder($symbol, $orderId ?: null, $linkId);
            }
        }
    }

    /**
     * Выставляет начальные limit add-ордера после создания плана.
     */
    public function placeInitialAddOrders(array $plan, array $position): array
    {
        return $this->ensureAddOrders($plan, $position);
    }

    private function processFilledOrders(array $plan, array $position, array $openIds): ?array
    {
        $symbol = $plan['symbol'] ?? '';
        $side   = $plan['side'] ?? '';
        $markPrice = (float)($position['markPrice'] ?? 0);

        // Проверить add orders
        $addOrders = $plan['limit_add_orders'] ?? [];
        foreach ($addOrders as $i => $ao) {
            $orderId = $ao['orderId'] ?? '';
            if ($orderId === '') {
                continue;
            }
            if (isset($openIds[$orderId])) {
                continue; // ещё открыт
            }
            $hist = $this->bybit->getOrderFromHistory($symbol, $orderId);
            if ($hist === null || ($hist['orderStatus'] ?? '') !== 'Filled') {
                continue; // отменён или неизвестен
            }
            $level  = (float)($ao['level'] ?? 0);
            $avgPx  = (float)($hist['avgPrice'] ?? $markPrice);
            $plan   = $this->grid->addLayer($plan, $level, $avgPx);
            $this->botHistory->log('rotational_add_layer', [
                'symbol' => $symbol, 'side' => $side, 'level' => $level,
                'ok' => true, 'layers' => count($plan['active_layers'] ?? []), 'source' => 'limit_fill',
            ]);
            unset($addOrders[$i]);
            $plan['limit_add_orders'] = array_values($addOrders);
            $this->savePlan($plan);
            return $plan; // один add за тик
        }

        // Проверить unload order
        $unload = $plan['limit_unload_order'] ?? null;
        if ($unload !== null) {
            $orderId = $unload['orderId'] ?? '';
            if ($orderId !== '' && !isset($openIds[$orderId])) {
                $hist = $this->bybit->getOrderFromHistory($symbol, $orderId);
                if ($hist !== null && ($hist['orderStatus'] ?? '') === 'Filled') {
                    $this->grid->removeLowestLayer($plan);
                    $plan['limit_unload_order'] = null;
                    $plan = $this->grid->getPlan($symbol, $side) ?? $plan;
                    $this->botHistory->log('rotational_unload_layer', [
                        'symbol' => $symbol, 'side' => $side,
                        'ok' => true, 'layers' => count($plan['active_layers'] ?? []), 'source' => 'limit_fill',
                    ]);
                    $this->savePlan($plan);
                    return $plan;
                }
            }
        }

        return $plan;
    }

    private function cancelStaleOrders(array $plan, array $openIds): array
    {
        $symbol = $plan['symbol'] ?? '';
        $addOrders = $plan['limit_add_orders'] ?? [];
        $filled   = array_map('floatval', array_column($plan['active_layers'] ?? [], 'entry_level'));

        foreach ($addOrders as $i => $ao) {
            $level   = (float)($ao['level'] ?? 0);
            $orderId = $ao['orderId'] ?? '';
            if (in_array($level, $filled, true) && $orderId !== '' && isset($openIds[$orderId])) {
                $this->bybit->cancelOrder($symbol, $orderId, null);
                unset($addOrders[$i]);
            }
        }
        $plan['limit_add_orders'] = array_values($addOrders);
        return $plan;
    }

    private function ensureAddOrders(array $plan, array $position): array
    {
        $symbol    = $plan['symbol'] ?? '';
        $side      = $plan['side'] ?? '';
        $markPrice = (float)($position['markPrice'] ?? 0);
        $leverage  = max(1, (int)($position['leverage'] ?? 1));
        $bybitSide = strtoupper($side) === 'BUY' ? 'BUY' : 'SELL';
        $layerUsdt = (float)($plan['layer_size_usdt'] ?? 50);
        $levels    = $plan['levels'] ?? [];
        $maxLayers = (int)($plan['max_layers'] ?? 3);
        $active    = $plan['active_layers'] ?? [];
        $filled    = array_map('floatval', array_column($active, 'entry_level'));
        $isLong    = strtoupper($side) === 'BUY';

        $addOrders = $plan['limit_add_orders'] ?? [];
        $existingLevels = array_map(fn($a) => (float)($a['level'] ?? 0), $addOrders);

        $slotsLeft = $maxLayers - count($active);
        if ($slotsLeft <= 0) {
            return $this->cancelAllAddOrders($plan);
        }

        $candidates = [];
        foreach ($levels as $lvl) {
            $lvlF = (float)$lvl;
            if (in_array($lvlF, $filled, true)) {
                continue;
            }
            if ($isLong && $lvlF < $markPrice) {
                $candidates[] = $lvlF;
            }
            if (!$isLong && $lvlF > $markPrice) {
                $candidates[] = $lvlF;
            }
        }

        $isLong ? rsort($candidates) : sort($candidates);
        $toPlace = array_slice($candidates, 0, $slotsLeft);

        foreach ($toPlace as $level) {
            if (in_array($level, $existingLevels, true)) {
                continue;
            }
            $linkId = $this->orderLinkId($symbol, $side, 'add', (string)$level);
            $result = $this->bybit->placeLimitOrder($symbol, $bybitSide, $level, $layerUsdt, $leverage, $linkId);
            if ($result['ok'] ?? false) {
                $addOrders[] = [
                    'level'       => $level,
                    'orderId'     => $result['orderId'] ?? '',
                    'orderLinkId' => $linkId,
                ];
                $existingLevels[] = $level;
            }
        }

        $plan['limit_add_orders'] = $addOrders;
        return $plan;
    }

    private function cancelAllAddOrders(array $plan): array
    {
        $symbol    = $plan['symbol'] ?? '';
        $addOrders = $plan['limit_add_orders'] ?? [];
        foreach ($addOrders as $ao) {
            $orderId = $ao['orderId'] ?? '';
            if ($orderId !== '') {
                $this->bybit->cancelOrder($symbol, $orderId, $ao['orderLinkId'] ?? null);
            }
        }
        $plan['limit_add_orders'] = [];
        return $plan;
    }

    private function ensureUnloadOrder(array $plan, array $position): array
    {
        $symbol    = $plan['symbol'] ?? '';
        $side      = $plan['side'] ?? '';
        $size      = (float)($position['size'] ?? 0);
        $markPrice = (float)($position['markPrice'] ?? 0);

        $unloadLevel = $this->grid->getUnloadLevel($plan);
        if ($unloadLevel === null) {
            $plan['limit_unload_order'] = null;
            return $plan;
        }

        $current = $plan['limit_unload_order'] ?? null;
        $currentLevel = $current !== null ? (float)($current['level'] ?? 0) : null;

        if ($currentLevel !== null && abs($currentLevel - $unloadLevel) < 0.0001) {
            return $plan; // уже есть на нужном уровне
        }

        if ($current !== null) {
            $orderId = $current['orderId'] ?? '';
            if ($orderId !== '') {
                $this->bybit->cancelOrder($symbol, $orderId, $current['orderLinkId'] ?? null);
            }
        }

        $fraction = $this->grid->getOneLayerFraction($plan, $size, $markPrice);
        $qty      = $size * $fraction;
        if ($qty <= 0) {
            $plan['limit_unload_order'] = null;
            return $plan;
        }

        $orderSide  = strtoupper($side) === 'BUY' ? 'SELL' : 'BUY';
        $linkId    = $this->orderLinkId($symbol, $side, 'unload', '');
        $result    = $this->bybit->placeLimitReduceOrder($symbol, $side, $unloadLevel, $qty, $linkId);

        if ($result['ok'] ?? false) {
            $plan['limit_unload_order'] = [
                'level'       => $unloadLevel,
                'orderId'     => $result['orderId'] ?? '',
                'orderLinkId' => $linkId,
            ];
        } else {
            $plan['limit_unload_order'] = null;
        }

        return $plan;
    }

    private function orderLinkId(string $symbol, string $side, string $type, string $suffix): string
    {
        $base = self::ORDER_LINK_PREFIX . $symbol . '_' . $side . '_' . $type;
        if ($suffix !== '') {
            $base .= '_' . str_replace('.', '', $suffix);
        }
        return substr($base, 0, 36);
    }

    private function savePlan(array $plan): void
    {
        $this->grid->updatePlan($plan);
    }
}
