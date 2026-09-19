/**
 * Подтверждение начала рабочего дня.
 */
(function () {
    'use strict';

    if (window.AlexWorkdayGuardInited) {
        return;
    }

    window.AlexWorkdayGuardInited = true;

    var options = window.AlexWorkdayOptions || {};
    var URL = options.url || '/alex_newmodule/workday_confirm.php';
    var DEBUG = !!options.debug;
    var TEXTS = {
        start: {
            title: options.title || 'Начало рабочего дня',
            text: options.text || 'Подтвердите начало рабочего дня.',
            button: options.button || 'Начать рабочий день'
        },
        continued: {
            title: options.titleContinue || options.title || 'Продолжение рабочего дня',
            text: options.textContinue || options.text || 'Подтвердите продолжение рабочего дня.',
            button: options.buttonContinue || 'Продолжить рабочий день'
        }
    };

    var mode = 'start';

    var SELECTORS = [
        '[data-tm-action="open"]',
        '[data-timeman-action="open"]',
        '.timeman-status-button',
        '.tm-status-button',
        '#tm-status-button',
        '.bx-timeman-status-open',
        '.timeman-open-day',
        '.ui-timeman-start'
    ];

    var PHRASES = [
        /начать\s+рабочий\s+день/i,
        /продолжить\s+рабочий\s+день/i,
        /^продолжить$/i,
        /start\s+workday/i,
        /continue\s+workday/i
    ];

    var popup = null;
    var busy = false;
    var sourceButton = null;
    var passThrough = false;

    function log() {
        if (DEBUG && window.console) {
            console.log.apply(console, ['[AlexWorkday]'].concat([].slice.call(arguments)));
        }
    }

    function nodeText(node) {
        var text = (node.innerText || node.textContent || '').replace(/\s+/g, ' ').replace(/^ | $/g, '');

        return text.length > 60 ? '' : text;
    }

    function matchesPhrase(text) {
        for (var i = 0; i < PHRASES.length; i++) {
            if (PHRASES[i].test(text)) {
                return true;
            }
        }

        return false;
    }

    function closestMatch(node, selector) {
        return node.closest ? node.closest(selector) : null;
    }

    function findStartButton(target) {
        if (!target || target.nodeType !== 1) {
            return null;
        }

        var i;

        for (i = 0; i < SELECTORS.length; i++) {
            var bySelector = closestMatch(target, SELECTORS[i]);

            if (bySelector) {
                log('совпадение по селектору', SELECTORS[i], bySelector);

                return bySelector;
            }
        }

        var node = target;

        for (i = 0; i < 5 && node && node.nodeType === 1; i++) {
            var text = nodeText(node);

            if (text && matchesPhrase(text)) {
                var inTimemanBlock = closestMatch(node, '[class*="timeman"], [id*="timeman"], [class*="tm-status"]');
                var rect = node.getBoundingClientRect();
                var nearTop = rect.top < 200;

                if (inTimemanBlock || nearTop) {
                    log('совпадение по тексту', text, node);

                    return node;
                }
            }

            node = node.parentElement;
        }

        return null;
    }

    function detectMode(node) {
        var text = nodeText(node).toLowerCase();

        if (/продолж|continue/.test(text)) {
            return 'continued';
        }

        if (/начать|start/.test(text)) {
            return 'start';
        }

        var block = closestMatch(node, '[class*="timeman"], [id*="timeman"], [class*="tm-status"]');

        if (block) {
            var blockText = (block.innerText || block.textContent || '').toLowerCase();

            if (/продолж|continue/.test(blockText)) {
                return 'continued';
            }
        }

        return 'start';
    }

    document.addEventListener('click', function (event) {
        if (passThrough) {
            passThrough = false;
            log('клик пропущен к штатному обработчику');

            return;
        }

        if (busy) {
            return;
        }

        var button = findStartButton(event.target);

        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (event.stopImmediatePropagation) {
            event.stopImmediatePropagation();
        }

        sourceButton = event.target && event.target.nodeType === 1 ? event.target : button;
        mode = detectMode(button);

        log('режим окна:', mode);

        showPopup();
    }, true);

    function buildContent() {
        var texts = TEXTS[mode] || TEXTS.start;
        var wrap = document.createElement('div');

        wrap.className = 'alex-workday-popup';
        wrap.style.cssText = 'padding:18px 20px;max-width:420px;font-size:14px;line-height:1.5;';

        var textNode = document.createElement('div');

        textNode.style.cssText = 'margin-bottom:16px;white-space:pre-line;';
        textNode.textContent = texts.text;

        var message = document.createElement('div');

        message.className = 'alex-workday-message';
        message.style.cssText = 'min-height:18px;margin-bottom:12px;';

        var button = document.createElement('button');

        button.type = 'button';
        button.className = 'alex-workday-submit ui-btn ui-btn-success';
        button.textContent = texts.button;
        button.style.cssText = 'padding:8px 18px;cursor:pointer;';

        wrap.appendChild(textNode);
        wrap.appendChild(message);
        wrap.appendChild(button);

        button.onclick = function () {
            confirmAndStart(button, message);
        };

        return wrap;
    }

    function showPopup() {
        var texts = TEXTS[mode] || TEXTS.start;
        var content = buildContent();

        if (window.BX && BX.PopupWindowManager) {
            if (popup) {
                popup.destroy();
                popup = null;
            }

            popup = BX.PopupWindowManager.create('alex_workday_popup', null, {
                titleBar: texts.title,
                content: content,
                width: 460,
                overlay: true,
                closeIcon: true,
                closeByEsc: true,
                autoHide: true,
                events: {
                    onPopupClose: function () {
                        log('окно закрыто — старт дня отменён');
                    }
                }
            });

            popup.show();

            return;
        }

        showFallbackPopup(content, texts.title);
    }

    function showFallbackPopup(content, title) {
        var overlay = document.createElement('div');

        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:10000;' +
            'display:flex;align-items:center;justify-content:center;';

        var box = document.createElement('div');

        box.style.cssText = 'background:#fff;border-radius:6px;box-shadow:0 6px 24px rgba(0,0,0,.3);min-width:360px;';

        var head = document.createElement('div');

        head.style.cssText = 'padding:14px 20px;border-bottom:1px solid #e6e6e6;font-weight:600;';
        head.textContent = title || TEXTS.start.title;

        box.appendChild(head);
        box.appendChild(content);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        var close = function () {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }

            log('окно закрыто — старт дня отменён');
        };

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                close();
            }
        });

        document.addEventListener('keydown', function onEsc(event) {
            if (event.key === 'Escape') {
                document.removeEventListener('keydown', onEsc);
                close();
            }
        });

        popup = {
            close: close,
            destroy: close
        };
    }

    function closePopup() {
        if (popup) {
            popup.close();
        }
    }

    function sessid() {
        if (window.BX && BX.bitrix_sessid) {
            return BX.bitrix_sessid();
        }

        var input = document.querySelector('input[name="sessid"]');

        return input ? input.value : '';
    }

    function confirmAndStart(button, message) {
        busy = true;
        button.disabled = true;
        message.style.color = '#555';
        message.textContent = mode === 'continued' ? 'Продолжаем рабочий день…' : 'Открываем рабочий день…';

        var data = new FormData();

        data.append('sessid', sessid());

        fetch(URL, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                busy = false;

                if (result && result.api) {
                    log('timeman API', result.api);
                }

                if (result && result.success) {
                    closePopup();
                    replayClick();

                    return;
                }

                button.disabled = false;
                message.style.color = '#c00';
                message.textContent = (result && result.error)
                    ? result.error
                    : (mode === 'continued' ? 'Не удалось продолжить рабочий день' : 'Не удалось начать рабочий день');
            })
            .catch(function () {
                busy = false;
                button.disabled = false;
                message.style.color = '#c00';
                message.textContent = 'Ошибка запроса к серверу';
            });
    }

    function replayClick() {
        if (!sourceButton) {
            log('нет исходной кнопки для повторного клика');

            return;
        }

        passThrough = true;

        setTimeout(function () {
            passThrough = false;
        }, 2000);

        log('повторный клик по', sourceButton);

        if (window.BX && BX.fireEvent) {
            BX.fireEvent(sourceButton, 'click');
        } else {
            sourceButton.dispatchEvent(new MouseEvent('click', {
                bubbles: true,
                cancelable: true,
                view: window
            }));
        }
    }
}());
