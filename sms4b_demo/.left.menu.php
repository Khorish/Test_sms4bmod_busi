<?
use \Bitrix\Main\Localization\Loc;
Loc::loadLanguageFile(__FILE__);

$aMenuLinks = Array(
    Array(
        Loc::getMessage('SMS4B_MAIN_MENUE_1'),
        'subscribe_demo.php',
        Array(),
        Array(),
        ''
    ),
    Array(
        Loc::getMessage('SMS4B_MAIN_MENUE_2'),
        'subscr_edit.php',
        Array(),
        Array(),
        ''
    ),
    Array(
        Loc::getMessage('SMS4B_MAIN_MENUE_3'),
        'subscr_edit_sms.php',
        Array(),
        Array(),
        ''
    ),
    Array(
        Loc::getMessage('SMS4B_MAIN_MENUE_4'),
        'minisub.php',
        Array(),
        Array(),
        ''
    )
);