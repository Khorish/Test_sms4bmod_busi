<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/classes/CustomizeSendSms.php';

use Bitrix\Main;

Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleOrderSaved',
    'SendSmsUserAdmin'
);


/**
 * @param Main\Event $obEvent
 * @return bool
 */
function SendSmsUserAdmin(Main\Event $obEvent)
{
    $smsNewOrder = new CustomizeSendSms($GLOBALS['SMS4B'], 19, 7);
    $smsNewOrder->sendCustomSMS($obEvent);

    return true;
}