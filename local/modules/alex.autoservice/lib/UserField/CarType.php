<?php

namespace Alex\Autoservice\UserField;

use Alex\Autoservice\Model\CarTable;
use Alex\Autoservice\Service\CarCrudService;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserField\Types\BaseType;

Loc::loadMessages(__FILE__);

/**
 * Тип пользовательского поля «Автомобиль».
 *
 * Хранит идентификатор записи из alex_autoservice_car, а показывает
 * «Марка Модель (Номер)». В форме редактирования — список автомобилей,
 * сгруппированный по владельцам, вместо ручного ввода числа.
 *
 * Регистрируется событием main/OnUserTypeBuildList в инсталляторе модуля.
 */
class CarType extends BaseType
{
    public const USER_TYPE_ID = 'alex_autoservice_car';

    /** Сколько автомобилей показываем в списке выбора */
    private const LIST_LIMIT = 300;

    /**
     * @return array описание типа для CUserTypeManager
     */
    public static function getUserTypeDescription(): array
    {
        return [
            'USER_TYPE_ID' => self::USER_TYPE_ID,
            'CLASS_NAME'   => self::class,
            'DESCRIPTION'  => Loc::getMessage('ALEX_AUTOSERVICE_UF_CAR_DESCRIPTION'),
            'BASE_TYPE'    => \CUserTypeManager::BASE_TYPE_INT,
            'EDIT_CALLBACK' => [self::class, 'getPublicEdit'],
            'VIEW_CALLBACK' => [self::class, 'getPublicView'],
        ];
    }

    /**
     * Храним то же целое, что и прежний тип integer: смена типа не требует
     * пересоздания колонки и не теряет значения.
     *
     * @return string
     */
    public static function getDbColumnType(): string
    {
        return 'int(18)';
    }

    /**
     * @param array $userField
     * @param array $additionalParameters
     *
     * @return string
     */
    public static function getPublicView($userField, $additionalParameters = []): string
    {
        return self::renderValue((int) ($userField['VALUE'] ?? 0));
    }

    /**
     * @param array $userField
     * @param array $additionalParameters
     *
     * @return string
     */
    public static function getPublicEdit($userField, $additionalParameters = []): string
    {
        return self::renderSelect($userField, $additionalParameters);
    }

    /**
     * Представление в административной части.
     *
     * @param array $userField
     * @param array|null $additionalParameters
     *
     * @return string
     */
    public static function renderView($userField, ?array $additionalParameters = []): string
    {
        return self::renderValue((int) ($userField['VALUE'] ?? 0));
    }

    /**
     * Форма редактирования в административной части.
     *
     * @param array $userField
     * @param array|null $additionalParameters
     *
     * @return string
     */
    public static function renderEdit($userField, ?array $additionalParameters = []): string
    {
        return self::renderSelect($userField, (array) $additionalParameters);
    }

    /**
     * @param array $userField
     * @param array|null $additionalParameters
     *
     * @return string
     */
    public static function renderAdminListView($userField, ?array $additionalParameters = []): string
    {
        return self::renderValue((int) ($userField['VALUE'] ?? 0));
    }

    /**
     * Текстовое представление: экспорт, печать, уведомления.
     *
     * @param array $userField
     * @param array|null $additionalParameters
     *
     * @return string
     */
    public static function getPublicText($userField, ?array $additionalParameters = []): string
    {
        $car = self::getCar((int) ($userField['VALUE'] ?? 0));

        return $car ? $car['TITLE'] : '';
    }

    // ---------------------------------------------------------------- отрисовка

    /**
     * @param int $carId
     *
     * @return string
     */
    private static function renderValue(int $carId): string
    {
        $car = self::getCar($carId);

        if (!$car) {
            return '';
        }

        // Ссылка ведёт в карточку владельца: там живёт вкладка «Гараж»
        return '<a href="/crm/contact/details/' . (int) $car['CONTACT_ID'] . '/">'
            . htmlspecialcharsbx($car['TITLE'])
            . '</a>';
    }

    /**
     * @param array $userField
     * @param array $additionalParameters
     *
     * @return string
     */
    private static function renderSelect(array $userField, array $additionalParameters): string
    {
        $name     = (string) ($additionalParameters['NAME'] ?? $userField['FIELD_NAME']);
        $selected = (int) ($userField['VALUE'] ?? 0);

        $html = '<select name="' . htmlspecialcharsbx($name) . '" class="main-ui-control main-ui-select">';
        $html .= '<option value="0">' . Loc::getMessage('ALEX_AUTOSERVICE_UF_CAR_EMPTY') . '</option>';

        $currentOwner = null;

        foreach (self::getCars() as $car) {
            $owner = (string) ($car['OWNER'] ?? '');

            if ($owner !== $currentOwner) {
                if ($currentOwner !== null) {
                    $html .= '</optgroup>';
                }

                $html .= '<optgroup label="' . htmlspecialcharsbx($owner) . '">';

                $currentOwner = $owner;
            }

            $html .= '<option value="' . (int) $car['ID'] . '"'
                . ((int) $car['ID'] === $selected ? ' selected' : '') . '>'
                . htmlspecialcharsbx($car['TITLE'])
                . '</option>';
        }

        if ($currentOwner !== null) {
            $html .= '</optgroup>';
        }

        $html .= '</select>';

        return $html;
    }

    /**
     * @param int $carId
     *
     * @return array|null
     */
    private static function getCar(int $carId): ?array
    {
        if ($carId <= 0) {
            return null;
        }

        try {
            // Читаем мимо CarCrudService: он проверяет права текущего
            // пользователя, а поле сделки показывается тому, кто имеет
            // доступ к самой сделке — права на гараж тут ни при чём
            $row = CarTable::query()
                ->setSelect(['ID', 'BRAND', 'MODEL', 'NUMBER', 'CONTACT_ID'])
                ->where('ID', $carId)
                ->setLimit(1)
                ->fetch();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        return [
            'ID'         => (int) $row['ID'],
            'CONTACT_ID' => (int) $row['CONTACT_ID'],
            'TITLE'      => trim($row['BRAND'] . ' ' . $row['MODEL'] . ' (' . $row['NUMBER'] . ')'),
        ];
    }

    /**
     * Автомобили для списка выбора, сгруппированные по владельцу.
     *
     * @return array<int, array{ID:int, TITLE:string, OWNER:string}>
     */
    private static function getCars(): array
    {
        try {
            $rows = CarTable::query()
                ->setSelect([
                    'ID',
                    'BRAND',
                    'MODEL',
                    'NUMBER',
                    'CONTACT_NAME'      => 'CONTACT.NAME',
                    'CONTACT_LAST_NAME' => 'CONTACT.LAST_NAME',
                ])
                ->setOrder(['CONTACT_ID' => 'ASC', 'BRAND' => 'ASC'])
                ->setLimit(self::LIST_LIMIT)
                ->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'ID'    => (int) $row['ID'],
                'TITLE' => trim($row['BRAND'] . ' ' . $row['MODEL'] . ' (' . $row['NUMBER'] . ')'),
                'OWNER' => trim(($row['CONTACT_LAST_NAME'] ?? '') . ' ' . ($row['CONTACT_NAME'] ?? '')),
            ],
            $rows
        );
    }
}
