# Миграция Bybit Trader: файлы → MySQL

## Этап 1 (текущий)

### Что сделано

- **Doctrine ORM + MySQL**: сущности User, TradingProfile, ExchangeIntegration, AiProviderConfig, BotSettings, ProfilePerformanceStats
- **Миграция**: `migrations/Version20250225120000.php` — создание таблиц
- **Поддержка файлов сохранена**: приложение продолжает работать с `var/settings.json` по умолчанию

### Настройка MySQL

1. Создайте БД:
   ```sql
   CREATE DATABASE bybit_trader CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'app'@'localhost' IDENTIFIED BY 'your_password';
   GRANT ALL ON bybit_trader.* TO 'app'@'localhost';
   ```

2. В `.env.local`:
   ```
   DATABASE_URL="mysql://app:your_password@127.0.0.1:3306/bybit_trader?serverVersion=8.0&charset=utf8mb4"
   ```

3. Выполните миграции:
   ```bash
   php bin/console doctrine:migrations:migrate --no-interaction
   ```

### Импорт текущего профиля в БД

```bash
php bin/console app:import-file-settings
```

Опции:
- `--profile-name="Main Testnet"` — имя профиля (по умолчанию: File Import (Main))
- `--environment=testnet` — окружение

Команда:
- читает `var/settings.json`;
- создаёт админ-пользователя (если нет, по APP_AUTH_USER / APP_AUTH_PASSWORD_HASHED);
- создаёт профиль с настройками Bybit, ChatGPT, DeepSeek, trading, alerts, strategies.

**Важно:** после импорта приложение продолжает работать с файлами. Переключение на профиль из БД (SettingsService → DatabaseSettingsSource) будет реализовано в Этапе 2.

## Дальнейшие этапы (ТЗ)

- **Этап 2**: регистрация, логин, кабинет, CRUD профилей, переключение в UI
- **Этап 3**: админка пользователей и профилей
- **Этап 4**: абстракция AI provider (OpenAI, Claude)
- **Этап 5**: статистика и сравнение профилей
