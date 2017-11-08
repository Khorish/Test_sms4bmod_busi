<?php
require_once($_SERVER['DOCUMENT_ROOT'].'/local/php_interface/include/classes/CustomizeSendSms.php');

use Bitrix\Main;
use Bitrix\Main\Type\DateTime;

Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleOrderSaved',
    'SendCustomSMS'
);

/**
 * Кастомизация данных отправляемых в СМС и времени отправки
 *
 * @param Main\Event $objEvent object - объект события, передаваемый обработчиком битрикса
 *
 * @throws CheckIncludeModule() - проверка подключения модуля rarus.sms4b
 * @throws CheckNewOrder() - проверка что заказ новый, а не обновление старого заказа
 * @throws checkUserPhone() - проверка что валидности телефона пользователя
 *
 * @return bool - результат
 */
function SendCustomSMS(Main\Event $objEvent){

    $objThis = new CustomizeSendSms($objEvent, $GLOBALS['SMS4B'], 19, 7);

    try{
        $objThis->checkIncludeModule();

        $arFields = $objThis->getFieldsSms($objEvent, $objThis->objSms4b);

        $objThis->checkNewOrder($objThis->objEvent);

        $objThis->checkUserPhone($objThis->objSms4b, $arFields['PHONE_TO']);

        $arAdminNumbers = $objThis->getAdminNumbers($objThis->objSms4b);

        $UserTextMessage = $objThis->getTextMessage($objThis->objSms4b, $arFields);

        $AdminTextMessage = $objThis->getTextMessage($objThis->objSms4b, $arFields, $arAdminNumbers);

        foreach ($arAdminNumbers as $phoneNum) {
            $arSendSms[$phoneNum] = $AdminTextMessage['MESSAGE'];
        }
        $arSendSms[$arFields['PHONE_TO']] = $UserTextMessage['MESSAGE'];

        $period = $objThis->getPeriod();

        AddMessage2Log('$period');
        AddMessage2Log($period);
        AddMessage2Log('$arSendSms');
        AddMessage2Log($arSendSms);

        $objThis->objSms4b->SendSmsSaveGroup($arSendSms, null, null, null, $period, null, $arFields['ORDER_ID'], 'SALE_NEW_ORDER', 0);

        return true;

    } catch (Exception $e) {
        AddMessage2Log('Выброшено исключение:', $e->getMessage(), "\n");
        return false;
    }
}