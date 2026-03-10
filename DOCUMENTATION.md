# Bybit Trader — Документация

> Последнее обновление: 25.02.2026 (rev 8)

## Содержание

1. [Обзор проекта](#1-обзор-проекта)
2. [Архитектура](#2-архитектура)
3. [Файловая структура](#3-файловая-структура)
4. [Сервисы (Services)](#4-сервисы-services)
5. [Контроллеры (Controllers)](#5-контроллеры-controllers)
6. [Фронтенд](#6-фронтенд)
7. [API Endpoints](#7-api-endpoints)
8. [Настройки](#8-настройки)
9. [Бот: логика принятия решений](#9-бот-логика-принятия-решений)
10. [История цен и таймфреймы](#10-история-цен-и-таймфреймы)
11. [Токен-бюджет LLM](#11-токен-бюджет-llm)
12. [Интеграция с Bybit API v5](#12-интеграция-с-bybit-api-v5)
13. [Интеграция с LLM](#13-интеграция-с-llm)
14. [Cron / ручной запуск бота](#14-cron--ручной-запуск-бота)
15. [Данные и хранилище](#15-данные-и-хранилище)
16. [Безопасность](#16-безопасность)
17. [Известные ограничения и особенности](#17-известные-ограничения-и-особенности)
18. [Rotational Grid Mode](#18-rotational-grid-mode)

---

## 1. Обзор проекта

**Bybit Trader** — веб-приложение для полуавтоматической торговли на Bybit (поддержка testnet и mainnet).

**Ключевые принципы:**
- Бот _предлагает_ новые сделки — пользователь _открывает_ с корректировками.
- По уже открытым позициям бот _сам принимает решения_ и ведёт сделки до закрытия.
- Все решения основаны на анализе LLM (ChatGPT / DeepSeek) с учётом истории цен, истории решений бота и торговых параметров.

**Стек:**
- Backend: PHP 8.1+, Symfony 6.4
- Frontend: jQuery, Twig-шаблоны, Bootstrap Icons 1.11
- Биржа: Bybit API v5 (linear perpetual, category=linear)
- LLM: OpenAI ChatGPT (primary), DeepSeek (fallback)

---

## 2. Архитектура

```
Browser (jQuery + Bootstrap Icons)
    │
    ▼
Symfony Router
    │
    ├── SecurityController   ──►  security/login.html.twig
    ├── DashboardController  ──►  dashboard.html.twig
    └── ApiController        ──►  JSON API
            │
            ├── BybitService          (Bybit API v5, retry, time-sync, instrument cache)
            ├── ChatGPTService        (LLM: OpenAI / DeepSeek, строгий контракт v3)
            ├── SettingsService       (var/settings.json)
            ├── BotHistoryService     (var/bot_history.json, atomic I/O)
            ├── BotRunService         (var/bot_runs.json, идемпотентность тика)
            ├── PositionLockService   (var/position_locks.json, atomic I/O)
            ├── PendingActionsService (var/pending_actions.json, строгий режим)
            ├── RiskGuardService      (kill-switch, loss limit, exposure, cooldown)
            ├── AlertService          (Telegram / webhook алерты)
            ├── BotMetricsService     (метрики LLM-решений, трасса "Why")
            ├── CostEstimatorService   (fees + slippage + funding, min-edge check)
            ├── ExecutionGuardService  (post-check исполнения ордеров)
            ├── CircuitBreakerService   (авто-пауза при серии сбоев)
            ├── RotationalGridService   (планы позиций, слои, сетка — var/position_plans.json)
            ├── AtomicFileStorage      (flock + temp-rename, базовый I/O)
            └── LogSanitizer           (редактирование секретов в логах)
```

---

## 3. Файловая структура

```
bybit_trader/
├── src/
│   ├── Command/
│   │   └── BotTickCommand.php          ← консольная команда app:bot-tick (для cron)
│   ├── Controller/
│   │   ├── ApiController.php         ← все /api/* эндпоинты
│   │   ├── DashboardController.php   ← рендер страниц
│   │   └── SecurityController.php    ← /login, /logout
│   └── Service/
│       ├── AtomicFileStorage.php       ← flock + temp-rename, базовый слой I/O
│       ├── CircuitBreakerService.php   ← авто-пауза при N подряд сбоях (TTL + manual reset)
│       ├── RotationalGridService.php   ← планы rotational grid (var/position_plans.json)
│       ├── CostEstimatorService.php    ← fees + slippage + funding, estimateTotalCost, checkMinimumEdge
│       ├── ExecutionGuardService.php   ← post-check исполнения: verifyClose/verifyOpen/verifyStopLoss
│       ├── BybitService.php          ← Bybit API v5 (retry, time-sync, instrument cache)
│       ├── ChatGPTService.php        ← LLM (OpenAI + DeepSeek, строгий контракт v3)
│       ├── SettingsService.php       ← настройки (var/settings.json + env override)
│       ├── BotHistoryService.php     ← история решений бота (atomic)
│       ├── BotRunService.php         ← идемпотентность тика, timeframe bucket
│       ├── PositionLockService.php   ← замки на позиции (atomic)
│       ├── PendingActionsService.php ← действия ожидающие подтверждения (strict mode)
│       ├── RiskGuardService.php      ← контроль рисков (kill-switch, limits, cooldown)
│       ├── AlertService.php          ← алерты (Telegram / webhook)
│       ├── BotMetricsService.php     ← метрики LLM-решений и трасса "Why"
│       └── LogSanitizer.php          ← редактирование секретов из логов
├── templates/
│   ├── base.html.twig                ← layout: лого, navbar (Bootstrap Icons), footer
│   ├── dashboard.html.twig           ← главная страница
│   ├── settings.html.twig            ← страница настроек
│   └── security/
│       └── login.html.twig           ← форма входа
├── public/
│   ├── assets/
│   │   ├── brand/
│   │   │   ├── bybit_trader_logo.png ← горизонтальный логотип (шапка)
│   │   │   ├── bybit_trader_icon.png ← квадратная иконка (favicon, footer)
│   │   │   └── icons/               ← favicon-файлы разных размеров
│   │   ├── js/
│   │   │   ├── app.js               ← логика дашборда
│   │   │   └── settings.js          ← логика страницы настроек
│   │   ├── css/
│   │   │   └── style.css            ← дизайн-система (CSS variables + компоненты)
│   │   └── lib/
│   │       └── jquery.min.js
│   └── .htaccess                    ← mod_rewrite (Symfony front controller)
├── config/
│   ├── packages/security.yaml       ← Symfony Security
│   └── routes.yaml
├── var/
│   ├── settings.json                ← конфигурация (без API-ключей)
│   ├── bot_history.json             ← история событий бота (14 дней, 1000 записей)
│   ├── bot_runs.json                ← история запусков тика (идемпотентность)
│   ├── position_locks.json          ← заблокированные позиции
│   ├── position_plans.json         ← планы Rotational Grid (слои, уровни)
│   ├── position_plans.json         ← планы Rotational Grid (слои, уровни)
│   ├── pending_actions.json         ← ожидающие подтверждения (strict mode, TTL 60 мин)
│   ├── circuit_breaker.json         ← состояние CB-автоматов (TTL = cooldown_minutes)
│   ├── bybit_time_offset.json       ← кеш сдвига часов (TTL 5 мин)
│   └── instrument_cache.json        ← кеш инструментов Bybit (TTL 1 ч)
├── .env                             ← дефолтные ENV-переменные (коммитится)
├── .env.local                       ← секреты и переопределения (НЕ коммитится)
└── DOCUMENTATION.md
```

---

## 4. Сервисы (Services)

### `AtomicFileStorage`

Базовый слой атомарного I/O для всех JSON-файлов состояния. Устраняет гонки при одновременном запуске cron + ручного тика.

**Механизм:** companion-файл `.lock` + `flock(LOCK_EX/SH)` + запись во временный `.tmp.PID`-файл + атомарный `rename()`.

| Метод | Описание |
|---|---|
| `read(path, default)` | Чтение под `LOCK_SH` (разделяемая блокировка) |
| `write(path, data)` | Запись под `LOCK_EX` через temp-rename |
| `update(path, callback, default)` | Read-modify-write под `LOCK_EX`. Перечитывает файл внутри блокировки — гарантирует отсутствие потерянных обновлений |

> Все остальные сервисы используют `AtomicFileStorage` вместо прямых `file_put_contents` / `file_get_contents`.

---

### `BotRunService`

Идемпотентный guard для тиков бота. Предотвращает дублирование тика при одновременном запуске cron + ручной кнопки.

**Концепция timeframe bucket:** для таймфрейма 5m текущее время 10:07 даёт bucket `2026-02-25T10:05`. Все запросы в одном окне имеют одинаковый ключ.

**Хранилище:** `var/bot_runs.json` (последние 200 записей).

| Метод | Описание |
|---|---|
| `tryStart(timeframeMinutes, staleSec)` | Атомарно резервирует bucket. Возвращает `run_id` (продолжать) или `null` (пропустить) |
| `finish(runId, status)` | Помечает run как `done` / `error` |
| `currentBucket(timeframeMinutes)` | Вычисляет текущий bucket-ключ |
| `getRecentRuns(limit)` | История запусков для диагностики |
| `isRunning(timeframeMinutes)` | Проверяет активный запуск |

**Stale-detection:** если `running`-запись существует, но `started_at` > 2×timeframe назад — считается аварийным завершением (`crashed`), новый запуск разрешается.

---

### `BybitService`

Вся коммуникация с Bybit API v5.

**Ключевые методы:**

| Метод | Описание |
|---|---|
| `getPositions()` | Список открытых позиций (обогащает `liqPrice`, `stopLoss`, `takeProfit`) |
| `getTopMarkets(limit, category)` | Топ монет по обороту. Дедупликация: предпочитает `*PERP`. Фильтрует dated-контракты |
| `getKlineHistory(symbol, intervalMinutes, limit, maxPricePoints)` | Исторические свечи. Использует канонический `*USDT`-символ |
| `getBalance()` | Баланс кошелька (USDT available + wallet balance) |
| `getStatistics()` | Статистика: totalTrades, winRate, totalProfit, drawdown. Использует closed-pnl → execution/list. Диагностика: source, note |
| `getClosedPnl(limit)` | `/v5/position/closed-pnl` (category=linear). Pagination до 200 записей |
| `getAccountInfo()` | GET /v5/account/info — marginMode (REGULAR_MARGIN, ISOLATED_MARGIN, PORTFOLIO_MARGIN), unifiedMarginStatus. Для UTA режим маржи задаётся на уровне аккаунта |
| `ensureMarginMode(targetMode)` | Опциональный guard: проверяет margin mode, при необходимости вызывает POST /v5/account/set-margin-mode |
| `placeOrder(symbol, side, positionSizeUSDT, leverage)` | Открытие позиции: ensureMarginMode(account-level) → setLeverage → createOrder → verifyOpen. Не вызывает switch-isolated (deprecated для UTA). Для cross buyLeverage=sellLeverage обязательно |
| `closePositionMarket(symbol, side, fraction)` | Закрытие (полное или частичное). Пропускает если qty < minOrderQty |
| `setBreakevenStopLoss(symbol, side, entryPrice)` | Перенос стопа в безубыток |
| `getOpenOrders(symbol)` | Открытые ордера |
| `getTrades(limit)` | История исполнений |
| `testConnection()` | Проверка + показывает сдвиг часов с Bybit |
| `getPositionBySymbol(symbol, side)` | Одна позиция по символу+side. Используется в ExecutionGuardService |
| `getOrderFromHistory(symbol, orderId)` | Детали ордера из `/v5/order/history` (cumExecQty, avgPrice, orderStatus) |
| `getTickerCostInfo(symbol)` | Funding rate, spread %, markPrice, bid1/ask1 — для CostEstimatorService |

**Freshness:** каждая позиция содержит поле `_fetched_at_ms` (unix-мс, когда был получен ответ от Bybit). Используется `RiskGuardService::checkDataFreshness()` перед вызовом LLM.

**HTTP-слой надёжности (`requestWithRetry`):**
- Ретраи с backoff (1s → 2s → 4s, 3 попытки) на сетевые ошибки и HTTP 5xx
- HTTP 429: ждёт `Retry-After`, затем повторяет
- retCode `10006` (rate-limit Bybit): backoff + повтор
- retCode `10002` (timestamp): инвалидирует кеш offset, повтор

**Кеши используют `AtomicFileStorage`:**
- `var/bybit_time_offset.json` — TTL 5 мин
- `var/instrument_cache.json` — TTL 1 ч + in-memory per request

---

### `ChatGPTService`

Управление LLM-запросами. OpenAI (primary), DeepSeek (fallback).

**Версионирование стратегии:**
- `STRATEGY_VERSION = 'manage_v3.2'` — версия промпта с StrategySignals (manage_v3.1 + индикаторы)
- `SCHEMA_VERSION = 'schema_v3'` — версия схемы ответа LLM; при несовпадении → `DO_NOTHING`
- `prompt_checksum` — MD5 (первые 8 символов) промпта для отслеживания изменений
- `canary_mode` — если включён, ВСЕ действия идут в pending, ничего не исполняется автоматически

**Строгий контракт ответа (schema_v3):**

```json
{
  "symbol": "BTCUSDT",
  "action": "CLOSE_FULL|CLOSE_PARTIAL|MOVE_STOP_TO_BREAKEVEN|AVERAGE_IN_ONCE|DO_NOTHING",
  "confidence": 75,
  "reason": "1-3 sentences",
  "risk": "low|medium|high",
  "params": { "close_fraction": 0.3, "average_size_usdt": null },
  "checks": { "pnl_positive": true, "trend": "bearish", "averaging_allowed": false, "strategy_profile": "intraday", "strategy_alignment": true, "regime": "trend" }
}
```

- Обязательные поля: `symbol`, `action`, `confidence`, `reason`, `risk`
- При невалидном JSON или отсутствии обязательных полей → `DO_NOTHING` + `llm_invalid_response` в историю + алерт через `AlertService`

| Метод | Описание |
|---|---|
| `requestLLMRaw(...)` | Возвращает `{content, provider, error}` |
| `getProposals(bybitService)` | Анализирует топ-25 монет, возвращает предложения (confidence ≥ 60) |
| `manageOpenPositions(bybitService, positions, freshnessSec, strategySignalsBySymbol)` | Решения по позициям. strategySignals — индикаторы/режим для LLM |
| `analyzeMarket(symbol, marketData)` | Анализ монеты, сигнал BUY/SELL/HOLD |
| `testConnection()` | Health-check LLM |

---

### Strategy Engine (StrategySignals)

Плагинный слой: вычисляет индикаторы и режим рынка, LLM принимает решение с учётом этих сигналов.

| Сервис | Описание |
|--------|----------|
| `IndicatorService` | RSI, EMA, ATR%, trendStrength, chopScore |
| `StrategyEngineService` | buildSignals(symbol, timeframe, kline) → regime, signals, rules_hint. Читает settings['strategies'] |
| `StrategyProfileService` | selectProfile(timeframe, signals) → scalp \| intraday \| swing \| chop. Использует profile_overrides |

**Профили:** scalp (1–5m), intraday (15–60m), swing (4h–D), chop (при chop_score ≥ threshold из settings).

**Настройки (`strategies` в settings.json):**
- `enabled` — вкл/выкл StrategySignals
- `profile_overrides` — маппинг таймфрейма (1,5,15,60,240,1440) → профиль
- `indicators` — ema (fast, slow), rsi14 (overbought, oversold), atr (period), chop (threshold)
- `rules` — allow_average_in, average_in_block_in_chop, prefer_be_in_trend
- `weights` — trend, mean_reversion, breakout, volatility_penalty (для расширения)

**Формат StrategySignals:**
- `signals`: ema (fast/slow/state/slope), rsi14, atr_pct, breakout, meanReversion, spread_pct
- `regime`: trend, strength, volatility, chop_score
- `rules_hint`: мягкие подсказки (LLM не обязан им следовать)

**Интеграция в тик:** kline → getKlineData → StrategyEngine::buildSignals → StrategyProfile::selectProfile → LLM (manageOpenPositions с blocks).

**Логирование:** event `strategy_signals` в BotHistory (profiles, regime_summary) для canary/отладки.

---

### `PnlStatisticsService`

Агрегирует PnL из closed-pnl / execution list для графиков.

| Метод | Описание |
|-------|----------|
| `getPnlSeries(days, groupBy, symbol, from, to)` | series по дням, bySymbol, totals |

Источник: closed-pnl (до 300), fallback — execution list. Фильтрация по датам.

---

### `SettingsService`

Хранит все настройки в `var/settings.json`. **API-ключи берутся приоритетно из `.env.local`** и никогда не сохраняются в JSON.

**Секции настроек:** `bybit`, `chatgpt`, `deepseek`, `trading`, `alerts`, `strategies` (см. раздел 8).

---

### `BotHistoryService`

История событий бота в `var/bot_history.json`. Все записи атомарны через `AtomicFileStorage::update()`.

**Типы событий:**

| Тип | Описание |
|---|---|
| `bot_tick` | Факт выполнения тика (содержит `run_id`) |
| `llm_failure` | LLM вернул пустые решения при наличии позиций |
| `llm_invalid_response` | Невалидный JSON или нарушение контракта LLM |
| `manual_open` | Ручное открытие пользователем |
| `auto_open` | Автооткрытие ботом |
| `close_full` | Полное закрытие ботом |
| `close_partial` | Частичное закрытие ботом |
| `close_partial_skip` | Пропуск (объём слишком мал) |
| `move_sl_to_be` | Перенос стопа в безубыток |
| `move_sl_to_be_skip` | Пропуск переноса (позиция в убытке) |
| `average_in` | Усреднение позиции |
| `manual_close_full` | Ручное закрытие пользователем |
| `position_lock` | Установка/снятие замка |
| `execution_mismatch` | Фактическое состояние биржи не совпало с ожидаемым после ордера |
| `stale_data_skip` | Тик пропущен — данные позиций старее `max_data_age_sec` |
| `circuit_breaker_reset` | Ручной сброс Circuit Breaker через API/UI (`type`, `reset_by`) |
| `strategy_signals` | Профили и regime_summary, переданные в LLM (для canary/отладки) |

**Ограничения:** 14 дней, максимум 1000 записей.

---

### `RiskGuardService`

Централизованный контроль рисков. Все проверки независимы от LLM.

| Метод | Описание |
|---|---|
| `isTradingEnabled()` | Kill-switch: `trading_enabled` из настроек |
| `checkDailyLossLimit()` | Ежедневный лимит потерь (USDT). Суммирует realized PnL за сегодня |
| `checkMaxExposure(positions)` | Максимальный суммарный риск (маржа). Считает `size × price / leverage` |
| `checkDataFreshness(positions)` | Проверяет актуальность данных по `_fetched_at_ms`. Блокирует тик при устаревших ценах |
| `isActionAllowed(symbol, recentEvents)` | Cooldown между действиями по символу (минуты) |
| `isStrictMode()` | Строгий режим: опасные действия требуют подтверждения |
| `isDangerousAction(action)` | `CLOSE_FULL` и `AVERAGE_IN_ONCE` → опасные |
| `getRiskStatus(positions)` | Сводный статус для UI (все флаги + freshness_check + сообщения) |

---

### `PendingActionsService`

Двухфазное исполнение: опасные действия бота ждут подтверждения пользователя. Активно только при `bot_strict_mode=true`.

**Хранилище:** `var/pending_actions.json`, TTL 60 минут. Все операции атомарны через `AtomicFileStorage::update()`.

| Метод | Описание |
|---|---|
| `getAll()` | Все не-просроченные записи |
| `add(action)` | Добавить ожидающее действие, вернуть `id` |
| `resolve(id, confirm)` | Подтвердить или отклонить, вернуть запись |
| `hasPending(symbol, action)` | Проверить наличие дублей |

---

### `PositionLockService`

Замки на позиции в `var/position_locks.json`. Заблокированные позиции бот не трогает.

**Ключ:** `"SYMBOL|Side"` (например, `"BTCUSDT|Buy"`). Мутации атомарны через `AtomicFileStorage::update()`. In-memory кеш обновляется после каждой записи.

---

### `AlertService`

Уведомления в Telegram и/или generic webhook (Slack, Discord и т.д.).

**Настройки** (`settings['alerts']`):
- `telegram_bot_token`, `telegram_chat_id`
- `webhook_url`
- Флаги: `on_llm_failure`, `on_invalid_response`, `on_risk_limit`, `on_bybit_error`, `on_repeated_failures`
- `repeated_failure_threshold` — порог повторяющихся ошибок подряд
- `repeated_failure_cooldown_minutes` — не дублировать алерт по одному символу чаще чем раз в N мин (default: 60)
- `update_enabled` — включить периодические сводки в Telegram
- `update_interval_minutes` — интервал сводок (min 15, default: 60)

**Методы:** `alertLLMFailure()`, `alertInvalidResponse()`, `alertRiskLimit()`, `alertBybitError()`, `alertRepeatedFailures()`, `send(level, message, context)`, `sendRawText(token, chatId, text)`.

**Периодические сводки:** команда `app:telegram-periodic-update` — раз в N минут отправляет сводку: баланс, изменение баланса, что открылось/закрылось. Cron: `*/15 * * * * php bin/console app:telegram-periodic-update` или с `--profile-id=1` для конкретного профиля.

---

### `BotMetricsService`

Агрегирует метрики из `BotHistoryService` без дополнительного хранилища.

**`getMetrics(days=30)`** возвращает:
- `tick_count`, `llm_failures`, `invalid_responses`
- `proposed` / `executed` / `skipped` / `failed` / `execution_rate_pct`
- `by_action` — разбивка по типам с `wins`, `losses`, `win_rate`, `total_pnl`
- `skip_reasons` — частота каждой причины пропуска

**`getRecentDecisions(limit=100)`** — последние события с полным trace-данными для UI.

**`getLastDecisionPerPosition()`** — последнее LLM-решение per `symbol|side` для колонки "Почему?" в таблице позиций.

---

### `CostEstimatorService`

Оценка издержек: fees (0.06%/side), slippage (половинный spread), funding (8h horizon). Используется для проверки min_edge перед закрытием.

| Метод | Описание |
|---|---|
| `estimateTotalCost(notionalUsdt, symbol, isOpen, holdingHours)` | Возвращает `fees_usdt`, `slippage_usdt`, `funding_usdt`, `total_usdt` |
| `checkMinimumEdge(estimatedEdgeUsdt, totalCostUsdt)` | Блокирует, если edge < cost × `min_edge_multiplier` (default 2x) |

**Настройка:** `min_edge_multiplier` (trading). 0 = отключить проверку.

---

### `ExecutionGuardService`

Post-check верификатор: после каждого реально исполненного действия бота проверяет, что биржа пришла в ожидаемое состояние.

**Механизм:** ждёт 2 секунды (рыночные ордера заполняются <1с), затем запрашивает актуальную позицию и историю ордера.

| Метод | Проверяет |
|---|---|
| `verifyClose(symbol, side, sizeBefore, fraction, orderResult)` | Размер позиции уменьшился на ожидаемую долю. При CLOSE_FULL — позиция исчезла |
| `verifyOpen(symbol, side, sizeBefore, orderResult)` | Размер позиции увеличился (open / average_in) |
| `verifyStopLoss(symbol, side, expectedSL)` | Поле `stopLoss` позиции совпадает с entry-ценой (точность 0.5%) |

**Возвращаемые поля** (всегда добавляются в payload события):
- `ok`, `mismatch` — результат верификации
- `requestedQty`, `executedQty` — запрошено vs фактически исполнено (из `/v5/order/history`)
- `avgPrice` — средняя цена исполнения
- `orderId`, `orderStatus` — данные ордера от Bybit
- `sizeBefore`, `sizeAfter` — размер позиции до и после
- `message` — человекочитаемый вердикт

**При mismatch:** автоматически записывает событие `execution_mismatch` в историю бота + отправляет алерт через `AlertService::alertBybitError()`.

---

### `CircuitBreakerService`

Автоматически приостанавливает торговлю при серии однотипных сбоев. Состояние хранится в `var/circuit_breaker.json` (TTL-based, atomic I/O).

**Три независимых автомата:**
| Тип | Источник сбоев | Порог по умолч. |
|---|---|---|
| `bybit` | Ошибки Bybit API (`alertBybitError`) | 5 подряд |
| `llm` | Сбои LLM — нет ответа / таймаут (`alertLLMFailure`) | 3 подряд |
| `llm_invalid` | Невалидные ответы LLM — плохой JSON/схема (`alertInvalidResponse`) | 5 подряд |

**Жизненный цикл:**
- `CLOSED` (норма) → накапливаются ошибки
- `OPEN` → срабатывает порог → торговля заблокирована на `cb_cooldown_minutes` (по умолч. 30 мин)
- Автоматически возвращается в CLOSED после истечения TTL, или вручную через API/UI

**Методы:**
- `recordFailure(type, reason)` — фиксирует сбой; возвращает `true`, если цепь только что разомкнулась
- `recordSuccess(type)` — сбрасывает счётчик consecutive (пока TTL не активен)
- `isOpen(): bool` — возвращает `true`, если хоть один автомат открыт
- `getStatus(): array` — полный статус для API/UI (включая `remaining_sec`, `reason`, счётчики)
- `reset(?type)` — ручной сброс одного или всех автоматов

**Интеграция:**
- `AlertService` вызывает `recordFailure()` автоматически при каждом `alertBybitError`, `alertLLMFailure`, `alertInvalidResponse`
- `ApiController::botTick()` и `BotTickCommand::runTick()` проверяют `isOpen()` сразу после kill-switch
- При блокировке возвращается ответ `{ ok: false, blocked: true, reason: "circuit_breaker", message: "Paused by circuit breaker: ..." }`

**Настройки** (в секции `trading` → `var/settings.json`):
- `cb_enabled` — bool, включить/выключить (default: `true`)
- `cb_bybit_threshold` — порог ошибок Bybit (default: `5`)
- `cb_llm_threshold` — порог сбоев LLM (default: `3`)
- `cb_llm_invalid_threshold` — порог невалидных ответов (default: `5`)
- `cb_cooldown_minutes` — длительность паузы (default: `30`)

---

### `LogSanitizer`

Статический хелпер. Редактирует API-ключи в логах: `sk-proj-AbCd****[REDACTED]`.

---

## 5. Контроллеры (Controllers)

### `SecurityController`
- `GET/POST /login` → форма входа (CSRF)
- `GET /logout` → выход

### `DashboardController`
- `/` → `dashboard.html.twig` (принимает `?page=dashboard|bot|history`)
- `/settings` → `settings.html.twig`

### `ApiController`
Все эндпоинты — см. раздел 7.

---

## 6. Фронтенд

### Дизайн-система (`style.css`)

CSS построен на custom properties:

```css
:root {
  --brand-navy:   #1B2A41;   /* основной тёмно-синий */
  --brand-navy-2: #0F1B2D;   /* темнее для хедера */
  --brand-orange: #F59E0B;   /* оранжевый акцент */
  --bg:           #0B1220;   /* фон страниц */
  --panel:        #0F1B2D;   /* карточки/таблицы */
  --panel-2:      #111A2E;   /* th, inputs, вложенные блоки */
  --border:       rgba(255,255,255,0.08);
  --positive:     #10B981;
  --negative:     #EF4444;
  --warning:      #F59E0B;
}
```

**Кнопки:** `btn-primary` (оранжевый), `btn-secondary` (navy), `btn-danger`, `btn-success`, `btn-small` + иконочные варианты `btn-icon-lock` / `btn-icon-danger`.

**Бейджи позиций:** `.side-long` (зелёный outline), `.side-short` (красный), `.lock-badge` (серый).

**Bot alert:** `.bot-alert.success/warning/error` — структурированный блок с иконкой и опциональным `<details>` для raw-данных.

### Navbar (`base.html.twig`)
- Логотип в белом «пилюле»-контейнере (оригинальные цвета, нет filter)
- Оранжевая нижняя полоска (3px `--brand-orange`)
- Bootstrap Icons 1.11 (CDN) на всех nav-ссылках
- Footer: иконка BT + описание проекта + технический стек

### `public/assets/js/app.js`

| Функция | Описание |
|---|---|
| `loadDashboard()` | Загружает все секции: баланс (вкл. Текущий PnL), статистику, позиции (маржа), ордера, сделки, историю бота, метрики, PnL charts |
| `loadPositions()` | Таблица позиций (колонка Маржа), обновляет «В позициях (маржа)» |
| `runBotTick()` | Запускает `/api/bot/tick`, показывает `.bot-alert` в `#bot-status-message` |
| `renderWhyBadge(decision)` | Бейдж в колонке "Почему?": action, confidence, risk, override |
| `loadBotMetrics()` | Загружает `/api/bot/metrics`, вызывает `renderBotMetrics()` |
| `renderBotMetrics(m)` | Рендер карточек метрик, таблицы по action-типам, skip-тегов |
| `loadBotDecisions()` | Загружает `/api/bot/decisions`, вызывает `renderDecisionsTable()` |
| `renderDecisionsTable(data)` | Таблица трассировки LLM-решений |
| `loadOrders()` | Открытые ордера |
| `loadTrades()` | История сделок |
| `loadBotHistory()` | История событий бота (50 событий, 7 дней) |
| `switchDashboardPage(page)` | Переключение страниц по `data-page` атрибуту |
| `loadPnlCharts()` | GET /api/statistics/pnl, рендер line (daily PnL) и bar (by symbol) через Chart.js |
| `renderPnlLineChart(series)` | Line chart: Daily PnL USDT |
| `renderPnlBarChart(bySymbol)` | Bar chart: PnL по символам (Top 10) |

**Chart.js** 4.4.0 (CDN) — лёгкая библиотека для line/bar. Фильтры: Period 7/30/90, Symbol (All + топ из bySymbol).

### `public/assets/js/settings.js`
- Загрузка/сохранение: Bybit, ChatGPT, DeepSeek, Trading, Risk Guards, Strategy Signals, Alerts
- Тест-алерт через `POST /api/alerts/test`
- Обработчик 401 (редирект на `/login`)

---

## 7. API Endpoints

Все эндпоинты: `GET/POST /api/...` Требуют авторизации.

| Метод | URL | Описание |
|---|---|---|
| GET | `/api/positions` | Открытые позиции (с `locked`, `lastDecision`) |
| GET | `/api/position-plans` | Планы Rotational Grid (слои, уровни, anchor) |
| GET | `/api/orders` | Открытые ордера (query: `symbol`) |
| GET | `/api/trades` | История исполнений (query: `limit`) |
| GET | `/api/closed-trades` | Закрытые позиции (query: `limit`) |
| GET | `/api/statistics` | Торговая статистика |
| GET | `/api/statistics/pnl` | PnL агрегаты: series (по дням), bySymbol. Query: days, groupBy, symbol, from, to |
| GET | `/api/balance` | Баланс кошелька (walletBalance, availableBalance, unrealisedPnl) |
| GET | `/api/market/top` | Топ монет (query: `limit`, `category`) |
| GET | `/api/market-data/{symbol}` | Данные по символу |
| GET | `/api/market-analysis/{symbol}` | LLM-анализ монеты |
| GET | `/api/analysis/proposals` | LLM-предложения по сделкам |
| POST | `/api/bot/tick` | Запуск тика бота. Возвращает `run_id`, `managed[]`, `opened[]` |
| GET | `/api/bot/history` | История событий бота (50, 7 дней) |
| GET | `/api/bot/metrics` | Агрегированные метрики LLM (query: `days`) |
| GET | `/api/bot/decisions` | Детальная трасса LLM-решений (query: `limit`) |
| GET | `/api/bot/runs` | История запусков тика (query: `limit`). Для диагностики |
| GET | `/api/bot/circuit-breaker` | Статус всех CB-автоматов (consecutive, tripped_at, remaining_sec) |
| POST | `/api/bot/circuit-breaker/reset` | Ручной сброс. Body: `{"type":"bybit"|"llm"|"llm_invalid"}` или `{}` для всех |
| GET | `/api/risk/status` | Статус всех риск-гардов |
| GET | `/api/pending-actions` | Действия ожидающие подтверждения |
| POST | `/api/pending-actions/{id}/confirm` | Подтвердить действие |
| POST | `/api/pending-actions/{id}/reject` | Отклонить действие |
| POST | `/api/order/open` | Открытие ордера |
| POST | `/api/position/close` | Ручное закрытие позиции |
| POST | `/api/position/lock` | Установка/снятие замка |
| GET | `/api/settings` | Получить все настройки |
| POST | `/api/settings` | Обновить настройки. Body: `{ bybit?, chatgpt?, trading?, alerts?, strategies? }` |
| GET | `/api/test/bybit` | Проверить подключение к Bybit |
| GET | `/api/test/chatgpt` | Проверить подключение к LLM |
| POST | `/api/alerts/test` | Отправить тестовый алерт |

---

## 8. Настройки

### Bybit API
`api_key`, `api_secret`, `testnet` (checkbox), `base_url`

### ChatGPT API
`api_key`, `model` (gpt-4o / gpt-4 / gpt-3.5-turbo), `timeout` (сек, 15–300, default: 60), `enabled`

### DeepSeek API (fallback)
`api_key`, `model` (deepseek-chat / deepseek-reasoner), `timeout` (сек, 15–300, default: 120), `enabled`

### Торговые параметры
| Параметр | Описание |
|---|---|
| `max_position_usdt` | Максимальная маржа (залог) на одну позицию в USDT (default: 100). Размер сделки = маржа × плечо. |
| `min_position_usdt` | Минимальная маржа (залог) в USDT (default: 10). Не открывать и не делать частичное закрытие, если маржа ниже этой суммы. |
| `min_leverage` / `max_leverage` | Диапазон плеча для LLM |
| `aggressiveness` | conservative / balanced / aggressive |
| `required_margin_mode` | auto / cross / isolated. Для cross/isolated — ensureMarginMode перед ордером; auto — без проверки |
| `max_managed_positions` | Максимум позиций под управлением (default: 10) |
| `auto_open_min_positions` | Порог для автооткрытия (default: 5) |
| `auto_open_enabled` | Разрешить боту открывать новые сделки |
| `bot_timeframe` | Таймфрейм решений: 1/5/15/30/60/240/1440 мин |
| `bot_history_candles` | Свечей истории для LLM (5–60) |

### Strategy Signals
| Параметр | Описание |
|----------|----------|
| `enabled` | Включить StrategySignals (default: true) |
| `profile_overrides` | {"1":"scalp","5":"scalp","15":"intraday","60":"intraday","240":"swing","1440":"swing"} |
| `indicators` | ema (fast, slow), rsi14 (overbought, oversold), atr (period), chop (threshold) |
| `rules` | allow_average_in, average_in_block_in_chop, prefer_be_in_trend |
| `weights` | trend, mean_reversion, breakout, volatility_penalty (расширение) |

### Risk Guards
| Параметр | Описание |
|---|---|
| `trading_enabled` | Kill-switch: false = все торговые операции заблокированы |
| `daily_loss_limit_usdt` | Лимит потерь за день. 0 = отключено |
| `max_total_exposure_usdt` | Макс. суммарный залог (notional/leverage). 0 = отключено |
| `action_cooldown_minutes` | Cooldown между действиями по одному символу. 0 = без ограничений |
| `bot_strict_mode` | Двухфазное исполнение: CLOSE_FULL и AVERAGE_IN требуют подтверждения |
| `canary_mode` | Safe rollout: ВСЕ действия бота идут в pending. Включать при смене стратегии/промпта |
| `min_edge_multiplier` | Min edge = costs × этот множитель. 0 = отключено (default: 2) |
| `max_data_age_sec` | Макс. допустимый возраст данных позиций в секундах. 0 = отключено (default: 30) |
| `cb_enabled` | Включить/выключить circuit breaker (default: `true`) |
| `cb_bybit_threshold` | Порог ошибок Bybit для срабатывания (default: `5`) |
| `cb_llm_threshold` | Порог сбоев LLM для срабатывания (default: `3`) |
| `cb_llm_invalid_threshold` | Порог невалидных LLM-ответов (default: `5`) |
| `cb_cooldown_minutes` | Длительность паузы после срабатывания в минутах (default: `30`) |

### Алерты (Telegram / Webhook)
| Параметр | Описание |
|---|---|
| `telegram_bot_token` | Token Telegram-бота |
| `telegram_chat_id` | ID чата/группы |
| `webhook_url` | URL для Slack / Discord / custom |
| `on_llm_failure` | Уведомлять при сбоях LLM |
| `on_invalid_response` | Уведомлять при невалидном ответе LLM |
| `on_risk_limit` | Уведомлять при превышении риск-лимитов |
| `on_bybit_error` | Уведомлять об ошибках Bybit API |
| `on_repeated_failures` | Уведомлять о повторяющихся ошибках |
| `repeated_failure_threshold` | Порог consecutive failures (default: 3) |

---

## 9. Бот: логика принятия решений

### Запуск

Бот запускается через `POST /api/bot/tick` — вручную (кнопка в UI) или по расписанию (cron, Task Scheduler).

**Рекомендуемый cron (каждую минуту):**
```bash
* * * * * /usr/bin/curl -s -X POST http://localhost/api/bot/tick
```

Предпочтительно вызывать `POST /api/bot/tick` через curl — запрос обрабатывает веб‑сервер (www-data), права на `var/` совпадают.

**Если cron запускает `php bin/console app:bot-tick` под другим пользователем (например palki):**

Web‑сервер (www-data) и cron (palki) пишут в один и тот же каталог `var/`. Если права на `var/` заданы только для www-data, cron не сможет записывать `bot_history.json`, `bot_runs.json` и т.п. — статистика не обновится, хотя тик выполнится.

**Варианты решения:**

1. **Запускать cron от www-data** (самый простой путь):
   ```bash
   * * * * * www-data cd /path/to/bybit_trader && php bin/console app:bot-tick
   ```
   Или через `runuser`:
   ```bash
   * * * * * runuser -u www-data -- bash -c 'cd /path/to/bybit_trader && php bin/console app:bot-tick'
   ```

2. **Общие права на `var/` для www-data и palki**:
   ```bash
   sudo chown -R www-data:www-data /path/to/bybit_trader/var
   sudo chmod -R 775 /path/to/bybit_trader/var
   sudo usermod -aG www-data palki
   # Перелогинься в palki, чтобы группа применилась
   ```

3. **Отдельный каталог через VAR_DIR** (если `var/` в проекте менять нельзя):
   ```bash
   sudo mkdir -p /var/lib/bybit_trader
   sudo chown www-data:palki /var/lib/bybit_trader
   sudo chmod 775 /var/lib/bybit_trader
   ```
   В `.env` или в crontab:
   ```
   VAR_DIR=/var/lib/bybit_trader
   ```
   И web, и cron должны видеть этот `VAR_DIR` и использовать его для всех JSON в `var/`.

### Алгоритм тика

```
1. Kill-switch: trading_enabled = false → вернуть blocked
2. Circuit Breaker: CircuitBreakerService::isOpen() = true → вернуть blocked (reason: circuit_breaker)
3. Daily loss limit: checkDailyLossLimit() → если нарушен → вернуть blocked + alertRiskLimit
4. Idempotency: BotRunService::tryStart(botTimeframe)
   ├── null  → bucket уже выполнен/выполняется → вернуть skipped
   └── runId → продолжить
5. Загрузить текущие позиции из Bybit
6. Обогатить позиции: getKlineData() → priceHistory (строка) + klineRaw (closes, highs, lows)
7. Strategy signals:
   7.1) StrategyEngineService::buildSignals(symbol, timeframe, klineRaw) → regime, signals, rules_hint
   7.2) StrategyProfileService::selectProfile(timeframe, signals) → scalp|intraday|swing|chop
8. Data Freshness: RiskGuardService::checkDataFreshness() → если stale → log(stale_data_skip) + alertRiskLimit + finish(skipped)
9. **Rotational Grid** (если position_mode=rotational_grid): для каждой позиции — createPlan при первом входе; shouldAddLayer → placeOrder; shouldUnloadLayer → closePositionMarket(fraction). Позиции в rotational пропускаются в LLM (skip_reason: rotational_grid).
10. LLM: ChatGPTService::manageOpenPositions(positions, strategySignalsBySymbol) → список решений
   └── если пустой при наличии позиций → log(llm_failure)
10. По каждому решению LLM:
   ├── action = DO_NOTHING → пропустить
   ├── position not found → пропустить
   ├── isLocked(symbol, side) → skip(locked)
   ├── !isActionAllowed(symbol) → skip(cooldown)
   ├── canary_mode=true → ВСЕ действия → pendingAction → skip(canary_mode)
   ├── isStrictMode() + isDangerousAction() → add pendingAction → skip(strict_mode_pending)
   ├── CLOSE: CostEstimatorService::checkMinimumEdge(edge, cost) → если fail → skip(min_edge)
   └── execute + ExecutionGuard (post-check 2s):
       ├── CLOSE_FULL           → closePositionMarket(1.0)    → verifyClose()
       ├── CLOSE_PARTIAL        → closePositionMarket(fraction) → verifyClose()
       ├── MOVE_STOP_TO_BREAKEVEN → только если PnL > 0       → verifyStopLoss()
       └── AVERAGE_IN_ONCE      → только если !alreadyAveraged[7 days] → verifyOpen()
11. Автооткрытие (если auto_open_enabled):
    ├── checkMaxExposure → если нарушен → alertRiskLimit, slots=0
    ├── slots = min(minPositions - openCount, maxManaged - openCount)
    └── getProposals → открыть с confidence >= 80%
12. log(bot_tick, {run_id, managedCount, openedCount, timeframe, data_freshness_sec})
13. BotRunService::finish(runId, 'done')
14. Вернуть {ok, run_id, summary, managed[], opened[]}
```

### Трасса решений (LLM observability)

Каждое выполненное событие содержит:
- `confidence` (0-100), `reason`, `risk` (low/medium/high)
- `checks` (pnl_positive, trend, averaging_allowed, strategy_profile, strategy_alignment, regime)
- `prompt_version` (`manage_v3.2`), `schema_version` (`schema_v3`), `prompt_checksum`, `provider` (chatgpt/deepseek)
- `skip_reason` (если пропущено: `locked` / `cooldown` / `min_edge` / `canary_mode` / `strict_mode_pending` / `already_averaged`)
- `pnlAtDecision`, `realizedPnlEstimate`

---

## 10. История цен и таймфреймы

**Источник:** Bybit `/v5/market/kline` — публичный эндпоинт.

При каждом тике вызывается `getKlineHistory()` — мгновенный доступ к OHLCV без периода накопления.

**Маппинг таймфреймов:**

| Настройка (мин) | Bybit interval |
|---|---|
| 1 | `1` |
| 5 | `5` |
| 15 | `15` |
| 30 | `30` |
| 60 | `60` |
| 240 | `240` |
| 1440 | `D` |

**Компактный формат для LLM:**
```
[60 5m candles | open=68000 close=69200 min=67000 max=69500 trend=UP] closes:67800,68100,...
```

---

## 11. Токен-бюджет LLM

Целевой максимум промпта: **14 000 символов ≈ 3 500 токенов**.
Лимит ответа: **2 000 токенов**.

**Алгоритм:**
1. Фиксированные части (~2 200 символов): инструкции + история бота
2. На каждую позицию базово ~120 символов
3. Остаток / позиции / 8 = `maxPricePoints` (min=5, max=30)
4. Fallback: если > 14 000 — история цен убирается полностью

---

## 12. Интеграция с Bybit API v5

**Base URL:** testnet `https://api-testnet.bybit.com` / mainnet `https://api.bybit.com`

### Аутентификация
```
offset    = serverTime − localTime   // GET /v5/market/time, кеш 5 мин
timestamp = now_ms + offset
signStr   = timestamp + api_key + recvWindow + queryString (GET) / body (POST)
signature = HMAC_SHA256(signStr, api_secret)
Headers: X-BAPI-API-KEY, X-BAPI-SIGN, X-BAPI-SIGN-TYPE=2, X-BAPI-TIMESTAMP, X-BAPI-RECV-WINDOW=20000
```

### Слой надёжности

| Механизм | Поведение |
|---|---|
| **Retry + backoff** | До 3 попыток; 1s → 2s → 4s. Сетевые ошибки и HTTP 5xx |
| **Rate-limit (HTTP 429)** | Читает `Retry-After`, ждёт (мин 1с, макс 30с) |
| **Rate-limit (retCode 10006)** | Bybit-уровень; backoff + повтор |
| **Timestamp re-sync** | retCode 10002 → инвалидация offset-кеша + повтор |
| **Instrument cache invalidation** | qty-ошибки (110017, 110009, 170036, 170037, 110043) → сброс кеша |

### Символы

| Правило | Пример |
|---|---|
| `*PERP` → `*USDT` для kline/market-data | `BTCPERP` → `BTCUSDT` |
| Dated-контракты фильтруются | `MNTUSDT-13MAR26` → исключён |
| Дедупликация: предпочтение `*PERP` на testnet | `BTCPERP` выигрывает у `BTCUSDT` |

### Расчёт qty
```
rawQty = positionSizeUSDT / price
qty    = floor(rawQty / qtyStep) * qtyStep
```
Если `qty < minOrderQty` → ошибка с указанием минимальной суммы в USDT.

### UTA: Margin Mode (account-level)

Для Unified Trading Account (UTA) режим маржи задаётся **на уровне аккаунта**, а не позиции.

| Источник | Описание |
|---|---|
| `GET /v5/account/info` | Возвращает `marginMode`: REGULAR_MARGIN (Cross), ISOLATED_MARGIN, PORTFOLIO_MARGIN |
| `POST /v5/account/set-margin-mode` | Переключение режима (body: `{"setMarginMode": "REGULAR_MARGIN"}` и т.д.) |
| `position/list.tradeMode` | **Deprecated** — для определения режима использовать account info |

**Switch Cross/Isolated Margin** (старый per-symbol endpoint) для UTA **no longer applicable** — Bybit рекомендует account-level API.

**placeOrder flow:** `ensureMarginMode(account-level) → setLeverage → createOrder → verifyOpen`. Не вызывается switch-isolated.

**Cross margin:** Bybit требует `buyLeverage = sellLeverage` — в коде соблюдается (одинаковое значение в set-leverage).

**Настройка `required_margin_mode`:** `auto` — без проверки; `cross` — guard на REGULAR_MARGIN; `isolated` — guard на ISOLATED_MARGIN.

---

## 13. Интеграция с LLM

### Порядок вызова
1. ChatGPT включён + api_key задан → пробуем ChatGPT
2. Недоступен → пробуем DeepSeek
3. Оба недоступны → null / mock + `alertLLMFailure()`

### Параметры

| Метод | temperature | max_tokens |
|---|---|---|
| `getProposals` | 0.5 | 1500 |
| `manageOpenPositions` | 0.4 | 2000 |
| `analyzeMarket` | 0.7 | 500 |
| `testConnection` | 0.1 | 10 |

---

## 14. Cron / ручной запуск бота

### Рекомендуемый способ: Symfony Console команда

```
src/Command/BotTickCommand.php  →  php bin/console app:bot-tick
```

**Запуск вручную (проверка):**
```bash
cd /home/bybit/bybit-trader
php bin/console app:bot-tick
```

**С флагом `--force`** — пропускает idempotency-проверку (полезно при отладке):
```bash
php bin/console app:bot-tick --force
```

**Настройка cron (каждую минуту):**
```bash
crontab -e
```
```
* * * * * /usr/bin/php /home/bybit/bybit-trader/bin/console app:bot-tick >> /home/bybit/bot_cron.log 2>&1
```

**Ротация лога** (добавить в `/etc/logrotate.d/bybit-bot`):
```
/home/bybit/bot_cron.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
}
```

### Вывод команды

Команда выводит цветной прогресс в stdout:
```
Bybit Trader — Bot Tick
========================
Run ID: 01HZ...  Timeframe: 5m
Open positions: 2

Managing open positions…
  [OK] BTCUSDT Buy → MOVE_STOP_TO_BREAKEVEN
  [SKIP locked] ETHUSDT Sell → CLOSE_FULL (locked)

Auto-open disabled.

[OK] Done. Managed: 1, Opened: 0.
```

Коды выхода: `0` — успех или пропущен (skip), `1` — исключение.

### Идемпотентность

`BotRunService` гарантирует выполнение тика не чаще одного раза на timeframe-окно. При запуске каждую минуту с `bot_timeframe=5` — реально отрабатывает раз в 5 минут, остальные 4 запуска тихо завершаются с `Skipped`.

### Диагностика запусков

`GET /api/bot/runs` — последние 30 записей: `run_id`, `timeframe_bucket`, `status`, `started_at`, `finished_at`.

### Windows Task Scheduler (альтернатива)

```
powershell -Command "Invoke-WebRequest -Uri 'http://localhost/api/bot/tick' -Method POST"
```

> На продакшн-сервере рекомендуется использовать Console команду через cron, а не HTTP-эндпоинт — не требует сессии, логируется напрямую, работает без Apache.

---

## 15. Данные и хранилище

Все JSON-файлы защищены от гонок через `AtomicFileStorage` (flock + temp-rename).

| Файл | Описание | Лимит |
|---|---|---|
| `var/settings.json` | Настройки (без API-ключей) | — |
| `var/bot_history.json` | История событий бота | 14 дней, 1 000 записей |
| `var/bot_runs.json` | История запусков тика (идемпотентность) | 200 записей |
| `var/position_locks.json` | Заблокированные позиции | — |
| `var/position_plans.json` | Планы Rotational Grid | — |
| `var/pending_actions.json` | Ожидают подтверждения (strict mode) | TTL 60 мин |
| `var/circuit_breaker.json` | Состояние CB-автоматов (consecutive, tripped_at, cooldown_until) | TTL = cooldown_minutes |
| `var/bybit_time_offset.json` | Кеш сдвига часов с Bybit | TTL 5 мин |
| `var/instrument_cache.json` | Кеш параметров инструментов | TTL 1 ч / запись |

История цен — **на стороне Bybit** (`/v5/market/kline`), локально не хранится.

Все файлы создаются автоматически. `var/*.lock` — служебные lock-файлы, создаются рядом с каждым JSON.

---

## 16. Безопасность

### Аутентификация
- Symfony Security `form_login`, сессионная авторизация
- CSRF-защита формы входа
- Все `/api/*` и `/settings` требуют активной сессии → редирект на `/login`

**`.env.local`:**
```dotenv
APP_AUTH_USER=admin
# Одинарные кавычки ОБЯЗАТЕЛЬНЫ (bcrypt-хеш содержит $)
APP_AUTH_PASSWORD_HASHED='$2y$13$...'
```

**Смена пароля:**
```bash
php bin/console security:hash-password ВАШ_ПАРОЛЬ
```

### API-ключи
Хранятся только в `.env.local`, не попадают в `settings.json`:
```dotenv
BYBIT_API_KEY=...
BYBIT_API_SECRET=...
CHATGPT_API_KEY=...
DEEPSEEK_API_KEY=...
```

### Что НЕ коммитится
```
.env.local       ← реальные ключи и пароль
var/             ← данные приложения
vendor/          ← зависимости Composer
```

---

## 17. Известные ограничения и особенности

**Testnet:**
- `BTCUSDT` на testnet — ненастоящие цены; `BTCPERP` корректнее
- `liqPrice` пустой для UTA-аккаунтов
- Bybit testnet часто не возвращает closed PnL (пустой список) → статистика берётся из execution/list; при пустоте показывается: «Statistics not available on testnet...»

**Статистика (источники данных):**
- Приоритет: `closed-pnl` (до 200) → `execution/list` filtered (200) → `execution/list` all (500)
- Все запросы: `category=linear`, `settleCoin=USDT`
- `/api/statistics` возвращает диагностику: `source` (closedPnl|closedTrades|trades|empty), `closedTradesCount`, `tradesCount`, `bybitRetCode`, `bybitRetMsg`, `note`
- В логи пишутся raw JSON первых 1–2 ответов (closed-pnl, execution/list, position/list, wallet-balance) для отладки

**LLM:**
- При невалидном JSON или нарушении контракта → автоматически `DO_NOTHING` + алерт
- Если оба LLM недоступны в тик — `llm_failure` пишется в историю

**Атомарность:**
- `.lock`-файлы остаются рядом с JSON — это нормально, не удалять
- На Windows `rename()` над существующим файлом атомарен (NTFS)

**Усреднение:**
- `AVERAGE_IN_ONCE` — не чаще 1 раза в 7 дней на символ

**TradingView:**
- Некоторые символы (1000PEPEUSDT) не поддерживаются виджетом — ожидаемо

**Комиссии и издержки:**
- Fees ~0.06%/side (Bybit linear taker). Учитываются в CostEstimatorService.
- Slippage: half-spread из bid1/ask1. Funding: rate × notional × horizon.
- Min-edge check: закрытие блокируется, если PnL < (fees+slippage+funding) × `min_edge_multiplier`.
- `totalFees` в `/api/statistics` — сумма `execFee` по execution list (если Bybit возвращает).
- UI показывает блок «Источник» под статистикой: source, closed/trades count, retCode при ошибке, note (например, «Statistics not available on testnet»).

---

## 18. Rotational Grid Mode (планируется)

Режим ведения позиции, при котором бот работает с **фиксированным количеством слоёв** на **постоянной сетке уровней**. Альтернатива простому усреднению: позиция не растёт бесконечно, а держится в коридоре; при отскоках цены нижние слои разгружаются, освобождённые слоты переоткрываются ниже.

### Главный принцип

> Позиция состоит из фиксированного числа слоёв.  
> Слои открываются на уровнях сетки при движении против позиции.  
> При возврате цены к вышестоящим уровням нижние слои частично разгружаются.  
> Освобождённые слои могут быть повторно открыты ниже.

### Три сущности

| Сущность | Описание |
|---------|----------|
| **Базовая сетка уровней** | L0, L1, L2, L3… (шаг 5%, ATR или AI). Уровни существуют постоянно |
| **Слоты позиции** | slot1, slot2, slot3… — максимум N одинаковых частей (layer_size_usdt каждая) |
| **Правила ротации** | При отскоке вверх — закрыть один нижний слот; при новом провале — открыть свободный слот ниже |

### Пример цикла (long, 3 слоя)

```
10 — открыт слой A (базовый)
9  — открыт слой B
8  — открыт слой C  → 3/3 занято

Цена отскакивает на 9 → закрываем слой C (самый нижний)
Остаются A + B

Цена снова падает на 7 → открываем слой C уже на 7

Отскок на 8 → закрываем слой C с 7
```

Средняя цена входа постепенно улучшается, объём не раздувается.

### Логика бота

| Действие | Условие |
|----------|---------|
| **Открытие** | Создать базовый слой на первом сигнале, построить сетку уровней вверх/вниз |
| **Добор** | Цена дошла до следующего уровня и есть свободный слот → открыть слой |
| **Разгрузка** | Цена вернулась на уровень выше и есть слой, открытый ниже → закрыть один слой (по умолчанию самый нижний) |
| **Повторный добор** | После разгрузки цена снова уходит глубже → открыть свободный слой на новом нижнем уровне |

### Ограничения (обязательны)

| Ограничение | Описание |
|-------------|----------|
| **max_layers** | Максимум активных слоёв (напр. 3) |
| **layer_size_usdt** | Размер одного слоя в USDT (или % от депозита) |
| **grid_step_pct** | Минимальная дистанция между уровнями (напр. 3–5%), чтобы не реагировать на шум |
| **grid_step_atr_mult** | Альтернатива: шаг от ATR (напр. 0.8× ATR) |
| **rotation_allowed** | Режим рынка: лучше во флэте/пилообразе; хуже в сильном импульсе без откатов |

### Роль AI vs GridEngine

| Компонент | Ответственность |
|-----------|-----------------|
| **StrategyEngine / GridEngine** | Механика: где уровни, когда открыть/закрыть слой |
| **LLM** | Стратегия: разрешать ли rotational mode, шаг сетки, max_layers, остановить доборы, закрыть всё при сломе рынка |

LLM — не для каждого ордера, а для режима. Надёжнее.

### Модель данных (план позиции)

```json
{
  "symbol": "BTCUSDT",
  "side": "Buy",
  "mode": "rotational_grid",
  "max_layers": 3,
  "layer_size_usdt": 50,
  "grid_step_pct": 5,
  "anchor_price": 10.0,
  "levels": [10, 9, 8, 7, 6],
  "active_layers": [
    {"layer_id": "A", "entry_level": 10, "entry_price": 10.0, "status": "open"},
    {"layer_id": "B", "entry_level": 9, "entry_price": 9.0, "status": "open"},
    {"layer_id": "C", "entry_level": 7, "entry_price": 7.0, "status": "open"}
  ]
}
```

### Настройки (предполагаемые)

| Параметр | Описание |
|----------|----------|
| `position_mode` | `single` \| `staged` \| `rotational_grid` |
| `max_layers` | Максимум активных слоёв |
| `layer_size_usdt` | Размер одного слоя |
| `grid_step_pct` | Шаг сетки в % |
| `grid_reentry_enabled` | Разрешить повторный добор после разгрузки |
| `unload_on_reclaim_level` | Закрывать 1 слой при возврате на уровень выше |
| `base_layer_persistent` | Базовый слой не трогать при разгрузке |
| `rotation_allowed_in_trend` | Разрешить ротацию в тренде |
| `rotation_allowed_in_chop` | Разрешить ротацию во флэте |

### UI (график)

- Все уровни сетки
- Активные / свободные уровни
- Текущая средняя цена позиции
- Next buy level / next unload level
- Занятость слоёв: 2/3 или 3/3
- Цвета: зелёный — разгрузка, синий/жёлтый — добор, маркеры — цена и средняя
