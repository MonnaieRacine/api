<?php
header('Access-Control-Allow-Origin: *');
require_once 'libs/jsonRPCClient.php';

$gethRPC = new jsonRPCClient('http://127.0.0.1:8545');
if(isset($_REQUEST['balance'])){
    header('Content-Type: application/json');
    echo getBalance($_REQUEST['balance'],$gethRPC);
} else if(isset($_REQUEST['rawtx'])){
    header('Content-Type: application/json');
    echo sendRawTransaction($_REQUEST['rawtx'],$gethRPC);
} else if(isset($_REQUEST['txdata'])){
    header('Content-Type: application/json');
    echo getTransactionData($_REQUEST['txdata'],$gethRPC);
} else if(isset($_REQUEST['estimatedGas'])){
    header('Content-Type: application/json');
    echo getEstimatedGas($_REQUEST['estimatedGas'],$gethRPC);
} else if(isset($_REQUEST['ethCall'])){
    header('Content-Type: application/json');
    echo getEthCall($_REQUEST['ethCall'],$gethRPC);
} else if(isset($_REQUEST['hash'])){
    header('Content-Type: application/json');
    echo getTransaction($_REQUEST['hash'],$gethRPC);
} else {
    header('Content-Type: application/json');
    echo blockNumber($gethRPC);
}
function blockNumber($gethRPC){
    $data = getDefaultResponse();
    try {
        $ret = getRPCResponse($gethRPC->eth_blockNumber());
        $data['data'] = $ret;
        $block=hexdec($ret);
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
    }
    return $block;
}
function getTransaction($hash, $gethRPC){
    $data = getDefaultResponse();
    try {
        $ret = getRPCResponse($gethRPC->eth_getTransactionByHash($hash),
        "pending");
        $cluster  = Cassandra::cluster($_ENV["DB_HOST"]) ->withCredentials("transactions_ro", "Public_transactions")
                ->build();
        $keyspace  = 'comchain';
        $session  = $cluster->connect($keyspace);
        $query = "SELECT * FROM transactions WHERE hash = '$hash'";  
	    foreach ($session->execute(new Cassandra\SimpleStatement($query)) as $row) {
	        $arr = $row;
	    }
	    
        $arr['transaction'] = $ret;
        $ret = json_encode($arr);
        $data = $ret;
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
    }
    return json_encode($data);
}
function getEstimatedGas($txobj, $gethRPC){
    $data = getDefaultResponse();
    try {
        $data['data'] = getRPCResponse($gethRPC->eth_estimateGas($txobj));
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
    }
    return json_encode($data);
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
    return json_encode($data);
}
function getTransactionData($addr, $gethRPC){
    $data = getDefaultResponse();
    try {
        $addr = formatAddress($addr);
        $balance = getRPCResponse($gethRPC->eth_getBalance($addr,
            "pending"));
        $nonce = getRPCResponse($gethRPC->eth_getTransactionCount($addr,
            "pending"));
        $gasprice = getRPCResponse($gethRPC->eth_gasPrice());
        $balance=bchexdec($balance);
        $tarr['address'] = $addr;
        $tarr['balance'] = $balance;
        $tarr['nonce'] = $nonce;
        $tarr['gasprice'] = $gasprice;
        $data['data'] = $tarr;
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
    }
    return json_encode($data);
}
function getBalance($addr, $gethRPC)
{
    $data = getDefaultResponse();
    try {
        $addr = formatAddress($addr);
        $balancehex = getRPCResponse($gethRPC->eth_getBalance($addr,
            "pending"));
        $balance=bchexdec($balancehex);
        $tarr['address'] = $addr;
        $tarr['balance'] = $balance;
        $tarr['balancehex'] = $balancehex;
        $data['data'] = $tarr;
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
    }
    return json_encode($data);
}
function getRPCResponse($result){
    if(isset($result['result'])){
        return $result['result'];
    } else {
        throw new Exception($result['error']['message']);
    }
}

// Lookup into the DB for a valid shop from the REQUEST parameters
// TODO PROTECT AGAINST CQL injection
function validateShop( $shop_id, $server_name){
   $cluster  = Cassandra::cluster($_ENV["DB_HOST"]) ->withCredentials("transactions_ro", "Public_transactions")->build();
   $keyspace  = 'comchain';
   $session  = $cluster->connect($keyspace);
   $query = "SELECT * FROM sellers  WHERE store_id = '$shop_id' AND server_name = '$server_name' ";  
   foreach ($session->execute(new Cassandra\SimpleStatement($query)) as $row) {
	 return true;
   }
   
   return false;
}

// Insert Pending Shop transaction storing the shop_id and the shop_ref
// TODO PROTECT AGAINST CQL injection
function insertPendingShopTransaction($shop_id,$shop_ref,$transaction_ash,$delegate){
   $field_add='';
   $value_add='';
   if (isset($delegate)){
       $field_add=', delegate';
       $value_add=", '$delegate'";
   }

//TODO change the table
   $cluster  = Cassandra::cluster($_ENV["DB_HOST"]) ->withCredentials("webhook_rw", "Private_access_transactions")->build();
   $keyspace  = 'comchain';
   $session  = $cluster->connect($keyspace);
   $query = "INSERT INTO webshop_transactions (hash, store_id, store_ref, status, $field_add) VALUES ('$transaction_ash','$shop_id','$shop_ref' $value_add)";  // Set old timestamp for the lock (tr_attempt_date)
}

// Insert Pending Delegated transaction storing the delegate
// TODO PROTECT AGAINST CQL injection
function insertPendingDelegateTransaction($delegate,$transaction_ash){
   $cluster  = Cassandra::cluster($_ENV["DB_HOST"]) ->withCredentials("webhook_rw", "Private_access_transactions")->build();
   $keyspace  = 'comchain';
   $session  = $cluster->connect($keyspace);
   //TODO change the table
   $query = "INSERT INTO webshop_transactions (hash, delegate) VALUES ('$transaction_ash','$delegate')"; 
}

function sendRawTransaction($rawtx,$gethRPC){
    $shop_id=$_REQUEST['shopId'];
    $shop_ref=$_REQUEST['txId'];
    $server_name=$_REQUEST['serverName'];
    $delegate=$_REQUEST['delegate'];
    
 

    $data = getDefaultResponse();
    try {
        $data['data'] = getRPCResponse($gethRPC->eth_sendRawTransaction($rawtx));
        
        // If needed put the delegate and/or the Shop information into the DB as a pending transaction 
        if (isset($shop_id) && isset($server_name) && isset($shop_ref) && validateShop( $shop_id, $server_name)){
            insertPendingShopTransaction($shop_id,$shop_ref,$data['data'],$delegate);
        } else if(isset($delegate)){
            insertPendingDelegateTransaction($delegate, $data['data']);
        }
    }
    catch (exception $e) {
        $data['error'] = true;
        $data['msg'] = $e->getMessage();
    }
    return json_encode($data);
}
function formatAddress($addr){
    if (substr($addr, 0, 2) == "0x")
        return $addr;
    else
        return "0x".$addr;
}
function getDefaultResponse(){
    $data['error'] = false;
    $data['msg'] = "";
    $data['data'] = "";
    return $data;
}
function bchexdec($hex)
{
    $dec = 0;
    $len = strlen($hex);
    for ($i = 1; $i <= $len; $i++) {
        $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
    }
    return $dec;
}
?>
