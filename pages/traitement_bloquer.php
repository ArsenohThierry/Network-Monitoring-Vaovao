<?php 
include '../inc/functions.php';
$ip = $_GET['ip']; 
// echo $ip." ";

// exec("whoami", $output);
// echo implode("\n", $output);

$result = blockConnectionForIP($ip);
// $result = unblockConnectionForIP($ip);
$bloque = isIPBlocked($ip);
if ($bloque) {
    echo $ip."Bloque";
}
else {
    echo $ip."unbloque";
}

echo $result['ip']." "." ";
echo $result['error']." ";
echo $result['message']." ";
echo $result['output']." ";
echo $result['returnCode']." ";
echo $result['success']." ";

header("Location: home.php?ip=".$ip);