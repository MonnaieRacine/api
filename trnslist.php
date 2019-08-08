<?php
header('Access-Control-Allow-Origin: *');
if (strlen($_GET['addr']) == 42) {
	$addr = strtolower(preg_replace("/[^a-zA-Z0-9]+/", "", $_GET['addr']));
}else{
	echo "Bye!";
}
if (empty($_GET['count'])) {
	$_GET['count'] = 5;
}
if (empty($_GET['offset'])) {
	$_GET['offset'] = 0;
}
if (is_numeric($_GET['count'])){
	$limit = $_GET['count'];
} else {
	$limit = 5;
}
if (is_numeric($_GET['offset'])){
	$offset = $_GET['offset'];
} else {
	$offset = 0;
}
 
$cluster  = Cassandra::cluster($_ENV["DB_HOST"]) ->withCredentials("transactions_ro", "Public_transactions")
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
$counter = $offset + $limit;
$query = "select * from transactions WHERE hash IN $hashes ORDER BY time DESC limit $counter;";
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
