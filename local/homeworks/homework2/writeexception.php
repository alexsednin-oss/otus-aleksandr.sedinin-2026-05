<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"); ?>
<?php
$APPLICATION->SetTitle("Ошибка для exeption");
?>
    <ul class="list-group">
        <li class="list-group-item">
            <a href="/local/logs/exceptions.log">Файл лога</a>
        </li>
    </ul>
<?php
// ошибка для exeption

$hh = 1 / 0;

?>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
