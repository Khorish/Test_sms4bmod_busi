<?php

use Bitrix\Main\Type\DateTime;

AddEventHandler('main', 'OnBeforeEventAdd', 'editOrderSMS');

/**
 * @param $event_name
 * @param $site
 * @param $arFields
 * @return bool|void
 */
function editOrderSMS($event_name, $site, &$arFields)
{
    if ($event_name !== 'SALE_NEW_ORDER') {
        return;
    }

    if (!CModule::includeModule('rarus.sms4b')) {
        AddMessage2Log('не подключен модуль rarus.sms4b');
        return;
    }

    global $SMS4B;

    $orderId = $arFields['ORDER_ID'];

    $arCode = array(
        'ZIP' => 'ZIP',
        'LOCATION' => 'LOCATION',
        'ADDRESS' => 'ADDRESS',
    );
    $arFields['PHONE_TO'] = $SMS4B->is_phone($SMS4B->GetPhoneOrder($orderId, 's1'));
    $arFields['ORDER_DATE'] = date('d-m-Y', strtotime($arFields['ORDER_DATE']));

    $orderIdReal = null;
    // 2 потенциальные ошибки вшитые в битрикс $by => $order
    // в D7 прямого подобного метода нет (если критично можно через getBasket попробовать)
    $by = 'ID';
    $order='DESC';
    $dbOrderList = CSaleOrder::GetList(
        array($by => $order),
        array('ACCOUNT_NUMBER' => $orderId),
        false,
        false,
        array('ID', 'CANCELED', 'ACCOUNT_NUMBER', 'USER_LOGIN')
    );

    while ($arSaleProp = $dbOrderList->GetNext()) {
        $orderIdReal = $arSaleProp['ID'];
        $arFields['USER_LOGIN'] = $arSaleProp['USER_LOGIN'];
    }

    $dbProps = CSaleOrderPropsValue::GetList(
        array('SORT' => 'ASC'),
        array(
            'ORDER_ID' => $orderIdReal,
            'CODE' => $arCode
        )
    );
    while ($arrOrder = $dbProps->Fetch()) {
        $arFields[$arrOrder['CODE']] = $arrOrder['VALUE'];
    }

    if (!empty($arFields['LOCATION'])) {
        $Location = CSaleLocation::GetByID($arFields['LOCATION']);
        $arFields['LOCATION'] = $Location['COUNTRY_NAME_ORIG'] . ' ' . $Location['REGION_NAME_ORIG'] . ' ' . $Location['CITY_NAME_ORIG'];
    }

    // Date Time block
    /**
     * @param $templSms4bUs
     * @param $event_name
     * @param $site
     * @param $arFields
     * @param string $admin
     */
    // Ошибка: Больше трех параметров в функции - плохая практика, я - плохой практикант.
    /**
     * @param $templSms4bUs
     * @param $event_name
     * @param $site
     * @param $arFields
     * @param string $admin
     * @return bool
     */
    function SendSmsToUser($templSms4bUs, $event_name, $site, $arFields, $admin = 'N')
    {
        if (!CModule::includeModule('rarus.sms4b')) {
            AddMessage2Log('не подключен модуль rarus.sms4b');
            return false;
        }

        global $SMS4B;

        //Методы GetCurrentOption GetEventTemplate принадлежат объекту $SMS4B - шторм ошибся в ошибке (смотри dev)
        $sender = $SMS4B->GetCurrentOption('defsender', $site);
        $text = $SMS4B->GetEventTemplate($templSms4bUs . $event_name, $site);

        // Ошибка - риск сбоя, из-за того что тип передаваемой переменной не определен - считаю можно проигнорировать
        foreach ($arFields as $k => $value) {
            $text['MESSAGE'] = str_replace('#' . $k . '#', $value, $text['MESSAGE']);
        }

        //Здесь ошибки - метод is_phone GetPhoneOrder и GetAdminPhones не принадлежат объекту - но по факту принадлежит
        //AddMessage2Log('<br> method_exists GetAdminPhones? - '.method_exists($SMS4B, 'GetAdminPhones'));
        //AddMessage2Log('<br> method_exists GetPhoneOrder? - '.method_exists($SMS4B, 'GetPhoneOrder'));
        //AddMessage2Log('<br> method_exists is_phone? - '.method_exists($SMS4B, 'is_phone'));

        if ($admin !== 'Y') {
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
                    $arFields['ORDER_ID'], $event_name, 0);
            }
        } else {
            $SMS4B->SendSmsSaveGroup(array($phone_send_sms => $message), $sender, $timeToSend, null, null, null,
                $arFields['ORDER_ID'], $event_name, 0);
        }

        return true;
    }

    SendSmsToUser('SMS4B_', $event_name, $site, $arFields);
    SendSmsToUser('SMS4B_ADMIN_', $event_name, $site, $arFields, 'Y');
}