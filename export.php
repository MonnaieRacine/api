<?php
header('Access-Control-Allow-Origin: *');
if (strlen($_GET['addr']) == 42) {
	$addr = strtolower(preg_replace("/[^a-zA-Z0-9]+/", "", $_GET['addr']));
}else{
	echo "Bye!";
}
if (empty($_GET['start'])) {
	$_GET['start'] = 0;
}
if (empty($_GET['end'])) {
	$_GET['end'] = 1999999999;
}
if (is_numeric($_GET['start'])){
	$start = $_GET['start'];
} else {
	$start = 0;
}
if (is_numeric($_GET['end'])){
	$end = $_GET['end'];
} else {
	$end = 1999999999;
}
 
#$dir = 'sqlite:/home/ethereum/transactions.db';
 
#$dbh = new PDO($dir) or die("cannot open database");

$cluster  = Cassandra::cluster($_ENV["DB_HOST"]) 
		->withCredentials("transactions_ro", "Public_transactions") 
		-> withDefaultPageSize(null)
                ->build();
$keyspace  = 'comchain';
$session  = $cluster->connect($keyspace);

$query = "SELECT * FROM transactions";
$query = "SELECT hash from trans_by_addr WHERE addr CONTAINS '$addr'";
$counter=0;
foreach ($session->execute(new Cassandra\SimpleStatement($query)) as $row) {
$string[$counter] = implode(",",$row);
$counter++;
}
isset($string) or exit("[]");
$hashes = json_encode($string);
$hashes = str_replace("[", "(", $hashes);
$hashes = str_replace("]", ")", $hashes);
$hashes = str_replace("\"", "'", $hashes);
#$counter = $offset + $limit;
#$query = "PAGING OFF";
#$session->execute(new Cassandra\SimpleStatement($query));
$query = "select addr_from, addr_to, time, recieved, sent, tax, type, hash, block from transactions WHERE hash IN $hashes AND time >= '$start' AND time <= '$end' ORDER by time";
$counter = 0;
foreach ($session->execute(new Cassandra\SimpleStatement($query)) as $row) {
$jstring[$counter] = json_encode($row);
$counter++;
}
$jstring = array_slice($jstring, -$limit);
if ($jstring != null){
echo json_encode($jstring);
}else{
echo "[]";
}
 
?>
