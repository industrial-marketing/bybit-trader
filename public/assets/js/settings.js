$(document).ready(function() {
    loadSettings();
    
    $('#bybit-settings-form').on('submit', function(e) {
        e.preventDefault();
        saveBybitSettings();
    });
    
    $('#chatgpt-settings-form').on('submit', function(e) {
        e.preventDefault();
        saveChatGPTSettings();
    });

    $('#deepseek-settings-form').on('submit', function(e) {
        e.preventDefault();
        saveDeepseekSettings();
    });

    $('#trading-settings-form').on('submit', function(e) {
        e.preventDefault();
        saveTradingSettings();
    });

    $('#risk-settings-form').on('submit', function(e) {
        e.preventDefault();
        saveRiskSettings();
    });

    $('#alerts-settings-form').on('submit', function(e) {
        e.preventDefault();
        saveAlertsSettings();
    });

    $('#test-alert-btn').on('click', function() {
        sendTestAlert();
    });

    $('#bybit-test-btn').on('click', function() {
        testBybitConnection();
    });

    $('#chatgpt-test-btn').on('click', function() {
        testChatGPTConnection();
    });

    $('#deepseek-test-btn').on('click', function() {
        testDeepseekConnection();
    });
});

function loadSettings() {
    $.get('/api/settings')
        .done(function(data) {
            // Bybit settings
            if (data.bybit) {
                $('#bybit-api-key').val(data.bybit.api_key || '');
                $('#bybit-api-secret').val(data.bybit.api_secret || '');
                $('#bybit-testnet').prop('checked', data.bybit.testnet !== false);
                $('#bybit-base-url').val(data.bybit.base_url || 'https://api-testnet.bybit.com');
            }
            
            // ChatGPT settings
            if (data.chatgpt) {
                $('#chatgpt-api-key').val(data.chatgpt.api_key || '');
                $('#chatgpt-model').val(data.chatgpt.model || 'gpt-4');
                $('#chatgpt-enabled').prop('checked', data.chatgpt.enabled || false);
            }

            // DeepSeek settings
            if (data.deepseek) {
                $('#deepseek-api-key').val(data.deepseek.api_key || '');
                $('#deepseek-model').val(data.deepseek.model || 'deepseek-chat');
                $('#deepseek-enabled').prop('checked', data.deepseek.enabled || false);
            }

            // Trading settings
            if (data.trading) {
                $('#trading-max-position').val(data.trading.max_position_usdt || '');
                $('#trading-min-leverage').val(data.trading.min_leverage || '');
                $('#trading-max-leverage').val(data.trading.max_leverage || '');
                $('#trading-aggressiveness').val(data.trading.aggressiveness || 'balanced');
                $('#trading-max-managed').val(data.trading.max_managed_positions || '10');
                $('#trading-auto-open-min').val(data.trading.auto_open_min_positions || '5');
                $('#trading-auto-open-enabled').prop('checked', !!data.trading.auto_open_enabled);
                $('#trading-bot-timeframe').val(String(data.trading.bot_timeframe || '5'));
                $('#trading-history-candles').val(data.trading.bot_history_candles || '60');

                // Risk settings
                $('#risk-trading-enabled').prop('checked', data.trading.trading_enabled !== false);
                $('#risk-daily-loss').val(data.trading.daily_loss_limit_usdt || '0');
                $('#risk-max-exposure').val(data.trading.max_total_exposure_usdt || '0');
                $('#risk-cooldown').val(data.trading.action_cooldown_minutes ?? '30');
                $('#risk-strict-mode').prop('checked', !!data.trading.bot_strict_mode);
            }

            // Alerts settings
            if (data.alerts) {
                $('#alerts-tg-token').val(data.alerts.telegram_bot_token || '');
                $('#alerts-tg-chat').val(data.alerts.telegram_chat_id || '');
                $('#alerts-webhook').val(data.alerts.webhook_url || '');
                $('#alerts-llm-failure').prop('checked', data.alerts.on_llm_failure !== false);
                $('#alerts-invalid-resp').prop('checked', data.alerts.on_invalid_response !== false);
                $('#alerts-risk-limit').prop('checked', data.alerts.on_risk_limit !== false);
                $('#alerts-bybit-error').prop('checked', !!data.alerts.on_bybit_error);
                $('#alerts-repeated').prop('checked', data.alerts.on_repeated_failures !== false);
                $('#alerts-threshold').val(data.alerts.repeated_failure_threshold || 3);
            }
        })
        .fail(function() {
            showMessage('Ошибка загрузки настроек', 'error');
        });
}

function saveBybitSettings() {
    const settings = {
        bybit: {
            api_key: $('#bybit-api-key').val(),
            api_secret: $('#bybit-api-secret').val(),
            testnet: $('#bybit-testnet').is(':checked'),
            base_url: $('#bybit-base-url').val() || 'https://api-testnet.bybit.com'
        }
    };
    
    $.ajax({
        url: '/api/settings',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(settings)
    })
    .done(function(data) {
        showMessage('Настройки Bybit сохранены успешно!', 'success');
    })
    .fail(function() {
        showMessage('Ошибка сохранения настроек Bybit', 'error');
    });
}

function saveChatGPTSettings() {
    const settings = {
        chatgpt: {
            api_key: $('#chatgpt-api-key').val(),
            model: $('#chatgpt-model').val(),
            enabled: $('#chatgpt-enabled').is(':checked')
        }
    };
    
    $.ajax({
        url: '/api/settings',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(settings)
    })
    .done(function(data) {
        showMessage('Настройки ChatGPT сохранены успешно!', 'success');
    })
    .fail(function() {
        showMessage('Ошибка сохранения настроек ChatGPT', 'error');
    });
}

function saveDeepseekSettings() {
    const settings = {
        deepseek: {
            api_key: $('#deepseek-api-key').val(),
            model: $('#deepseek-model').val(),
            enabled: $('#deepseek-enabled').is(':checked')
        }
    };
    
    $.ajax({
        url: '/api/settings',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(settings)
    })
    .done(function(data) {
        showMessage('Настройки DeepSeek сохранены успешно!', 'success');
    })
    .fail(function() {
        showMessage('Ошибка сохранения настроек DeepSeek', 'error');
    });
}

function saveTradingSettings() {
    const settings = {
        trading: {
            max_position_usdt: parseFloat($('#trading-max-position').val() || '0'),
            min_leverage: parseInt($('#trading-min-leverage').val() || '1', 10),
            max_leverage: parseInt($('#trading-max-leverage').val() || '5', 10),
            aggressiveness: $('#trading-aggressiveness').val() || 'balanced',
            max_managed_positions: parseInt($('#trading-max-managed').val() || '10', 10),
            auto_open_min_positions: parseInt($('#trading-auto-open-min').val() || '5', 10),
            auto_open_enabled: $('#trading-auto-open-enabled').is(':checked'),
            bot_timeframe: parseInt($('#trading-bot-timeframe').val() || '5', 10),
            bot_history_candles: parseInt($('#trading-history-candles').val() || '60', 10)
        }
    };

    $.ajax({
        url: '/api/settings',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(settings)
    })
    .done(function(data) {
        showMessage('Торговые настройки сохранены успешно!', 'success');
    })
    .fail(function() {
        showMessage('Ошибка сохранения торговых настроек', 'error');
    });
}

function saveRiskSettings() {
    const settings = {
        trading: {
            trading_enabled:         $('#risk-trading-enabled').is(':checked'),
            daily_loss_limit_usdt:   parseFloat($('#risk-daily-loss').val()    || '0'),
            max_total_exposure_usdt: parseFloat($('#risk-max-exposure').val()  || '0'),
            action_cooldown_minutes: parseInt($('#risk-cooldown').val()        || '0', 10),
            bot_strict_mode:         $('#risk-strict-mode').is(':checked')
        }
    };

    $.ajax({ url: '/api/settings', method: 'POST', contentType: 'application/json', data: JSON.stringify(settings) })
        .done(function() { showMessage('Настройки защиты сохранены!', 'success'); })
        .fail(function() { showMessage('Ошибка сохранения настроек защиты', 'error'); });
}

function showMessage(text, type) {
    const $message = $('#settings-message');
    $message.removeClass('success error').addClass(type).text(text).fadeIn();
    setTimeout(function() {
        $message.fadeOut();
    }, 3000);
}

function testBybitConnection() {
    $.get('/api/test/bybit')
        .done(function(data) {
            if (data.ok) {
                showMessage(data.message || 'Подключение к Bybit успешно', 'success');
            } else {
                const msg = data.retMsg || data.reason || data.error || 'Ошибка подключения к Bybit';
                showMessage('Bybit: ' + msg, 'error');
            }
        })
        .fail(function() {
            showMessage('Ошибка запроса к /api/test/bybit', 'error');
        });
}

function testChatGPTConnection() {
    $.get('/api/test/chatgpt')
        .done(function(data) {
            if (data.ok) {
                showMessage(data.message || 'Подключение к LLM (ChatGPT/DeepSeek) успешно', 'success');
            } else {
                let msg = data.error || data.reason || 'Ошибка подключения к LLM';
                if (data.raw) {
                    const rawStr = String(data.raw);
                    msg += ' RAW: ' + rawStr.substring(0, 200);
                }
                showMessage('LLM: ' + msg, 'error');
            }
        })
        .fail(function() {
            showMessage('Ошибка запроса к /api/test/chatgpt', 'error');
        });
}

function saveAlertsSettings() {
    const settings = {
        alerts: {
            telegram_bot_token:         $('#alerts-tg-token').val(),
            telegram_chat_id:           $('#alerts-tg-chat').val(),
            webhook_url:                $('#alerts-webhook').val(),
            on_llm_failure:             $('#alerts-llm-failure').is(':checked'),
            on_invalid_response:        $('#alerts-invalid-resp').is(':checked'),
            on_risk_limit:              $('#alerts-risk-limit').is(':checked'),
            on_bybit_error:             $('#alerts-bybit-error').is(':checked'),
            on_repeated_failures:       $('#alerts-repeated').is(':checked'),
            repeated_failure_threshold: parseInt($('#alerts-threshold').val() || '3', 10)
        }
    };
    $.ajax({ url: '/api/settings', method: 'POST', contentType: 'application/json', data: JSON.stringify(settings) })
        .done(function() { showMessage('Настройки алертов сохранены!', 'success'); })
        .fail(function() { showMessage('Ошибка сохранения настроек алертов', 'error'); });
}

function sendTestAlert() {
    $.ajax({ url: '/api/alerts/test', method: 'POST', contentType: 'application/json', data: JSON.stringify({}) })
        .done(function(data) {
            showMessage(data.ok ? 'Тестовый алерт отправлен.' : ('Ошибка: ' + (data.error || '?')), data.ok ? 'success' : 'error');
        })
        .fail(function() { showMessage('Ошибка запроса тестового алерта', 'error'); });
}

function testDeepseekConnection() {
    // используем тот же эндпоинт: сервис сам выберет доступный провайдер
    $.get('/api/test/chatgpt')
        .done(function(data) {
            if (data.ok) {
                showMessage(data.message || 'Подключение к LLM успешно', 'success');
            } else {
                let msg = data.error || data.reason || 'Ошибка подключения к LLM';
                if (data.raw) {
                    const rawStr = String(data.raw);
                    msg += ' RAW: ' + rawStr.substring(0, 200);
                }
                showMessage('LLM: ' + msg, 'error');
            }
        })
        .fail(function() {
            showMessage('Ошибка запроса к /api/test/chatgpt', 'error');
        });
}
