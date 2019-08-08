<?php
require("includes/session.inc");
session_start();
session_regenerate_id(true);
header('Access-Control-Allow-Origin: *');
require_once 'libs/jsonRPCClient.php';

$cluster  = Cassandra::cluster($_ENV["DB_HOST"]) ->withCredentials("challenges_rw", "Private_access_challenges")
                ->build();
$ml_cluster  = Cassandra::cluster($_ENV["DB_HOST"]) ->withCredentials("session_rw", "Private_access_sessions")
                ->build();
$keyspace  = 'comchain';
$session  = $cluster->connect($keyspace);
$ml_keyspace  = 'ml';
$ml_session  = $ml_cluster->connect($ml_keyspace);

$gethRPC = new jsonRPCClient('http://127.0.0.1:8545');

if (strlen($_GET['addr']) == 42) {
        $addr = strtolower(preg_replace("/[^a-zA-Z0-9]+/", "", $_GET['addr']));
} else if($_GET['addr'] == "0x0"){
	session_destroy();
        $message['Authentication'] = "Logout";
        echo json_encode($message);
        exit;
}else{
	session_destroy();
	$message['Error'] = "Address invalid";
	echo json_encode($message);
	exit;
}
if (strlen($_GET['sign']) == 132) {
        $sign = strtolower(preg_replace("/[^a-zA-Z0-9]+/", "", $_GET['sign']));
}else{
	$challenge = random_str(25);
	$query = "INSERT INTO challenges (addr, challenge) VALUES ('$addr', '$challenge')";
	$session->execute(new Cassandra\SimpleStatement($query));
	$arrayChallenge['Challenge'] = $challenge;
        echo json_encode($arrayChallenge);
	exit;
}

$user = getChallenge($addr,$session);
$signingAddr = ecrecover(getTransaction($user['challenge'], $sign),$addr,$gethRPC);

if ($user['addr'] == $signingAddr){
	$_SESSION['loggedAddr'] = $signingAddr;
	$query = "SELECT roles FROM users WHERE addr = '$signingAddr'";
        foreach ($ml_session->execute(new Cassandra\SimpleStatement($query)) as $row) {
        	//$roles = $row['roles'];
		foreach($row['roles'] as $role){
			$_SESSION[$role] = true;
		}
        }
	$arrayLogin['Authentication'] = "OK";
	$arrayLogin['Address'] = $signingAddr;
        echo json_encode($arrayLogin);
}else{
        $challenge = random_str(25);
        $query = "INSERT INTO challenges (addr, challenge) VALUES ('$addr', '$challenge')";
        $session->execute(new Cassandra\SimpleStatement($query));
	$arrayLogin['Authentication'] = "KO";
	$arrayLogin['Challenge'] = $challenge;
        echo json_encode($arrayLogin);
}

/*
FUNCTIONS
*/

function getChallenge ($addr, $session){
	$query = "SELECT * FROM challenges WHERE addr = '$addr'";
	$counter=0;
	foreach ($session->execute(new Cassandra\SimpleStatement($query)) as $row) {
	$user['addr'] = $row['addr'];
	$user['challenge'] = $row['challenge'];
	$counter++;
	}
	$query = "DELETE FROM challenges WHERE addr = '$addr'";
	$session->execute(new Cassandra\SimpleStatement($query));
	return $user;
}

function random_str($length, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')
{
    $str = '';
    $max = mb_strlen($keyspace, '8bit') - 1;
    for ($i = 0; $i < $length; ++$i) {
        $str .= $keyspace[random_int(0, $max)];
    }
    return $str;
}

function ecrecover($rawTx, $addr, $gethRPC){
	$data['data'] = $rawTx;
	$data['to'] = '0x42675B1a93f7a35a57Ed1F07cf364E76d9C7510d';
	$data['from'] = $addr;
	$addr = str_replace("00","",getEthCall($data,$gethRPC));
	return $addr;
}

function getTransaction($msg, $sign){
	$prefix = "19457468657265756d205369676e6564204d6573736167653a0a";
	$hash = "0x1380a080";
	$offset = 80;
	$arr = str_split(str_replace("0x", "", $sign), 64);
	$msg = strlen($msg) . $msg;
	$msgLen = dechex(strlen($msg)+26);
	$msgHex = $prefix . strToHex($msg);
	$strOffset = str_pad($offset, 64, "0", STR_PAD_LEFT);
	$vPadded = str_pad($arr[2], 64, "0", STR_PAD_LEFT);
	$msgLenPadded = str_pad($msgLen, 64, "0", STR_PAD_LEFT);
	$msgPadded = str_pad($msgHex, 128, "0");
	$rawTx = $hash . $vPadded . $arr[0] . $arr[1] . $strOffset . $msgLenPadded . $msgPadded;
	return $rawTx;
}

function getRPCResponse($result){
    if(isset($result['result'])){
        return $result['result'];
    } else {
        throw new Exception($result['error']['message']);
    }
}

function strToHex($string){
    $hex='';
    for ($i=0; $i < strlen($string); $i++){
        $hex .= dechex(ord($string[$i]));
    }
    return $hex;
}

function getEthCall($txobj, $gethRPC){
    $data = getDefaultResponse();
    try {
        $data['data'] = getRPCResponse($gethRPC->eth_call($txobj,
        "pending"));
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
    }
    return $data['data'];
}

function getDefaultResponse(){
    $data['error'] = false;
    $data['msg'] = "";
    $data['data'] = "";
    return $data;
}

?>
