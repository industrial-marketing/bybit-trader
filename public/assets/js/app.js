let tvWidget = null;
let tvReady = false;

// Глобальный перехватчик 401 — перезагружаем страницу, браузер покажет Basic Auth диалог
$(document).ajaxError(function(event, xhr) {
    if (xhr.status === 401) {
        document.body.innerHTML = '<div style="font-family:sans-serif;text-align:center;padding:80px;color:#e05;">' +
            '<h2>⚠️ Сессия истекла или доступ запрещён</h2>' +
            '<p>Пожалуйста, <a href="/">обновите страницу</a> и введите логин и пароль.</p></div>';
    }
});

$(document).ready(function() {
    loadDashboard();
    setInterval(loadDashboard, 30000);
    loadRiskStatus();
    setInterval(loadRiskStatus, 30000);

    // Обработчик анализа рынка
    $('#analyze-btn').on('click', function() {
        const symbol = $('#symbol-selector').val();
        analyzeMarket(symbol);
        getTradingDecision(symbol);
        initTradingView(symbol);
    });

    // Клик по строке в таблице топ-монет
    $('#top-markets-table').on('click', 'tbody tr', function() {
        const symbol = $(this).data('symbol');
        if (!symbol) {
            return;
        }
        $('#symbol-selector').val(symbol);
        analyzeMarket(symbol);
        getTradingDecision(symbol);
        initTradingView(symbol);
        // Подсветка выбранной строки
        $('#top-markets-table tbody tr').removeClass('active-row');
        $(this).addClass('active-row');
    });

    // Кнопка обновления
    $('#refresh-btn').on('click', function() {
        loadDashboard();
    });

    // Ручной запуск бота (тот же эндпоинт, что и по cron)
    $('#bot-tick-btn').on('click', function() {
        runBotTick();
    });

    // Предложения: загрузка списка
    $('#load-proposals-btn').on('click', function() {
        loadProposals();
    });

    // Модалка открытия сделки
    $('#modal-cancel-btn, #open-order-modal .modal-backdrop').on('click', function() {
        $('#open-order-modal').hide();
    });

    // Ручное управление позициями
    $('#positions-table').on('click', '.btn-pos-lock', function() {
        const $row = $(this).closest('tr');
        const symbol = $row.data('symbol');
        const side = $row.data('side');
        if (!symbol || !side) return;
        const isLockedNow = $row.find('td').eq(7).text().indexOf('Заблок') !== -1;
        const newLocked = !isLockedNow;

        $.ajax({
            url: '/api/position/lock',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ symbol: symbol, side: side, locked: newLocked }),
            success: function() {
                loadPositions();
            }
        });
    });

    $('#positions-table').on('click', '.btn-pos-close', function() {
        const $row = $(this).closest('tr');
        const symbol = $row.data('symbol');
        const side = $row.data('side');
        if (!symbol || !side) return;
        if (!confirm(`Закрыть позицию ${symbol} (${side}) полностью?`)) {
            return;
        }

        $.ajax({
            url: '/api/position/close',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ symbol: symbol, side: side }),
            success: function(res) {
                if (res && res.ok !== false) {
                    loadPositions();
                    loadBalance();
                } else {
                    alert(res.error || 'Ошибка закрытия позиции');
                }
            },
            error: function(xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.statusText || 'Ошибка сети';
                alert(msg);
            }
        });
    });
    $('#modal-submit-btn').on('click', function() {
        submitOpenOrder();
    });

    // Инициализируем график для дефолтного символа
    const initialSymbol = $('#symbol-selector').val() || 'BTCUSDT';
    initTradingView(initialSymbol);

    const params = new URLSearchParams(window.location.search);
    const initialPage = params.get('page') || 'dashboard';
    switchDashboardPage(initialPage);
});

function loadDashboard() {
    loadPositions();
    loadOrders();
    loadTrades();
    loadStatistics();
    loadTopMarkets();
    loadBalance();
    loadBotHistory();
}

function loadPositions() {
    $.get('/api/positions')
        .done(function(data) {
            if (data.length === 0) {
                $('#positions-table tbody').html('<tr><td colspan="11" class="loading">Нет открытых позиций</td></tr>');
                return;
            }

            let html = '';
            data.forEach(function(position) {
                const pnl = parseFloat(position.unrealizedPnl || 0);
                const pnlClass = pnl >= 0 ? 'profit' : 'loss';
                const pnlSign = pnl >= 0 ? '+' : '';
                const locked = position.locked === true;
                const botStatus = locked ? 'Заблок.' : 'Разрешен';
                const liq = position.liquidationPrice != null ? parseFloat(position.liquidationPrice) : null;
                const liqText = liq && !isNaN(liq) && liq !== 0 ? liq.toFixed(2) : '-';
                const entryPrice = parseFloat(position.entryPrice || 0);
                const size = parseFloat(position.size || 0);
                const entryUsdt = entryPrice && size ? (entryPrice * size) : 0;
                const levText = position.leverage != null ? String(position.leverage) + 'x' : '-';
                const sideRaw = (position.side || '').toUpperCase();
                const sideText = sideRaw === 'BUY' ? 'Long' : sideRaw === 'SELL' ? 'Short' : (position.side || '');
                const sideClass = sideRaw === 'BUY' ? 'profit' : sideRaw === 'SELL' ? 'loss' : '';
                
                html += `
                    <tr data-symbol="${position.symbol}" data-side="${position.side}">
                        <td><strong>${position.symbol}</strong></td>
                        <td class="${sideClass}">${sideText}</td>
                        <td>${position.size}</td>
                        <td>${entryUsdt ? entryUsdt.toFixed(2) : '-'}</td>
                        <td>${levText}</td>
                        <td>${entryPrice ? entryPrice.toFixed(2) : '-'}</td>
                        <td>${parseFloat(position.markPrice).toFixed(2)}</td>
                        <td>${liqText}</td>
                        <td class="${pnlClass}">${pnlSign}${pnl.toFixed(2)}</td>
                        <td>${position.openedAt}</td>
                        <td>${botStatus}</td>
                        <td>
                            <button type="button" class="btn-small btn-pos-lock">${locked ? 'Разблок.' : 'Замок'}</button>
                            <button type="button" class="btn-small btn-pos-close">Закрыть</button>
                        </td>
                    </tr>
                `;
            });
            $('#positions-table tbody').html(html);
        })
        .fail(function() {
            $('#positions-table tbody').html('<tr><td colspan="11" class="loading">Ошибка загрузки данных</td></tr>');
        });
}

function loadOrders() {
    $.get('/api/orders')
        .done(function(data) {
            if (data.length === 0) {
                $('#orders-table tbody').html('<tr><td colspan="8" class="loading">Нет открытых ордеров</td></tr>');
                return;
            }

            let html = '';
            data.forEach(function(order) {
                const statusClass = order.status === 'Filled' ? 'profit' : order.status === 'Cancelled' ? 'loss' : '';
                const executedPercent = parseFloat(order.qty) > 0 
                    ? ((parseFloat(order.cumExecQty) / parseFloat(order.qty)) * 100).toFixed(1) 
                    : '0';

                const rawPrice = parseFloat(order.price || 0);
                const rawTrigger = order.triggerPrice != null ? parseFloat(order.triggerPrice) : null;
                let priceText = '-';
                if (rawPrice && !isNaN(rawPrice) && rawPrice !== 0) {
                    priceText = rawPrice.toFixed(2);
                } else if (rawTrigger && !isNaN(rawTrigger) && rawTrigger !== 0) {
                    // Для стоп-ордеров показываем цену триггера вместо 0
                    priceText = rawTrigger.toFixed(2);
                }

                const sideRaw = (order.side || '').toUpperCase();
                const sideText = sideRaw === 'BUY' ? 'Long' : sideRaw === 'SELL' ? 'Short' : (order.side || '');
                const sideClassSide = sideRaw === 'BUY' ? 'profit' : sideRaw === 'SELL' ? 'loss' : '';
                
                html += `
                    <tr>
                        <td><strong>${order.symbol}</strong></td>
                        <td class="${sideClassSide}">${sideText}</td>
                        <td>${order.orderType}</td>
                        <td>${priceText}</td>
                        <td>${parseFloat(order.qty).toFixed(4)}</td>
                        <td>${parseFloat(order.cumExecQty).toFixed(4)} (${executedPercent}%)</td>
                        <td class="${statusClass}">${order.status}</td>
                        <td>${order.createdTime}</td>
                    </tr>
                `;
            });
            $('#orders-table tbody').html(html);
        })
        .fail(function() {
            $('#orders-table tbody').html('<tr><td colspan="8" class="loading">Ошибка загрузки данных</td></tr>');
        });
}

function loadTrades() {
    $.get('/api/trades?limit=50')
        .done(function(data) {
            if (data.length === 0) {
                $('#trades-table tbody').html('<tr><td colspan="7" class="loading">Нет сделок</td></tr>');
                return;
            }

            let html = '';
            data.slice(0, 20).forEach(function(trade) {
                const profit = trade.closedPnl ? parseFloat(trade.closedPnl) : null;
                const profitClass = profit !== null ? (profit >= 0 ? 'profit' : 'loss') : '';
                const profitText = profit !== null ? (profit >= 0 ? '+' : '') + profit.toFixed(2) : '-';

                const sideRaw = (trade.side || '').toUpperCase();
                const sideText = sideRaw === 'BUY' ? 'Long' : sideRaw === 'SELL' ? 'Short' : (trade.side || '');
                const sideClassSide = sideRaw === 'BUY' ? 'profit' : sideRaw === 'SELL' ? 'loss' : '';
                
                html += `
                    <tr>
                        <td><strong>${trade.symbol}</strong></td>
                        <td class="${sideClassSide}">${sideText}</td>
                        <td>${parseFloat(trade.price).toFixed(2)}</td>
                        <td>${parseFloat(trade.quantity).toFixed(4)}</td>
                        <td class="${profitClass}">${profitText}</td>
                        <td>${trade.status}</td>
                        <td>${trade.openedAt}</td>
                    </tr>
                `;
            });
            $('#trades-table tbody').html(html);
        })
        .fail(function() {
            $('#trades-table tbody').html('<tr><td colspan="7" class="loading">Ошибка загрузки данных</td></tr>');
        });
}

function switchDashboardPage(page) {
    $('.section').each(function() {
        const p = $(this).data('page') || 'dashboard';
        $(this).toggle(p === page);
    });
}

function loadStatistics() {
    $.get('/api/statistics')
        .done(function(data) {
            $('#stat-total-trades').text(data.totalTrades);
            $('#stat-win-rate').text(data.winRate + '%');
            
            const totalProfit = parseFloat(data.totalProfit);
            $('#stat-total-profit').text('$' + totalProfit.toFixed(2));
            $('#stat-total-profit').removeClass('profit loss').addClass(totalProfit >= 0 ? 'profit' : 'loss');
            
            $('#stat-avg-profit').text('$' + data.averageProfit.toFixed(2));
            
            const maxDrawdown = parseFloat(data.maxDrawdown);
            $('#stat-max-drawdown').text('$' + maxDrawdown.toFixed(2));
            $('#stat-max-drawdown').removeClass('profit loss').addClass(maxDrawdown >= 0 ? 'profit' : 'loss');
            
            $('#stat-profit-factor').text(data.profitFactor.toFixed(2));
        })
        .fail(function() {
            console.error('Ошибка загрузки статистики');
        });
}

function loadBalance() {
    $.get('/api/balance')
        .done(function(data) {
            const wallet = parseFloat(data.walletBalance || 0);
            const available = parseFloat(data.availableBalance || 0);

            $('#stat-balance-usdt').text(wallet.toFixed(2) + ' USDT');
            $('#stat-balance-available').text(available.toFixed(2) + ' USDT');
        })
        .fail(function() {
            $('#stat-balance-usdt').text('-');
            $('#stat-balance-available').text('-');
        });
}

function runBotTick() {
    const $btn = $('#bot-tick-btn');
    const originalText = $btn.text();
    $btn.prop('disabled', true).text('Запуск...');

    $.ajax({
        url: '/api/bot/tick',
        method: 'POST',
        success: function(res) {
            const msg = res && res.summary ? res.summary : (res && res.message ? res.message : 'Бот выполнен');
            console.log('Bot tick:', res);
            // Фидбек по статусу бота
            $('#bot-status-message').removeClass('error').addClass('success').text(msg);
            // Лёгкий визуальный фидбек через текст кнопки
            $btn.text('Готово');
            // Обновим данные на дашборде после действий бота
            loadDashboard();
            setTimeout(function() {
                $btn.text(originalText);
            }, 1500);
        },
        error: function(xhr) {
            console.error('Bot tick error', xhr);
            $('#bot-status-message').removeClass('success').addClass('error').text('Ошибка запуска бота');
            $btn.text('Ошибка');
            setTimeout(function() {
                $btn.text(originalText);
            }, 2000);
        },
        complete: function() {
            $btn.prop('disabled', false);
        }
    });
}

function loadBotHistory() {
    $.get('/api/bot/history')
        .done(function(data) {
            const $tbody = $('#bot-history-table tbody');
            if (!data || data.length === 0) {
                $tbody.html('<tr><td colspan="6" class="loading">Нет записей истории бота за последнюю неделю</td></tr>');
                return;
            }
            let html = '';
            data.forEach(function(e) {
                const ts = e.timestamp ? new Date(e.timestamp) : null;
                const timeText = ts ? ts.toLocaleString('ru-RU') : '-';
                const type = e.type || '-';
                const symbol = e.symbol || '-';

                let actionText = e.action || '';
                if (!actionText && type === 'manual_open') actionText = 'Ручное открытие';
                if (!actionText && type === 'auto_open') actionText = 'Автооткрытие';
                if (!actionText && type === 'close_full') actionText = 'Полное закрытие';
                if (!actionText && type === 'close_partial') actionText = 'Частичное закрытие';
                if (!actionText && type === 'move_sl_to_be') actionText = 'Стоп в безубыток';
                if (!actionText && type === 'average_in') actionText = 'Усреднение';
                if (!actionText && type === 'bot_tick') actionText = 'Тик бота';
                if (!actionText) actionText = '-';

                let resultText = '';
                if (e.skipped) {
                    resultText = 'Пропуск: ' + (e.skipReason || 'условия не выполнены');
                } else if (typeof e.ok !== 'undefined') {
                    resultText = e.ok ? 'Успех' : 'Ошибка';
                    if (!e.ok && e.error) {
                        resultText += ': ' + e.error;
                    }
                } else if (typeof e.managedCount !== 'undefined' || typeof e.openedCount !== 'undefined') {
                    resultText = 'managed=' + (e.managedCount || 0) + ', opened=' + (e.openedCount || 0);
                } else {
                    resultText = '-';
                }

                if (typeof e.realizedPnlEstimate !== 'undefined' && e.realizedPnlEstimate !== null) {
                    const pnlDec = typeof e.pnlAtDecision !== 'undefined' && e.pnlAtDecision !== null
                        ? parseFloat(e.pnlAtDecision).toFixed(2)
                        : 'n/a';
                    const pnlReal = parseFloat(e.realizedPnlEstimate).toFixed(2);
                    resultText += ` (PnL до: ${pnlDec}, реализовано ≈ ${pnlReal})`;
                }

                const note = e.note || '';

                html += `
                    <tr>
                        <td>${timeText}</td>
                        <td>${type}</td>
                        <td>${symbol}</td>
                        <td>${actionText}</td>
                        <td>${resultText}</td>
                        <td>${note}</td>
                    </tr>
                `;
            });
            $('#bot-history-table tbody').html(html);
        })
        .fail(function() {
            $('#bot-history-table tbody').html('<tr><td colspan="6" class="loading">Ошибка загрузки истории бота</td></tr>');
        });
}

function loadTopMarkets() {
    $.get('/api/market/top?limit=20')
        .done(function(data) {
            if (!data || data.length === 0) {
                $('#top-markets-table tbody').html('<tr><td colspan="7" class="loading">Нет данных по рынку</td></tr>');
                return;
            }

            let html = '';
            const top20 = data.slice(0, 20);
            const $sel = $('#symbol-selector');
            const currentVal = $sel.val();
            if ($sel.find('option').length <= 3) {
                $sel.empty();
                top20.forEach(function(item) {
                    $sel.append($('<option></option>').val(item.symbol).text(item.symbol));
                });
                if (currentVal && top20.some(function(i) { return i.symbol === currentVal; })) {
                    $sel.val(currentVal);
                } else if (top20.length) {
                    $sel.val(top20[0].symbol);
                }
            }
            data.forEach(function(item, index) {
                const change = parseFloat(item.price24hPcnt || 0);
                const changeClass = change > 0 ? 'profit' : (change < 0 ? 'loss' : '');
                const lastPrice = parseFloat(item.lastPrice || 0);
                const vol = parseFloat(item.volume24h || 0);
                const turnover = parseFloat(item.turnover24h || 0);

                html += `
                    <tr data-symbol="${item.symbol}">
                        <td><strong>${item.symbol}</strong></td>
                        <td>${lastPrice ? lastPrice.toFixed(4) : '-'}</td>
                        <td class="${changeClass}">${change.toFixed(2)}%</td>
                        <td>${vol.toFixed(2)}</td>
                        <td>${turnover.toFixed(2)}</td>
                        <td>${item.highPrice24h != null ? parseFloat(item.highPrice24h).toFixed(4) : '-'}</td>
                        <td>${item.lowPrice24h != null ? parseFloat(item.lowPrice24h).toFixed(4) : '-'}</td>
                    </tr>
                `;
            });

            $('#top-markets-table tbody').html(html);
        })
        .fail(function() {
            $('#top-markets-table tbody').html('<tr><td colspan="7" class="loading">Ошибка загрузки данных по рынку</td></tr>');
        });
}

function loadProposals() {
    const $tbody = $('#proposals-table tbody');
    $tbody.html('<tr><td colspan="7" class="loading">Загрузка предложений...</td></tr>');
    $.get('/api/analysis/proposals')
        .done(function(data) {
            if (!data || data.length === 0) {
                $tbody.html('<tr><td colspan="7" class="loading">Нет предложений. Включите ChatGPT в настройках.</td></tr>');
                return;
            }
            let html = '';
            data.forEach(function(p) {
                const sideText = (p.signal || '').toUpperCase() === 'BUY' ? 'Long' : 'Short';
                const sideClass = sideText === 'Long' ? 'profit' : 'loss';
                html += `<tr data-proposal='${JSON.stringify(p).replace(/'/g, "&#39;")}'>
                    <td><strong>${p.symbol}</strong></td>
                    <td class="${sideClass}">${sideText}</td>
                    <td>${p.confidence}%</td>
                    <td>${(p.positionSizeUSDT != null ? p.positionSizeUSDT : 10).toFixed(2)}</td>
                    <td>${p.leverage || 1}x</td>
                    <td title="${(p.reason || '').replace(/"/g, '&quot;')}">${(p.reason || '').substring(0, 50)}${(p.reason || '').length > 50 ? '…' : ''}</td>
                    <td><button type="button" class="btn-open-deal btn-small">Открыть</button></td>
                </tr>`;
            });
            $tbody.html(html);
        })
        .fail(function() {
            $tbody.html('<tr><td colspan="7" class="loading">Ошибка загрузки предложений</td></tr>');
        });
}

function openOrderModal(proposal) {
    const symbol = proposal.symbol || '';
    const side = ((proposal.signal || '').toUpperCase() === 'BUY') ? 'Buy' : 'Sell';
    const amount = proposal.positionSizeUSDT != null ? parseFloat(proposal.positionSizeUSDT) : 10;
    const leverage = proposal.leverage != null ? parseInt(proposal.leverage, 10) : 1;

    $('#modal-symbol').val(symbol);
    $('#modal-side').val(side);
    $('#modal-symbol-display').val(symbol);
    $('#modal-side-display').val(side === 'Buy' ? 'Long' : 'Short');
    $('#modal-amount').val(amount);
    $('#modal-leverage').val(leverage);
    $('#modal-message').text('').removeClass('error success');
    $('#open-order-modal').show();
}

function submitOpenOrder() {
    const symbol = $('#modal-symbol').val();
    const side = $('#modal-side').val();
    const positionSizeUSDT = parseFloat($('#modal-amount').val()) || 10;
    const leverage = parseInt($('#modal-leverage').val(), 10) || 1;

    $('#modal-message').text('Отправка...').removeClass('error success');
    $('#modal-submit-btn').prop('disabled', true);

    $.ajax({
        url: '/api/order/open',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ symbol: symbol, side: side, positionSizeUSDT: positionSizeUSDT, leverage: leverage }),
        success: function(res) {
            if (res && res.ok !== false) {
                $('#modal-message').text('Сделка открыта.').addClass('success');
                loadPositions();
                loadBalance();
                setTimeout(function() {
                    $('#open-order-modal').hide();
                }, 800);
            } else {
                $('#modal-message').text(res.error || 'Ошибка').addClass('error');
            }
        },
        error: function(xhr) {
            const msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.statusText || 'Ошибка сети';
            $('#modal-message').text(msg).addClass('error');
        },
        complete: function() {
            $('#modal-submit-btn').prop('disabled', false);
        }
    });
}

$(document).on('click', '#proposals-table tbody tr[data-proposal]', function() {
    const dataAttr = $(this).attr('data-proposal');
    if (!dataAttr) return;
    try {
        const proposal = JSON.parse(dataAttr.replace(/&#39;/g, "'"));
        openOrderModal(proposal);
    } catch (err) {
        console.error(err);
    }
});

function getTradingViewSymbol(symbol) {
    // Для Bybit на TradingView чаще всего используется префикс BYBIT:
    // например, BYBIT:BTCUSDT. При необходимости это место легко поменять.
    return 'BYBIT:' + symbol;
}

function initTradingView(symbol) {
    if (typeof TradingView === 'undefined') {
        console.warn('TradingView library is not loaded yet');
        return;
    }

    const tvSymbol = getTradingViewSymbol(symbol || 'BTCUSDT');

    if (tvWidget && tvReady && typeof tvWidget.chart === 'function') {
        tvWidget.chart().setSymbol(tvSymbol);
        return;
    }

    if (tvWidget && typeof tvWidget.onChartReady === 'function') {
        tvWidget.onChartReady(function() {
            tvReady = true;
            if (typeof tvWidget.chart === 'function') {
                tvWidget.chart().setSymbol(tvSymbol);
            }
        });
        return;
    }

    tvWidget = new TradingView.widget({
        symbol: tvSymbol,
        interval: '60',
        container_id: 'tv-chart-container',
        timezone: 'Etc/UTC',
        theme: 'dark',
        style: '1',
        locale: 'ru',
        toolbar_bg: '#0a0e27',
        hide_side_toolbar: false,
        allow_symbol_change: false,
        withdateranges: true,
        autosize: true,
    });

    if (typeof tvWidget.onChartReady === 'function') {
        tvWidget.onChartReady(function() {
            tvReady = true;
        });
    }
}

function analyzeMarket(symbol) {
    $.get(`/api/market-analysis/${symbol}`)
        .done(function(data) {
            const signalClass = data.signal.toLowerCase();
            const confidenceColor = data.confidence > 70 ? '#4caf50' : data.confidence > 50 ? '#ff9800' : '#f44336';
            
            $('#analysis-result').html(`
                <h4>Анализ рынка: ${data.symbol}</h4>
                <p><strong>Сигнал:</strong> <span style="color: ${confidenceColor}">${data.signal}</span></p>
                <p><strong>Уверенность:</strong> <span style="color: ${confidenceColor}">${data.confidence}%</span></p>
                <p><strong>Обоснование:</strong> ${data.reason}</p>
                <p><small>Время анализа: ${data.timestamp}</small></p>
            `);
        })
        .fail(function() {
            $('#analysis-result').html('<p style="color: #f44336;">Ошибка анализа рынка</p>');
        });
}

function getTradingDecision(symbol) {
    $.get(`/api/trading-decision/${symbol}`)
        .done(function(data) {
            const actionClass = data.action.toLowerCase().replace('_', '-');
            let actionText = '';
            let actionColor = '#888';
            
            switch(data.action) {
                case 'OPEN_LONG':
                    actionText = 'ОТКРЫТЬ ЛОНГ';
                    actionColor = '#4caf50';
                    break;
                case 'OPEN_SHORT':
                    actionText = 'ОТКРЫТЬ ШОРТ';
                    actionColor = '#4caf50';
                    break;
                case 'CLOSE_LONG':
                case 'CLOSE_SHORT':
                    actionText = 'ЗАКРЫТЬ ПОЗИЦИЮ';
                    actionColor = '#ff9800';
                    break;
                default:
                    actionText = 'ДЕРЖАТЬ';
                    actionColor = '#888';
            }
            
            const positionSize = data.positionSizeUSDT != null ? parseFloat(data.positionSizeUSDT).toFixed(2) + ' USDT' : '-';
            const leverage = data.leverage != null ? data.leverage + 'x' : '-';

            $('#decision-result').html(`
                <h4>Решение по торговле: ${data.symbol}</h4>
                <p><strong>Действие:</strong> <span style="color: ${actionColor}; font-size: 18px; font-weight: bold;">${actionText}</span></p>
                <p><strong>Размер сделки:</strong> ${positionSize}</p>
                <p><strong>Плечо:</strong> ${leverage}</p>
                <p><strong>Уверенность:</strong> ${data.confidence}%</p>
                <p><strong>Причина:</strong> ${data.reason}</p>
                <p><small>Время: ${data.timestamp}</small></p>
            `).removeClass('action-open action-close action-hold').addClass('action-' + actionClass.split('-')[0]);
        })
        .fail(function() {
            $('#decision-result').html('<p style="color: #f44336;">Ошибка получения решения</p>');
        });
}

// ─────────────────────────────────────────────────────────────────
// Risk status + Pending confirmations
// ─────────────────────────────────────────────────────────────────

function loadRiskStatus() {
    $.get('/api/bot/risk-status')
        .done(function(data) {
            renderRiskPanel(data);
        })
        .fail(function() {
            $('#risk-status-panel').html('<span style="color:#f85149">Ошибка загрузки статуса защиты</span>');
        });

    $.get('/api/bot/pending')
        .done(function(items) {
            renderPendingTable(items);
        });
}

function renderRiskPanel(data) {
    const ok   = (s) => `<span class="risk-dot risk-ok">●</span> ${s}`;
    const warn = (s) => `<span class="risk-dot risk-warn">●</span> ${s}`;
    const bad  = (s) => `<span class="risk-dot risk-bad">●</span> ${s}`;

    let html = '<div class="risk-grid">';

    // Kill-switch
    html += '<div class="risk-item">' +
        (data.trading_enabled ? ok('Торговля разрешена') : bad('Торговля ОТКЛЮЧЕНА (kill-switch)')) +
        '</div>';

    // Daily loss
    const dl = data.daily_loss_check || {};
    const dlLimit = data.daily_loss_limit_usdt || 0;
    const dlPnl   = dl.daily_pnl != null ? dl.daily_pnl.toFixed(2) : '—';
    if (dlLimit <= 0) {
        html += '<div class="risk-item">' + ok('Дневной лимит: не задан') + '</div>';
    } else if (dl.ok !== false) {
        html += '<div class="risk-item">' + ok(`Дневной PnL: ${dlPnl} USDT (лимит: −${dlLimit})`) + '</div>';
    } else {
        html += '<div class="risk-item">' + bad(`Дневной лимит превышен: ${dlPnl} USDT`) + '</div>';
    }

    // Exposure
    const ex = data.exposure_check || {};
    const exLimit = data.max_total_exposure_usdt || 0;
    const exVal   = ex.total_exposure != null ? ex.total_exposure.toFixed(2) : '—';
    if (exLimit <= 0) {
        html += '<div class="risk-item">' + ok('Макс. риск: не задан') + '</div>';
    } else if (ex.ok !== false) {
        html += '<div class="risk-item">' + ok(`Суммарная маржа: ${exVal} / ${exLimit} USDT`) + '</div>';
    } else {
        html += '<div class="risk-item">' + bad(`Лимит риска превышен: ${exVal} USDT`) + '</div>';
    }

    // Cooldown
    const cd = data.action_cooldown_minutes || 0;
    html += '<div class="risk-item">' + ok(`Кулдаун: ${cd > 0 ? cd + ' мин' : 'откл.'}`) + '</div>';

    // Strict mode
    html += '<div class="risk-item">' +
        (data.strict_mode ? warn('Строгий режим: CLOSE_FULL/AVERAGE_IN требуют подтверждения') : ok('Строгий режим: откл.')) +
        '</div>';

    html += '</div>';

    // Alerts
    if (data.alerts && data.alerts.length > 0) {
        html += '<div class="risk-alerts">' +
            data.alerts.map(a => `<div class="risk-alert-item">⚠ ${a}</div>`).join('') +
            '</div>';
    }

    $('#risk-status-panel').html(html);
}

function renderPendingTable(items) {
    if (!items || items.length === 0) {
        $('#pending-section').hide();
        return;
    }
    $('#pending-section').show();

    let rows = '';
    items.forEach(function(item) {
        const pnl = item.pnlAtDecision != null ? parseFloat(item.pnlAtDecision).toFixed(2) : '—';
        const pnlColor = item.pnlAtDecision > 0 ? '#4caf50' : '#f44336';
        rows += `<tr>
            <td>${(item.created_at || '').replace('T',' ')}</td>
            <td>${item.symbol || '—'}</td>
            <td>${item.side === 'Buy' ? '<span class="side-long">Long</span>' : '<span class="side-short">Short</span>'}</td>
            <td><strong>${item.action || '—'}</strong></td>
            <td style="color:${pnlColor}">${pnl}</td>
            <td style="font-size:0.8em">${item.note || ''}</td>
            <td><button class="btn-primary btn-sm btn-confirm-pending" data-id="${item.id}">✓ Да</button></td>
            <td><button class="btn-secondary btn-sm btn-reject-pending" data-id="${item.id}">✗ Нет</button></td>
        </tr>`;
    });
    $('#pending-table tbody').html(rows);

    // Confirm
    $('.btn-confirm-pending').off('click').on('click', function() {
        const id = $(this).data('id');
        resolvePending(id, true);
    });
    // Reject
    $('.btn-reject-pending').off('click').on('click', function() {
        const id = $(this).data('id');
        resolvePending(id, false);
    });
}

function resolvePending(id, confirm) {
    $.ajax({
        url: '/api/bot/confirm',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ id: id, confirm: confirm })
    })
    .done(function(data) {
        const msg = confirm
            ? (data.ok ? 'Действие исполнено.' : 'Ошибка: ' + (data.error || '?'))
            : 'Действие отклонено.';
        const $bsm = $('#bot-status-message');
        $bsm.removeClass('error success').addClass(data.ok || !confirm ? 'success' : 'error').text(msg).show();
        setTimeout(function() { $bsm.fadeOut(); }, 4000);
        loadRiskStatus();
        loadPositions();
    })
    .fail(function() {
        $('#bot-status-message').removeClass('success').addClass('error').text('Ошибка подтверждения').show();
    });
}
