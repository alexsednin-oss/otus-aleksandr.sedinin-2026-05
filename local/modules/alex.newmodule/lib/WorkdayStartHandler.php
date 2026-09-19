<?php

namespace Alex\Newmodule;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Подтверждение начала рабочего дня.
 */
class WorkdayStartHandler
{
    public const MODULE_ID   = 'alex.newmodule';
    public const SESSION_KEY = 'ALEX_NEWMODULE_WORKDAY_CONFIRMED';
    public const SCRIPT_PATH = '/alex_newmodule/workday_start.js';
    public const CONFIRM_URL = '/alex_newmodule/workday_confirm.php';
    public const CONFIRM_TTL = 60;

    public static function onProlog(): void
    {
        global $USER, $APPLICATION;

        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
            return;
        }

        if (defined('PUBLIC_AJAX_MODE') && PUBLIC_AJAX_MODE === true) {
            return;
        }

        if (!is_object($USER) || !$USER->IsAuthorized()) {
            return;
        }

        if (!Loader::includeModule('timeman')) {
            return;
        }

        $APPLICATION->AddHeadString(
            '<script>window.AlexWorkdayOptions = ' . \CUtil::PhpToJSObject(self::getPopupOptions()) . ';</script>',
            true
        );

        $APPLICATION->AddHeadScript(self::SCRIPT_PATH);
    }

    public static function getPopupOptions(): array
    {
        return [
            'title'  => Option::get(
                self::MODULE_ID,
                'workday_popup_title',
                'Начало рабочего дня'
            ),
            'text'   => Option::get(
                self::MODULE_ID,
                'workday_popup_text',
                'Подтвердите начало рабочего дня. Проверьте, что вы на рабочем месте, '
                . 'задачи на день распределены, а отчёт за предыдущий день отправлен.'
            ),
            'button' => Option::get(
                self::MODULE_ID,
                'workday_popup_button',
                'Начать рабочий день'
            ),

            // Вариант для продолжения дня после паузы.
            // Пустой текст = используется общий текст окна.
            'titleContinue'  => Option::get(
                self::MODULE_ID,
                'workday_popup_title_continue',
                'Продолжение рабочего дня'
            ),
            'textContinue'   => Option::get(
                self::MODULE_ID,
                'workday_popup_text_continue',
                'Подтвердите продолжение рабочего дня.'
            ),
            'buttonContinue' => Option::get(
                self::MODULE_ID,
                'workday_popup_button_continue',
                'Продолжить рабочий день'
            ),

            'url'    => self::CONFIRM_URL,
            'debug'  => Option::get(self::MODULE_ID, 'workday_debug', 'N') === 'Y',
        ];
    }

    /**
     * @return bool|void
     */
    public static function onBeforeDayStart()
    {
        if (Option::get(self::MODULE_ID, 'workday_strict', 'Y') !== 'Y') {
            return;
        }

        if (self::isStartAllowed()) {
            // Подтверждение одноразовое: «съедаем» его на первом же старте дня
            self::clearStart();

            return;
        }

        global $APPLICATION;

        if (is_object($APPLICATION)) {
            $APPLICATION->ThrowException(
                'Рабочий день можно начать только после подтверждения в окне.'
            );
        }

        return false;
    }

    public static function allowStart(): void
    {
        self::sessionSet(time());
    }

    /**
     * @return void
     */
    public static function clearStart(): void
    {
        self::sessionSet(null);
    }

    /**
     * @return bool
     */
    public static function isStartAllowed(): bool
    {
        $stamp = (int) self::sessionGet();

        return $stamp > 0 && (time() - $stamp) <= self::CONFIRM_TTL;
    }

    /**
     * @return bool
     */
    public static function isDebug(): bool
    {
        return Option::get(self::MODULE_ID, 'workday_debug', 'N') === 'Y';
    }

    public static function getState(int $userId): string
    {
        if (!$userId || !Loader::includeModule('timeman')) {
            return '';
        }

        if (!class_exists('\CTimeMan') || !method_exists('\CTimeMan', 'State')) {
            return '';
        }

        try {
            $timeman = new \CTimeMan();
            $state   = $timeman->State($userId);
        } catch (\Throwable $e) {
            return '';
        }

        if (!is_array($state)) {
            return '';
        }

        return (string) ($state['STATUS'] ?? '');
    }

    public static function getTimemanApiInfo(): array
    {
        $info = ['CTimeMan' => 'нет класса'];

        if (class_exists('\CTimeMan')) {
            $methods = get_class_methods('\CTimeMan');
            sort($methods);
            $info['CTimeMan'] = $methods;
        }

        foreach ([
                     '\Bitrix\Timeman\Service\DependencyManager',
                     '\Bitrix\Timeman\Service\Worktime\Action\WorktimeActionService',
                     '\Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordTable',
                     '\Bitrix\Timeman\Controller\Worktime\Record',
                 ] as $class) {
            $info[ltrim($class, '\\')] = class_exists($class) ? 'есть' : 'нет';
        }

        return $info;
    }

    /**
     * @param $value
     * @return void
     */
    private static function sessionSet($value): void
    {
        $session = self::getSession();

        if ($session !== null) {
            if ($value === null) {
                unset($session[self::SESSION_KEY]);
            } else {
                $session[self::SESSION_KEY] = $value;
            }

            return;
        }

        if ($value === null) {
            unset($_SESSION[self::SESSION_KEY]);
        } else {
            $_SESSION[self::SESSION_KEY] = $value;
        }
    }

    /**
     * @return mixed|null
     */
    private static function sessionGet()
    {
        $session = self::getSession();

        if ($session !== null) {
            return $session[self::SESSION_KEY] ?? null;
        }

        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * @return \Bitrix\Main\Session\SessionInterface|null
     */
    private static function getSession()
    {
        try {
            return Application::getInstance()->getSession();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
