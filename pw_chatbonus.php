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

            $getStatus = getRoleStatus($onlineGM);
            $level = $getStatus['status']['level2']; //cultivation level

            $mysqli = new mysqli($config['mysql']['host'], $config['mysql']['user'], $config['mysql']['password'], $config['mysql']['db']);
            if (!$mysqli->connect_errno) {
                $sqlCheck = "SELECT * FROM usebonuslog WHERE userid = '$userID' AND bonuslog = '$goldBonus'";
                $result = $mysqli->query($sqlCheck);
                if ($result && $result->num_rows > 0) {
                    return;
                }
            }



            if ($level == 22 || $level == 32) {
                // verifica se 'cult_string' está definido em $getStatus
                if (isset($getStatus['cult_string'])) {
                    $cultString = $getStatus['cult_string'];
                } else {
                    // define uma string padrão se 'cult_string' não estiver definido
                    $cultString = "Default Cultivation";
                }
            
  
                if (isset($isGM['id'])) {
                    $gmId = $isGM['id'];
                } else {
 
                    $gmId = 0;
                }
            

                $key = mt_rand(0, count($msg) - 1);
                $msg[$key] = str_replace('{{cultivation}}', $cultString, $msg[$key]);
                $msg[$key] = str_replace('{{bonus}}', $config['goldBonus'], $msg[$key]);
                chatInGame($msg[$key], $gmId);
            
            
                $date = date("Y-m-d H:i:s");
                $sqlInsertCash = "INSERT INTO `usecashnow`(userid, zoneid, sn, aid, point, cash, status, creatime) VALUES ('$userID', '1', '0', '1', '0', '$goldBonus', '1', '$date')";
                $queryCash = $mysqli->query($sqlInsertCash);
            
            
                $sqlInsertBonusLog = "INSERT INTO `usebonuslog`(userid, bonuslog) VALUES ('$userID', '$goldBonus')";
                $queryBonusLog = $mysqli->query($sqlInsertBonusLog);
            }
        } else {
            exit;
        }
    }
}
