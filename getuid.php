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


function getAddress($currency){
   
    // Connect to Cassandra
    $cluster  = Cassandra::cluster($_ENV["DB_HOST"]) ->withCredentials("members_rw", "Private_access_members_rw")->build();
    $keyspace  = strtolower($currency);
    $notAccepted = array("-", "_");
    $keyspace = str_replace($notAccepted, "", $keyspace);
    
    $session  = $cluster->connect($keyspace);
    $table="Members";
      
    $results = [];    
    $sql ="SELECT Code, Adresses from $table ";
    foreach ($session->execute(new Cassandra\SimpleStatement($sql)) as $row) {
            
	        $results[$row['code']] = $row['adresses']; 
    }
    $session->close();
    return $results;
}

// Get the Server/currency and address list 
$currency = filter_var($_POST['server'], FILTER_SANITIZE_STRING);
$adresses_text = filter_var($_POST['addresses'], FILTER_SANITIZE_STRING);
$caller = filter_var($_POST['caller'], FILTER_SANITIZE_STRING);
$sign = $_POST['signature'];
$res = [];

if (checkLegitimateAdmin($adresses_text, $sign, $caller, $currency)){
    $adresses = explode(",", $adresses_text);
    $count = count($adresses);

    // get the public key for the currency:
    $pubkeyid = getPublicKey($currency);
    // check if the file was found (=> valid currency)
    if ($pubkeyid!==false) {
        openssl_free_key($pubkeyid);
        $add_dict = getAddress($currency);
        
      
        foreach ($adresses as $add){
             foreach ( $add_dict as $key => $value){
                if (strpos($value, $add) !== false) {
                    $res[$add]=$key;
                }
             }
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
