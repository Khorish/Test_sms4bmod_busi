<?php
define("LOG_FILENAME", $_SERVER["DOCUMENT_ROOT"]."/log.txt");
// начало и конец периода когда смс не должны приходить (ночь, тихий период)
define("START_QUITE_PERIOD", '21:00');
define("END_QUITE_PERIOD", '08:00');
?>