<?php

require('./config.php');
require('./msg.php');
require('./pwapi.php');

$argv[1]($argv[2]);

function sendBonus($line = null)
{
    global $config;
    global $msg;

    if (@fsockopen('127.0.0.1', $config["ports"]['gamedbd'], $errCode, $errStr, 1)) {
        if (strpos($line, "msg=IQBCAE8ATgBVAFMA") !== false and strpos($line, "chl=0") !== false) {
            $onlineGM = explode("=", explode(":",  $line)[5])[1];
            $isGM = getRoleBase($onlineGM);
            $userID = $isGM['userid'];
            $goldBonus = $config['goldBonus'] * 100;

            $mysqli = new mysqli($config['mysql']['host'], $config['mysql']['user'], $config['mysql']['password'], $config['mysql']['db']);
            if (!$mysqli->connect_errno) {
                $sqlCheck = "SELECT * FROM usebonuslog WHERE userid = '$userID' AND bonuslog = '$goldBonus'";
                $result = $mysqli->query($sqlCheck);
                if ($result && $result->num_rows > 0) {
                    return;
                }
            }



            $key = mt_rand(0, count($msg) - 1);
            $msg[$key] = str_replace('{{player}}', $isGM['name'], $msg[$key]);
            $msg[$key] = str_replace('{{class}}', $isGM['cls_string'], $msg[$key]);
            $msg[$key] = str_replace('{{bonus}}', $config['bonusMsg'], $msg[$key]);
            chatInGame($msg[$key], $isGM);


            $date = date("Y-m-d H:i:s");
            $sqlInsertCash = "INSERT INTO `usecashnow`(userid, zoneid, sn, aid, point, cash, status, creatime) VALUES ('$userID', '1', '0', '1', '0', '$goldBonus', '1', '$date')";
            $queryCash = $mysqli->query($sqlInsertCash);


            $sqlInsertBonusLog = "INSERT INTO `usebonuslog`(userid, bonuslog) VALUES ('$userID', '$goldBonus')";
            $queryBonusLog = $mysqli->query($sqlInsertBonusLog);
        } else {
            exit;
        }
    }
}
