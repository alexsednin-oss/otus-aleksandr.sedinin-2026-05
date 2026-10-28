<?php

namespace Alex\Newmodule;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Rest\Event\ProviderInterface;
use Bitrix\Rest\Event\Sender;

/**
 * Локальная доставка событий REST-приложениям.
 */
class LocalEventProvider implements ProviderInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Обработчик rest/onEventManagerInitialize: подменяем провайдера доставки.
     */
    public static function onEventManagerInitialize($event = null): void
    {
        if (class_exists('\Bitrix\Rest\Event\Sender')) {
            Sender::setProvider(self::instance());
        }
    }

    /**
     * @param array $queryData элементы очереди Sender::$queryData
     */
    public function send(array $queryData)
    {
        // Доставка идёт в фоновом задании на тот же сервер. Если не отпустить
        // сессию, обработчик встанет на session_start() до таймаута.
        if (function_exists('session_id') && session_id() !== '') {
            session_write_close();
        }

        foreach ($queryData as $item) {
            $this->sendOne((array) $item);
        }
    }

    private function sendOne(array $item): void
    {
        $query = (array) ($item['query'] ?? []);
        $url   = (string) ($query['QUERY_URL'] ?? '');
        $data  = (array) ($query['QUERY_DATA'] ?? []);

        if ($url === '' || !$data) {
            return;
        }

        $additional = (array) ($item['additional'] ?? []);

        // Облако прикладывает auth только если подписчик его запрашивал
        if (!empty($additional['sendAuth'])) {
            $data['auth'] = $this->buildAuth($item, $query);
        }

        $http = new HttpClient([
            'socketTimeout'          => 5,
            'streamTimeout'          => 15,
            'waitResponse'           => true,
            'disableSslVerification' => true, // коробка часто с самоподписанным сертификатом
        ]);

        $response = $http->post($url, $data);

        LastCommunicationHandler::log(
            'LocalEventProvider: ' . (string) ($data['event'] ?? '?') . ' → ' . $url
            . ' [' . $http->getStatus() . '] '
            . mb_substr((string) $response, 0, 300)
        );
    }

    /**
     * Блок auth в том виде, в каком его присылает облако, за вычетом токенов.
     */
    private function buildAuth(array $item, array $query): array
    {
        $auth   = (array) ($item['auth'] ?? []);
        $domain = (string) ($query['DOMAIN'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return [
            'application_token' => (string) ($auth['application_token'] ?? ''),
            'domain'            => $domain,
            'client_endpoint'   => $scheme . '://' . $domain . '/rest/',
            'server_endpoint'   => $scheme . '://' . $domain . '/rest/',
            'member_id'         => \CRestUtil::getMemberId(),
            'status'            => 'L',   // local application
            'user_id'           => (int) ($auth['LOCAL_USER'] ?? 0),
        ];
    }
}
