<?php

namespace Alex\Autoservice\Service;

use Bitrix\Main\Loader;

/**
 * Уведомления сотрудникам через мессенджер портала.
 *
 * Если модуль im недоступен, сообщение не теряется, а уходит в журнал
 * проекта — иначе бизнес-логика молча лишалась бы обратной связи.
 */
class Notifier
{
    private const MODULE_ID = 'alex.autoservice';

    /**
     * @param int    $userId  получатель
     * @param string $message текст уведомления
     * @param string $tag     метка для схлопывания повторов
     *
     * @return bool
     */
    public static function toUser(int $userId, string $message, string $tag = ''): bool
    {
        Log::add([
            'stage'   => 'NOTIFY',
            'user'    => $userId,
            'tag'     => $tag,
            'message' => $message,
        ]);

        if ($userId <= 0 || !Loader::includeModule('im') || !class_exists('\CIMNotify')) {
            return false;
        }

        $fields = [
            'TO_USER_ID'     => $userId,
            'FROM_USER_ID'   => 0,
            'NOTIFY_TYPE'    => defined('IM_NOTIFY_SYSTEM') ? IM_NOTIFY_SYSTEM : 1,
            'NOTIFY_MODULE'  => self::MODULE_ID,
            'NOTIFY_MESSAGE' => $message,
        ];

        if ($tag !== '') {
            $fields['NOTIFY_TAG'] = self::MODULE_ID . '|' . $tag;
        }

        return (bool) \CIMNotify::Add($fields);
    }

    /**
     * @param int[]  $userIds
     * @param string $message
     * @param string $tag
     *
     * @return int сколько уведомлений отправлено
     */
    public static function toUsers(array $userIds, string $message, string $tag = ''): int
    {
        $sent = 0;

        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $userId) {
            if (self::toUser($userId, $message, $tag)) {
                $sent++;
            }
        }

        return $sent;
    }
}
