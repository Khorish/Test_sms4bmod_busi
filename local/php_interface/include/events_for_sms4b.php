<?

use Bitrix\Main\Type\DateTime;

AddEventHandler("main", "OnBeforeEventAdd", "editOrderSMS");

/**
 * @param $event
 * @param $site
 * @param $arFields
 * @return bool|void
 */
function editOrdeпшеrSMS($event_name, $site, &$arFields)
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
        "ZIP" => 'ZIP',
        "LOCATION" => 'LOCATION',
        "ADDRESS" => 'ADDRESS',
    );
    $arFields["PHONE_TO"] = $SMS4B->is_phone($SMS4B->GetPhoneOrder($orderId, "s1"));
    $arFields["ORDER_DATE"] = date("d-m-Y", strtotime($arFields["ORDER_DATE"]));

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
        $arFields[$arrOrder["CODE"]] = $arrOrder['VALUE'];
    }

    if (!empty($arFields["LOCATION"])) {
        $Location = CSaleLocation::GetByID($arFields["LOCATION"]);
        $arFields["LOCATION"] = $Location["COUNTRY_NAME_ORIG"] . ' ' . $Location["REGION_NAME_ORIG"] . ' ' . $Location["CITY_NAME_ORIG"];
    }

    // Date Time block
    function SendSmsToUser($templSms4bUs, $event_name, $site, $arFields, $admin = 'N')
    {
        if (!CModule::includeModule('rarus.sms4b')) {
            AddMessage2Log('не подключен модуль rarus.sms4b');
            return;
        }

        global $SMS4B;

        $sender = $SMS4B->GetCurrentOption('defsender', $site);
        $text = $SMS4B->GetEventTemplate($templSms4bUs . $event_name, $site);

        foreach ($arFields as $k => $value) {
            $text['MESSAGE'] = str_replace('#' . $k . '#', $value, $text['MESSAGE']);
        }

        if ($admin !== 'Y') {
            $phone_send_sms = $SMS4B->is_phone($SMS4B->GetPhoneOrder($arFields['ORDER_ID'], $site));
        } else {
            $phone_send_sms = $SMS4B->GetAdminPhones($site);
            $text['MESSAGE'] = str_replace('#PHONE_TO#', $phone_send_sms, $text['MESSAGE']);
        }

        if (empty($phone_send_sms) || !is_array($text)) {
            return false;
        }

        $message = $SMS4B->use_translit === 'Y' ? $SMS4B->Translit($text['MESSAGE']) : $text['MESSAGE'];

        $startQuitPeriod = DateTime::createFromPhp(new \DateTime(START_QUITE_PERIOD));
        $endQuitPeriod = DateTime::createFromPhp(new \DateTime(END_QUITE_PERIOD));

        $nowTime = new DateTime();

        if (is_object($nowTime)) {
            AddMessage2Log('object DateTime - Y');
        } else {
            AddMessage2Log('not object DateTime - dont create');
        }

        if (($startQuitPeriod <= $nowTime || $nowTime <= $endQuitPeriod) && ($startQuitPeriod > $endQuitPeriod)) {
            AddMessage2Log('Quite period');
            $timeToSend = $endQuitPeriod->add("1 day");
        } elseif (($startQuitPeriod <= $nowTime && $nowTime <= $endQuitPeriod) && ($startQuitPeriod < $endQuitPeriod)) {
            ;
            AddMessage2Log('Quite period');
            $timeToSend = $endQuitPeriod;
        } else {
            AddMessage2Log('not Quite period');
        }

        if (is_array($phone_send_sms)) {
            foreach ($phone_send_sms as $phone_num) {
                $res = $SMS4B->SendSmsSaveGroup(array($phone_num => $message), $sender, $timeToSend, null, null, null,
                    $arFields['ORDER_ID'],
                    $event_name, 0);
            }
        } else {
            $res = $SMS4B->SendSmsSaveGroup(array($phone_send_sms => $message), $sender, $timeToSend, null, null, null,
                $arFields['ORDER_ID'],
                $event_name, 0);
        }

    }

    SendSmsToUser('SMS4B_', $event_name, $site, $arFields);
    SendSmsToUser('SMS4B_ADMIN_', $event_name, $site, $arFields, 'Y');
}


?>