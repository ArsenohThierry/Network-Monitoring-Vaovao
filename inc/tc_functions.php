<?php
/**
 * Traffic Control Functions - Version 2
 * Limitation de débit pour hotspot WiFi utilisant IFB
 * 
 * Pour un hotspot, on doit limiter:
 * - Download (vers le client) = trafic sortant de wlan0
 * - Upload (depuis le client) = trafic entrant sur wlan0, redirigé vers ifb0
 */

/**
 * Initialiser le système de contrôle de trafic complet
 * Doit être appelé une fois au démarrage du hotspot
 * 
 * @param string $interface Interface hotspot (ex: wlan0)
 * @return array Résultat
 */
function initTrafficControlV2($interface = 'wlan0') {
    $commands = [];
    
    // 1. Nettoyer les anciennes règles
    $commands[] = "sudo tc qdisc del dev $interface root 2>/dev/null || true";
    $commands[] = "sudo tc qdisc del dev $interface ingress 2>/dev/null || true";
    $commands[] = "sudo tc qdisc del dev ifb0 root 2>/dev/null || true";
    $commands[] = "sudo ip link set ifb0 down 2>/dev/null || true";
    $commands[] = "sudo modprobe -r ifb 2>/dev/null || true";
    
    // 2. Charger le module IFB et créer l'interface
    $commands[] = "sudo modprobe ifb numifbs=1";
    $commands[] = "sudo ip link set ifb0 up";
    
    // 3. Configurer HTB sur wlan0 pour le DOWNLOAD (trafic sortant vers les clients)
    $commands[] = "sudo tc qdisc add dev $interface root handle 1: htb default 9999";
    $commands[] = "sudo tc class add dev $interface parent 1: classid 1:1 htb rate 100mbit ceil 100mbit";
    $commands[] = "sudo tc class add dev $interface parent 1:1 classid 1:9999 htb rate 100mbit ceil 100mbit"; // classe par défaut
    
    // 4. Configurer ingress sur wlan0 et rediriger vers ifb0 pour l'UPLOAD
    $commands[] = "sudo tc qdisc add dev $interface handle ffff: ingress";
    $commands[] = "sudo tc filter add dev $interface parent ffff: protocol ip u32 match u32 0 0 action mirred egress redirect dev ifb0";
    
    // 5. Configurer HTB sur ifb0 pour l'UPLOAD (trafic venant des clients)
    $commands[] = "sudo tc qdisc add dev ifb0 root handle 1: htb default 9999";
    $commands[] = "sudo tc class add dev ifb0 parent 1: classid 1:1 htb rate 100mbit ceil 100mbit";
    $commands[] = "sudo tc class add dev ifb0 parent 1:1 classid 1:9999 htb rate 100mbit ceil 100mbit"; // classe par défaut
    
    $results = [];
    $allSuccess = true;
    
    foreach ($commands as $cmd) {
        exec($cmd . " 2>&1", $output, $returnCode);
        $results[] = [
            'command' => $cmd,
            'output' => $output,
            'return_code' => $returnCode
        ];
        // Ne pas considérer les erreurs de nettoyage comme des échecs
        if ($returnCode !== 0 && strpos($cmd, '|| true') === false && strpos($cmd, '2>/dev/null') === false) {
            $allSuccess = false;
        }
        $output = [];
    }
    
    return [
        'success' => $allSuccess,
        'message' => $allSuccess ? 'Traffic Control initialisé avec succès' : 'Erreurs lors de l\'initialisation',
        'results' => $results
    ];
}

/**
 * Limiter le débit d'une IP spécifique
 * 
 * @param string $interface Interface hotspot
 * @param string $ip Adresse IP du client
 * @param int $downloadMbps Limite download en Mbps
 * @param int|null $uploadMbps Limite upload en Mbps (défaut: 50% du download)
 * @return array Résultat
 */
function limitIPv2($interface, $ip, $downloadMbps, $uploadMbps = null) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['success' => false, 'message' => 'Adresse IP invalide'];
    }
    
    // Vérifier que TC est initialisé
    exec("sudo tc qdisc show dev $interface | grep htb", $checkOut, $checkRet);
    if ($checkRet !== 0 || empty($checkOut)) {
        // Initialiser TC automatiquement
        $initResult = initTrafficControlV2($interface);
        if (!$initResult['success']) {
            return ['success' => false, 'message' => 'Échec initialisation TC', 'init_result' => $initResult];
        }
    }
    
    $uploadMbps = $uploadMbps ?? max(1, intval($downloadMbps * 0.5));
    $downloadKbps = $downloadMbps * 1000;
    $uploadKbps = $uploadMbps * 1000;
    
    // Générer un class ID unique basé sur l'IP (doit être <= 65535)
    $parts = explode('.', $ip);
    $classMinor = (((int)$parts[2]) << 8) + (int)$parts[3];
    $classMinor = ($classMinor % 65530) + 2; // entre 2 et 65531
    $classId = "1:$classMinor";
    
    $commands = [];
    
    // Supprimer les anciennes règles pour cette IP (ignore les erreurs)
    $commands[] = "sudo tc filter del dev $interface parent 1: protocol ip prio 1 u32 match ip dst $ip 2>/dev/null || true";
    $commands[] = "sudo tc class del dev $interface classid $classId 2>/dev/null || true";
    $commands[] = "sudo tc filter del dev ifb0 parent 1: protocol ip prio 1 u32 match ip src $ip 2>/dev/null || true";
    $commands[] = "sudo tc class del dev ifb0 classid $classId 2>/dev/null || true";
    
    // DOWNLOAD: Limiter le trafic VERS le client (sur wlan0)
    $commands[] = "sudo tc class add dev $interface parent 1:1 classid $classId htb rate {$downloadKbps}kbit ceil {$downloadKbps}kbit";
    $commands[] = "sudo tc filter add dev $interface parent 1: protocol ip prio 1 u32 match ip dst $ip flowid $classId";
    
    // UPLOAD: Limiter le trafic DEPUIS le client (sur ifb0)
    $commands[] = "sudo tc class add dev ifb0 parent 1:1 classid $classId htb rate {$uploadKbps}kbit ceil {$uploadKbps}kbit";
    $commands[] = "sudo tc filter add dev ifb0 parent 1: protocol ip prio 1 u32 match ip src $ip flowid $classId";
    
    $results = [];
    $success = true;
    
    foreach ($commands as $cmd) {
        exec($cmd . " 2>&1", $output, $returnCode);
        $results[] = [
            'command' => $cmd,
            'output' => $output,
            'return_code' => $returnCode
        ];
        // Ignorer les erreurs de suppression
        if ($returnCode !== 0 && strpos($cmd, '|| true') === false) {
            $outputStr = implode(' ', $output);
            if (strpos($outputStr, 'File exists') === false) {
                $success = false;
            }
        }
        $output = [];
    }
    
    // Sauvegarder en session
    if ($success) {
        if (!isset($_SESSION['limited_ips'])) $_SESSION['limited_ips'] = [];
        $_SESSION['limited_ips'][$ip] = [
            'download' => $downloadMbps,
            'upload' => $uploadMbps,
            'class_id' => $classId,
            'timestamp' => time()
        ];
        
        // Log
        if (function_exists('writeProjectLog')) {
            writeProjectLog('LIMIT_V2', $ip, "down={$downloadMbps}M up={$uploadMbps}M class=$classId");
        }
    }
    
    return [
        'success' => $success,
        'message' => $success ? 
            "IP $ip limitée: ↓{$downloadMbps}Mbps ↑{$uploadMbps}Mbps" : 
            "Échec de la limitation",
        'ip' => $ip,
        'download' => $downloadMbps,
        'upload' => $uploadMbps,
        'class_id' => $classId,
        'results' => $results
    ];
}

/**
 * Supprimer la limitation d'une IP
 */
function unlimitIPv2($interface, $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['success' => false, 'message' => 'Adresse IP invalide'];
    }
    
    // Retrouver le class ID
    $parts = explode('.', $ip);
    $classMinor = (((int)$parts[2]) << 8) + (int)$parts[3];
    $classMinor = ($classMinor % 65530) + 2;
    $classId = "1:$classMinor";
    
    $commands = [
        // Supprimer sur wlan0 (download)
        "sudo tc filter del dev $interface parent 1: protocol ip prio 1 u32 match ip dst $ip 2>/dev/null || true",
        "sudo tc class del dev $interface classid $classId 2>/dev/null || true",
        // Supprimer sur ifb0 (upload)
        "sudo tc filter del dev ifb0 parent 1: protocol ip prio 1 u32 match ip src $ip 2>/dev/null || true",
        "sudo tc class del dev ifb0 classid $classId 2>/dev/null || true"
    ];
    
    foreach ($commands as $cmd) {
        exec($cmd . " 2>&1", $output, $returnCode);
        $output = [];
    }
    
    // Supprimer de la session
    if (isset($_SESSION['limited_ips'][$ip])) {
        unset($_SESSION['limited_ips'][$ip]);
    }
    
    if (function_exists('writeProjectLog')) {
        writeProjectLog('UNLIMIT_V2', $ip, 'removed');
    }
    
    return [
        'success' => true,
        'message' => "Limitation supprimée pour $ip",
        'ip' => $ip
    ];
}

/**
 * Afficher l'état actuel de TC
 */
function showTCStatus($interface = 'wlan0') {
    $output = [];
    
    exec("sudo tc -s qdisc show dev $interface 2>&1", $qdisc);
    exec("sudo tc -s class show dev $interface 2>&1", $classes);
    exec("sudo tc -s filter show dev $interface 2>&1", $filters);
    exec("sudo tc -s qdisc show dev ifb0 2>&1", $ifbQdisc);
    exec("sudo tc -s class show dev ifb0 2>&1", $ifbClasses);
    
    return [
        'interface' => $interface,
        'qdisc' => $qdisc,
        'classes' => $classes,
        'filters' => $filters,
        'ifb0_qdisc' => $ifbQdisc,
        'ifb0_classes' => $ifbClasses
    ];
}

/**
 * Vérifier si une IP est limitée
 */
function isIPLimitedV2($ip) {
    return isset($_SESSION['limited_ips'][$ip]);
}

/**
 * Obtenir les infos de limitation d'une IP
 */
function getIPLimitInfo($ip) {
    if (isset($_SESSION['limited_ips'][$ip])) {
        return $_SESSION['limited_ips'][$ip];
    }
    return null;
}
?>
