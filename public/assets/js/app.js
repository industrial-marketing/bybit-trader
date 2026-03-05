let tvWidget = null;
let tvReady = false;
let tvWidgetBot = null;
let tvReadyBot = false;

/**
 * Форматирует цену с учётом величины: малые цены (0.0045) — больше знаков, большие (45000) — меньше.
 * @param {number|string|null} price
 * @returns {string}
 */
function formatPrice(price) {
    const v = parseFloat(price);
    if (isNaN(v) || v === 0) return '-';
    const abs = Math.abs(v);
    if (abs < 0.0001) return v.toFixed(8);
    if (abs < 0.01) return v.toFixed(6);
    if (abs < 1) return v.toFixed(4);
    if (abs < 1000) return v.toFixed(2);
    return v.toFixed(2);
}

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

    $('#pnl-refresh-btn').on('click', loadPnlCharts);

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

    $('#positions-table').on('click', 'tbody tr[data-symbol]', function(e) {
        if ($(e.target).closest('button').length) return;
        const symbol = $(this).data('symbol');
        if (symbol) {
            setBotChartSymbol(symbol);
            switchDashboardPage('bot');
        }
    });

    $('#bot-chart-symbol').on('change', function() {
        const sym = $(this).val();
        if (sym) setBotChartSymbol(sym);
    });

    $('#positions-debug-btn').on('click', function() {
        const $modal = $('#positions-debug-modal');
        const $out = $('#positions-debug-output');
        $modal.show();
        $out.html('Загрузка...');
        $.get('/api/positions/debug')
            .done(function(d) {
                const lines = [
                    'base_url: ' + (d.base_url || '—'),
                    'retCode: ' + (d.retCode ?? '—') + ', retMsg: ' + (d.retMsg || '—'),
                    'raw_count: ' + (d.raw_count ?? '—'),
                    'with_size_gt0: ' + (d.with_size_gt0 ?? '—'),
                    'symbols: ' + (d.symbols && d.symbols.length ? d.symbols.join(', ') : '(пусто)'),
                    'nextPageCursor: ' + (d.nextPageCursor ? 'есть (ещё страницы!)' : 'нет'),
                    d.error ? 'error: ' + d.error : ''
                ].filter(Boolean);
                $out.text(lines.join('\n'));
            })
            .fail(function(xhr) {
                $out.text('Ошибка: ' + (xhr.responseJSON?.error || xhr.statusText || xhr.status));
            });
    });

    $('#positions-debug-close, #positions-debug-modal .modal-backdrop').on('click', function() {
        $('#positions-debug-modal').hide();
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
    loadBotMetrics();
    loadBotDecisions();
    loadPnlCharts();
}

function renderWhyBadge(decision) {
    if (!decision) return '<span style="color:#555">—</span>';
    const action     = decision.action     || '';
    const confidence = decision.confidence != null ? decision.confidence : null;
    const reason     = (decision.reason     || '').substring(0, 80);
    const risk       = decision.risk        || '';
    const skipReason = decision.skip_reason || '';
    const riskColor  = risk === 'low' ? '#4caf50' : risk === 'high' ? '#f85149' : '#ff9800';
    const confText   = confidence != null ? `${confidence}%` : '';
    let overrideText = '';
    if (skipReason === 'locked')             overrideText = '🔒 Заблок.';
    else if (skipReason === 'cooldown')      overrideText = '⏱ Кулдаун';
    else if (skipReason === 'strict_mode_pending') overrideText = '⚠ Ожидание';
    else if (skipReason)                     overrideText = `⛔ ${skipReason}`;

    const fullTitle = [
        action ? `Действие: ${action}` : '',
        confText ? `Уверенность: ${confText}` : '',
        risk ? `Риск: ${risk}` : '',
        skipReason ? `Правило: ${skipReason}` : '',
        reason ? `Причина: ${reason}` : '',
        decision.prompt_version ? `v: ${decision.prompt_version}` : '',
    ].filter(Boolean).join('\n');

    return `<span class="why-badge" title="${fullTitle.replace(/"/g, '&quot;')}">
        ${action ? `<span class="why-action">${action.replace('_', ' ')}</span>` : ''}
        ${confText ? `<span class="why-conf">${confText}</span>` : ''}
        ${risk ? `<span class="why-risk" style="color:${riskColor}">${risk}</span>` : ''}
        ${overrideText ? `<span class="why-override">${overrideText}</span>` : ''}
    </span>`;
}

function loadPositions() {
    $.get('/api/positions', { _t: Date.now() })  // cache-bust
        .done(function(data) {
            if (data.length === 0) {
                $('#positions-table tbody').html('<tr><td colspan="13" class="loading">Нет открытых позиций</td></tr>');
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
                const liqText = liq && !isNaN(liq) && liq !== 0 ? formatPrice(liq) : '-';
                const entryPrice = parseFloat(position.entryPrice || 0);
                const size = parseFloat(position.size || 0);
                const entryUsdt = entryPrice && size ? (entryPrice * size) : 0;
                const levText = position.leverage != null ? String(position.leverage) + 'x' : '-';
                const sideRaw = (position.side || '').toUpperCase();
                const sideText = sideRaw === 'BUY' ? 'Long' : sideRaw === 'SELL' ? 'Short' : (position.side || '');
                const sideBadge = sideRaw === 'BUY'
                    ? `<span class="side-long">↑ Long</span>`
                    : sideRaw === 'SELL'
                        ? `<span class="side-short">↓ Short</span>`
                        : sideText;
                const whyHtml = renderWhyBadge(position.lastDecision || null);
                const lockIcon = locked ? 'bi-lock-fill' : 'bi-unlock';
                const lockLabel = locked ? 'Разблок.' : 'Замок';
                const botStatusHtml = locked
                    ? `<span class="lock-badge"><i class="bi bi-lock-fill"></i> LOCKED</span>`
                    : `<span style="color:var(--positive);font-size:11px;"><i class="bi bi-check-circle"></i> Разрешен</span>`;

                html += `
                    <tr data-symbol="${position.symbol}" data-side="${position.side}">
                        <td><strong>${position.symbol}</strong></td>
                        <td>${sideBadge}</td>
                        <td class="num">${position.size}</td>
                        <td class="num">${entryUsdt ? entryUsdt.toFixed(2) : '-'}</td>
                        <td class="num">${levText}</td>
                        <td class="num">${entryPrice ? formatPrice(entryPrice) : '-'}</td>
                        <td class="num">${formatPrice(position.markPrice)}</td>
                        <td class="num">${liqText}</td>
                        <td class="num ${pnlClass}">${pnlSign}${pnl.toFixed(2)}</td>
                        <td>${position.openedAt}</td>
                        <td class="why-cell">${whyHtml}</td>
                        <td>${botStatusHtml}</td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="btn-small btn-icon-lock btn-pos-lock" title="${lockLabel}">
                                <i class="bi ${lockIcon}"></i>
                            </button>
                            <button type="button" class="btn-small btn-icon-danger btn-pos-close" title="Закрыть позицию">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            $('#positions-table tbody').html(html);
            addPositionSymbolsToSelector(data);
            updateBotChartSymbolSelector(data);
        })
        .fail(function() {
            $('#positions-table tbody').html('<tr><td colspan="13" class="loading">Ошибка загрузки данных</td></tr>');
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
                    priceText = formatPrice(rawPrice);
                } else if (rawTrigger && !isNaN(rawTrigger) && rawTrigger !== 0) {
                    priceText = formatPrice(rawTrigger);
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
                        <td>${formatPrice(trade.price)}</td>
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

            const totalFees = parseFloat(data.totalFees ?? 0);
            $('#stat-total-fees').text('$' + totalFees.toFixed(2));
            
            $('#stat-avg-profit').text('$' + data.averageProfit.toFixed(2));
            
            const maxDrawdown = parseFloat(data.maxDrawdown);
            $('#stat-max-drawdown').text('$' + maxDrawdown.toFixed(2));
            $('#stat-max-drawdown').removeClass('profit loss').addClass(maxDrawdown >= 0 ? 'profit' : 'loss');
            
            $('#stat-profit-factor').text(data.profitFactor.toFixed(2));

            // Diagnostics: source, note
            var diag = $('#stat-diagnostics');
            var parts = [];
            if (data.source && data.source !== 'empty') {
                parts.push('Источник: ' + data.source);
                if (data.closedTradesCount != null) parts.push('closed=' + data.closedTradesCount);
                if (data.tradesCount != null && data.tradesCount > 0) parts.push('trades=' + data.tradesCount);
                if (data.bybitRetCode != null && data.bybitRetCode !== 0) {
                    parts.push('retCode=' + data.bybitRetCode + (data.bybitRetMsg ? ' ' + data.bybitRetMsg : ''));
                }
            }
            if (data.note) parts.push(data.note);
            if (parts.length > 0) diag.html(parts.join(' | ')).show();
            else diag.hide();
        })
        .fail(function() {
            console.error('Ошибка загрузки статистики');
        });
}

var pnlLineChart = null;
var pnlBarChart = null;

function loadPnlCharts() {
    var days = parseInt($('#pnl-period').val() || '30', 10);
    var symbol = $('#pnl-symbol').val() || '';
    $.get('/api/statistics/pnl', { days: days, symbol: symbol || undefined })
        .done(function(data) {
            if (data.bySymbol && data.bySymbol.length > 0) {
                var $sel = $('#pnl-symbol');
                $sel.find('option:not([value=""])').remove();
                data.bySymbol.forEach(function(s){ $sel.append($('<option>').val(s.symbol).text(s.symbol)); });
            }
            renderPnlLineChart(data.series || []);
            renderPnlBarChart(data.bySymbol || []);
        })
        .fail(function() { console.error('PnL charts load failed'); });
}

function renderPnlLineChart(series) {
    var ctx = document.getElementById('pnl-line-chart');
    if (!ctx) return;
    if (pnlLineChart) pnlLineChart.destroy();
    var labels = series.map(function(s){ return s.date; });
    var values = series.map(function(s){ return s.pnl_usdt; });
    var colors = values.map(function(v){ return v >= 0 ? 'rgba(76,175,80,0.8)' : 'rgba(244,67,54,0.8)'; });
    pnlLineChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{ label: 'Daily PnL (USDT)', data: values, borderColor: 'rgb(76,175,80)', backgroundColor: 'rgba(76,175,80,0.1)', fill: true }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
}

function renderPnlBarChart(bySymbol) {
    var ctx = document.getElementById('pnl-bar-chart');
    if (!ctx) return;
    if (pnlBarChart) pnlBarChart.destroy();
    var top10 = bySymbol.slice(0, 10);
    var labels = top10.map(function(s){ return s.symbol; });
    var values = top10.map(function(s){ return s.pnl_usdt; });
    var colors = values.map(function(v){ return v >= 0 ? 'rgba(76,175,80,0.8)' : 'rgba(244,67,54,0.8)'; });
    pnlBarChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{ label: 'PnL by Symbol', data: values, backgroundColor: colors }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
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
            const alertClass = res && res.skipped ? 'warning' : 'success';
            const alertIcon  = res && res.skipped ? 'bi-skip-forward-circle' : 'bi-check-circle-fill';
            $('#bot-status-message').html(
                `<div class="bot-alert ${alertClass}"><i class="bi ${alertIcon}"></i><span>${msg}</span></div>`
            );
            if (typeof window.showToast === 'function') {
                window.showToast(msg, res && res.skipped ? 'info' : 'success');
            }
            // Лёгкий визуальный фидбек через текст кнопки
            $btn.text('Готово');
            // Обновим данные на дашборде после действий бота
            loadDashboard();
            loadBotDecisions();
            loadBotMetrics();
            setTimeout(function() {
                $btn.text(originalText);
            }, 1500);
        },
        error: function(xhr) {
            console.error('Bot tick error', xhr);
            $('#bot-status-message').html(
                `<div class="bot-alert error"><i class="bi bi-exclamation-triangle-fill"></i><span>Ошибка запуска бота</span></div>`
            );
            if (typeof window.showToast === 'function') {
                window.showToast('Ошибка запуска бота', 'error');
            }
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

function mergeSymbolsIntoSelector(topSymbols, positionSymbols) {
    const $sel = $('#symbol-selector');
    const currentVal = $sel.val();
    const existing = {};
    $sel.find('option').each(function() { existing[$(this).val()] = true; });
    const add = function(sym) { if (sym && !existing[sym]) { existing[sym] = true; $sel.append($('<option></option>').val(sym).text(sym)); } };
    (positionSymbols || []).forEach(add);
    (topSymbols || []).forEach(function(i) { add(i.symbol || i); });
    if (currentVal && existing[currentVal]) { $sel.val(currentVal); }
    else if (positionSymbols && positionSymbols[0]) { $sel.val(positionSymbols[0]); }
    else if (topSymbols && topSymbols[0]) { $sel.val(topSymbols[0].symbol || topSymbols[0]); }
}

function addPositionSymbolsToSelector(positions) {
    if (!positions || positions.length === 0) return;
    const symbols = positions.map(function(p) { return p.symbol; }).filter(Boolean);
    const existing = {};
    $('#symbol-selector').find('option').each(function() { existing[$(this).val()] = true; });
    symbols.forEach(function(sym) {
        if (sym && !existing[sym]) { existing[sym] = true; $('#symbol-selector').append($('<option></option>').val(sym).text(sym)); }
    });
}

function loadTopMarkets() {
    $.get('/api/market/top?limit=50')
        .done(function(data) {
            if (!data || data.length === 0) {
                $('#top-markets-table tbody').html('<tr><td colspan="7" class="loading">Нет данных по рынку</td></tr>');
                return;
            }

            let html = '';
            const top50 = data.slice(0, 50);
            mergeSymbolsIntoSelector(top50, []);
            data.forEach(function(item, index) {
                const change = parseFloat(item.price24hPcnt || 0);
                const changeClass = change > 0 ? 'profit' : (change < 0 ? 'loss' : '');
                const lastPrice = parseFloat(item.lastPrice || 0);
                const vol = parseFloat(item.volume24h || 0);
                const turnover = parseFloat(item.turnover24h || 0);

                html += `
                    <tr data-symbol="${item.symbol}">
                        <td><strong>${item.symbol}</strong></td>
                        <td>${formatPrice(lastPrice)}</td>
                        <td class="${changeClass}">${change.toFixed(2)}%</td>
                        <td>${vol.toFixed(2)}</td>
                        <td>${turnover.toFixed(2)}</td>
                        <td>${item.highPrice24h != null ? formatPrice(item.highPrice24h) : '-'}</td>
                        <td>${item.lowPrice24h != null ? formatPrice(item.lowPrice24h) : '-'}</td>
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
                const isBuy = (p.signal || '').toUpperCase() === 'BUY';
                const sideBadge = isBuy
                    ? `<span class="side-long">↑ Long</span>`
                    : `<span class="side-short">↓ Short</span>`;
                html += `<tr data-proposal='${JSON.stringify(p).replace(/'/g, "&#39;")}'>
                    <td><strong>${p.symbol}</strong></td>
                    <td>${sideBadge}</td>
                    <td>${p.confidence}%</td>
                    <td class="num">${(p.positionSizeUSDT != null ? p.positionSizeUSDT : 10).toFixed(2)}</td>
                    <td class="num">${p.leverage || 1}x</td>
                    <td title="${(p.reason || '').replace(/"/g, '&quot;')}">${(p.reason || '').substring(0, 50)}${(p.reason || '').length > 50 ? '…' : ''}</td>
                    <td><button type="button" class="btn-open-deal btn-small"><i class="bi bi-plus-lg"></i> Открыть</button></td>
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
                // Bybit может обновлять список позиций с задержкой 1–2 сек
                setTimeout(function() { loadPositions(); loadBalance(); }, 1500);
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

function updateBotChartSymbolSelector(positions) {
    const $sel = $('#bot-chart-symbol');
    $sel.find('option:not(:first)').remove();
    if (!positions || positions.length === 0) return;
    positions.forEach(function(p) {
        const sym = p.symbol;
        if (sym) $sel.append($('<option></option>').val(sym).text(sym));
    });
}

function setBotChartSymbol(symbol) {
    if (!symbol) return;
    $('#bot-chart-symbol').val(symbol);
    if (typeof TradingView === 'undefined') return;
    const tvSymbol = getTradingViewSymbol(symbol);
    if (tvWidgetBot && tvReadyBot && typeof tvWidgetBot.chart === 'function') {
        tvWidgetBot.chart().setSymbol(tvSymbol);
        return;
    }
    if (tvWidgetBot && typeof tvWidgetBot.onChartReady === 'function') {
        tvWidgetBot.onChartReady(function() {
            tvReadyBot = true;
            if (typeof tvWidgetBot.chart === 'function') tvWidgetBot.chart().setSymbol(tvSymbol);
        });
        return;
    }
    tvWidgetBot = new TradingView.widget({
        symbol: tvSymbol,
        interval: '60',
        container_id: 'tv-chart-container-bot',
        timezone: 'Etc/UTC',
        theme: 'dark',
        style: '1',
        locale: 'ru',
        toolbar_bg: '#0a0e27',
        hide_side_toolbar: false,
        allow_symbol_change: true,
        withdateranges: true,
        autosize: true,
    });
    if (typeof tvWidgetBot.onChartReady === 'function') {
        tvWidgetBot.onChartReady(function() { tvReadyBot = true; });
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
            <td>${item.side === 'Buy' ? '<span class="side-long">↑ Long</span>' : '<span class="side-short">↓ Short</span>'}</td>
            <td><strong>${item.action || '—'}</strong></td>
            <td class="${item.pnlAtDecision > 0 ? 'profit' : item.pnlAtDecision < 0 ? 'loss' : ''}">${pnl}</td>
            <td style="font-size:0.8em">${item.note || ''}</td>
            <td><button class="btn-success btn-sm btn-confirm-pending" data-id="${item.id}"><i class="bi bi-check2"></i> Да</button></td>
            <td><button class="btn-secondary btn-sm btn-reject-pending" data-id="${item.id}"><i class="bi bi-x"></i> Нет</button></td>
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

// ─────────────────────────────────────────────────────────────────
// Bot metrics
// ─────────────────────────────────────────────────────────────────

function loadBotMetrics() {
    $.get('/api/bot/metrics?days=30')
        .done(function(m) {
            renderBotMetrics(m);
        })
        .fail(function() {
            $('#metrics-summary-cards').html('<span style="color:#f85149">Ошибка загрузки метрик</span>');
        });
}

function renderBotMetrics(m) {
    const execRate = m.execution_rate_pct != null ? m.execution_rate_pct + '%' : '—';
    const cards = [
        { label: 'Тиков бота',      value: m.tick_count,        color: '#58a6ff' },
        { label: 'Предложено LLM',  value: m.proposed,          color: '#58a6ff' },
        { label: 'Исполнено',       value: m.executed,          color: '#3fb950' },
        { label: 'Пропущено',       value: m.skipped,           color: '#d29922' },
        { label: 'Ошибок API',      value: m.failed,            color: '#f85149' },
        { label: 'Исп. rate',       value: execRate,            color: '#3fb950' },
        { label: 'Сбоев LLM',      value: m.llm_failures,      color: '#f85149' },
        { label: 'Невал. ответов',  value: m.invalid_responses, color: '#d29922' },
    ];
    let html = '<div class="metrics-cards">';
    cards.forEach(function(c) {
        html += `<div class="metric-card">
            <div class="metric-value" style="color:${c.color}">${c.value != null ? c.value : 0}</div>
            <div class="metric-label">${c.label}</div>
        </div>`;
    });
    html += '</div>';
    $('#metrics-summary-cards').html(html);

    // By-action table
    const ba = m.by_action || {};
    if (Object.keys(ba).length > 0) {
        let tbl = '<h4 style="margin:0 0 8px">По типам действий (30 дней)</h4>';
        tbl += '<table class="metrics-table"><thead><tr><th>Действие</th><th>Предл.</th><th>Исп.</th><th>Пропуск</th><th>Ошибок</th><th>Побед</th><th>Пораж.</th><th>Win%</th><th>PnL ест.</th></tr></thead><tbody>';
        Object.entries(ba).forEach(function([action, s]) {
            const wr = s.win_rate != null ? s.win_rate + '%' : '—';
            const pnlColor = s.total_pnl >= 0 ? '#3fb950' : '#f85149';
            tbl += `<tr>
                <td><strong>${action}</strong></td>
                <td>${s.proposed}</td>
                <td>${s.executed}</td>
                <td>${s.skipped}</td>
                <td>${s.failed}</td>
                <td class="profit">${s.wins}</td>
                <td class="loss">${s.losses}</td>
                <td>${wr}</td>
                <td style="color:${pnlColor}">${s.total_pnl}</td>
            </tr>`;
        });
        tbl += '</tbody></table>';
        $('#metrics-by-action').html(tbl);
    } else {
        $('#metrics-by-action').html('');
    }

    // Skip reasons
    const sr = m.skip_reasons || {};
    if (Object.keys(sr).length > 0) {
        let html2 = '<h4 style="margin:0 0 8px">Причины пропусков</h4><div class="skip-reasons">';
        Object.entries(sr).forEach(function([reason, count]) {
            html2 += `<span class="skip-tag">${reason}: <strong>${count}</strong></span>`;
        });
        html2 += '</div>';
        $('#metrics-skip-reasons').html(html2);
    } else {
        $('#metrics-skip-reasons').html('');
    }

    if (m.data_file) {
        $('#metrics-data-path').text('Файл: ' + m.data_file).show();
    } else {
        $('#metrics-data-path').hide();
    }
}

// ─────────────────────────────────────────────────────────────────
// Decisions trace (Why panel)
// ─────────────────────────────────────────────────────────────────

function loadBotDecisions() {
    $.get('/api/bot/decisions?limit=50')
        .done(function(data) {
            renderDecisionsTable(data);
        })
        .fail(function() {
            $('#decisions-table tbody').html('<tr><td colspan="9" class="loading">Ошибка загрузки</td></tr>');
        });
}

function renderDecisionsTable(data) {
    if (!data || data.length === 0) {
        $('#decisions-table tbody').html('<tr><td colspan="9" class="loading">Нет данных о решениях</td></tr>');
        return;
    }
    let html = '';
    data.forEach(function(d) {
        const ts = d.timestamp ? new Date(d.timestamp).toLocaleString('ru-RU') : '—';
        const sym = d.symbol || '—';
        const action = d.action || (d.type || '—');
        const conf = d.confidence != null ? d.confidence + '%' : '—';
        const risk = d.risk || '—';
        const riskColor = risk === 'low' ? '#3fb950' : risk === 'high' ? '#f85149' : '#d29922';
        const reason = (d.reason || d.note || '').substring(0, 60);
        const skipReason = d.skip_reason || (d.skipReason || '');
        const errDetail = d.error || (d.guard && d.guard.message);
        const ruleText = skipReason ? `<span class="rule-badge">${skipReason}</span>` : (d.ok === false && !d.skipped ? `<span class="rule-badge rule-error" title="${(errDetail || '').replace(/"/g, '&quot;')}">${(errDetail || 'error').substring(0, 40)}${(errDetail && errDetail.length > 40) ? '…' : ''}</span>` : '');
        const pnl = d.realizedPnlEstimate != null ? parseFloat(d.realizedPnlEstimate).toFixed(2) : '—';
        const pnlColor = d.realizedPnlEstimate > 0 ? '#3fb950' : d.realizedPnlEstimate < 0 ? '#f85149' : '';
        const pv = d.prompt_version ? `<span title="prompt version" style="font-size:0.7em;color:#555">${d.prompt_version}</span>` : '';

        const actionClass = d.ok === false && !d.skipped ? 'loss' : (d.skipped ? '' : 'profit');
        html += `<tr>
            <td style="font-size:0.8em">${ts}</td>
            <td><strong>${sym}</strong></td>
            <td class="${actionClass}">${action.replace(/_/g, ' ')}</td>
            <td>${conf}</td>
            <td style="color:${riskColor}">${risk}</td>
            <td style="font-size:0.8em" title="${(d.reason||'').replace(/"/g,'&quot;')}">${reason}${reason.length >= 60 ? '…' : ''}</td>
            <td>${ruleText}</td>
            <td style="color:${pnlColor}">${pnl}</td>
            <td>${pv}</td>
        </tr>`;
    });
    $('#decisions-table tbody').html(html);
}
