<?php

use Bitrix\Main,
    Bitrix\Main\Type\DateTime;

Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleOrderSaved',
    'EditOrderAndSendSMS'
);

/**
 * @param Main\Event $event
 * @return bool
 */
function EditOrderAndSendSMS(Main\Event $event)
{
    if (!CModule::includeModule('rarus.sms4b')) {
        AddMessage2Log('не подключен модуль rarus.sms4b');
        return false;
    }
    /**
     * @var Csms4b $SMS4B
     */
    global $SMS4B;

    $isNew = $event->getParameter('IS_NEW');

    if (!$isNew) {
        return false;
    }

    $arFields = array();
    $arFields['SALE_EMAIL'] = \Bitrix\Main\Config\Option::get('main', 'email_from', '');
    $order = $event->getParameter('ENTITY');
    $site = $order->getSiteId();

    $userId = $order->getUserId();
    $result = \Bitrix\Main\UserTable::getList(array(
        'select' => array('NAME'),
        'filter' => array(
            '=ID' => $userId
        )
    ));
    if ($row = $result->fetch()) {
        $arFields['USER_LOGIN'] = $row['NAME'];
    }

    $arFields['ORDER_ID'] = $order->getId();

    $arFields['ORDER_DATE'] = $order->getDateInsert()->format('Y-m-d H:i:s');

    $arFields['PRICE'] = CCurrencyLang::CurrencyFormat($order->getPrice(), $order->getCurrency(), true);

    $arItems = $order->getBasket()->getListOfFormatText();
    foreach ($arItems as $key => $val) {
        $arFields['ORDER_LIST'] .= $val . ' ';
    }

    $propertyCollection = $order->getPropertyCollection();
    $arOrderProperties = array();
    foreach ($propertyCollection as $propertyItem) {
        $arOrderProperties[$propertyItem->getField('CODE')] = $propertyItem->getValue();
    }

    $arFields['ORDER_USER'] = $arOrderProperties['FIO'];
    $arFields['LOCATION'] = $propertyCollection->getDeliveryLocation()->getViewHtml();
    $arFields['ADDRESS'] = $arOrderProperties['ADDRESS'];
    $arFields['ZIP'] = $arOrderProperties['ZIP'];
    unset($arOrderProperties);

    $arFields['PHONE_TO'] = $SMS4B->is_phone($SMS4B->GetPhoneOrder($arFields['ORDER_ID'], $site));

    // Date Time block
    /**
     * @param $templSms4bUs
     * @param $site
     * @param $arFields
     * @param string $admin
     * @return bool
     */
    function SendSmsToUser($templSms4bUs, $site, $arFields, $admin = 'N')
    {
        /**
         * @var Csms4b $SMS4B
         */
        global $SMS4B;
        $eventName = 'SALE_NEW_ORDER';

        //Методы GetCurrentOption GetEventTemplate принадлежат объекту $SMS4B - шторм ошибся в ошибке (смотри dev)
        $sender = $SMS4B->GetCurrentOption('defsender', $site);
        $text = $SMS4B->GetEventTemplate($templSms4bUs . $eventName, $site);

        // Ошибка - не определен тип передаваемой переменной, т.к. не документирован метод  - считаю можно проигнорировать
        foreach ($arFields as $k => $value) {
            $text['MESSAGE'] = str_replace('#' . $k . '#', $value, $text['MESSAGE']);
        }

        //Здесь ошибки - метод is_phone GetPhoneOrder и GetAdminPhones не принадлежат объекту - но по факту принадлежит
        //AddMessage2Log('<br> method_exists GetAdminPhones? - '.method_exists($SMS4B, 'GetAdminPhones'));
        //AddMessage2Log('<br> method_exists GetPhoneOrder? - '.method_exists($SMS4B, 'GetPhoneOrder'));
        //AddMessage2Log('<br> method_exists is_phone? - '.method_exists($SMS4B, 'is_phone'));
        if ($admin !== 'Y') {
            // сохраняю нижнее подчеркивание для этой переменной,
            //  чтобы она оставалась такая же как в коде модуля, чтоб легче видеть соответсвия при чтении кода
            $phone_send_sms = $SMS4B->is_phone($SMS4B->GetPhoneOrder($arFields['ORDER_ID'], $site));
        } else {
            $phone_send_sms = $SMS4B->GetAdminPhones($site);
            $text['MESSAGE'] = str_replace('#PHONE_TO#', $phone_send_sms, $text['MESSAGE']);
        }

        if (empty($phone_send_sms) || !is_array($text)) {
            return false;
        }

        //Здесь ошибка метод Translit не принадлежит объекту - но по факту принадлежит
        //AddMessage2Log('<br> method_exists Translit? - '.method_exists($SMS4B, 'Translit'));
        // шторм  ошибся наверно потому что этот метод принадлежит родителю того класса, который создал метод, а не классу-создателю объекта
        $message = $SMS4B->use_translit === 'Y' ? $SMS4B->Translit($text['MESSAGE']) : $text['MESSAGE'];

        $startQuitPeriod = DateTime::createFromPhp(new \DateTime(START_QUITE_PERIOD));
        $endQuitPeriod = DateTime::createFromPhp(new \DateTime(END_QUITE_PERIOD));

        $nowTime = new DateTime();
        //$nowTime = DateTime::createFromPhp(new \DateTime('03:50')); // для теста //v/

        $timeToSend = null;

        if (($startQuitPeriod <= $nowTime || $nowTime <= $endQuitPeriod) && ($startQuitPeriod > $endQuitPeriod)) {
            $timeToSend = $endQuitPeriod->add('1 day');
        } elseif (($startQuitPeriod <= $nowTime && $nowTime <= $endQuitPeriod) && ($startQuitPeriod < $endQuitPeriod)) {
            $timeToSend = $endQuitPeriod;
        }

        if (is_array($phone_send_sms)) {
            foreach ($phone_send_sms as $phone_num) {
                //Здесь ошибка - SendSmsSaveGroup не принадлежит объекту $SMS4B  Method 'SendSmsSaveGroup' not found in less... Referenced method is not found in subject class.
                // но по факту - принадлежит
                //AddMessage2Log('method_exists? - '.method_exists($SMS4B, 'SendSmsSaveGroup'));
                // шторм наверно ошибся потому что этот метод принадлежит родителю того класса, который создал метод, а не классу-создателю объекта
                $SMS4B->SendSmsSaveGroup(array($phone_num => $message), $sender, $timeToSend, null, null, null,
                    $arFields['ORDER_ID'], $eventName, 0);
            }
        } else {
            $SMS4B->SendSmsSaveGroup(array($phone_send_sms => $message), $sender, $timeToSend, null, null, null,
                $arFields['ORDER_ID'], $eventName, 0);
        }

        return true;
    }

    SendSmsToUser('SMS4B_', $site, $arFields);
    SendSmsToUser('SMS4B_ADMIN_', $site, $arFields, 'Y');

    return true;

}