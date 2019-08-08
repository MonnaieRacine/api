<?php
include './checkAdmin.php';
header('Access-Control-Allow-Origin: *');



function getPublicKey($server){
    // remove anything which can change repertory
    $server_folder = str_replace("..", "", $server);
    $server_folder = str_replace("/", "", $server_folder);
    // Get the key
    $flie_path = "file:///var/www/html/".$server_folder."/pubkey.pem";
    return openssl_pkey_get_public($flie_path);
}


function getAddress($currency,$code){
   
    // Connect to Cassandra
    $cluster  = Cassandra::cluster($_ENV["DB_HOST"]) ->withCredentials("members_rw", "Private_access_members_rw")->build();
    $keyspace  = strtolower($currency);
    $notAccepted = array("-", "_");
    $keyspace = str_replace($notAccepted, "", $keyspace);
    
    $session  = $cluster->connect($keyspace);
    $table="Members";
      
    $results = "";    
    $sql ="SELECT Adresses from $table where Code = '$code'";
    foreach ($session->execute(new Cassandra\SimpleStatement($sql)) as $row) {
            
	        $results = $row['adresses']; 
    }
    $session->close();
    return $results;
}

// Get the Server/currency and address list 
$currency = filter_var($_POST['server'], FILTER_SANITIZE_STRING);
$code_text = filter_var($_POST['code'], FILTER_SANITIZE_STRING);
$caller = filter_var($_POST['caller'], FILTER_SANITIZE_STRING);
$sign = $_POST['signature'];
$res = [];

if (checkLegitimateAdmin($code_text, $sign, $caller, $currency)){
    

    // get the public key for the currency:
    $pubkeyid = getPublicKey($currency);
    // check if the file was found (=> valid currency)
    if ($pubkeyid!==false) {
        openssl_free_key($pubkeyid);
        $addresses_text = getAddress($currency,$code_text);
        $adresses = explode(",", $addresses_text);
        foreach ($adresses as $add){
            array_push($res,$add);
        } 
    } else {
         $res = "KO";
    }
} else {
    $res = "KO";
}

$json = json_encode($res);
echo $json;


?>
