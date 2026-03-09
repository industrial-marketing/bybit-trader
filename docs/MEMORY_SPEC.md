# Bybit Trader — Long-term Memory & Retrieval Layer

## Цель

Бот не принимает решения только по текущему срезу рынка, а:
- сохраняет ежедневный опыт;
- запоминает прошлые сделки, идеи, ошибки;
- ищет похожие исторические ситуации перед новым решением;
- использует память как дополнительный контекст для LLM.

**Важно:** память — опция в настройках. Когда `memory_enabled = false`, текущий флоу остаётся без изменений.

---

## Настройки профиля (strategy_settings)

| Ключ | Тип | По умолчанию | Описание |
|------|-----|--------------|----------|
| `memory_enabled` | bool | false | Включить retrieval перед LLM |
| `memory_write_enabled` | bool | false | Записывать память по событиям |
| `memory_top_k` | int | 5 | Кол-во похожих кейсов для retrieval |
| `memory_lookback_days` | int | 90 | Макс. возраст записей (дни) |
| `memory_include_cross_symbol` | bool | false | Искать по другим символам |
| `memory_include_daily_summaries` | bool | true | Включать daily summaries |
| `memory_include_insights` | bool | true | Включать insights |
| `memory_max_tokens` | int | 800 | Лимит токенов для memory-блока в prompt |
| `memory_min_score` | float | 0.5 | Мин. cosine similarity |

---

## Архитектура

### 1. Хранилище: Qdrant (vector DB)

Память хранится в Qdrant. Каждая точка:
- `id` (string), `vector` (1536 dims, Cosine), `payload`:
  - `profile_id`, `symbol`, `memory_type`, `event_time`, `text_content`, `json_payload`,
  - `quality_score`, `outcome_score`, `created_at`

Типы: `trade`, `daily_summary`, `decision`, `insight`

Конфиг (`.env`): `QDRANT_HOST`, `QDRANT_PORT`, `QDRANT_COLLECTION`, `QDRANT_API_KEY` (опционально)

**Запуск Qdrant локально:** `docker run -p 6333:6333 qdrant/qdrant`

### 2. Сервисы

- **QdrantClientService** — обёртка над REST API: ensureCollection, upsertPoints, search
- **EmbeddingService** — `embedText(text): array` (OpenAI text-embedding-3-small)
- **MemoryWriteService** — createTradeMemory, createDecisionMemory, createInsightMemory → Qdrant
- **MemoryRetrievalService** — findRelevantMemories(profileId, queryText, symbol) → Qdrant search
- **DailyReflectionService** — cron job, агрегация дня, distilled memories
- **LlmDecisionContextBuilder** — сбор prompt context с memory блоком

### 3. Integration points

1. **После close_full / close_partial** (BotTickCommand) → MemoryWriteService.createTradeMemory (если memory_write_enabled)
2. **Перед LLM call** (ChatGPTService) → MemoryRetrievalService.findRelevantMemories → добавить блок в prompt (если memory_enabled)
3. **Cron daily** → DailyReflectionService.run()

---

## MVP Scope (Фаза 1)

- [x] Qdrant vector store + QdrantClientService
- [x] EmbeddingService (OpenAI text-embedding-3-small)
- [x] MemoryWriteService
- [x] MemoryRetrievalService (Qdrant semantic search)
- [x] Profile settings (memory_* in strategy_settings)
- [x] Запись trade memory при close_full/close_partial
- [x] Retrieval + блок в prompt перед manageSinglePosition
- [x] UI: Memory toggles в Settings → Strategy Signals
- [ ] Daily reflection cron (Фаза 3)
- [ ] UI Memory tab (Фаза 4)

---

## Формат memory-блока в prompt

```
RELEVANT HISTORICAL CASES:
- [BTCUSDT] Long, closed +2.3%. Entry after pullback in uptrend. Averaging helped.
- [ETHUSDT] Short, closed -1.1%. Late entry in chop. Lesson: wait for confirmation.

LEARNED HEURISTICS:
- In range market with weak volume, averaging too early often fails.
```
