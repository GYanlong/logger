<?php
require './Logger.php';

$log = new Logger('./');
$log->init('demo');
//$log->resetStd();
$file = './data.dat';

$start = 1;
if (($exit = $log->getLastExitData())) {
    $start = $exit;
}

while ($data = $log->readDataFile($file)) {
    foreach ($data as $item) {
        if ($item > $start) {
            if ($item % 2 == 0) {
                $log->logSuccess($item);
            } else {
                $log->logFailure($item);
            }
            $log->logExit($item);
        }
    }
}

$log->displayUI('demo');