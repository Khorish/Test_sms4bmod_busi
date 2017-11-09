<?php
use Bitrix\Main;

/*
 * Класс отправляет смс клиенту и админам по событию новый заказ
 * (Возможна кастомизация по тексту, полям, времени отправки, номерам отправки)
 */

class CustomizeSendSms
{
    /**
     * @var Csms4b $obSms4b - объект Sms4b, уже созданный в системе
     */
    public $obSms4b;
    /**
     * @var string $startQuitePeriod - номер часа, с которого начинается "тихий период" - (0-23)
     */
    public $startQuitePeriod;
    /**
     * @var string $endQuitePeriod - номер часа, на котором кончается "тихий период" - (0-23)
     */
    public $endQuitePeriod;

    /**
     * CustomizeSendSms constructor.
     * @param Csms4b $obSms4b
     * @param null $startQuitePeriod
     * @param null $endQuitePeriod
     */
    public function __construct(Csms4b $obSms4b,  $startQuitePeriod = null,  $endQuitePeriod = null)
    {
        if (null !== $obSms4b) {
            $this->obSms4b = $obSms4b;
        } else {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/rarus.sms4b/classes/mysql/sms4b.php';
            $this->obSms4b = new Csms4b();
        }

        if ($startQuitePeriod !== null)
        {
            $this->startQuitePeriod = $startQuitePeriod;
        }

        if ($endQuitePeriod !== null)
        {
            $this->endQuitePeriod = $endQuitePeriod;
        }

    }

    /**
     * @throws |Exception
     */
    public function checkIncludeModule()
    {
        if (!CModule::IncludeModule('rarus.sms4b'))
        {
            throw new \Exception('модуль rarus.sms4b не подключен');
        }
    }

    /**
     * @param Csms4b $obSms4b
     * @param $userPhone
     * @return false|string
     * @throws |Exception
     */
    public function validateUserPhone(Csms4b $obSms4b, $userPhone)
    {
        $userPhoneFormat = $obSms4b->is_phone($userPhone);

        if ($userPhoneFormat === false) {
            throw new \Exception('Не возможно отправить по такому телефону' . $userPhone);
        }
        return $userPhoneFormat;
    }


    /**
     * @param Main\Event $obEvent
     * @param Csms4b $obSms4b
     * @return array
     * @throws Main\ArgumentException
     * @throws Main\LoaderException
     * @throws Main\ObjectException
     */
    public function getFieldsSms(Main\Event $obEvent, Csms4b $obSms4b)
    {
       /* $arFields['ORDER_ID'] = $obEvent->getParameter('ENTITY')->getId();

        $arFieldsRes = $obSms4b->GetOrderData(array($arFields['ORDER_ID']));
        $arFields = array_merge($arFields, $arFieldsRes[$arFields['ORDER_ID']]);

        $arFields['PRICE'] = CCurrencyLang::CurrencyFormat($arFields['PRICE'], $arFields['CURRENCY']);
        $result = \Bitrix\Main\UserTable::getList(array(
            'select' => array('NAME'),
            'filter' => array('=ID' => $arFields['USER_ID'])
        ));
        if ($row = $result->fetch()) {
            $arFields['USER_LOGIN'] = $row['NAME'];
        }

        $order = $obEvent->getParameter('ENTITY');
        $propertyCollection = $order->getPropertyCollection();
        $arFields['LOCATION'] = $propertyCollection->getDeliveryLocation()->getViewHtml();*/


        $order = $obEvent->getParameter('ENTITY');
        // fields from $order
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
        $arFields['FIO'] = $arOrderProperties['FIO'];
        $arFields['ADDRESS'] = $arOrderProperties['ADDRESS'];
        $arFields['ZIP'] = $arOrderProperties['ZIP'];

        $arFields['PHONE_TO'] = $obSms4b->is_phone($obSms4b->GetPhoneOrder($arFields['ORDER_ID'], SITE_ID));

        unset($arOrderProperties, $row);

        return $arFields;
    }

    /** Как альтернатива есть функция SendSmsByTemplate - но там нужен будет цикл при отправке, потому что отправка по SendSms
     * @param Csms4b $obSms4b
     * @param array $arFields
     * @param $templName
     * @return array
     */
    public function getTextMessage(Csms4b $obSms4b, array $arFields, $templName)
    {
        $textMessage = $obSms4b->GetEventTemplate($templName, SITE_ID);

        foreach ($arFields as $k => $value) {
            $textMessage['MESSAGE'] = str_replace('#' . $k . '#', $value, $textMessage['MESSAGE']);
        }

        $textMessage['MESSAGE'] = str_replace('#PHONE_TO#', $arFields['PHONE_TO'], $textMessage['MESSAGE']);

        if ($obSms4b->use_translit === 'Y') {
            $textMessage['MESSAGE'] = $obSms4b->Translit($textMessage['MESSAGE']);
        }

        return $textMessage;
    }

    /**
     * @return null|string
     */
    public function getPeriod()
    {
        $period = null;

        if (($this->startQuitePeriod !== null) && ($this->endQuitePeriod !== null)) {
            $period = trim(chr(65 + (int)$this->startQuitePeriod) . chr(65 + (int)$this->endQuitePeriod));
        }
        return $period;
    }
    /**
     *
     *
     * @param Main\Event $obEvent
     * @return bool
     */
    public function sendCustomSMS(Main\Event $obEvent)
    {
        try {
            /** @var Csms4b $obSms4b */
            $obSms4b = $this->obSms4b;

            if (!$obEvent->getParameter('IS_NEW'))
            {
                return false;
            }

            $this->checkIncludeModule();

            $arFields = $this->getFieldsSms($obEvent, $obSms4b);

            $arFields['PHONE_TO'] = $this->validateUserPhone($obSms4b, $arFields['PHONE_TO']);

            $arAdminNumbers = $obSms4b->GetAdminPhones(SITE_ID);

            $userTextMessage = $this->getTextMessage($obSms4b, $arFields, 'SMS4B_SALE_NEW_ORDER');

            $adminTextMessage = $this->getTextMessage($obSms4b, $arFields, 'SMS4B_ADMIN_SALE_NEW_ORDER');

            foreach ($arAdminNumbers as $phoneNum) {
                $arSendSms[$phoneNum] = $adminTextMessage['MESSAGE'];
            }
            $arSendSms[$arFields['PHONE_TO']] = $userTextMessage['MESSAGE'];

            $period = $this->getPeriod();

            $obSms4b->SendSmsSaveGroup($arSendSms, null, null, null, $period, null, $arFields['ORDER_ID'], 'SALE_NEW_ORDER', 0);

            return true;
        } catch (Exception $e) {
            AddMessage2Log('Выброшено исключение:', $e->getMessage(), "\n");
            return false;
        }
    }
}