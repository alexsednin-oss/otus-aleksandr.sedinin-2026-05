<?php

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");

use Bitrix\Main\Loader;

Loader::includeModule('iblock');

require_once $_SERVER['DOCUMENT_ROOT'].'/local/App/ORMDZ/Model/AbstractMeatShopElementTable.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/App/ORMDZ/Model/ManufacturerElementTable.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/App/ORMDZ/Model/ProductTypeElementTable.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/App/ORMDZ/Model/ProductTable.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/local/App/ORMDZ/Repository/ProductRepository.php';

use App\ORMDZ\Repository\ProductRepository;

$products = ProductRepository::getList();

/**
 *  @var CMain $APPLICATION
 */
$APPLICATION->SetTitle('MeatShop: товары');
?>

    <section>
        <h1>Товары мясной лавки</h1>

        <table border="1" cellpadding="8" cellspacing="0">
            <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Цена</th>
                <th>Тип товара</th>
                <th>Класс</th>
                <th>Производитель</th>
                <th>Тип производителя</th>
                <th>Создано</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td><?= htmlspecialchars($product['ID']) ?></td>
                    <td><?= htmlspecialchars($product['NAME']) ?></td>
                    <td><?= htmlspecialchars($product['PRICE']) ?></td>
                    <td><?= htmlspecialchars($product['PRODUCT_TYPE_NAME'] ?? '') ?></td>
                    <td><?= htmlspecialchars($product['PRODUCT_TYPE_CLASS'] ?? '') ?></td>
                    <td><?= htmlspecialchars($product['MANUFACTURER_NAME'] ?? '') ?></td>
                    <td><?= htmlspecialchars($product['MANUFACTURER_TYPE'] ?? '') ?></td>
                    <td><?= $product['CREATED_AT'] instanceof \Bitrix\Main\Type\DateTime
                            ? $product['CREATED_AT']->format('d.m.Y H:i')
                            : htmlspecialchars((string) $product['CREATED_AT']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
