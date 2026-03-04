<?php

namespace App\Service;

/**
 * Aggregates PnL by day/symbol from closed-pnl and execution list.
 * Source for /api/statistics/pnl charts.
 */
class PnlStatisticsService
{
    public function __construct(
        private readonly BybitService $bybitService,
    ) {}

    /**
     * @param int         $days     Default 30
     * @param string      $groupBy  day|symbol|day_symbol
     * @param string|null $symbol   Filter by symbol
     * @param string|null $from     YYYY-MM-DD
     * @param string|null $to       YYYY-MM-DD
     */
    public function getPnlSeries(int $days = 30, string $groupBy = 'day', ?string $symbol = null, ?string $from = null, ?string $to = null): array
    {
        $records = $this->fetchPnlRecords($days, $symbol, $from, $to);
        if (empty($records)) {
            return [
                'ok'      => true,
                'source'  => 'empty',
                'range'   => $this->dateRange($days, $from, $to),
                'totals'  => ['pnl_usdt' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0],
                'series'  => [],
                'bySymbol'=> [],
            ];
        }

        $totals = ['pnl_usdt' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0];
        $byDate = [];
        $bySym  = [];

        foreach ($records as $r) {
            $pnl   = (float)($r['closedPnl'] ?? $r['closed_pnl'] ?? 0);
            $sym   = $r['symbol'] ?? '';
            $date  = $this->extractDate($r);
            $fee   = (float)($r['execFee'] ?? ($r['openFee'] ?? 0) + ($r['closeFee'] ?? 0));

            $totals['pnl_usdt'] += $pnl;
            $totals['trades']++;
            if ($pnl > 0) {
                $totals['wins']++;
            } elseif ($pnl < 0) {
                $totals['losses']++;
            }

            if ($date !== null) {
                $byDate[$date] = $byDate[$date] ?? ['date' => $date, 'pnl_usdt' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0];
                $byDate[$date]['pnl_usdt'] += $pnl;
                $byDate[$date]['trades']++;
                if ($pnl > 0) $byDate[$date]['wins']++;
                elseif ($pnl < 0) $byDate[$date]['losses']++;
            }

            if ($sym !== '') {
                $bySym[$sym] = $bySym[$sym] ?? ['symbol' => $sym, 'pnl_usdt' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0];
                $bySym[$sym]['pnl_usdt'] += $pnl;
                $bySym[$sym]['trades']++;
                if ($pnl > 0) $bySym[$sym]['wins']++;
                elseif ($pnl < 0) $bySym[$sym]['losses']++;
            }
        }

        $series = array_values(array_map(function ($d) {
            $d['win_rate'] = ($d['wins'] + $d['losses']) > 0
                ? round(100 * $d['wins'] / ($d['wins'] + $d['losses']), 1)
                : 0;
            unset($d['wins'], $d['losses']);
            return $d;
        }, $byDate));

        usort($series, fn($a, $b) => strcmp($a['date'], $b['date']));

        $bySymbol = array_values(array_map(function ($d) {
            $d['win_rate'] = ($d['wins'] + $d['losses']) > 0
                ? round(100 * $d['wins'] / ($d['wins'] + $d['losses']), 1)
                : 0;
            unset($d['wins'], $d['losses']);
            return $d;
        }, $bySym));

        usort($bySymbol, fn($a, $b) => $b['pnl_usdt'] <=> $a['pnl_usdt']);

        $range = $this->dateRange($days, $from, $to);

        return [
            'ok'       => true,
            'source'   => 'closedPnl',
            'range'    => $range,
            'totals'   => array_merge($totals, [
                'win_rate' => $totals['trades'] > 0
                    ? round(100 * $totals['wins'] / $totals['trades'], 1)
                    : 0,
            ]),
            'series'   => $series,
            'bySymbol' => $bySymbol,
        ];
    }

    private function fetchPnlRecords(int $days, ?string $symbol, ?string $from, ?string $to): array
    {
        $closedRaw = $this->bybitService->getClosedPnl(300);
        $list      = $closedRaw['list'] ?? [];

        if (empty($list)) {
            $trades = $this->bybitService->getClosedTrades(500);
            foreach ($trades as $t) {
                $list[] = [
                    'symbol'    => $t['symbol'] ?? '',
                    'closedPnl' => $t['closedPnl'] ?? null,
                    'execFee'   => $t['execFee'] ?? null,
                    'openedAt'  => $t['openedAt'] ?? null,
                ];
            }
        }

        $range = $this->dateRange($days, $from, $to);
        $fromTs = strtotime($range['from'] . ' 00:00:00');
        $toTs   = strtotime($range['to'] . ' 23:59:59');

        $filtered = [];
        foreach ($list as $r) {
            $sym = $r['symbol'] ?? '';
            if ($symbol !== null && $symbol !== '' && $sym !== $symbol) {
                continue;
            }

            $ts = $this->extractTimestamp($r);
            if ($ts !== null && $ts >= $fromTs && $ts <= $toTs) {
                $filtered[] = $r;
            } elseif ($ts === null && empty($symbol)) {
                $filtered[] = $r;
            }
        }

        return $filtered;
    }

    private function extractDate(array $r): ?string
    {
        $ts = $this->extractTimestamp($r);
        return $ts !== null ? date('Y-m-d', $ts) : null;
    }

    private function extractTimestamp(array $r): ?int
    {
        if (isset($r['createdTime'])) {
            return (int)($r['createdTime'] / 1000);
        }
        if (isset($r['updatedTime'])) {
            return (int)($r['updatedTime'] / 1000);
        }
        if (isset($r['openedAt']) && is_string($r['openedAt'])) {
            $ts = strtotime($r['openedAt']);
            return $ts ?: null;
        }
        return null;
    }

    private function dateRange(int $days, ?string $from, ?string $to): array
    {
        $toDate = $to ? strtotime($to) : time();
        $fromDate = $from ? strtotime($from) : strtotime("-{$days} days", $toDate);
        if (!$fromDate || !$toDate) {
            $toDate = time();
            $fromDate = strtotime("-{$days} days", $toDate);
        }
        return [
            'from' => date('Y-m-d', $fromDate),
            'to'   => date('Y-m-d', $toDate),
            'days' => (int)ceil(($toDate - $fromDate) / 86400),
        ];
    }
}
