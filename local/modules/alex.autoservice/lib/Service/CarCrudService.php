<?php

namespace Alex\Autoservice\Service;

use Alex\Autoservice\Model\CarTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Alex\Autoservice\Service\Access;

Loc::loadMessages(__FILE__);

/**
 * CRUD над автомобилями клиентов.
 *
 * Слой не знает про REST: бросает обычные исключения, а в коды ошибок REST
 * их превращает Rest\RestService. Благодаря этому логику можно звать из
 * вкладки «Гараж», бизнес-процессов, миграций и тестов.
 */
class CarCrudService
{
    /** Поля, доступные на создание */
    public const CREATE_FIELDS = ['CONTACT_ID', 'BRAND', 'MODEL', 'BRAND_ID', 'MODEL_ID', 'NUMBER', 'YEAR', 'COLOR', 'MILEAGE',];
    /** Владельца через update не меняем: это отдельная операция передачи автомобиля */
    public const UPDATABLE_FIELDS = ['BRAND', 'MODEL', 'BRAND_ID', 'MODEL_ID', 'NUMBER', 'YEAR', 'COLOR', 'MILEAGE',];
    private const LIST_LIMIT = 50;

    /** Поле сделки с идентификатором автомобиля */
    public const DEAL_CAR_FIELD = 'UF_CRM_CAR_ID';

    // ------------------------------------------------------------------ CREATE

    /**
     * @param array $fields CONTACT_ID, BRAND, MODEL, NUMBER и необязательные YEAR, COLOR, MILEAGE
     *
     * @throws \InvalidArgumentException|\OutOfBoundsException|\RuntimeException
     *
     * @return array{ID:int, NUMBER:string}
     */
    public static function create(array $fields): array
    {
        Access::assertCanWriteCar();

        $contactId = self::requireContact($fields);
        $number    = self::requireNumber($fields);

        if (self::findIdByNumber($number)) {
            throw new \InvalidArgumentException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_DUPLICATE_NUMBER',
                ['#NUMBER#' => $number]
            ));
        }

        $reference = self::resolveReference($fields);

        $result = CarTable::add([
            'CONTACT_ID' => $contactId,
            'BRAND'      => $reference['BRAND'],
            'MODEL'      => $reference['MODEL'],
            'BRAND_ID'   => $reference['BRAND_ID'],
            'MODEL_ID'   => $reference['MODEL_ID'],
            'NUMBER'     => $number,
            'YEAR'       => self::optionalInt($fields, 'YEAR'),
            'COLOR'      => self::optionalString($fields, 'COLOR'),
            'MILEAGE'    => self::optionalInt($fields, 'MILEAGE'),
        ]);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_ADD_ERROR',
                ['#ERROR#' => implode('; ', $result->getErrorMessages())]
            ));
        }

        return ['ID' => (int) $result->getId(), 'NUMBER' => $number];
    }

    // -------------------------------------------------------------------- READ

    /**
     * Чтение: одна запись по ID либо список с фильтрами.
     *
     * Вместо CONTACT_ID наружу отдаётся ФИО владельца — так же, как
     * в гараже клиента по ТЗ.
     *
     * @param array $params ID | CONTACT_ID, NUMBER, LIMIT, OFFSET
     *
     * @return array
     */
    public static function read(array $params): array
    {
        Access::assertCanViewCars();

        $query = CarTable::query()
            ->setSelect([
                'ID',
                'BRAND',
                'MODEL',
                'BRAND_ID',
                'MODEL_ID',
                'NUMBER',
                'YEAR',
                'COLOR',
                'MILEAGE',
                'CREATED_AT',
                'UPDATED_AT',
                'CONTACT_ID',
                'CONTACT_NAME'        => 'CONTACT.NAME',
                'CONTACT_LAST_NAME'   => 'CONTACT.LAST_NAME',
                'CONTACT_SECOND_NAME' => 'CONTACT.SECOND_NAME',
            ])
            ->setOrder(['ID' => 'ASC']);

        // Механик видит гараж только своих клиентов: условие уходит в запрос,
        // иначе сломается постраничная навигация
        if (Access::restrictedToOwnContacts()) {
            $query->where('CONTACT.ASSIGNED_BY_ID', Access::getCurrentUserId());
        }

        $id = (int) ($params['ID'] ?? 0);

        if ($id > 0) {
            $row = $query->where('ID', $id)->setLimit(1)->fetch();

            if (!$row) {
                throw new \OutOfBoundsException(Loc::getMessage(
                    'ALEX_AUTOSERVICE_CAR_NOT_FOUND',
                    ['#ID#' => $id]
                ));
            }

            Access::assertCanReadCar((int) $row['CONTACT_ID']);

            return self::formatRow($row);
        }

        if (!empty($params['CONTACT_ID'])) {
            $query->where('CONTACT_ID', (int) $params['CONTACT_ID']);
        }

        if (!empty($params['NUMBER'])) {
            $query->where('NUMBER', self::normalizeNumber((string) $params['NUMBER']));
        }

        $limit  = max(1, min((int) ($params['LIMIT'] ?? self::LIST_LIMIT), self::LIST_LIMIT));
        $offset = max(0, (int) ($params['OFFSET'] ?? 0));

        $rows = $query->setLimit($limit)->setOffset($offset)->fetchAll();

        return array_map([self::class, 'formatRow'], $rows);
    }

    /**
     * Автомобили одного клиента — основа вкладки «Гараж».
     *
     * @param int $contactId
     *
     * @return array
     */
    public static function getByContact(int $contactId): array
    {
        return self::read(['CONTACT_ID' => $contactId, 'LIMIT' => self::LIST_LIMIT]);
    }

    // ------------------------------------------------------------------ UPDATE

    /**
     * @param int   $id
     * @param array $fields только BRAND, MODEL, NUMBER, YEAR, COLOR, MILEAGE
     *
     * @throws \InvalidArgumentException|\OutOfBoundsException|\RuntimeException
     *
     * @return array{ID:int, UPDATED:string[]}
     */
    public static function update(int $id, array $fields): array
    {
        Access::assertCanWriteCar();

        if ($id <= 0) {
            throw new \InvalidArgumentException(Loc::getMessage('ALEX_AUTOSERVICE_CAR_NO_ID'));
        }

        $forbidden = array_diff(array_keys($fields), self::UPDATABLE_FIELDS);

        if ($forbidden) {
            throw new \InvalidArgumentException(Loc::getMessage('ALEX_AUTOSERVICE_CAR_FORBIDDEN_FIELDS', [
                '#ALLOWED#'   => implode(', ', self::UPDATABLE_FIELDS),
                '#FORBIDDEN#' => implode(', ', $forbidden),
            ]));
        }

        $current = CarTable::query()
            ->setSelect(['ID', 'NUMBER', 'BRAND_ID', 'MODEL_ID'])
            ->where('ID', $id)
            ->setLimit(1)
            ->fetch();

        if (!$current) {
            throw new \OutOfBoundsException(Loc::getMessage('ALEX_AUTOSERVICE_CAR_NOT_FOUND', ['#ID#' => $id]));
        }

        $update = [];

        if (array_intersect(['BRAND', 'MODEL', 'BRAND_ID', 'MODEL_ID'], array_keys($fields))) {
            $reference = self::resolveReference($fields, $current);

            $update['BRAND']    = $reference['BRAND'];
            $update['MODEL']    = $reference['MODEL'];
            $update['BRAND_ID'] = $reference['BRAND_ID'];
            $update['MODEL_ID'] = $reference['MODEL_ID'];
        }

        if (array_key_exists('NUMBER', $fields)) {
            $number = self::requireNumber($fields);
            $owner  = self::findIdByNumber($number);

            if ($owner && $owner !== $id) {
                throw new \InvalidArgumentException(Loc::getMessage(
                    'ALEX_AUTOSERVICE_CAR_DUPLICATE_NUMBER',
                    ['#NUMBER#' => $number]
                ));
            }

            $update['NUMBER'] = $number;
        }

        foreach (['YEAR', 'MILEAGE'] as $key) {
            if (array_key_exists($key, $fields)) {
                $update[$key] = self::optionalInt($fields, $key);
            }
        }

        if (array_key_exists('COLOR', $fields)) {
            $update['COLOR'] = self::optionalString($fields, 'COLOR');
        }

        if (!$update) {
            throw new \InvalidArgumentException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_NOTHING_TO_UPDATE',
                ['#ALLOWED#' => implode(', ', self::UPDATABLE_FIELDS)]
            ));
        }

        $update['UPDATED_AT'] = new DateTime();

        $result = CarTable::update($id, $update);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_UPDATE_ERROR',
                ['#ERROR#' => implode('; ', $result->getErrorMessages())]
            ));
        }

        unset($update['UPDATED_AT']);

        return ['ID' => $id, 'UPDATED' => array_keys($update)];
    }

    // ------------------------------------------------------------------ DELETE

    /**
     * @param int $id
     *
     * @throws \InvalidArgumentException|\OutOfBoundsException|\RuntimeException
     *
     * @return array{ID:int, DELETED:bool}
     */
    public static function delete(int $id): array
    {
        Access::assertCanDeleteCar();

        if ($id <= 0) {
            throw new \InvalidArgumentException(Loc::getMessage('ALEX_AUTOSERVICE_CAR_NO_ID'));
        }

        if (!self::exists($id)) {
            throw new \OutOfBoundsException(Loc::getMessage('ALEX_AUTOSERVICE_CAR_NOT_FOUND', ['#ID#' => $id]));
        }

        $deals = self::countDeals($id);

        if ($deals > 0) {
            throw new \InvalidArgumentException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_HAS_DEALS',
                ['#COUNT#' => $deals]
            ));
        }

        $result = CarTable::delete($id);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_DELETE_ERROR',
                ['#ERROR#' => implode('; ', $result->getErrorMessages())]
            ));
        }

        return ['ID' => $id, 'DELETED' => true];
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Нормализация госномера: верхний регистр, без пробелов и дефисов.
     *
     * Нужна, чтобы «а123вс 777» и «А123ВС777» считались одним автомобилем.
     *
     * @param string $number
     *
     * @return string
     */
    public static function normalizeNumber(string $number): string
    {
        return preg_replace('/[\s\-]+/u', '', mb_strtoupper(trim($number)));
    }

    /**
     * @param string $number нормализованный номер
     *
     * @return int ID автомобиля или 0
     */
    public static function findIdByNumber(string $number): int
    {
        $row = CarTable::query()
            ->setSelect(['ID'])
            ->where('NUMBER', $number)
            ->setLimit(1)
            ->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * @param int $id
     *
     * @return bool
     */
    public static function exists(int $id): bool
    {
        return (bool) CarTable::query()
            ->setSelect(['ID'])
            ->where('ID', $id)
            ->setLimit(1)
            ->fetch();
    }

    /**
     * @param array $row
     *
     * @return array
     */
    private static function formatRow(array $row): array
    {
        $owner = trim(implode(' ', array_filter([
            (string) ($row['CONTACT_LAST_NAME'] ?? ''),
            (string) ($row['CONTACT_NAME'] ?? ''),
            (string) ($row['CONTACT_SECOND_NAME'] ?? ''),
        ])));

        $created = $row['CREATED_AT'] ?? null;
        $updated = $row['UPDATED_AT'] ?? null;

        return [
            'ID'         => (int) $row['ID'],
            'BRAND'      => (string) $row['BRAND'],
            'MODEL'      => (string) $row['MODEL'],
            'BRAND_ID'   => isset($row['BRAND_ID']) ? (int) $row['BRAND_ID'] : null,
            'MODEL_ID'   => isset($row['MODEL_ID']) ? (int) $row['MODEL_ID'] : null,
            'NUMBER'     => (string) $row['NUMBER'],
            'YEAR'       => $row['YEAR'] !== null ? (int) $row['YEAR'] : null,
            'COLOR'      => $row['COLOR'] !== null ? (string) $row['COLOR'] : null,
            'MILEAGE'    => $row['MILEAGE'] !== null ? (int) $row['MILEAGE'] : null,
            'CONTACT_ID' => (int) $row['CONTACT_ID'],
            'OWNER'      => $owner !== '' ? $owner : null,
            'TITLE'      => trim($row['BRAND'] . ' ' . $row['MODEL'] . ' (' . $row['NUMBER'] . ')'),
            'CREATED_AT' => $created instanceof DateTime ? $created->toString() : $created,
            'UPDATED_AT' => $updated instanceof DateTime ? $updated->toString() : $updated,

        ];
    }

    /**
     * @param array $fields
     *
     * @return int
     */
    private static function requireContact(array $fields): int
    {
        $contactId = (int) ($fields['CONTACT_ID'] ?? 0);

        if ($contactId <= 0) {
            throw new \InvalidArgumentException(Loc::getMessage('ALEX_AUTOSERVICE_CAR_NO_CONTACT'));
        }

        if (!Loader::includeModule('crm')) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_CAR_NO_CRM'));
        }

        $contact = \Bitrix\Crm\ContactTable::query()
            ->setSelect(['ID'])
            ->where('ID', $contactId)
            ->setLimit(1)
            ->fetch();

        if (!$contact) {
            throw new \OutOfBoundsException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_CONTACT_NOT_FOUND',
                ['#ID#' => $contactId]
            ));
        }

        return $contactId;
    }

    /**
     * @param array $fields
     *
     * @return string
     */
    private static function requireNumber(array $fields): string
    {
        $number = self::normalizeNumber((string) ($fields['NUMBER'] ?? ''));

        if ($number === '') {
            throw new \InvalidArgumentException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_FIELD_REQUIRED',
                ['#FIELD#' => 'NUMBER']
            ));
        }

        if (mb_strlen($number) > 32) {
            throw new \InvalidArgumentException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_FIELD_TOO_LONG',
                ['#FIELD#' => 'NUMBER', '#LENGTH#' => 32]
            ));
        }

        return $number;
    }

    /**
     * @param array  $fields
     * @param string $key
     *
     * @return string
     */
    private static function requireString(array $fields, string $key): string
    {
        $value = trim((string) ($fields[$key] ?? ''));

        if ($value === '') {
            throw new \InvalidArgumentException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_FIELD_REQUIRED',
                ['#FIELD#' => $key]
            ));
        }

        if (mb_strlen($value) > 100) {
            throw new \InvalidArgumentException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_FIELD_TOO_LONG',
                ['#FIELD#' => $key, '#LENGTH#' => 100]
            ));
        }

        return $value;
    }

    /**
     * Приводит марку и модель к справочнику.
     *
     * Принимает и идентификаторы, и названия: интерфейс присылает первое,
     * внешние системы по REST — второе. Неизвестное значение отвергается:
     * заводить записи в справочнике на лету нельзя, иначе опечатка
     * интегратора превратится в новую марку.
     *
     * @param array $fields  входные поля
     * @param array $current текущая строка автомобиля — для update
     *
     * @throws \InvalidArgumentException
     *
     * @return array{BRAND:string, MODEL:string, BRAND_ID:int, MODEL_ID:int}
     */
    private static function resolveReference(array $fields, array $current = []): array
    {
        $brandId = (int) ($fields['BRAND_ID'] ?? 0);

        if (!$brandId && isset($fields['BRAND'])) {
            $name    = self::requireString($fields, 'BRAND');
            $brandId = CarReference::findBrand($name);

            if (!$brandId) {
                throw new \InvalidArgumentException(Loc::getMessage(
                    'ALEX_AUTOSERVICE_CAR_BRAND_UNKNOWN',
                    ['#NAME#' => $name]
                ));
            }
        }

        if (!$brandId) {
            $brandId = (int) ($current['BRAND_ID'] ?? 0);
        }

        $brandName = CarReference::getBrandName($brandId);

        if ($brandName === '') {
            throw new \InvalidArgumentException(Loc::getMessage('ALEX_AUTOSERVICE_CAR_BRAND_REQUIRED'));
        }

        $modelId = (int) ($fields['MODEL_ID'] ?? 0);

        if (!$modelId && isset($fields['MODEL'])) {
            $name    = self::requireString($fields, 'MODEL');
            $modelId = CarReference::findModel($brandId, $name);

            if (!$modelId) {
                throw new \InvalidArgumentException(Loc::getMessage(
                    'ALEX_AUTOSERVICE_CAR_MODEL_UNKNOWN',
                    ['#NAME#' => $name, '#BRAND#' => $brandName]
                ));
            }
        }

        // Модель осталась прежней — но только если не сменилась марка:
        // «Camry» от Toyota не может принадлежать Kia
        if (!$modelId
            && (int) ($current['BRAND_ID'] ?? 0) === $brandId) {

            $modelId = (int) ($current['MODEL_ID'] ?? 0);
        }

        $modelName = CarReference::getModelName($modelId);

        if ($modelName === '') {
            throw new \InvalidArgumentException(Loc::getMessage('ALEX_AUTOSERVICE_CAR_MODEL_REQUIRED'));
        }

        return [
            'BRAND'    => $brandName,
            'MODEL'    => $modelName,
            'BRAND_ID' => $brandId,
            'MODEL_ID' => $modelId,
        ];
    }

    /**
     * @param array  $fields
     * @param string $key
     *
     * @return string|null
     */
    private static function optionalString(array $fields, string $key): ?string
    {
        $value = trim((string) ($fields[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 50);
    }

    /**
     * @param array  $fields
     * @param string $key
     *
     * @return int|null
     */
    private static function optionalInt(array $fields, string $key): ?int
    {
        $raw = $fields[$key] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_FIELD_NOT_NUMBER',
                ['#FIELD#' => $key]
            ));
        }

        $value = (int) $raw;

        if ($value < 0) {
            throw new \InvalidArgumentException(Loc::getMessage(
                'ALEX_AUTOSERVICE_CAR_FIELD_NEGATIVE',
                ['#FIELD#' => $key]
            ));
        }

        return $value;
    }

    /**
     * Сколько заказ-нарядов заведено по автомобилю.
     *
     * Через CCrmDeal::GetListEx, а не ORM: фильтрация по пользовательскому
     * полю сделки надёжнее работает именно там.
     *
     * @param int $carId
     *
     * @return int
     */
    public static function countDeals(int $carId): int
    {
        if (!Loader::includeModule('crm')) {
            return 0;
        }

        $fieldName = self::DEAL_CAR_FIELD;

        if (!\Bitrix\Main\UserFieldTable::query()
            ->setSelect(['ID'])
            ->where('ENTITY_ID', 'CRM_DEAL')
            ->where('FIELD_NAME', $fieldName)
            ->setLimit(1)
            ->fetch()) {

            return 0;
        }

        $res = \CCrmDeal::GetListEx(
            [],
            [$fieldName => $carId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID']
        );

        $count = 0;

        while ($res->Fetch()) {
            $count++;
        }

        return $count;
    }
}
