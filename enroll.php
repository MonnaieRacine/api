<?php
header('Access-Control-Allow-Origin: *');
$maxAccounts=1000;


function base64url_decode($data) { 
  return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT)); 
} 

function getPublicKey($server){
    // remove anything which can change repertory
    $server_folder = str_replace("..", "", $server);
    $server_folder = str_replace("/", "", $server_folder);
    // Get the key
    $flie_path = "file:///var/www/html/".$server_folder."/pubkey.pem";
    return openssl_pkey_get_public($flie_path);
}

function checkSign($uid, $signature, $pubkeyid){
    if (openssl_verify($uid, $signature, $pubkeyid, OPENSSL_ALGO_SHA1)==1){
        return "OK";
    } else {
        return "KO";
    } 
}

function saveAddress($uid, $token, $pubkeyid, $currency, $adresse, $maxAccounts){
    if (checkSign($uid, $token, $pubkeyid)=="OK"){ 
        // Connect to Cassandra
        $cluster  = Cassandra::cluster($_ENV["DB_HOST"])->withCredentials("members_rw", "Private_access_members_rw")->build();
        $keyspace  = strtolower($currency);
        
        $notAccepted = array("-", "_");
        $keyspace = str_replace($notAccepted, "", $keyspace);
        
        $session  = $cluster->connect($keyspace);
        $table="Members";
        $dbg=',in,'.$maxAccounts.',' ;
        $count=1; 
        $addr = $adresse;
        $sql ="SELECT Adresses, Count from $table where Code = '$uid'";
        foreach ($session->execute(new Cassandra\SimpleStatement($sql)) as $row) {
	        $count = $row['Count']+1;

                if ($row['adresses'] != ""){ 
                                $addr = $row['adresses'] . "," . $adresse;
                }
                $dbg = $dbg.'row found,';
        }
 
	$result = "KO";
	if ($count < $maxAccounts) {
                        $dbg = $dbg.'count ok,';  
		        $updateCount = "UPDATE $table SET Count=$count, Adresses='$addr' WHERE Code='$uid'";
		        if ($session->execute(new Cassandra\SimpleStatement($updateCount))) {            
		            $result = "OK";
                              
		        }
	} 
       	
        $session->close();
        return $result;
    } else {
        return "KO";
    }
}


$data = json_decode($_POST['data'], true);

// Get the Server/currency uid and signature
$currency = filter_var($data['currency'], FILTER_SANITIZE_STRING);
$uid = filter_var($data['id'], FILTER_SANITIZE_STRING);
$sign = filter_var($data['signature'], FILTER_SANITIZE_STRING); 
$signature = base64url_decode($sign);
$tok = filter_var($data['token'], FILTER_SANITIZE_STRING); 
$token = base64url_decode($tok);
$adresse = filter_var($data['adresse'], FILTER_SANITIZE_STRING);

$check_or_enroll = !isset($data['adresse']);


// get the public key for the currency:
$pubkeyid = getPublicKey($currency);
// check if the file was found (=> valid currency)
if ($pubkeyid!==false) {
     if ($check_or_enroll){
        // Check validity 
        $result = checkSign($uid, $signature, $pubkeyid);
     } else {
        // Add address
        $result = saveAddress($uid, $token, $pubkeyid, $currency, $adresse, $maxAccounts);
     }
 
     openssl_free_key($pubkeyid);
} else {
     $result = "KO";
}

$res = array('adresse' => $adresse,'token'=>$data['signature'], 'result' => $result);
$json = json_encode($res);
echo $json;

?>
