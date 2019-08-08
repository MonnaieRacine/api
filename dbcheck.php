<?php
header('Access-Control-Allow-Origin: *');
$cluster  = Cassandra::cluster($_ENV["DB_HOST"])  ->withCredentials("ping_ro", "Public_ping")
                ->build();
$keyspace  = 'comchain';
$session  = $cluster->connect($keyspace);

//$query = "SELECT _from, _to, time, (CASE WHEN _from == \"$addr\" THEN sent ELSE recieved END) AS AMOUNT, type, hash, block FROM TRANSACTIONS WHERE (_FROM = \"$addr\" OR _TO =\"$addr\") COLLATE NOCASE ORDER BY CAST(TIME AS REAL) DESC LIMIT $limit OFFSET $offset";
$query = "SELECT ping FROM ping";
$counter=0;
foreach ($session->execute(new Cassandra\SimpleStatement($query)) as $row) {
$string[$counter] = implode(",",$row);
$counter++;
}
isset($string) or exit("KO");
print "pong";
?>
