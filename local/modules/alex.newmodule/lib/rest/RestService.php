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
 */
class RestService
{
    public const SCOPE = 'meatshop';
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
        $fields = self::normalize($params);

        return self::guard(static function () use ($fields) {
            return ProductCrudService::create($fields);
        });
    }

    public static function read($params, $n = 0, $server = null)
    {
        $fields = self::normalize($params);

        return self::guard(static function () use ($fields) {
            return ProductCrudService::read($fields);
        });
    }

    public static function update($params, $n = 0, $server = null): array
    {
        $fields = self::normalize($params);
        $id     = (int) ($fields['ID'] ?? 0);

        unset($fields['ID']);

        return self::guard(static function () use ($id, $fields) {
            return ProductCrudService::update($id, $fields);
        });
    }

    public static function delete($params, $n = 0, $server = null): array
    {
        $fields = self::normalize($params);
        $id     = (int) ($fields['ID'] ?? 0);

        return self::guard(static function () use ($id) {
            return ProductCrudService::delete($id);
        });
    }

    /**
     * Приводит входные параметры к единому виду: ключи в верхнем регистре,
     * поддержана как плоская передача, так и обёртка fields[] в стиле crm.*.
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

    /**
     * Единая точка превращения исключений слоя логики в коды ошибок REST.
     */
    private static function guard(callable $action)
    {
        try {
            return $action();
        } catch (\InvalidArgumentException $e) {
            throw new RestException($e->getMessage(), 'ERROR_WRONG_PARAMS');
        } catch (\OutOfBoundsException $e) {
            throw new RestException($e->getMessage(), 'ERROR_NOT_FOUND');
        } catch (\Throwable $e) {
            throw new RestException($e->getMessage(), 'ERROR_MEATSHOP');
        }
    }
}
