/**
 * Попап с историей обращений по автомобилю.
 *
 * Содержимое вкладки вставляется в карточку готовой разметкой, поэтому
 * строки вызывают AlexGarage.open() атрибутом onclick, а не через
 * делегирование: так обработчик не зависит от того, успел ли выполниться
 * этот файл к моменту отрисовки таблицы.
 *
 * Подписи берутся из BX.message: их заполняет garage_tab.php языковыми
 * фразами модуля, чтобы в скрипте не было текста.
 */
(function () {
    'use strict';

    var popup = null;

    function phrase(code) {
        return (typeof BX !== 'undefined' && BX.message(code)) || '';
    }

    function escape(text) {
        return String(text === null || text === undefined ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderDeals(deals) {
        if (!deals || !deals.length) {
            return '<div class="alex-garage-history">'
                + '<div class="empty">' + escape(phrase('ALEX_GARAGE_NO_DEALS')) + '</div></div>';
        }

        var html = '<div class="alex-garage-history">';

        deals.forEach(function (deal) {
            var parts = (deal.PRODUCTS || []).map(function (product) {
                return '<li>' + escape(product.NAME)
                    + ' <span class="qty">× ' + product.QUANTITY + '</span></li>';
            }).join('');

            var stageStyle = deal.STAGE_COLOR
                ? ' style="background:' + escape(deal.STAGE_COLOR) + '"'
                : '';

            html += '<div class="deal">'
                + '<div class="deal-title"><a href="' + escape(deal.URL) + '" target="_blank">'
                + escape(deal.TITLE) + '</a></div>'
                + '<div class="deal-grid">'
                + '<div class="deal-label">' + escape(phrase('ALEX_GARAGE_DATE')) + '</div>'
                + '<div class="deal-value">' + escape(deal.DATE_CREATE) + '</div>'
                + '<div class="deal-label">' + escape(phrase('ALEX_GARAGE_STAGE')) + '</div>'
                + '<div class="deal-value"><span class="stage"' + stageStyle + '>'
                + escape(deal.STAGE_NAME) + '</span></div>'
                + '<div class="deal-label">' + escape(phrase('ALEX_GARAGE_SUM')) + '</div>'
                + '<div class="deal-value">' + escape(deal.SUM) + '</div>'
                + '<div class="deal-label">' + escape(phrase('ALEX_GARAGE_ASSIGNED')) + '</div>'
                + '<div class="deal-value"><a href="' + escape(deal.ASSIGNED_URL) + '" target="_blank">'
                + escape(deal.ASSIGNED) + '</a></div>'
                + '<div class="deal-label">' + escape(phrase('ALEX_GARAGE_PARTS')) + '</div>'
                + '<div class="deal-value">'
                + (parts !== '' ? '<ul class="parts">' + parts + '</ul>' : '—')
                + '</div>'
                + '</div>'
                + '</div>';
        });

        return html + '</div>';
    }

    function showPopup(caption, content) {
        if (typeof BX === 'undefined' || !BX.PopupWindowManager) {
            alert(caption + '\n\n' + phrase('ALEX_GARAGE_NO_POPUP'));
            return;
        }

        if (popup) {
            popup.destroy();
            popup = null;
        }

        popup = BX.PopupWindowManager.create('alex-garage-history', null, {
            titleBar: { content: BX.create('span', { text: caption }) },
            content: content,
            width: 680,
            maxHeight: 540,
            contentPadding: 0,
            closeIcon: true,
            closeByEsc: true,
            overlay: { backgroundColor: '#000', opacity: 20 },
            buttons: [
                new BX.PopupWindowButtonLink({
                    text: phrase('ALEX_GARAGE_CLOSE'),
                    events: { click: function () { popup.close(); } }
                })
            ]
        });

        popup.show();
    }


    function config() {
        return window.AlexGarageData || {};
    }

    function element(tag, attrs, parent) {
        var node = document.createElement(tag);

        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') {
                node.textContent = attrs[key];
            } else if (attrs[key] !== null && attrs[key] !== undefined) {
                node.setAttribute(key, attrs[key]);
            }
        });

        if (parent) {
            parent.appendChild(node);
        }

        return node;
    }

    /**
     * Поле формы: подпись и управляющий элемент внутри неё.
     *
     * @param {HTMLElement} form
     * @param {string} caption
     * @param {string} tag
     * @param {Object} attrs
     * @returns {HTMLElement}
     */
    function formField(form, caption, tag, attrs) {
        var label = element('label', { text: caption }, form);

        return element(tag, attrs, label);
    }

    function fillBrands(select, selectedId) {
        var cfg = config();

        select.innerHTML = '';

        element('option', { value: '0', text: phrase('ALEX_GARAGE_CHOOSE') }, select);

        (cfg.brands || []).forEach(function (brand) {
            var option = element('option', { value: brand.ID, text: brand.NAME }, select);

            if (Number(brand.ID) === Number(selectedId)) {
                option.selected = true;
            }
        });
    }

    function fillModels(select, brandId, selectedId) {
        var cfg = config();

        select.innerHTML = '';

        element('option', { value: '0', text: phrase('ALEX_GARAGE_CHOOSE') }, select);

        (cfg.models || []).forEach(function (model) {
            if (Number(model.BRAND_ID) !== Number(brandId)) {
                return;
            }

            var option = element('option', { value: model.ID, text: model.NAME }, select);

            if (Number(model.ID) === Number(selectedId)) {
                option.selected = true;
            }
        });
    }

    /**
     * Перерисовывает вкладку после изменения данных.
     *
     * Ответ вкладки содержит <script> с фразами и справочником, поэтому
     * просто подставить HTML мало — скрипты надо выполнить отдельно.
     */
    function reloadTab() {
        var cfg = config();
        var container = document.querySelector('.alex-garage');

        if (!container || !cfg.tabUrl) {
            window.location.reload();
            return;
        }

        fetch(cfg.tabUrl + '?contactId=' + encodeURIComponent(cfg.contactId)
            + '&sessid=' + encodeURIComponent(cfg.sessid), { credentials: 'same-origin' })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                if (typeof BX === 'undefined' || !BX.processHTML) {
                    window.location.reload();
                    return;
                }

                var parsed = BX.processHTML(html);
                var holder = document.createElement('div');

                holder.innerHTML = parsed.HTML;

                var fresh = holder.querySelector('.alex-garage');

                if (!fresh) {
                    window.location.reload();
                    return;
                }

                container.parentNode.replaceChild(fresh, container);

                BX.ajax.processScripts(parsed.SCRIPT);
            })
            .catch(function () {
                window.location.reload();
            });
    }

    function send(payload, onError) {
        var cfg = config();
        var body = new URLSearchParams();

        Object.keys(payload).forEach(function (key) {
            body.append(key, payload[key] === null || payload[key] === undefined ? '' : payload[key]);
        });

        body.append('sessid', cfg.sessid);

        fetch(cfg.saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.ok) {
                    onError(result.error || phrase('ALEX_GARAGE_LOAD_ERROR'));
                    return;
                }

                if (popup) {
                    popup.close();
                }

                reloadTab();
            })
            .catch(function (error) {
                onError(String(error));
            });
    }

    /**
     * Форма заведения и правки автомобиля.
     *
     * @param {Object|null} car данные для правки либо null для создания
     */
    function showForm(car) {
        var cfg = config();
        var columns = cfg.columns || {};
        var form = document.createElement('div');

        form.className = 'alex-garage-form';

        var error = element('div', { 'class': 'error' }, form);

        var brand = formField(form, columns.BRAND, 'select', { 'data-role': 'brand' });
        var model = formField(form, columns.MODEL, 'select', { 'data-role': 'model' });
        var number = formField(form, columns.NUMBER, 'input', { type: 'text', maxlength: '32' });
        var year = formField(form, columns.YEAR, 'input', { type: 'number', min: '1950', max: '2100' });
        var color = formField(form, columns.COLOR, 'input', { type: 'text', maxlength: '50' });
        var mileage = formField(form, columns.MILEAGE, 'input', { type: 'number', min: '0' });

        fillBrands(brand, car ? car.BRAND_ID : 0);
        fillModels(model, car ? car.BRAND_ID : 0, car ? car.MODEL_ID : 0);

        brand.onchange = function () {
            fillModels(model, brand.value, 0);
        };

        if (car) {
            number.value = car.NUMBER || '';
            year.value = car.YEAR || '';
            color.value = car.COLOR || '';
            mileage.value = car.MILEAGE || '';
        }

        if (popup) {
            popup.destroy();
            popup = null;
        }

        popup = BX.PopupWindowManager.create('alex-garage-form', null, {
            titleBar: {
                content: BX.create('span', {
                    text: car
                        ? phrase('ALEX_GARAGE_FORM_TITLE_EDIT')
                        : phrase('ALEX_GARAGE_FORM_TITLE_ADD')
                })
            },
            content: form,
            width: 520,
            contentPadding: 0,
            closeIcon: true,
            closeByEsc: true,
            overlay: { backgroundColor: '#000', opacity: 20 },
            buttons: [
                new BX.PopupWindowButton({
                    text: phrase('ALEX_GARAGE_SAVE'),
                    className: 'popup-window-button-accept',
                    events: {
                        click: function () {
                            error.textContent = '';

                            send({
                                action: car ? 'update' : 'create',
                                id: car ? car.ID : 0,
                                contactId: cfg.contactId,
                                brandId: brand.value,
                                modelId: model.value,
                                number: number.value,
                                year: year.value,
                                color: color.value,
                                mileage: mileage.value
                            }, function (message) {
                                error.textContent = message;
                            });
                        }
                    }
                }),
                new BX.PopupWindowButtonLink({
                    text: phrase('ALEX_GARAGE_CANCEL'),
                    events: { click: function () { popup.close(); } }
                })
            ]
        });

        popup.show();
    }

    /**
     * @param {HTMLElement} node кнопка внутри строки таблицы
     * @returns {Object|null}
     */
    function rowCar(node) {
        var row = node.closest('.alex-garage-row');

        if (!row) {
            return null;
        }

        try {
            return JSON.parse(row.getAttribute('data-car'));
        } catch (e) {
            console.error('AlexGarage: не разобраны данные строки', e, row.getAttribute('data-car'));

            return null;
        }
    }

    window.AlexGarage = {
        /**
         * @param {HTMLElement} row строка таблицы гаража
         */
        open: function (row) {
            var container = row.closest('.alex-garage');
            var sessid = container ? container.getAttribute('data-sessid') : '';
            var carId = row.getAttribute('data-car-id');
            var caption = row.getAttribute('data-caption') || '';

            showPopup(caption, '<div style="padding:18px;color:#828b95">'
                + escape(phrase('ALEX_GARAGE_LOADING')) + '</div>');

            fetch('/alex_autoservice/garage_history.php?carId=' + encodeURIComponent(carId)
                + '&sessid=' + encodeURIComponent(sessid), { credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        showPopup(caption, '<div style="padding:18px;color:#d64646">'
                            + escape(data.error || phrase('ALEX_GARAGE_LOAD_ERROR')) + '</div>');
                        return;
                    }

                    showPopup(data.caption || caption, renderDeals(data.deals));
                })
                .catch(function (error) {
                    showPopup(caption, '<div style="padding:18px;color:#d64646">'
                        + escape(error) + '</div>');
                });
        },

        /**
         * Заведение автомобиля текущему клиенту.
         */
        add: function () {
            showForm(null);
        },

        /**
         * @param {HTMLElement} node кнопка правки в строке
         */
        edit: function (node) {
            var car = rowCar(node);

            if (car) {
                showForm(car);
            }
        },

        /**
         * @param {HTMLElement} node кнопка удаления в строке
         */
        remove: function (node) {
            var car = rowCar(node);

            if (!car || !window.confirm(phrase('ALEX_GARAGE_DELETE_CONFIRM'))) {
                return;
            }

            send({ action: 'delete', id: car.ID }, function (message) {
                showPopup(phrase('ALEX_GARAGE_DELETE'),
                    '<div style="padding:18px;color:#d64646">' + escape(message) + '</div>');
            });
        }
    };
})();
