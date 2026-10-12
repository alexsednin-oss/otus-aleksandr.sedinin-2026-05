<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/** @var array $arCurrentValues */
/** @var string $formName */

$inn         = htmlspecialcharsbx((string) ($arCurrentValues['inn_field'] ?? ''));
$customer    = htmlspecialcharsbx((string) ($arCurrentValues['customer_field'] ?? ''));
$companyType = htmlspecialcharsbx((string) ($arCurrentValues['company_type'] ?? 'CUSTOMER'));

?>
<tr>
    <td align="right" width="40%">
        <span class="adm-required-field">ИНН заказчика:</span>
    </td>
    <td width="60%">
        <input type="text" name="inn_field" id="id_inn_field" value="<?= $inn ?>" size="45">
        <input type="button" value="..." onclick="BPAShowSelector('id_inn_field', 'string');">
        <div style="color:#888;font-size:11px;">
            Например: <code>{=Document:PROPERTY_123}</code> — поле «Заказчик ИНН» документа
        </div>
    </td>
</tr>
<tr>
    <td align="right">Поле «Заказчик»:</td>
    <td>
        <input type="text" name="customer_field" id="id_customer_field" value="<?= $customer ?>" size="45">
        <div style="color:#888;font-size:11px;">
            Код поля документа, куда записать компанию, например <code>PROPERTY_124</code>
        </div>
    </td>
</tr>
<tr>
    <td align="right">Тип компании:</td>
    <td>
        <select name="company_type">
            <option value="CUSTOMER" <?= $companyType === 'CUSTOMER' ? 'selected' : '' ?>>Клиент</option>
            <option value="SUPPLIER" <?= $companyType === 'SUPPLIER' ? 'selected' : '' ?>>Поставщик</option>
            <option value="PARTNER" <?= $companyType === 'PARTNER' ? 'selected' : '' ?>>Партнёр</option>
            <option value="OTHER" <?= $companyType === 'OTHER' ? 'selected' : '' ?>>Другое</option>
        </select>
    </td>
</tr>
