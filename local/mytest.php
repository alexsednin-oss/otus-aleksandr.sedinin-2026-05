<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

use Bitrix\Main\DataManager;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

Loader::includeModule('iblock');

const MEATSHOP_IBLOCK_TYPE = 'meatshop_type';

$iblockTypeExists = \CIBlockType::GetByID(MEATSHOP_IBLOCK_TYPE);

var_dump($iblockTypeExists);


require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
