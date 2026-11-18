<?php

namespace Alex\Autoservice\Service;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Справочники марок и моделей автомобилей.
 *
 * Хранятся в highload-блоках: отдельная сущность, своя таблица, штатный
 * интерфейс редактирования в админке и доступность из других модулей.
 *
 * В самой карточке автомобиля лежат и идентификатор, и название: по
 * идентификатору работает интерфейс, по названию — внешние системы через
 * REST, которым про справочники знать необязательно.
 */
class CarReference
{
    private const MODULE_ID = 'alex.autoservice';

    public const OPTION_BRAND_HL = 'brand_hlblock_id';
    public const OPTION_MODEL_HL = 'model_hlblock_id';

    public const BRAND_BLOCK = 'AutoserviceBrand';
    public const MODEL_BLOCK = 'AutoserviceModel';

    /** Наполнение по умолчанию: марка => модели */
    public const SEED = [
        'Toyota'     => ['Camry', 'Corolla', 'RAV4', 'Land Cruiser'],
        'Kia'        => ['Rio', 'Ceed', 'Sportage', 'Optima'],
        'Hyundai'    => ['Solaris', 'Creta', 'Tucson', 'Elantra'],
        'Lada'       => ['Vesta', 'Granta', 'Niva', 'Largus'],
        'Volkswagen' => ['Polo', 'Tiguan', 'Passat', 'Golf'],
        'Skoda'      => ['Octavia', 'Rapid', 'Kodiaq'],
        'Renault'    => ['Logan', 'Duster', 'Sandero'],
        'Nissan'     => ['Qashqai', 'X-Trail', 'Almera'],
        'BMW'        => ['X3', 'X5', '3 series'],
        'Mercedes'   => ['C-class', 'E-class', 'GLC'],
        'Ford'       => ['Focus', 'Mondeo', 'Kuga'],
        'Audi'       => ['A4', 'A6', 'Q5'],
    ];

    /**
     * Создаёт оба блока с полями, если их ещё нет.
     *
     * @throws \RuntimeException
     *
     * @return array{BRAND:int, MODEL:int} идентификаторы блоков
     */
    public static function ensureBlocks(): array
    {
        self::requireModule();

        $brandId = self::ensureBlock(self::BRAND_BLOCK, 'alex_autoservice_brand', [
            [
                'FIELD_NAME'   => 'UF_NAME',
                'USER_TYPE_ID' => 'string',
                'MANDATORY'    => 'Y',
                'SORT'         => 100,
                'LABEL'        => Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_FIELD_NAME'),
            ],
            [
                'FIELD_NAME'   => 'UF_XML_ID',
                'USER_TYPE_ID' => 'string',
                'SORT'         => 200,
                'LABEL'        => Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_FIELD_XML_ID'),
            ],
        ]);

        $modelId = self::ensureBlock(self::MODEL_BLOCK, 'alex_autoservice_model', [
            [
                'FIELD_NAME'   => 'UF_NAME',
                'USER_TYPE_ID' => 'string',
                'MANDATORY'    => 'Y',
                'SORT'         => 100,
                'LABEL'        => Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_FIELD_NAME'),
            ],
            [
                'FIELD_NAME'   => 'UF_BRAND_ID',
                'USER_TYPE_ID' => 'integer',
                'MANDATORY'    => 'Y',
                'SORT'         => 200,
                'LABEL'        => Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_FIELD_BRAND'),
            ],
            [
                'FIELD_NAME'   => 'UF_XML_ID',
                'USER_TYPE_ID' => 'string',
                'SORT'         => 300,
                'LABEL'        => Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_FIELD_XML_ID'),
            ],
        ]);

        Option::set(self::MODULE_ID, self::OPTION_BRAND_HL, $brandId);
        Option::set(self::MODULE_ID, self::OPTION_MODEL_HL, $modelId);

        return ['BRAND' => $brandId, 'MODEL' => $modelId];
    }

    /**
     * Заполняет справочники. Повторный вызов дублей не создаёт.
     *
     * @return string[] отчёт
     */
    public static function seed(): array
    {
        $report = [];
        $brands = 0;
        $models = 0;

        foreach (self::SEED as $brand => $brandModels) {
            $brandId = self::findBrand($brand);

            if (!$brandId) {
                $brandId = self::addReferenceBrand($brand);
                $brands++;
            }

            foreach ($brandModels as $model) {
                if (!self::findModel($brandId, $model)) {
                    self::addReferenceModel($brandId, $model);
                    $models++;
                }
            }
        }

        $report[] = Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_SEEDED', [
            '#BRANDS#' => $brands,
            '#MODELS#' => $models,
        ]);

        return $report;
    }

    /**
     * @return array<int, array{ID:int, NAME:string}>
     */
    public static function getBrands(): array
    {
        $rows = self::dataClass(self::OPTION_BRAND_HL)::getList([
            'select' => ['ID', 'UF_NAME'],
            'order'  => ['UF_NAME' => 'ASC'],
        ])->fetchAll();

        return array_map(
            static fn (array $row): array => ['ID' => (int) $row['ID'], 'NAME' => (string) $row['UF_NAME']],
            $rows
        );
    }

    /**
     * @param int $brandId 0 — все модели
     *
     * @return array<int, array{ID:int, NAME:string, BRAND_ID:int}>
     */
    public static function getModels(int $brandId = 0): array
    {
        $filter = $brandId > 0 ? ['=UF_BRAND_ID' => $brandId] : [];

        $rows = self::dataClass(self::OPTION_MODEL_HL)::getList([
            'select' => ['ID', 'UF_NAME', 'UF_BRAND_ID'],
            'filter' => $filter,
            'order'  => ['UF_NAME' => 'ASC'],
        ])->fetchAll();

        return array_map(
            static fn (array $row): array => [
                'ID'       => (int) $row['ID'],
                'NAME'     => (string) $row['UF_NAME'],
                'BRAND_ID' => (int) $row['UF_BRAND_ID'],
            ],
            $rows
        );
    }

    /**
     * @param string $name
     *
     * @return int идентификатор марки или 0
     */
    public static function findBrand(string $name): int
    {
        $row = self::dataClass(self::OPTION_BRAND_HL)::getList([
            'select' => ['ID'],
            'filter' => ['=UF_NAME' => trim($name)],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * @param int    $brandId
     * @param string $name
     *
     * @return int идентификатор модели или 0
     */
    public static function findModel(int $brandId, string $name): int
    {
        $row = self::dataClass(self::OPTION_MODEL_HL)::getList([
            'select' => ['ID'],
            'filter' => ['=UF_BRAND_ID' => $brandId, '=UF_NAME' => trim($name)],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int) $row['ID'] : 0;
    }

    /**
     * @param int $id
     *
     * @return string пустая строка, если марки нет
     */
    public static function getBrandName(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $row = self::dataClass(self::OPTION_BRAND_HL)::getByPrimary($id, ['select' => ['UF_NAME']])->fetch();

        return $row ? (string) $row['UF_NAME'] : '';
    }

    /**
     * @param int $id
     *
     * @return string пустая строка, если модели нет
     */
    public static function getModelName(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $row = self::dataClass(self::OPTION_MODEL_HL)::getByPrimary($id, ['select' => ['UF_NAME']])->fetch();

        return $row ? (string) $row['UF_NAME'] : '';
    }

    /**
     * Удаляет оба блока вместе с данными — откат миграции.
     *
     * @return string[] отчёт
     */
    public static function removeBlocks(): array
    {
        self::requireModule();

        $report = [];

        foreach ([self::OPTION_MODEL_HL, self::OPTION_BRAND_HL] as $option) {
            $id = (int) Option::get(self::MODULE_ID, $option, 0);

            if ($id > 0) {
                HighloadBlockTable::delete($id);

                $report[] = Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_BLOCK_REMOVED', ['#ID#' => $id]);
            }

            Option::delete(self::MODULE_ID, ['name' => $option]);
        }

        return $report;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param string $name      имя блока, с заглавной буквы
     * @param string $tableName таблица в базе
     * @param array  $fields    поля: FIELD_NAME => параметры
     *
     * @throws \RuntimeException
     *
     * @return int
     */
    /**
     * @param string  $name      имя блока, с заглавной буквы
     * @param string  $tableName таблица в базе
     * @param array[] $fields    описания полей для UserField::ensure()
     *
     * @throws \RuntimeException
     *
     * @return int
     */
    private static function ensureBlock(string $name, string $tableName, array $fields): int
    {
        $existing = HighloadBlockTable::getList([
            'select' => ['ID'],
            'filter' => ['=NAME' => $name],
            'limit'  => 1,
        ])->fetch();

        if ($existing) {
            $blockId = (int) $existing['ID'];
        } else {
            $result = HighloadBlockTable::add(['NAME' => $name, 'TABLE_NAME' => $tableName]);

            if (!$result->isSuccess()) {
                throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_BLOCK_ERROR', [
                    '#NAME#'  => $name,
                    '#ERROR#' => implode('; ', $result->getErrorMessages()),
                ]));
            }

            $blockId = (int) $result->getId();
        }

        foreach ($fields as $field) {
            UserField::ensure('HLBLOCK_' . $blockId, $field);
        }

        return $blockId;
    }
    /**
     * Добавляет марку в справочник.
     *
     * Публичный, потому что им пользуется миграция: при переходе на
     * справочники она заводит марки, которых в наполнении не было,
     * чтобы не потерять уже заведённые автомобили.
     *
     * @param string $name
     *
     * @throws \RuntimeException
     *
     * @return int идентификатор марки
     */
    public static function addReferenceBrand(string $name): int
    {
        $name = trim($name);

        $result = self::dataClass(self::OPTION_BRAND_HL)::add([
            'UF_NAME'   => $name,
            'UF_XML_ID' => self::MODULE_ID . '.' . mb_strtolower($name),
        ]);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_ADD_ERROR', [
                '#NAME#'  => $name,
                '#ERROR#' => implode('; ', $result->getErrorMessages()),
            ]));
        }

        return (int) $result->getId();
    }

    /**
     * Добавляет модель к марке.
     *
     * @param int    $brandId
     * @param string $name
     *
     * @throws \RuntimeException
     *
     * @return int идентификатор модели
     */
    public static function addReferenceModel(int $brandId, string $name): int
    {
        $name = trim($name);

        $result = self::dataClass(self::OPTION_MODEL_HL)::add([
            'UF_NAME'     => $name,
            'UF_BRAND_ID' => $brandId,
            'UF_XML_ID'   => self::MODULE_ID . '.' . $brandId . '.' . mb_strtolower($name),
        ]);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_ADD_ERROR', [
                '#NAME#'  => $name,
                '#ERROR#' => implode('; ', $result->getErrorMessages()),
            ]));
        }

        return (int) $result->getId();
    }

    /**
     * Класс данных highload-блока.
     *
     * @param string $option опция с идентификатором блока
     *
     * @throws \RuntimeException
     *
     * @return string|\Bitrix\Main\ORM\Data\DataManager
     */
    private static function dataClass(string $option): string
    {
        self::requireModule();

        $blockId = (int) Option::get(self::MODULE_ID, $option, 0);

        if (!$blockId) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_NO_BLOCK'));
        }

        $block = HighloadBlockTable::getById($blockId)->fetch();

        if (!$block) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_NO_BLOCK'));
        }

        return HighloadBlockTable::compileEntity($block)->getDataClass();
    }

    /**
     * @throws \RuntimeException
     *
     * @return void
     */
    private static function requireModule(): void
    {
        if (!Loader::includeModule('highloadblock')) {
            throw new \RuntimeException(Loc::getMessage('ALEX_AUTOSERVICE_REFERENCE_NO_MODULE'));
        }
    }
}
