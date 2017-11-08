<?php
//use Rarus\Sms4b;
//use Rarus\Sms4b\Sms4bException;
use Bitrix\Main;

/*
 * Класс отправляет смс клиенту и админам по событию новый заказ
 * (Возможна кастомизация по тексту, полям, времени отправки, номерам отправки)
 */

class CustomizeSendSms
{
    /**
     * @var object - объект события переданный битрикс-обработчиком
     */
    public $objEvent;
    /**
     * @var object - объект Sms4b, уже созданный в системе
     */
    public $objSms4b;
    /**
     * @var integer - номер часа, с которого начинается "тихий период" - (0-23)
     */
    public $startQuitePeriod;
    /**
     * @var integer - номер часа, на котором кончается "тихий период" - (0-23)
     */
    public $endQuitePeriod;

    /**
     * CustomizeSendSms constructor.
     * @param $objEvent object - объект события OnSaleOrderSaved (передается битриксом)
     * @param $objSms4b object - объект модуля sms4b, который уже создан в системе
     * @param null $startQuitePeriod string - начало периода, в который не отсылать смс
     * @param null $endQuitePeriod string - конец периода, в который не отсылать смс
     */
    public function __construct(Main\Event $objEvent, object $objSms4b, string $startQuitePeriod = null, string $endQuitePeriod = null)
    {
        $this->objEvent = $objEvent;

        /**
         * @var Csms4b $objThis ->objSms4b
         */
        if (null !== $objSms4b) {
            $this->objSms4b = $objSms4b;
        } else {
            require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/rarus.sms4b/classes/mysql/sms4b.php');
            $this->objSms4b = new Csms4b();
        }

        if ($startQuitePeriod !== null) {
            $this->startQuitePeriod = $startQuitePeriod;
        }

        if ($endQuitePeriod !== null) {
            $this->endQuitePeriod = $endQuitePeriod;
        }
    }

    /**
     * @throws Exception - не подключенный модуль rarus.sms4b
     */
    public function checkIncludeModule()
    {
        if (!CModule::IncludeModule('rarus.sms4b'))
        {
            throw new Exception('модуль rarus.sms4b не подключен');
        }
    }

    /**
     * @param $objEvent object -  объект события OnSaleOrderSaved (передается битриксом)
     *
     * @throws Exception - проверка, что заказ новый, а не обновление
     */
    public function checkNewOrder(object $objEvent)
    {
        if (!$objEvent->getParameter('IS_NEW'))
        {
            throw new Exception('Это обновление заказа, а не новый заказ');
        }
    }

    /**
     * @param $objSms4b object
     * @param $userPhone string
     *
     * @throws Exception
     *
     * @return string
     */
    public function checkUserPhone(object $objSms4b, string $userPhone)
    {
        $userPhoneFormat = $objSms4b->is_phone($userPhone);
        if ($userPhoneFormat === false) {
            throw new Exception('Не возможно отправить по такому телефону' . $userPhone);
        }
        return $userPhone;
    }

    /**
     * -  объект события OnSaleOrderSaved (передается битриксом)
     * @param $event obj
     * @param $objSms4b obj - объект модуля sms4b, который уже создан в системе
     *
     * @throws Main\ArgumentException
     *
     * @return array - поля с данными для отправки - состав заказа, цена, ...
     */
    public function getFieldsSms(object $event, object $objSms4b)
    {
        $arFields['ORDER_ID'] = $event->getParameter('ENTITY')->getId();

        $arFieldsRes = $objSms4b->GetOrderData(array($arFields['ORDER_ID']));
        $arFields = array_merge($arFields, $arFieldsRes[$arFields['ORDER_ID']]);

        $arFields['PRICE'] = CCurrencyLang::CurrencyFormat($arFields['PRICE'], $arFields['CURRENCY']);
        $result = \Bitrix\Main\UserTable::getList(array(
            'select' => array('NAME'),
            'filter' => array('=ID' => $arFields['USER_ID'])
        ));
        if ($row = $result->fetch()) {
            $arFields['USER_LOGIN'] = $row['NAME'];
        }
//        метод getLocationPathDisplay - deprecated
//
//        но есть, ресурсоемкая альтернатива
//        $order = $event->getParameter('ENTITY');
//        $propertyCollection = $order->getPropertyCollection();
//        $arFields['LOCATION'] = $propertyCollection->getDeliveryLocation()->getViewHtml();
//
//        или альтернатива на идентификаторе, не учитывающая возможность передачи символьного кода в Location
//        $arFields['LOCATION'] = Bitrix\Sale\Location\Admin\LocationHelper::getLocationStringById($arFields['LOCATION']);
        $arFields['LOCATION'] = Bitrix\Sale\Location\Admin\LocationHelper::getLocationPathDisplay($arFields['LOCATION']);

        return $arFields;
    }

    /**
     * @param $objSms4b object - объект модуля sms4b, который уже создан в системе
     * @return array - массив номеров админов
     */
    public function getAdminNumbers(object $objSms4b)
    {
        return $objSms4b->GetAdminPhones(SITE_ID);
    }


    /** Как альтернатива есть функция SendSmsByTemplate - но там нужен будет цикл при отправке, потому что отправка по SendSms
     *
     * @param $objSms4b object - объект модуля sms4b, который уже создан в системе
     * @param $arFields array - поля данных для СМС
     * @param array $arAdminNumbers array - массив номеров админа, если от правка юзеру, то массив пустой, номер юзер уже есть в полях данных
     * @return array
     */
    public function getTextMessage(object $objSms4b, array $arFields, $templName)
    {
//        if (empty($arAdminNumbers)) {
//            $templName = '';
//        } else {
//            $templName = 'SMS4B_ADMIN_SALE_NEW_ORDER';
//        }

        $textMessage = $objSms4b->GetEventTemplate($templName, SITE_ID);

        foreach ($arFields as $k => $value) {
            $textMessage['MESSAGE'] = str_replace('#' . $k . '#', $value, $textMessage['MESSAGE']);
        }

        $textMessage['MESSAGE'] = str_replace('#PHONE_TO#', $arFields['PHONE_TO'], $textMessage['MESSAGE']);

        if ($objSms4b->use_translit === 'Y') {
            $textMessage['MESSAGE'] = $objSms4b->Translit($textMessage['MESSAGE']);
        }
        return $textMessage;
    }

    /**
     * @return null|string - возвращает две большие латинские буквы от A до X, пример перерыв 23-01 Y-B
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
     * Кастомизация данных отправляемых в СМС и времени отправки
     * - объект события, передаваемый обработчиком битрикса
     * @param Main\Event $objEvent object
     *
     * @throws CheckIncludeModule() - проверка подключения модуля rarus.sms4b
     * @throws CheckNewOrder() - проверка что заказ новый, а не обновление старого заказа
     *
     * @return bool - результат
     */
    public function sendCustomSMS(Main\Event $objEvent)
    {

        $objThis = new CustomizeSendSms($objEvent, $GLOBALS['SMS4B'], 19, 7);

        try {
            $objThis->checkIncludeModule();

            $arFields = $objThis->getFieldsSms($objEvent, $objThis->objSms4b);

            $objThis->checkNewOrder($objThis->objEvent);

            //$objThis->checkUserPhone($objThis->objSms4b, $arFields['PHONE_TO']);

            $arAdminNumbers = $objThis->getAdminNumbers($objThis->objSms4b);

            $UserTextMessage = $objThis->getTextMessage($objThis->objSms4b, $arFields, 'SMS4B_SALE_NEW_ORDER');

            $AdminTextMessage = $objThis->getTextMessage($objThis->objSms4b, $arFields, 'SMS4B_SALE_NEW_ORDER_ADMIN');

            foreach ($arAdminNumbers as $phoneNum) {
                $arSendSms[$phoneNum] = $AdminTextMessage['MESSAGE'];
            }
            $arSendSms[$arFields['PHONE_TO']] = $UserTextMessage['MESSAGE'];

            $period = $objThis->getPeriod();

            // проверить отправку по периодам
            AddMessage2Log('$period');
            AddMessage2Log($period);

            $objThis->objSms4b->SendSmsSaveGroup($arSendSms, null, null, null, $period, null, $arFields['ORDER_ID'],
                'SALE_NEW_ORDER', 0);

            return true;
        } catch (Exception $e) {
            AddMessage2Log('Выброшено исключение:', $e->getMessage(), "\n");
            return false;
        }
    }
}