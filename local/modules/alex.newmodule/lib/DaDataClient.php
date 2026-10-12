<?php

namespace Alex\Newmodule;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Клиент сервиса DaData: поиск организации по ИНН.
 *
 * Токен хранится в настройках модуля:
 *   Option::set('alex.newmodule', 'dadata_token', '<ключ>');
 *
 * @see https://dadata.ru/api/find-party/
 */
class DaDataClient
{
    public const MODULE_ID = 'alex.newmodule';
    public const URL       = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party';

    public static function getToken(): string
    {
        return trim((string) Option::get(self::MODULE_ID, 'dadata_token', ''));
    }

    /**
     * Ищет организацию по ИНН.
     *
     * @return array|null Найденная подсказка: ['value' => 'ООО «Ромашка»', 'data' => [...]]
     *                    или null, если ничего не нашлось.
     *
     * @throws \RuntimeException при ошибке конфигурации или ответа сервиса
     */
    public static function findByInn(string $inn, string $kpp = ''): ?array
    {
        $inn = preg_replace('/\D+/', '', $inn);

        if ($inn === '') {
            throw new \RuntimeException('ИНН не указан');
        }

        if (!in_array(strlen($inn), [10, 12], true)) {
            throw new \RuntimeException('ИНН должен содержать 10 или 12 цифр, получено: ' . strlen($inn));
        }

        $token = self::getToken();

        if ($token === '') {
            throw new \RuntimeException(
                'Не задан токен DaData. Установите настройку модуля: '
                . "Option::set('alex.newmodule', 'dadata_token', '<ключ>')"
            );
        }

        $query = ['query' => $inn, 'count' => 1];

        if ($kpp !== '') {
            $query['kpp'] = $kpp;
        }

        $http = new HttpClient([
            'socketTimeout' => 10,
            'streamTimeout' => 15,
            'waitResponse'  => true,
        ]);

        $http->setHeader('Content-Type', 'application/json');
        $http->setHeader('Accept', 'application/json');
        $http->setHeader('Authorization', 'Token ' . $token);

        $response = $http->post(self::URL, Json::encode($query));
        $status   = (int) $http->getStatus();

        if ($response === false) {
            $errors  = $http->getError();
            $message = is_array($errors) ? implode('; ', $errors) : 'нет ответа';

            throw new \RuntimeException('DaData недоступна: ' . $message);
        }

        if ($status === 401 || $status === 403) {
            throw new \RuntimeException('DaData: неверный или заблокированный токен (HTTP ' . $status . ')');
        }

        if ($status === 429) {
            throw new \RuntimeException('DaData: превышен лимит запросов (HTTP 429)');
        }

        if ($status !== 200) {
            throw new \RuntimeException('DaData: неожиданный ответ HTTP ' . $status);
        }

        try {
            $result = Json::decode((string) $response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('DaData: не удалось разобрать ответ — ' . $e->getMessage());
        }

        $suggestion = $result['suggestions'][0] ?? null;

        return is_array($suggestion) ? $suggestion : null;
    }
}
