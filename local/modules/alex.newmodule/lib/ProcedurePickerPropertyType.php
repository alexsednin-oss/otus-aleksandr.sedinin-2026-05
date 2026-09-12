<?php

namespace Alex\Newmodule;

use Bitrix\Main\Loader;

/**
 * Кастомный тип свойства инфоблока: в списке/карточке врача показывает кликабельные
 * ссылки на его процедуры. Клик открывает попап записи на приём.
 *
 * Значение свойства — CSV из ID процедур, его пишет ProcedureSyncHandler
 * на OnAfterIBlockElementAdd / OnAfterIBlockElementUpdate.
 */
class ProcedurePickerPropertyType
{
    public const USER_TYPE = 'DoctorProcedurePicker';

    /** Скрипт попапа печатаем один раз на страницу */
    private static bool $scriptPrinted = false;

    public static function GetUserTypeDescription(): array
    {
        return [
            'PROPERTY_TYPE'        => 'S',
            'USER_TYPE'            => self::USER_TYPE,
            'DESCRIPTION'          => 'Процедуры врача (запись на приём)',
            'GetPropertyFieldHtml' => [__CLASS__, 'GetPropertyFieldHtml'],
            'GetAdminListViewHTML' => [__CLASS__, 'GetAdminListViewHTML'],
            'GetPublicViewHTML'    => [__CLASS__, 'GetAdminListViewHTML'],
            'ConvertToDB'          => [__CLASS__, 'ConvertToDB'],
            'ConvertFromDB'        => [__CLASS__, 'ConvertFromDB'],
        ];
    }

    public static function ConvertToDB($property, $value)
    {
        return $value;
    }

    public static function ConvertFromDB($property, $value)
    {
        return $value;
    }

    /**
     * Колонка в списке элементов инфоблока в админке.
     */
    public static function GetAdminListViewHTML($property, $value, $strHTMLControlName)
    {
        return self::renderLinks((string) ($value['VALUE'] ?? ''));
    }

    /**
     * Поле в форме редактирования элемента. Скрытый input нужен, чтобы значение
     * не затиралось при сохранении формы (виджет сам по себе ничего не отправляет).
     */
    public static function GetPropertyFieldHtml($property, $value, $strHTMLControlName)
    {
        $csv = trim((string) ($value['VALUE'] ?? ''));

        $inputName = $strHTMLControlName['VALUE']
            ?? 'PROP[' . (int) ($property['ID'] ?? 0) . '][0][VALUE]';

        return '<input type="hidden" name="' . htmlspecialcharsbx($inputName) . '"'
            . ' value="' . htmlspecialcharsbx($csv) . '">'
            . self::renderLinks($csv)
            . '<div style="color:#888;font-size:11px;margin-top:6px;">'
            . 'Заполняется автоматически из свойства «' . ProcedureSyncHandler::SOURCE_PROPERTY_CODE
            . '» при сохранении врача.</div>';
    }

    private static function renderLinks(string $csv): string
    {
        $csv = trim($csv);

        if ($csv === '') {
            return '<span style="color:#999;">Нет привязанных процедур</span>';
        }

        $ids = array_values(array_filter(array_map('intval', explode(',', $csv))));

        if (!$ids) {
            return '<span style="color:#999;">Нет привязанных процедур</span>';
        }

        Loader::includeModule('iblock');

        $names = [];
        $res   = \CIBlockElement::GetList(['SORT' => 'ASC'], ['ID' => $ids], false, false, ['ID', 'NAME']);

        while ($row = $res->Fetch()) {
            $names[(int) $row['ID']] = (string) $row['NAME'];
        }

        if (!$names) {
            return '<span style="color:#999;">Процедуры не найдены (ID: ' . htmlspecialcharsbx($csv) . ')</span>';
        }

        $html = self::getPopupScriptOnce();
        $html .= '<div class="alex-newmodule-proc-widget">';

        foreach ($names as $id => $name) {
            $html .= '<a href="#" onclick="AlexNewmoduleOpenBookingPopup(' . (int) $id . ', '
                . \CUtil::PhpToJSObject($name) . '); return false;">'
                . htmlspecialcharsbx($name) . '</a><br>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function getPopupScriptOnce(): string
    {
        if (self::$scriptPrinted) {
            return '';
        }

        self::$scriptPrinted = true;

        return self::getPopupScript();
    }

    private static function getPopupScript(): string
    {
        return <<<'HTML'
<script>
if (typeof window.AlexNewmoduleOpenBookingPopup === 'undefined') {
    window.AlexNewmoduleOpenBookingPopup = function (procedureId, procedureName) {
        BX.loadScript('/bitrix/js/main/core/core_window.js', function () {
            var popupId = 'alex_nm_booking_popup';
            var existing = BX.PopupWindowManager.getPopupById(popupId);

            if (existing) {
                existing.destroy();
            }

            var content = BX.create('div', {
                attrs: {style: 'padding:16px;min-width:320px;'},
                html: '' +
                    '<div style="margin-bottom:10px;">' +
                        '<label style="display:block;margin-bottom:4px;">ФИО пациента</label>' +
                        '<input type="text" class="alex-nm-patient" style="width:100%;padding:6px;box-sizing:border-box;">' +
                    '</div>' +
                    '<div style="margin-bottom:10px;">' +
                        '<label style="display:block;margin-bottom:4px;">Время записи</label>' +
                        '<input type="datetime-local" class="alex-nm-time" style="width:100%;padding:6px;box-sizing:border-box;">' +
                    '</div>' +
                    '<div class="alex-nm-message" style="margin-bottom:10px;min-height:18px;"></div>' +
                    '<button type="button" class="alex-nm-submit adm-btn-save">Создать бронирование</button>'
            });

            var popup = BX.PopupWindowManager.create(popupId, null, {
                titleBar: 'Запись на процедуру: ' + procedureName,
                content: content,
                width: 380,
                overlay: true,
                closeIcon: true,
                closeByEsc: true,
                autoHide: false
            });

            var patientInput = content.querySelector('.alex-nm-patient');
            var timeInput = content.querySelector('.alex-nm-time');
            var messageBox = content.querySelector('.alex-nm-message');
            var submitBtn = content.querySelector('.alex-nm-submit');

            var showError = function (text) {
                messageBox.style.color = '#c00';
                messageBox.innerHTML = BX.util.htmlspecialchars(text);
            };

            BX.bind(submitBtn, 'click', function () {
                var patientName = patientInput.value.replace(/^\s+|\s+$/g, '');
                var appointmentTime = timeInput.value;

                messageBox.innerHTML = '';

                if (!patientName || !appointmentTime) {
                    showError('Заполните оба поля');
                    return;
                }

                submitBtn.disabled = true;

                BX.ajax({
                    url: '/alex_newmodule/booking_create.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        PROCEDURE_ID: procedureId,
                        PATIENT_NAME: patientName,
                        APPOINTMENT_TIME: appointmentTime,
                        sessid: BX.bitrix_sessid()
                    },
                    onsuccess: function (result) {
                        submitBtn.disabled = false;

                        if (result && result.success) {
                            messageBox.style.color = '#3a8600';
                            messageBox.innerHTML = 'Бронирование создано на ' +
                                BX.util.htmlspecialchars(result.time || appointmentTime);

                            setTimeout(function () {
                                popup.close();
                            }, 1200);
                        } else {
                            showError((result && result.error) ? result.error : 'Неизвестная ошибка');
                        }
                    },
                    onfailure: function () {
                        submitBtn.disabled = false;
                        showError('Ошибка запроса к серверу');
                    }
                });
            });

            popup.show();
            patientInput.focus();
        });
    };
}
</script>
HTML;
    }
}
