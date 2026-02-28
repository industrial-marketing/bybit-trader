<?php

namespace App\Service;

/**
 * Computes observability metrics from BotHistoryService.
 *
 * Tracks:
 *  - How many actions LLM proposed vs how many were executed vs skipped by rules
 *  - Skip-reason breakdown
 *  - Win-rate by action type (using realizedPnlEstimate when available)
 *  - LLM failures and invalid-response counts
 *  - Recent decision trace for the "Why" UI panel
 */
class BotMetricsService
{
    // Action event types that represent LLM-driven management decisions
    private const MANAGE_TYPES = [
        'close_full', 'close_partial', 'close_partial_skip',
        'move_sl_to_be', 'move_sl_to_be_skip',
        'average_in',
    ];

    public function __construct(private readonly BotHistoryService $botHistory) {}

    /**
     * Aggregate metrics over the last $days days.
     */
    public function getMetrics(int $days = 30): array
    {
        $events = $this->botHistory->getRecentEvents($days);

        $tickCount         = 0;
        $llmFailures       = 0;
        $invalidResponses  = 0;
        $proposed          = 0;
        $executed          = 0;
        $skipped           = 0;
        $failed            = 0;
        $byAction          = [];
        $skipReasons       = [];
        $consecutiveFails  = []; // symbol → consecutive fail count

        foreach ($events as $e) {
            $type = $e['type'] ?? '';

            if ($type === 'bot_tick') {
                $tickCount++;
                continue;
            }
            if ($type === 'llm_failure') {
                $llmFailures++;
                continue;
            }
            if ($type === 'llm_invalid_response') {
                $invalidResponses++;
                continue;
            }
            if (!in_array($type, self::MANAGE_TYPES, true)) {
                continue;
            }

            $ok         = (bool)($e['ok']      ?? false);
            $isSkip     = (bool)($e['skipped'] ?? false);
            $skipReason = $e['skip_reason']    ?? ($e['skipReason'] ?? null);
            $pnl        = isset($e['realizedPnlEstimate']) ? (float)$e['realizedPnlEstimate'] : null;
            $symbol     = $e['symbol'] ?? '';
            // Normalise action label
            $action     = strtoupper($e['action'] ?? str_replace('_skip', '', $type));

            if (!isset($byAction[$action])) {
                $byAction[$action] = [
                    'proposed' => 0, 'executed' => 0, 'skipped' => 0, 'failed' => 0,
                    'wins' => 0, 'losses' => 0, 'total_pnl' => 0.0,
                ];
            }

            $proposed++;
            $byAction[$action]['proposed']++;

            if ($isSkip) {
                $skipped++;
                $byAction[$action]['skipped']++;
                if ($skipReason !== null) {
                    $skipReasons[$skipReason] = ($skipReasons[$skipReason] ?? 0) + 1;
                }
            } elseif ($ok) {
                $executed++;
                $byAction[$action]['executed']++;
                if ($symbol) {
                    $consecutiveFails[$symbol] = 0;
                }
                if ($pnl !== null) {
                    $byAction[$action]['total_pnl'] += $pnl;
                    if ($pnl > 0) {
                        $byAction[$action]['wins']++;
                    } elseif ($pnl < 0) {
                        $byAction[$action]['losses']++;
                    }
                }
            } else {
                $failed++;
                $byAction[$action]['failed']++;
                if ($symbol) {
                    $consecutiveFails[$symbol] = ($consecutiveFails[$symbol] ?? 0) + 1;
                }
            }
        }

        // Win-rate per action (only for actions with PnL data)
        foreach ($byAction as &$stats) {
            $withPnl           = $stats['wins'] + $stats['losses'];
            $stats['win_rate'] = $withPnl > 0 ? round($stats['wins'] / $withPnl * 100, 1) : null;
            $stats['total_pnl']= round($stats['total_pnl'], 2);
        }
        unset($stats);

        arsort($skipReasons);

        return [
            'period_days'        => $days,
            'tick_count'         => $tickCount,
            'llm_failures'       => $llmFailures,
            'invalid_responses'  => $invalidResponses,
            'proposed'           => $proposed,
            'executed'           => $executed,
            'skipped'            => $skipped,
            'failed'             => $failed,
            'execution_rate_pct' => $proposed > 0 ? round($executed / $proposed * 100, 1) : null,
            'by_action'          => $byAction,
            'skip_reasons'       => $skipReasons,
        ];
    }

    /**
     * Recent decision trace events for the "Why" UI panel.
     * Returns events enriched with LLM decision data (reason, confidence, risk, checks).
     */
    public function getRecentDecisions(int $limit = 100): array
    {
        $traceTypes = array_merge(self::MANAGE_TYPES, [
            'llm_invalid_response', 'pending_rejected', 'auto_open',
        ]);

        $events    = $this->botHistory->getRecentEvents(14);
        $decisions = array_values(array_filter(
            $events,
            fn($e) => in_array($e['type'] ?? '', $traceTypes, true)
        ));

        usort($decisions, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));

        return array_slice($decisions, 0, $limit);
    }

    /**
     * Last decision for each position (symbol+side), for the "Why" column in the positions table.
     */
    public function getLastDecisionPerPosition(): array
    {
        $decisions = $this->getRecentDecisions(200);
        $byPos     = [];

        foreach ($decisions as $d) {
            $key = ($d['symbol'] ?? '') . '|' . ($d['side'] ?? '');
            if ($key === '|' || isset($byPos[$key])) {
                continue;
            }
            $byPos[$key] = [
                'timestamp'  => $d['timestamp']  ?? null,
                'action'     => $d['action']      ?? ($d['type'] ?? ''),
                'confidence' => $d['confidence']  ?? null,
                'reason'     => $d['reason']      ?? ($d['note'] ?? ''),
                'risk'       => $d['risk']        ?? null,
                'checks'     => $d['checks']      ?? null,
                'ok'         => $d['ok']          ?? null,
                'skip_reason'=> $d['skip_reason'] ?? ($d['skipReason'] ?? null),
                'prompt_version' => $d['prompt_version'] ?? null,
            ];
        }

        return $byPos;
    }
}
