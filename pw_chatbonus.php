<?php

require('./configs/config.php');
require('./msg.php');
require('./api/PwAPI.php');

$api = new API;

$argv[1]($argv[2]);

function sendBonus($line = null)
{
    global $config, $msg, $api;

    if (strpos($line, "msg=IQBCAE8ATgBVAFMA") !== false and strpos($line, "chl=0") !== false) {
        $onlineGM = explode("=", explode(":",  $line)[5])[1];
        $isGM = $api->getRoleBase($onlineGM);
        $userID = $isGM['userid'];
        $goldBonus = $config['goldBonus'] * 100;

        $getStatus = $api->getRoleStatus($onlineGM);
        $level2 = $getStatus['status']['level2']; // nível de cultivo
        $level = $getStatus['status']['level'];   // nível normal

        $mysqli = new mysqli($config['mysql']['host'], $config['mysql']['user'], $config['mysql']['password'], $config['mysql']['db']);
        if (!$mysqli->connect_errno) {
            $sqlCheck = "SELECT * FROM usebonuslog WHERE userid = '$userID' AND bonuslog = '$goldBonus'";
            $result = $mysqli->query($sqlCheck);
            if ($result && $result->num_rows > 0) {
                return; 
            }
        }

        if ($config['BonusPorCultivo'] == 1 && ($level2 == 22 || $level2 == 32)) {
            // Bônus por nível de cultivo (22 = god3 ou 32 = evil3)
            processBonus($isGM, $userID, $goldBonus, $msg, 'cultivation', $getStatus['cult_string'] ?? 'Default Cultivation');
        }

        if ($config['BonusPorLevel'] == 1 && $level >= 100) {
            // Bônus por nível (100)
            processBonus($isGM, $userID, $goldBonus, $msg, 'level', $level);
        }

        if ($config['BonusPorLiga'] == 1) {
            // bônus por liga - Ajuste conforme necessário
            $liga = $getStatus['status']['liga'] ?? 'Sem Liga';
            processBonus($isGM, $userID, $goldBonus, $msg, 'liga', $liga);
        }
    }
}

function processBonus($isGM, $userID, $goldBonus, $msg, $type, $value)
{
    global $api, $mysqli;

    $gmId = $isGM['id'] ?? 0;
    $key = mt_rand(0, count($msg) - 1);

    $msg[$key] = str_replace("{{{$type}}}", $value, $msg[$key]);
    $msg[$key] = str_replace('{{bonus}}', $goldBonus, $msg[$key]);
    $api->chatInGame($msg[$key], $gmId);

    $date = date("Y-m-d H:i:s");
    $sqlInsertCash = "INSERT INTO `usecashnow` (userid, zoneid, sn, aid, point, cash, status, creatime) 
                      VALUES ('$userID', '1', '0', '1', '0', '$goldBonus', '1', '$date')";
    $mysqli->query($sqlInsertCash);

    $sqlInsertBonusLog = "INSERT INTO `usebonuslog` (userid, bonuslog) VALUES ('$userID', '$goldBonus')";
    $mysqli->query($sqlInsertBonusLog);
}
