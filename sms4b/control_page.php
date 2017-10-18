<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Control Page send sms");
echo date("d-m-Y H:i");
if($USER->IsAdmin()){
    $event_name = "OPEN_CONTROL_PAGE";

    $arFields = array(
        "DATE" =>date("d-m-Y H:i"),
        "USER_ID" => $USER->GetID(),
        "USER_LOGIN" => $USER->GetLogin(),
        "CUR_PAGE" => $APPLICATION->GetCurPage()
    );

    $event = new CEvent;
    $event->Send($event_name, SITE_ID, $arFields, "N");
}
?><?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>