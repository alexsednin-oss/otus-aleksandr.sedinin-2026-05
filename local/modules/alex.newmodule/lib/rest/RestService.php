<?php

namespace Alex\Newmodule\Rest;

use Bitrix\Rest\RestException;

/**
 * REST-методы мясной лавки.
 *
 * Регистрируется на rest / OnRestServiceBuildDescription при установке модуля.
 * Ключ верхнего уровня — scope: именно его выдают входящему вебхуку в правах.
 *
 *   meatshop_product.create  NAME, PRICE, MANUFACTURER, PRODUCT_TYPE
 *   meatshop_product.read    ID | LIMIT, OFFSET
 *   meatshop_product.update  ID + NAME и/или PRICE
 *   meatshop_product.delete  ID
 *
 * Все вызовы проходят через handle(): он пишет в лог принятые данные и
 * результат обработки и превращает исключения слоя логики в коды ошибок REST.
 */
class RestService
{
    public const SCOPE = 'meatshop';

    /** Служебные параметры REST — к полям сущности не относятся */
    private const SERVICE_PARAMS = ['AUTH', 'SCOPE', 'METHOD', 'HALT', 'CMD'];

    /**
     * @return array<string, array<string, callable>>
     */
    public static function onRestServiceBuildDescription(): array
    {
        return [
            self::SCOPE => [
                'meatshop_product.create' => [__CLASS__, 'create'],
                'meatshop_product.read'   => [__CLASS__, 'read'],
                'meatshop_product.update' => [__CLASS__, 'update'],
                'meatshop_product.delete' => [__CLASS__, 'delete'],
            ],
        ];
    }

    public static function create($params, $n = 0, $server = null): array
    {
        return self::handle('meatshop_product.create', $params, static function (array $fields) {
            return ProductCrudService::create($fields);
        });
    }

    public static function read($params, $n = 0, $server = null)
    {
        return self::handle('meatshop_product.read', $params, static function (array $fields) {
            return ProductCrudService::read($fields);
        });
    }

    public static function update($params, $n = 0, $server = null): array
    {
        return self::handle('meatshop_product.update', $params, static function (array $fields) {
            $id = (int) ($fields['ID'] ?? 0);

            unset($fields['ID']);

            return ProductCrudService::update($id, $fields);
        });
    }

    public static function delete($params, $n = 0, $server = null): array
    {
        return self::handle('meatshop_product.delete', $params, static function (array $fields) {
            return ProductCrudService::delete((int) ($fields['ID'] ?? 0));
        });
    }

    /**
     * Единая обвязка метода: лог входа, вызов логики, лог результата,
     * перевод исключений в коды ошибок REST.
     *
     * @param mixed $rawParams как пришло от клиента, до нормализации
     *
     * @return mixed
     */
    private static function handle(string $method, $rawParams, callable $action)
    {
        $requestId = Log::request($method, $rawParams);

        try {
            $result = $action(self::normalize($rawParams));

            Log::response($requestId, $method, $result);

            return $result;
        } catch (\InvalidArgumentException $e) {
            Log::failure($requestId, $method, 'ERROR_WRONG_PARAMS', $e);

            throw new RestException($e->getMessage(), 'ERROR_WRONG_PARAMS');
        } catch (\OutOfBoundsException $e) {
            Log::failure($requestId, $method, 'ERROR_NOT_FOUND', $e);

            throw new RestException($e->getMessage(), 'ERROR_NOT_FOUND');
        } catch (\Throwable $e) {
            Log::failure($requestId, $method, 'ERROR_MEATSHOP', $e);

            throw new RestException($e->getMessage(), 'ERROR_MEATSHOP');
        }
    }

    /**
     * Приводит входные параметры к единому виду: ключи в верхнем регистре,
     * поддержана как плоская передача, так и обёртка fields[] в стиле crm.*.
     * Служебные параметры REST отбрасываются, иначе update сочтёт их лишними.
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
