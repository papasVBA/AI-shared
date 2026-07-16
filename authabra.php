<?php

//modul pro autorizaci pristupu do abry, validujeme user (locked) podle username ziskane z sso (keycloak)
//prijimame jedinej parametr "preferred_username" (stejny klic jako v sso tokenu)
  header("Cache-Control: no-cache, no-store"); 
  header('Content-type: text/html; charset=utf-8');
  
include("../../settings/conf.php");
include("../../settings/errlog.php");

function exceptions_error_handler($severity, $message, $filename, $lineno) {
    throw new ErrorException($message, 0, $severity, $filename, $lineno);
    logto(basename(__FILE__).">> ".$message);
    $out = array("errmess"=>$message);
    echo('{"status":{"result":"NOK"}, "data":['.json_encode($out).']}'); 
    die();
}

set_error_handler('exceptions_error_handler');

$userInfo = array();
$roleInfo = array();
$conninfo = array();
$completedata = array();



$indata = json_decode(file_get_contents('php://input'), true);

if (!isset($indata)){
  //chyba - nejsou data
  trigger_error("Input data failed or missing",E_USER_WARNING);
} else {
  if (array_key_exists('preferred_username', $indata)) {

    $userInfo = getUserAbraInfo($indata["preferred_username"]); 

    if (array_key_exists('ID', $userInfo)) {
        $roleInfo = getUserAbraRoles($userInfo["ID"]);

        
        $userInfo["IPCKey"]=getIPCKey($userInfo["ID"]);


    } 
    $conninfo = array("OCI"=>getOCIconnectName(), "API"=>awapi, "INFO"=>conninfo);
    $completedata = array("userinfo"=>$userInfo, "roleinfo"=>$roleInfo, "conninfo"=>$conninfo);

    echo('{"status":{"result":"OK"}, "data":['.json_encode($completedata).']}');

  } else {
  //chyba - neznamy parametr
  trigger_error("Input command unknown",E_USER_WARNING);
}
}

//echo("here \r\n");

function getUserAbraRoles($userID) {
        $request_headers = [
            'Authorization:Basic '.base64_encode(waUser.":".waPass),
            'Content-Type:application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, awapi."/securityusers/".$userID."/securityroles"); //nove api = securityroles
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);

        $response = json_decode(curl_exec($ch), true);

        if (curl_errno($ch)){
            trigger_error(curl_error($ch));
        }

       curl_close($ch);

        //var_dump($response);


    // NOVÉ: Transformace na flat array
    if (!empty($response['data'][0]['roleinfo'])) {
        return flattenAbraRoles($response['data'][0]['roleinfo']);
    } elseif (!empty($response)) {
        return flattenAbraRoles($response); // fallback pro starší strukturu
    }
    
    return array();
}


function flattenAbraRoles($rolesArray) {
    // Vstup: array objektů s 'id' polem
    // Výstup: flat array jen s ID stringy
    $flatRoles = [];
    
    if (!empty($rolesArray) && is_array($rolesArray)) {
        foreach ($rolesArray as $role) {
            if (isset($role['id'])) {
                $flatRoles[] = $role['id'];
            }
        }
    }
    
    return $flatRoles;
}






function getUserAbraInfo($loginname) {
    //nejprve zjistime, jestli neni locked a jeho ID pro nahrani roli
    $data = '{
    "class": "securityusers",
    "select": [
        "name",
        "loginname",
        "locked",
        "webapiaccess",
        "ID"
    ],
    "where": "loginname=\''.$loginname.'\'"
    }';

        $request_headers = [
            'Authorization:Basic '.base64_encode(waUser.":".waPass),
            'Content-Type:application/json',
        ];

        $ch = curl_init(awapi."/query");
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data); 
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);

        $response = json_decode(curl_exec($ch), true);

        if (curl_errno($ch)){
            trigger_error(curl_error($ch));
        }

       curl_close($ch);

        //var_dump($response);


       if (!empty($response[0])=="") {
            //echo('{"result":"NOK"}');
            //echo('{"status":{"result":"NOK"}, "data":['.json_encode(array("response"=>$response)).']}');
            return array();
       } else {
            //echo(var_dump($response[0]));
            return $response[0];
       }
}



function getOCIconnectName() {
    $sqlsel = "SELECT SYS_CONTEXT('USERENV','CURRENT_SCHEMA') as CONNAME FROM dual";

            if ($c = oci_connect(ociname, ocipass, dboci, 'AL32UTF8')){
            //
            $stdid = oci_parse($c,$sqlsel);
            $result = oci_execute($stdid);

            $nrows = oci_fetch_all($stdid, $resSubsStatus, 0, 0, OCI_FETCHSTATEMENT_BY_ROW);

            //a zavreme spojeni
            oci_free_statement($stdid);
            oci_close($c);  

            if (count($resSubsStatus) != 0) {
                //echo($resSubsStatus);
                return $resSubsStatus[0]["CONNAME"];
            } else {
                return "";                
            }

        }
}



function getIPCKey($securityUserID) {
    //vygenerujeme si IPC klic pro sdilenou pamet pro sse
    $clientIP = $_SERVER['REMOTE_ADDR'];
        if ($clientIP === "::1") {
            $clientIP = "127.0.0.1";
        } 
    $actualTime = time();
    $strforhash = (string)$clientIP.(string)$actualTime.$securityUserID;

    
    /*
    echo($strforhash."\r\n");
    $hash = md5($strforhash);
    echo($hash."\r\n");
    echo(substr($hash,0,8)."\r\n");
    echo(hexdec(substr($hash,1,5))."\r\n");
        */
    return hexdec(substr(md5($strforhash),0,8));
}








?>