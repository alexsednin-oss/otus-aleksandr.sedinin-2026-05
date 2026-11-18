<?php

namespace Alex\Autoservice\Rest;

use Alex\Autoservice\Service\CarCrudService;
use Alex\Autoservice\Service\Log;
use Bitrix\Rest\RestException;

/**
 * REST-методы сервисного центра.
 *
 * Регистрируется на rest / OnRestServiceBuildDescription при установке модуля.
 * Ключ верхнего уровня — scope: именно его выдают входящему вебхуку в правах.
 *
 *   autoservice_car.create  CONTACT_ID, BRAND, MODEL, NUMBER, YEAR, COLOR, MILEAGE
 *   autoservice_car.read    ID | CONTACT_ID, NUMBER, LIMIT, OFFSET
 *   autoservice_car.update  ID + любые из BRAND, MODEL, NUMBER, YEAR, COLOR, MILEAGE
 *   autoservice_car.delete  ID
 */
class RestService
{
    public const SCOPE = 'autoservice';

    /** Служебные параметры REST — к полям сущности не относятся */
    private const SERVICE_PARAMS = ['AUTH', 'SCOPE', 'METHOD', 'HALT', 'CMD'];

    /**
     * @return array<string, array<string, callable>>
     */
    public static function onRestServiceBuildDescription(): array
    {
        return [
            self::SCOPE => [
                'autoservice_car.create' => [__CLASS__, 'carCreate'],
                'autoservice_car.read'   => [__CLASS__, 'carRead'],
                'autoservice_car.update' => [__CLASS__, 'carUpdate'],
                'autoservice_car.delete' => [__CLASS__, 'carDelete'],
            ],
        ];
    }

    /**
     * @param mixed $params
     * @param int   $n
     * @param mixed $server
     *
     * @return array
     */
    public static function carCreate($params, $n = 0, $server = null): array
    {
        return self::handle('autoservice_car.create', $params, static function (array $fields) {
            return CarCrudService::create($fields);
        });
    }

    /**
     * @param mixed $params
     * @param int   $n
     * @param mixed $server
     *
     * @return array
     */
    public static function carRead($params, $n = 0, $server = null)
    {
        return self::handle('autoservice_car.read', $params, static function (array $fields) {
            return CarCrudService::read($fields);
        });
    }

    /**
     * @param mixed $params
     * @param int   $n
     * @param mixed $server
     *
     * @return array
     */
    public static function carUpdate($params, $n = 0, $server = null): array
    {
        return self::handle('autoservice_car.update', $params, static function (array $fields) {
            $id = (int) ($fields['ID'] ?? 0);

            unset($fields['ID']);

            return CarCrudService::update($id, $fields);
        });
    }

    /**
     * @param mixed $params
     * @param int   $n
     * @param mixed $server
     *
     * @return array
     */
    public static function carDelete($params, $n = 0, $server = null): array
    {
        return self::handle('autoservice_car.delete', $params, static function (array $fields) {
            return CarCrudService::delete((int) ($fields['ID'] ?? 0));
        });
    }

    /**
     * Единая обвязка метода: лог входа, вызов логики, лог результата,
     * перевод исключений в коды ошибок REST.
     *
     * @param string   $method
     * @param mixed    $rawParams
     * @param callable $action
     *
     * @throws RestException
     *
     * @return mixed
     */
    private static function handle(string $method, $rawParams, callable $action)
    {
        $requestId = self::logRequest($method, $rawParams);

        try {
            $result = $action(self::normalize($rawParams));

            Log::add(['id' => $requestId, 'stage' => 'RESPONSE', 'method' => $method, 'result' => $result]);

            return $result;
        } catch (\Alex\Autoservice\Service\AccessDeniedException $e) {
            return self::fail($requestId, $method, 'ACCESS_DENIED', $e);
        } catch (\InvalidArgumentException $e) {
            return self::fail($requestId, $method, 'ERROR_WRONG_PARAMS', $e);
        } catch (\OutOfBoundsException $e) {
            return self::fail($requestId, $method, 'ERROR_NOT_FOUND', $e);
        } catch (\Throwable $e) {
            return self::fail($requestId, $method, 'ERROR_AUTOSERVICE', $e);
        }
    }

    /**
     * @param string $method
     * @param mixed  $rawParams
     *
     * @return string идентификатор запроса для парной записи в логе
     */
    private static function logRequest(string $method, $rawParams): string
    {
        $requestId = substr(md5(uniqid('', true)), 0, 8);

        global $USER;

        Log::add([
            'id'     => $requestId,
            'stage'  => 'REQUEST',
            'method' => $method,
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'user'   => is_object($USER) && $USER->IsAuthorized() ? (int) $USER->GetID() : 0,
            'params' => $rawParams,
        ]);

        return $requestId;
    }

    /**
     * @param string     $requestId
     * @param string     $method
     * @param string     $code
     * @param \Throwable $e
     *
     * @throws RestException
     *
     * @return never
     */
    private static function fail(string $requestId, string $method, string $code, \Throwable $e)
    {
        Log::add([
            'id'        => $requestId,
            'stage'     => 'ERROR',
            'method'    => $method,
            'error'     => $code,
            'message'   => $e->getMessage(),
            'exception' => get_class($e),
            'at'        => $e->getFile() . ':' . $e->getLine(),
        ]);

        throw new RestException($e->getMessage(), $code);
    }

    /**
     * Ключи в верхний регистр, поддержка обёртки fields[] в стиле crm.*,
     * отбрасывание служебных параметров REST.
     *
     * @param mixed $params
     *
     * @return array
     */
    private static function normalize($params): array
    {
        if (!is_array($params)) {
            return [];
        }

        $normalized = [];

        foreach ($params as $key => $value) {
            $normalized[mb_strtoupper((string) $key)] = $value;
        }

        if (isset($normalized['FIELDS']) && is_array($normalized['FIELDS'])) {
            $nested = $normalized['FIELDS'];
            unset($normalized['FIELDS']);

            foreach ($nested as $key => $value) {
                $normalized[mb_strtoupper((string) $key)] = $value;
            }
        }

        return array_diff_key($normalized, array_flip(self::SERVICE_PARAMS));
    }
}
