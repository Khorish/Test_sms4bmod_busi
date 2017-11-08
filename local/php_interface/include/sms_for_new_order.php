<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/local/php_interface/include/classes/CustomizeSendSms.php');

use Bitrix\Main;

Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleOrderSaved',
    array('CustomizeSendSms', 'sendCustomSMS')
);