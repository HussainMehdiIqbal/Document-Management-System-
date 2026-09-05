<?php
$conn = new mysqli('localhost','root','','dms_database');
if($conn->connect_error) {
    echo 'DB ERROR: '.$conn->connect_error.PHP_EOL;
} else {
    echo 'DB CONNECTED OK'.PHP_EOL;
    $r = $conn->query('SHOW TABLES');
    while($row = $r->fetch_row()) echo '  table: '.$row[0].PHP_EOL;
}
