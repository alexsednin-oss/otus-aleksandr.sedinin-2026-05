<?php

namespace Alex\Autoservice\Migrations;

use Alex\Autoservice\Migration\AbstractMigration;
use Alex\Autoservice\Service\PartsCatalog;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Раздел «Запчасти» в каталоге товаров.
 *
 * Миграция создаёт только структуру — сам раздел. Номенклатура запчастей
 * относится к данным и разворачивается через DemoData: на портале
 * заказчика свой список, и подсовывать ему наш было бы неуместно.
 */
class Migration20261113090000PartsCatalog extends AbstractMigration
{
    private const MODULE_ID = 'alex.autoservice';

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return Loc::getMessage('ALEX_AUTOSERVICE_MIGRATION_PARTS_DESCRIPTION');
    }

    /**
     * @return void
     */
    public function up(): void
    {
        $this->requireModules('crm', 'iblock', 'catalog');

        $catalogId = PartsCatalog::getCatalogId();

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PARTS_CATALOG', ['#ID#' => $catalogId]));

        $section = PartsCatalog::ensureSection(Loc::getMessage('ALEX_AUTOSERVICE_PARTS_SECTION_NAME'));

        Option::set(self::MODULE_ID, PartsCatalog::OPTION_SECTION_ID, $section['ID']);

        $this->note(Loc::getMessage(
            $section['CREATED'] ? 'ALEX_AUTOSERVICE_PARTS_SECTION_CREATED' : 'ALEX_AUTOSERVICE_PARTS_SECTION_EXISTS',
            ['#ID#' => $section['ID']]
        ));
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->requireModules('crm', 'iblock', 'catalog');

        $removed = PartsCatalog::removeSection();

        $this->note(Loc::getMessage('ALEX_AUTOSERVICE_PARTS_SECTION_REMOVED', ['#COUNT#' => $removed]));

        Option::delete(self::MODULE_ID, ['name' => PartsCatalog::OPTION_SECTION_ID]);
    }
}
