<?php

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
$APPLICATION->SetTitle("Курс валюты");
?><?$APPLICATION->IncludeComponent(
	"curr:currency.rate",
	"",
Array()
);?>