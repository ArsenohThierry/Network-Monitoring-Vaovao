<?php
include '../inc/functions.php';
$devices = getAllConnectedDevices();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appareils Connectés</title>
</head>
<body>
    <h1>Appareils Connectés</h1>
    <?php 
    if (!empty($devices)) {
        foreach ($devices as $dev) { ?>
            <p>
                IP: <?= htmlspecialchars($dev['ip']) ?> | 
                MAC: <?= htmlspecialchars($dev['mac']) ?> | 
                Nom: <?= htmlspecialchars($dev['nom'] ?? '-') ?>
            </p>
        <?php }
    } else { ?>
        <p>Aucun appareil connecté.</p>
    <?php } ?>
</body>
</html>
