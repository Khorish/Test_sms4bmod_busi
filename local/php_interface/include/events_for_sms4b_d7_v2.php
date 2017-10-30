<?php

class CustomizeSendSMS
{
    public $startQuitePeriod = START_QUITE_PERIOD;
    public $endQuitePeriod = END_QUITE_PERIOD;
    private $objSMS4B = null;
    private $templeMessage = 'SMS4B_';
    private $sendUserAdmin = false;
    private $userPhone = null;
    private $eventName = 'SALE_NEW_ORDER';
    private $orderId = null;
    private $siteId = null;
    private $fieldsSMS = array();
    private $sender = null;

    /**
     * CustomizeSendSMS constructor.
     * @param $siteId
     * @param $arFields
     * @param bool $admin
     */
    public function __construct($siteId, $arFields, $admin = false)
    {
        $this->siteId = $siteId;
        $this->orderId = $arFields['ORDER_ID'];
        $this->fieldsSMS = $arFields;
        $this->objSMS4B = $GLOBALS['SMS4B'];
        if ($admin === true) {
            $this->templeMessage .= 'ADMIN_';
            $this->sendUserAdmin = $admin;
        }
    }

    /**
     * @return mixed
     */
    public function phoneTo()
    {
        $notFormatPhone = $this->objSMS4B->GetPhoneOrder($this->orderId, $this->siteId);
        $this->userPhone = $this->objSMS4B->is_phone($notFormatPhone);

        if ($this->sendUserAdmin === true) {
            $phoneToSend = $this->objSMS4B->GetAdminPhones($this->siteId);
        } else {
            $phoneToSend = $this->userPhone;
        }

        return $phoneToSend;
    }

    /**
     * @return mixed
     */
    public function getSender()
    {
        $this->sender = $this->objSMS4B->GetCurrentOption('defsender', $this->siteId);
        return $this->sender;
    }

    /**
     * @param $userNumber
     * @return mixed
     */
    public function getTextMessage()
    {
        $textMessage = $this->objSMS4B->GetEventTemplate($this->templeMessage . $this->eventName, $this->siteId);

        foreach ($this->fieldsSMS as $k => $value) {
            $textMessage['MESSAGE'] = str_replace('#' . $k . '#', $value, $textMessage['MESSAGE']);
        }

        $textMessage['MESSAGE'] = str_replace('#PHONE_TO#', $this->userPhone, $textMessage['MESSAGE']);

        if ($this->objSMS4B->use_translit === 'Y') {
            $textMessage['MESSAGE'] = $this->objSMS4B->Translit($textMessage['MESSAGE']);
        }
        return $textMessage;
    }

    /**
     * @param $startQuitePeriod
     * @param $endQuitePeriod
     * @return null|string
     */
    public function checkQuitTime($startQuitePeriod, $endQuitePeriod)
    {
        $nowTime = new DateTime();
        $startQuitPeriod = new DateTime($startQuitePeriod);
        $endQuitPeriod = new DateTime($endQuitePeriod);
        $timeToSend = null;

        if (($startQuitPeriod <= $nowTime || $nowTime <= $endQuitPeriod) && ($startQuitPeriod > $endQuitPeriod)) {
            $timeToSend = $endQuitPeriod->modify('+1 day')->format('Y-m-d H:i:s');
        } elseif (($startQuitPeriod <= $nowTime && $nowTime <= $endQuitPeriod) && ($startQuitPeriod < $endQuitPeriod)) {
            $timeToSend = $endQuitPeriod->format('Y-m-d H:i:s');
        }
        return $timeToSend;
    }

    /**
     * @return bool
     */
    public function sendCustomSMS()
    {
        $phoneSendSMS = $this->phoneTo();
        $textMessage = $this->getTextMessage();

        $timeSend = $this->checkQuitTime($this->startQuitePeriod, $this->endQuitePeriod);

        if (empty($phoneSendSMS) || !is_array($textMessage)) {
            return false;
        }

        $arSendSms = array();
        if (is_array($phoneSendSMS)) {
            foreach ($phoneSendSMS as $phoneNum) {
                $arSendSms[$phoneNum] = $textMessage['MESSAGE'];
            }
        } else {
            $arSendSms[$phoneSendSMS] = $textMessage['MESSAGE'];
        }

        $this->objSMS4B->SendSmsSaveGroup($arSendSms, $this->getSender(), $timeSend, null, null, null, $this->orderId,
            $this->eventName, 0);

        return true;
    }
}

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
 * @throws Main\ArgumentException
 */
function EditOrderAndSendSMS(Main\Event $event)
{
    try {
        if (!$event->getParameter('IS_NEW')) {
            return false;
        }

        if (!CModule::IncludeModule('rarus.sms4b')) {
            throw new Exception('модуль rarus.sms4b не подключен');
        }

        $order = $event->getParameter('ENTITY');

        // fields from $order
        $site = $order->getSiteId();
        $arFields['ORDER_ID'] = $order->getId();
        $arFields['ORDER_DATE'] = $order->getDateInsert()->format('Y-m-d H:i:s');
        $arFields['PRICE'] = CCurrencyLang::CurrencyFormat($order->getPrice(), $order->getCurrency());
        $arItems = $order->getBasket()->getListOfFormatText();
        foreach ($arItems as $key => $val) {
            $arFields['ORDER_LIST'] .= $val . ' ';
        }
        $userId = $order->getUserId();
        $result = \Bitrix\Main\UserTable::getList(array(
            'select' => array('NAME'),
            'filter' => array('=ID' => $userId)
        ));
        if ($row = $result->fetch()) {
            $arFields['USER_LOGIN'] = $row['NAME'];
        }

        // fields from $propertyCollection
        $propertyCollection = $order->getPropertyCollection();
        $arFields['LOCATION'] = $propertyCollection->getDeliveryLocation()->getViewHtml();
        $arOrderProperties = array();
        foreach ($propertyCollection as $propertyItem) {
            $arOrderProperties[$propertyItem->getField('CODE')] = $propertyItem->getValue();
        }
        $arFields['ORDER_USER'] = $arOrderProperties['FIO'];
        $arFields['ADDRESS'] = $arOrderProperties['ADDRESS'];
        $arFields['ZIP'] = $arOrderProperties['ZIP'];

        unset($arOrderProperties, $row);

        $SendSmsToUser = new CustomizeSendSMS($site, $arFields);
        $SendSmsToUser->sendCustomSMS();

        $SendSmsToAdmin = new CustomizeSendSMS($site, $arFields, true);
        $SendSmsToAdmin->sendCustomSMS();

    } catch (Exception $e) {
        AddMessage2Log('Выброшено исключение: ', $e->getMessage(), "\n");
        return false;
    }
    return true;
}