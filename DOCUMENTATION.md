# Bybit Trader — Документация

> Последнее обновление: 25.02.2026 (rev 4)

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

---

## 1. Обзор проекта

**Bybit Trader** — веб-приложение для полуавтоматической торговли на Bybit (поддержка testnet и mainnet).

**Ключевые принципы:**
- Бот _предлагает_ новые сделки — пользователь _открывает_ с корректировками.
- По уже открытым позициям бот _сам принимает решения_ и ведёт сделки до закрытия.
- Все решения основаны на анализе LLM (ChatGPT / DeepSeek) с учётом истории цен, истории решений бота и торговых параметров.

**Стек:**
- Backend: PHP 8.1+, Symfony 6+
- Frontend: jQuery, Twig-шаблоны
- Биржа: Bybit API v5 (linear perpetual, category=linear)
- LLM: OpenAI ChatGPT (primary), DeepSeek (fallback)

---

## 2. Архитектура

```
Browser (jQuery)
    │
    ▼
Symfony Router
    │
    ├── SecurityController   ──►  security/login.html.twig  (форма входа)
    ├── DashboardController  ──►  dashboard.html.twig
    └── ApiController        ──►  JSON API
            │
            ├── BybitService        (Bybit API v5, включая /v5/market/kline)
            ├── ChatGPTService      (LLM: OpenAI / DeepSeek)
            ├── SettingsService     (var/settings.json)
            ├── BotHistoryService   (var/bot_history.json)
            ├── PositionLockService (var/position_locks.json)
            └── LogSanitizer        (редактирование секретов в логах)
```

---

## 3. Файловая структура

```
bybit_trader/
├── src/
│   ├── Controller/
│   │   ├── ApiController.php         ← все /api/* эндпоинты
│   │   ├── DashboardController.php   ← рендер страниц
│   │   └── SecurityController.php    ← /login, /logout
│   └── Service/
│       ├── BybitService.php          ← Bybit API v5 (включая kline)
│       ├── ChatGPTService.php        ← LLM (OpenAI + DeepSeek)
│       ├── SettingsService.php       ← настройки (var/settings.json + env override)
│       ├── BotHistoryService.php     ← история решений бота
│       ├── PositionLockService.php   ← замки на позиции
│       └── LogSanitizer.php          ← редактирование секретов из логов
├── templates/
│   ├── base.html.twig                ← базовый layout, навигация + кнопка Выйти
│   ├── dashboard.html.twig           ← главная страница
│   ├── settings.html.twig            ← страница настроек
│   └── security/
│       └── login.html.twig           ← страница входа (форма логин/пароль)
├── config/
│   ├── packages/
│   │   └── security.yaml             ← Symfony Security (form_login, memory provider)
│   └── routes/
│       └── web_profiler.yaml         ← маршруты /_wdt и /_profiler для dev
├── public/assets/
│   ├── js/
│   │   ├── app.js                    ← логика дашборда
│   │   └── settings.js               ← логика страницы настроек
│   └── css/
│       └── style.css
├── var/
│   ├── settings.json                 ← конфигурация (торговые параметры, без API-ключей)
│   ├── bot_history.json              ← история решений бота (14 дней, макс 1000 записей)
│   ├── position_locks.json           ← заблокированные позиции
│   ├── bybit_time_offset.json        ← кеш сдвига часов (TTL 5 мин)
│   └── instrument_cache.json         ← кеш инструментов Bybit (TTL 1 ч)
├── .env                              ← дефолтные ENV-переменные (коммитится в git)
├── .env.local                        ← секреты и переопределения (НЕ коммитится)
└── DOCUMENTATION.md
```

---

## 4. Сервисы (Services)

### `BybitService`

Вся коммуникация с Bybit API v5. Содержит многоуровневый слой надёжности:

**Ключевые методы:**

| Метод | Описание |
|---|---|
| `getPositions()` | Список открытых позиций (обогащает `liqPrice`, `stopLoss`, `takeProfit`) |
| `getTopMarkets(limit, category)` | Топ монет по обороту. Дедупликация: предпочитает `*PERP` над `*USDT`. Фильтрует dated-контракты |
| `getKlineHistory(symbol, intervalMinutes, limit, maxPricePoints)` | Исторические свечи с Bybit `/v5/market/kline`. Использует канонический `*USDT`-символ |
| `getBalance()` | Баланс кошелька (USDT available + wallet balance) |
| `getStatistics()` | Статистика: totalTrades, winRate, totalProfit, drawdown. Фоллбэк: `getClosedTrades` → `getTrades` |
| `placeOrder(symbol, side, positionSizeUSDT, leverage)` | Открытие позиции. Включает: setLeverage, switchIsolated, createOrder. Валидирует qty и leverage по instrumentInfo. Логирует параметры перед отправкой |
| `closePositionMarket(symbol, side, fraction)` | Закрытие позиции (полное или частичное). Пропускает если qty < minOrderQty |
| `setBreakevenStopLoss(symbol, side, entryPrice)` | Перенос стопа в безубыток. Пропускает если позиция в убытке |
| `getOpenOrders(symbol)` | Открытые ордера. Включает `triggerPrice` для условных ордеров |
| `getTrades(limit)` | История исполненных ордеров |
| `getClosedTrades(limit)` | История закрытых позиций (P&L) |
| `testConnection()` | Проверка подключения + показывает сдвиг часов с сервером Bybit |

**HTTP-слой надёжности (`requestWithRetry`):**
- **Ретраи с экспоненциальным backoff** (1s → 2s → 4s, до 3 попыток) на сетевые ошибки и HTTP 5xx.
- **Rate-limit**: HTTP 429 ждёт `Retry-After` (заголовок), Bybit retCode `10006` — ждёт и повторяет.

**Синхронизация времени (`getServerTimeOffset`):**
- Вызывает `GET /v5/market/time`, вычисляет сдвиг `serverTime − localTime` в миллисекундах.
- Кеш в `var/bybit_time_offset.json`, TTL 5 минут; инвалидируется при timestamp-ошибках.
- Используется вместо простого `time() * 1000` при формировании подписи → устраняет ошибку `invalid request, please check your server timestamp`.

**Кеш инструментов (`getInstrumentInfo`):**
- Двухуровневый: in-memory (per request) + дисковый `var/instrument_cache.json`, TTL 1 час.
- Принудительное обновление (`forceRefresh=true`) при qty-ошибках Bybit (retCode 110017, 110009, 170036, 170037, 110043).

**Канонический символ (`toCanonicalSymbol`):**
- `BTCPERP → BTCUSDT`, `ETHPERP → ETHUSDT` и т.д.
- Используется в `getMarketData()` и `getKlineHistory()` — гарантирует, что рыночные данные запрашиваются по стандартному `*USDT`-тикеру.
- Позиции и ордера открываются по исходному символу (из `getTopMarkets`).

**Pre-order log (без секретов):**
Перед каждым `placeOrder` в лог пишется: symbol, side, requestedUSDT, price, rawQty, qty, leverage, minQty, maxQty, step, levRange.

**Аутентификация:**
- HMAC-SHA256, `recvWindow=20000`, timestamp скорректирован на server offset.
- POST: подпись по JSON-body строке; GET: по строке параметров.

**Изолированная маржа:**
Каждый вызов `placeOrder` устанавливает изолированную маржу (`tradeMode=1`) для конкретной позиции.

---

### `ChatGPTService`

Управление LLM-запросами. Поддерживает OpenAI (primary) и DeepSeek (fallback).

**Ключевые методы:**

| Метод | Описание |
|---|---|
| `requestLLMContent(purpose, messages, temperature, maxTokens)` | Унифицированный вызов: сначала ChatGPT, при ошибке — DeepSeek |
| `hasAnyProvider()` | Проверяет наличие хотя бы одного настроенного LLM |
| `getProposals(bybitService)` | Анализирует топ-25 монет, возвращает предложения (confidence ≥ 60) |
| `manageOpenPositions(bybitService, positions)` | Решения по открытым позициям (см. раздел 9) |
| `analyzeMarket(symbol, marketData)` | Анализ отдельной монеты, сигнал BUY/SELL/HOLD |
| `testConnection()` | Проверка LLM: возвращает `ok`, `error`, `raw` |

---

### `SettingsService`

Хранит все настройки в `var/settings.json`. **API-ключи берутся приоритетно из переменных окружения** (`.env.local`), в `settings.json` они не сохраняются.

**Структура настроек:**

```json
{
  "bybit": {
    "api_key": "",
    "api_secret": "",
    "testnet": true,
    "base_url": "https://api-testnet.bybit.com"
  },
  "chatgpt": {
    "api_key": "",
    "model": "gpt-4",
    "enabled": false
  },
  "deepseek": {
    "api_key": "",
    "model": "deepseek-chat",
    "enabled": false
  },
  "trading": {
    "max_position_usdt": 100.0,
    "min_leverage": 1,
    "max_leverage": 5,
    "aggressiveness": "balanced",
    "max_managed_positions": 10,
    "auto_open_min_positions": 5,
    "auto_open_enabled": false,
    "bot_timeframe": 5,
    "bot_history_candles": 60
  }
}
```

---

### `BotHistoryService`

Хранит историю событий бота в `var/bot_history.json`.

**Типы событий:**

| Тип | Описание |
|---|---|
| `bot_tick` | Факт запуска тика бота |
| `manual_open` | Ручное открытие пользователем |
| `auto_open` | Автооткрытие ботом |
| `close_full` | Полное закрытие ботом |
| `close_partial` | Частичное закрытие ботом |
| `close_partial_skip` | Пропуск частичного закрытия (объём слишком мал) |
| `move_sl_to_be` | Перенос стопа в безубыток |
| `move_sl_to_be_skip` | Пропуск переноса стопа (позиция в убытке) |
| `average_in` | Усреднение позиции |
| `manual_close_full` | Ручное полное закрытие пользователем |
| `position_lock` | Установка/снятие замка |

**Ограничения:** 14 дней, максимум 1000 записей. Метод `getWeeklySummaryText()` возвращает сводку по топ-10 символам за 7 дней.

---

### `PositionLockService`

Управляет "замками" на позиции в `var/position_locks.json`. Заблокированные позиции бот не трогает.

**Ключ записи:** `"SYMBOL|Side"` (например, `"BTCUSDT|Buy"`).

---

### `LogSanitizer`

Статический хелпер для безопасного логирования. Автоматически редактирует API-ключи и токены перед записью в лог.

```php
LogSanitizer::log('Prefix', $message, $settingsService);
// В логе: "sk-proj-AbCd****[REDACTED]" вместо полного ключа
```

---

## 5. Контроллеры (Controllers)

### `SecurityController`

Обрабатывает авторизацию:
- `GET/POST /login` → `security/login.html.twig` (форма входа, CSRF-защита)
- `GET /logout` → выход (обрабатывается firewall Symfony, редирект на `/login`)

### `DashboardController`

Рендерит HTML-страницы:
- `/` → `dashboard.html.twig` (принимает `?page=dashboard|bot|history`)
- `/settings` → `settings.html.twig`

### `ApiController`

Все эндпоинты — см. раздел 7.

---

## 6. Фронтенд

**`public/assets/js/app.js`** — логика дашборда:

| Функция | Описание |
|---|---|
| `loadDashboard()` | Загружает все секции: баланс, статистику, позиции, ордера, сделки, историю бота |
| `loadPositions()` | Таблица позиций: Long/Short цветом, Entry USDT, leverage, liquidation, кнопки закрытия/замка |
| `loadOrders()` | Открытые ордера. Показывает `triggerPrice` если `price=0` |
| `loadTrades()` | История сделок. Long/Short цветом |
| `loadBotHistory()` | Таблица истории решений бота (последние 50 событий за 7 дней) |
| `runBotTick()` | Запускает `/api/bot/tick`, показывает `summary` в `#bot-status-message` |
| `switchDashboardPage(page)` | Переключение страниц дашборда по `data-page` атрибуту |

**`public/assets/js/settings.js`** — логика настроек:

- Загрузка/сохранение всех блоков: Bybit, ChatGPT, DeepSeek, Trading
- Поля `bot_timeframe` (выпадающий список) и `bot_history_candles` (число)
- Тестирование подключения к Bybit и LLM с отображением raw-ответа при ошибке
- Обработчик глобальных AJAX-ошибок 401 (редирект на `/login`)

**Навигация (`base.html.twig`):**
- Дашборд: `/?page=dashboard`
- Бот: `/?page=bot`
- История: `/?page=history`
- Настройки: `/settings`
- Выйти: `/logout`

---

## 7. API Endpoints

Все эндпоинты: `GET/POST /api/...` Требуют авторизации (сессия Symfony).

| Метод | URL | Описание |
|---|---|---|
| GET | `/api/positions` | Открытые позиции (с полем `locked`) |
| GET | `/api/orders` | Открытые ордера (query: `symbol`) |
| GET | `/api/trades` | История исполнений (query: `limit`) |
| GET | `/api/closed-trades` | Закрытые позиции (query: `limit`) |
| GET | `/api/statistics` | Торговая статистика |
| GET | `/api/balance` | Баланс кошелька |
| GET | `/api/market/top` | Топ монет (query: `limit`, `category`) |
| GET | `/api/market-data/{symbol}` | Данные по символу |
| GET | `/api/market-analysis/{symbol}` | LLM-анализ монеты |
| GET | `/api/analysis/proposals` | LLM-предложения по сделкам |
| POST | `/api/bot/tick` | Запуск тика бота (управление позициями + авто-открытие) |
| GET | `/api/bot/history` | История решений бота (50 событий, 7 дней) |
| POST | `/api/order/open` | Открытие ордера |
| POST | `/api/position/close` | Полное закрытие позиции вручную |
| POST | `/api/position/lock` | Установка/снятие замка на позицию |
| GET | `/api/settings` | Получить все настройки |
| POST | `/api/settings` | Обновить настройки |
| GET | `/api/test/bybit` | Проверить подключение к Bybit |
| GET | `/api/test/chatgpt` | Проверить подключение к LLM |

---

## 8. Настройки

Страница `/settings` содержит:

**Bybit API:** api_key, api_secret, testnet (checkbox), base_url

**ChatGPT API:** api_key, model (gpt-4 / gpt-3.5-turbo), enabled, кнопка проверки

**DeepSeek API (fallback LLM):** api_key, model (deepseek-chat / deepseek-reasoner), enabled, кнопка проверки

**Торговые параметры:**
- `max_position_usdt` — максимальный размер позиции в USDT
- `min_leverage` / `max_leverage` — диапазон плеча для LLM
- `aggressiveness` — консервативная / сбалансированная / агрессивная
- `max_managed_positions` — максимум позиций под управлением бота (default: 10)
- `auto_open_min_positions` — минимум позиций для автооткрытия (default: 5)
- `auto_open_enabled` — разрешить ли боту открывать новые сделки
- `bot_timeframe` — таймфрейм принятия решений: 1/5/15/30/60/240/1440 мин
- `bot_history_candles` — сколько свечей истории передавать боту (5–60)

---

## 9. Бот: логика принятия решений

### Запуск

Бот запускается через `POST /api/bot/tick` — вручную (кнопка в UI) или по расписанию (cron, Task Scheduler).

**Рекомендуемый cron (каждую минуту):**
```
* * * * * curl -s -X POST http://localhost/api/bot/tick > /dev/null 2>&1
```

**Windows Task Scheduler:**
```
powershell -Command "Invoke-WebRequest -Uri 'http://localhost/api/bot/tick' -Method POST"
```

### Алгоритм тика

```
1. Загрузить текущие позиции из Bybit
2. Проверить частоту: прошло ли >= bot_timeframe минут с последнего тика?
   ├── НЕТ → вернуть "ждём таймфрейм"
   └── ДА → продолжить
3. Обогатить позиции историей цен с Bybit kline (компактный формат)
4. [Шаг 1] Управление позициями:
   ├── Вызов ChatGPTService::manageOpenPositions
   └── По каждому решению LLM:
       ├── Позиция заблокирована? → пропустить
       ├── CLOSE_FULL → closePositionMarket(fraction=1.0)
       ├── CLOSE_PARTIAL → closePositionMarket(fraction), skip если qty<min
       ├── MOVE_STOP_TO_BREAKEVEN → только если PnL > 0, иначе skip
       ├── AVERAGE_IN_ONCE → только если не усреднялся за 7 дней
       └── DO_NOTHING → пропустить
5. [Шаг 2] Автооткрытие (если auto_open_enabled):
   ├── Если открытых позиций < auto_open_min_positions
   └── Открыть сделки с confidence >= 80%
6. Залогировать bot_tick с managedCount, openedCount, timeframe
7. Вернуть JSON с summary, managed[], opened[]
```

### Решения LLM для позиций

LLM получает:
- Таймфрейм торговли (например, "5min")
- По каждой позиции: symbol, side, size, entry, mark, pnl, leverage, openedAt, **история цен**
- Сводку истории бота за 7 дней (по символам: wins/losses/errors)
- Список символов, по которым было усреднение в последние 7 дней

Возможные действия LLM: `CLOSE_FULL`, `CLOSE_PARTIAL`, `MOVE_STOP_TO_BREAKEVEN`, `AVERAGE_IN_ONCE`, `DO_NOTHING`

---

## 10. История цен и таймфреймы

**Источник данных: Bybit `/v5/market/kline`** — публичный эндпоинт, авторизация не нужна.

Вместо самостоятельного сбора цен с накоплением во времени, при каждом тике бота вызывается `BybitService::getKlineHistory()`, который мгновенно получает готовые исторические свечи с биржи. Это даёт:
- Полные OHLCV-данные (а не только текущую цену снапшота)
- Мгновенный доступ к любому таймфрейму без периода накопления
- Нет хранилища на диске — данные всегда свежие прямо с биржи

**Маппинг таймфреймов (минуты → Bybit interval):**

| Настройка (мин) | Bybit interval |
|---|---|
| 1 | `1` |
| 5 | `5` |
| 15 | `15` |
| 30 | `30` |
| 60 | `60` |
| 240 | `240` |
| 1440 | `D` |

**Формат для LLM (компактный, ~20–30 токенов на позицию):**
```
[60 5m candles | open=68000 close=69200 min=67000 max=69500 trend=UP] closes:67800,68100,68200,...
```

Содержит: число свечей, таймфрейм, open/close/min/max, тренд, последние N close-цен.

**Параметр `bot_history_candles`** (настройки, 5–60) — сколько свечей запрашивать у Bybit.

---

## 11. Токен-бюджет LLM

Целевой максимум входного промпта: **14 000 символов ≈ 3 500 токенов**.  
Лимит ответа: **2 000 токенов**.  
Итого: ~5 500 токенов — безопасно для всех моделей (gpt-4 8k, gpt-3.5-turbo-16k, deepseek).

**Алгоритм контроля:**

1. Фиксированные части промпта (~2 200 символов): инструкции + история бота
2. На каждую позицию базово ~120 символов (заголовок без истории)
3. Остаток делится на позиции, каждая точка ≈ 8 символов → `maxPricePoints`
4. `maxPricePoints` ограничен: min=5, max=30
5. **Аварийный fallback:** если промпт всё равно > 14 000 символов — история цен убирается полностью

**Пример расчёта для 10 позиций:**
- Доступно для истории: 14000 - 2200 - 10×120 = 10600 символов
- На позицию: 10600 / 10 = 1060 символов / 8 = 132 точки → ограничено до 30
- Каждая позиция получает 30 ценовых точек

---

## 12. Интеграция с Bybit API v5

**Base URL:**
- Testnet: `https://api-testnet.bybit.com`
- Mainnet: `https://api.bybit.com`

### Аутентификация

```
offset    = serverTime − localTime          // из GET /v5/market/time, кешируется 5 мин
timestamp = now_ms + offset
signStr   = timestamp + api_key + recvWindow + queryString   // GET
signStr   = timestamp + api_key + recvWindow + body          // POST
signature = HMAC_SHA256(signStr, api_secret)
Headers: X-BAPI-API-KEY, X-BAPI-SIGN, X-BAPI-SIGN-TYPE=2, X-BAPI-TIMESTAMP, X-BAPI-RECV-WINDOW=20000
```

### Слой надёжности

| Механизм | Поведение |
|---|---|
| **Retry + backoff** | До 3 попыток; паузы 1s → 2s → 4s. Применяется при сетевых ошибках и HTTP 5xx |
| **Rate-limit (HTTP 429)** | Читает `Retry-After` заголовок, ждёт (мин 1с, макс 30с), затем повторяет |
| **Rate-limit (retCode 10006)** | Bybit-уровень; backoff + повтор |
| **Timestamp re-sync** | При retCode 10002 — инвалидирует кеш сдвига, делает повтор |
| **Instrument cache invalidation** | При qty-ошибках (110017, 110009, 170036, 170037, 110043) — сбрасывает кеш инструмента и логирует |

### Кеш инструментов

- Хранится в `var/instrument_cache.json` (TTL 1 час)
- In-memory layer для повторных вызовов внутри одного запроса
- При qty-ошибке: `invalidateInstrumentCache(symbol)` → следующий `placeOrder` получит свежие данные

### Символы

| Правило | Пример |
|---|---|
| `*PERP` → `*USDT` для market-data/kline | `BTCPERP` → `BTCUSDT` |
| Dated-контракты фильтруются | `MNTUSDT-13MAR26` → исключён из топа |
| Дедупликация: предпочтение `*PERP` на testnet | `BTCPERP` выигрывает у `BTCUSDT` в `getTopMarkets` |

### Расчёт qty

```
rawQty = positionSizeUSDT / price
qty    = floor(rawQty / qtyStep) * qtyStep   // выравнивание по шагу
```
Если `qty < minOrderQty` → ошибка с указанием минимальной суммы в USDT.  
Перед отправкой в лог пишется: symbol, side, USDT, price, rawQty, qty, leverage, minQty, maxQty, step, levRange (без ключей).

### Известные особенности testnet

- `liqPrice` может быть пустым — UTA считает ликвидацию на уровне портфеля
- `getClosedTrades` может возвращать пустой список → фоллбэк на `getTrades`
- `BTCUSDT` на testnet имеет некорректные исторические цены; `BTCPERP` — корректные

---

## 13. Интеграция с LLM

### Порядок вызова

1. Если ChatGPT включён и `api_key` задан → пробуем ChatGPT
2. Если ChatGPT недоступен (ошибка, нет баланса) → пробуем DeepSeek
3. Если оба недоступны → возвращаем null / mock-данные

### Параметры вызовов

| Метод | temperature | max_tokens | Примечание |
|---|---|---|---|
| `getProposals` | 0.5 | 1500 | 25 монет, JSON-массив |
| `manageOpenPositions` | 0.4 | 2000 | До 10 позиций с историей |
| `analyzeMarket` | 0.7 | 500 | Анализ одной монеты |
| `testConnection` | 0.1 | 10 | Health-check |

### Проверка подключения

`GET /api/test/chatgpt` возвращает:
```json
{"ok": true, "message": "LLM connection ok"}
// или
{"ok": false, "error": "...", "raw": "сырой ответ LLM"}
```

---

## 14. Cron / ручной запуск бота

**Логика частоты:**
- Бот должен запускаться каждую минуту
- Принятие решений — только если прошло `bot_timeframe` минут с последнего тика

**Ручной запуск:** кнопка "Запустить бота" на странице `/?page=bot` в дашборде.

**Настройка cron (Linux):**
```bash
* * * * * /usr/bin/curl -s -X POST http://localhost/api/bot/tick
```

**Windows Task Scheduler (каждую минуту):**
1. Открыть Task Scheduler → Create Task
2. Triggers: "Daily", повторять каждые 1 минуту в течение 1 дня
3. Actions: `powershell.exe -Command "Invoke-WebRequest -Uri 'http://YOUR_URL/api/bot/tick' -Method POST"`

---

## 15. Данные и хранилище

| Файл | Описание | Лимит |
|---|---|---|
| `var/settings.json` | Настройки приложения (без API-ключей) | — |
| `var/bot_history.json` | История событий бота | 14 дней, 1 000 записей |
| `var/position_locks.json` | Заблокированные позиции | — |
| `var/pending_actions.json` | Действия бота, ожидающие подтверждения (strict mode) | TTL 60 мин |
| `var/bybit_time_offset.json` | Кеш сдвига часов между локальным временем и сервером Bybit | TTL 5 мин |
| `var/instrument_cache.json` | Кеш параметров инструментов Bybit (lotSizeFilter, leverageFilter) | TTL 1 ч / запись |

История цен монет хранится **на стороне Bybit** и запрашивается через `/v5/market/kline` при каждом тике — локальный файл не нужен.

Все файлы создаются автоматически при первом запуске.

---

## 16. Безопасность

### Аутентификация (форма входа)

Приложение использует **Symfony Security** с формой входа (`form_login`).

- При открытии любого URL пользователь перенаправляется на `/login`
- После успешного входа — на главную страницу (`/`)
- Кнопка "Выйти" в навигации → `GET /logout`
- CSRF-токен защищает форму от подделки запросов

**Настройка учётных данных** — только в `.env.local` (не коммитится в git):

```dotenv
APP_AUTH_USER=admin
# Одинарные кавычки ОБЯЗАТЕЛЬНЫ — иначе $ в bcrypt-хеше сломает парсинг
APP_AUTH_PASSWORD_HASHED='$2y$13$...'
```

**Смена пароля:**
```bash
php bin/console security:hash-password ВАШ_НОВЫЙ_ПАРОЛЬ
# Скопируйте результат в .env.local в одинарных кавычках
```

### API-ключи из переменных окружения

API-ключи (Bybit, OpenAI, DeepSeek) хранятся в `.env.local` и **не сохраняются в `var/settings.json`**:

```dotenv
BYBIT_API_KEY=AamatQ7B...
BYBIT_API_SECRET=p0vdpJyz...
CHATGPT_API_KEY=sk-proj-...
DEEPSEEK_API_KEY=sk-1a47...
```

`SettingsService::applyEnvOverrides()` считывает эти переменные и перекрывает значения из `settings.json`.

### Логи

`LogSanitizer` автоматически заменяет API-ключи в логах на `****[REDACTED]`. Полные Bearer-токены также редактируются.

### Что НЕ коммитится в git

```
.env.local          ← реальные ключи и пароль
.env.*.local        ← любые локальные переопределения
var/                ← данные приложения (settings.json, история)
vendor/             ← зависимости Composer
```

---

## 17. Известные ограничения и особенности

**Testnet:**
- Некоторые символы (BTCUSDT) имеют ненастоящие цены на testnet — отображаются данные BTCPERP
- `liqPrice` пустой для UTA-аккаунтов (используется портфельный расчёт ликвидации)
- `getClosedTrades` может возвращать пустой массив — статистика считается по `getTrades`

**TradingView:**
- Некоторые символы (например, 1000PEPEUSDT) не поддерживаются виджетом TradingView — ожидаемое поведение

**Комиссии:**
- Bybit берёт ~0.06% за открытие и ~0.06% за закрытие
- Бот учитывает комиссии в промпте: не торгует если ожидаемое движение слишком мало

**Усреднение:**
- AVERAGE_IN_ONCE допускается не чаще 1 раза в 7 дней на символ

**Замок позиции:**
- Если позиция заблокирована (`locked=true`), бот её не трогает ни при каких условиях
- Пользователь может закрыть её вручную через кнопку в таблице позиций
