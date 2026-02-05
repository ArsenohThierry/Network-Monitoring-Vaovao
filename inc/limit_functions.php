<?php
/**
 * Traffic Shaper Functions
 * Fonctions pour le contrôle de débit réseau avec TC (Traffic Control)
 */

/**
 * Valider une adresse IP
 * 
 * @param string $ip Adresse IP à valider
 * @return bool True si l'IP est valide
 */
function validateIP($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP);
}

/**
 * Exécuter une commande shell
 * 
 * @param string $command Commande à exécuter
 * @param bool $return_output Si true, retourne la sortie
 * @return array|int Résultat de l'exécution
 */
function executeCommand($command, $return_output = true) {
    if ($return_output) {
        exec($command . " 2>&1", $output, $return_code);
        return [
            'output' => $output,
            'return_code' => $return_code,
            'command' => $command
        ];
    } else {
        passthru($command, $return_code);
        return $return_code;
    }
}

/**
 * Initialiser le contrôle de trafic TC
 * 
 * @param string $interface Interface réseau (ex: wlan0)
 * @return array Résultats de l'initialisation
 */
function initTrafficControl($interface) {
    $commands = [
        // Supprimer les règles existantes
        "sudo tc qdisc del dev $interface root 2>/dev/null",
        "sudo tc qdisc del dev $interface ingress 2>/dev/null",
        
        // Configurer HTB pour le téléchargement (download)
        "sudo tc qdisc add dev $interface root handle 1: htb default 999",
        
        // Créer une classe racine avec bande passante totale
        "sudo tc class add dev $interface parent 1: classid 1:1 htb rate 100mbit ceil 100mbit",
        
        // Classe par défaut (illimité)
        "sudo tc class add dev $interface parent 1:1 classid 1:999 htb rate 100mbit ceil 100mbit",
        
        // Configurer ingress pour l'upload
        "sudo tc qdisc add dev $interface handle ffff: ingress"
    ];
    
    $results = [];
    foreach ($commands as $cmd) {
        $result = executeCommand($cmd);
        $results[] = $result;
    }
    
    return $results;
}

/**
 * Limiter le débit d'une adresse IP spécifique
 * 
 * @param string $interface Interface réseau
 * @param string $ip Adresse IP à limiter
 * @param int $download_limit Limite download en Mbit/s
 * @param int|null $upload_limit Limite upload en Mbit/s (optionnel)
 * @return array Résultat de l'opération
 */
function limitIP($interface, $ip, $download_limit, $upload_limit = null) {
    if (!validateIP($ip)) {
        return [
            'success' => false,
            'message' => "Adresse IP invalide"
        ];
    }
    
    // Conversion Mbit/s -> kbit/s
    $download_kbps = $download_limit * 1000;
    $upload_kbps = ($upload_limit ? $upload_limit * 1000 : $download_kbps * 0.5);
    
    // Générer un ID unique et valide pour la classe (minor doit être <= 65535)
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        // Utiliser les deux derniers octets pour créer un minor ID (0..65535)
        $minor = (((int)$parts[2]) << 8) + (int)$parts[3];
    } else {
        // Fallback: utiliser un hash 16-bit
        $minor = crc32($ip) & 0xffff;
    }
    // Assurer que minor est dans la plage 2..65535 (éviter 0/1 réservés)
    $minor = $minor % 65534 + 2; // résultat entre 2 et 65535
    $class_id = sprintf("1:%d", $minor);
    
    $commands = [
        // Créer une classe HTB pour cette IP
        "sudo tc class add dev $interface parent 1:1 classid $class_id htb rate {$download_kbps}kbit ceil {$download_kbps}kbit",
        
        // Filtrer le trafic vers cette IP
        "sudo tc filter add dev $interface protocol ip parent 1:0 prio 1 u32 match ip dst $ip flowid $class_id",
        
        // Limiter l'upload (ingress)
        "sudo tc filter add dev $interface parent ffff: protocol ip prio 1 u32 match ip src $ip police rate {$upload_kbps}kbit burst 32k drop flowid :1"
    ];
    
    $results = [];
    $success = true;
    
    foreach ($commands as $cmd) {
        $result = executeCommand($cmd);
        $results[] = $result;
        
        if ($result['return_code'] !== 0) {
            // Ignorer les erreurs "file exists" (règle déjà présente)
            $output_str = implode(' ', $result['output']);
            if (strpos($output_str, 'File exists') === false) {
                $success = false;
            }
        }
    }
    
    if ($success) {
        // Sauvegarder en session
        if (!isset($_SESSION['limited_ips'])) {
            $_SESSION['limited_ips'] = [];
        }
        $_SESSION['limited_ips'][$ip] = [
            'download' => $download_limit,
            'upload' => $upload_kbps / 1000,
            'timestamp' => time()
        ];
    // project-level log
    if (function_exists('writeProjectLog')) writeProjectLog('LIMIT', $ip, "download={$download_limit}M upload=".($upload_kbps/1000)."M");
    }
    
    return [
        'success' => $success,
        'message' => $success ? 
            "IP $ip limitée à {$download_limit}Mbit/s download, " . ($upload_kbps/1000) . "Mbit/s upload" : 
            "Échec de la limitation",
        'results' => $results
    ];
}

/**
 * Supprimer la limitation d'une adresse IP
 * 
 * @param string $interface Interface réseau
 * @param string $ip Adresse IP à délimiter
 * @return array Résultat de l'opération
 */
function unlimitIP($interface, $ip) {
    if (!validateIP($ip)) {
        return [
            'success' => false,
            'message' => "Adresse IP invalide"
        ];
    }
    
    // Reproduire la même méthode pour retrouver le class_id
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        $minor = (((int)$parts[2]) << 8) + (int)$parts[3];
    } else {
        $minor = crc32($ip) & 0xffff;
    }
    $minor = $minor % 65534 + 2;
    $class_id = sprintf("1:%d", $minor);
    
    $commands = [
        // Supprimer le filtre de download
        "sudo tc filter del dev $interface parent 1: protocol ip prio 1 u32 match ip dst $ip 2>/dev/null",
        
        // Supprimer la classe HTB
        "sudo tc class del dev $interface classid $class_id 2>/dev/null",
        
        // Supprimer le filtre d'upload
        "sudo tc filter del dev $interface parent ffff: protocol ip prio 1 u32 match ip src $ip 2>/dev/null"
    ];
    
    $results = [];
    $success = true;
    
    foreach ($commands as $cmd) {
        $result = executeCommand($cmd);
        $results[] = $result;
    }
    
    if ($success) {
        // Supprimer de la session
        if (isset($_SESSION['limited_ips'][$ip])) {
            unset($_SESSION['limited_ips'][$ip]);
        }
    if (function_exists('writeProjectLog')) writeProjectLog('UNLIMIT', $ip, 'removed');
    }
    
    return [
        'success' => true,
        'message' => "Limitations supprimées pour l'IP $ip",
        'results' => $results
    ];
}

/**
 * Obtenir les statistiques du système TC
 * 
 * @param string $interface Interface réseau
 * @param string|null $ip IP spécifique (optionnel)
 * @return array Statistiques
 */
function getStats($interface, $ip = null) {
    $stats = [];
    
    if ($ip) {
        // Statistiques pour une IP spécifique
        $commands = [
            "sudo tc -s class show dev $interface",
            "sudo tc -s filter show dev $interface",
            "sudo iptables -L -n -v | grep -i $ip"
        ];
    } else {
        // Statistiques générales
        $commands = [
            "sudo tc -s qdisc show dev $interface",
            "sudo tc -s class show dev $interface",
            "sudo tc -s filter show dev $interface",
            "sudo ifconfig $interface",
            "date"
        ];
    }
    
    foreach ($commands as $cmd) {
        $result = executeCommand($cmd);
        if (!empty($result['output'])) {
            $stats[] = "=== " . substr($cmd, 0, 50) . (strlen($cmd) > 50 ? "..." : "") . " ===";
            $stats = array_merge($stats, $result['output']);
        }
    }
    
    return $stats;
}

/**
 * Vérifier l'état d'une IP (limitée ou non)
 * 
 * @param string $interface Interface réseau
 * @param string $ip Adresse IP à vérifier
 * @return array État de l'IP
 */
function checkIPStatus($interface, $ip) {
    if (!validateIP($ip)) {
        return [
            'limited' => false,
            'message' => "IP invalide"
        ];
    }
    
    // Vérifier si l'IP est dans une classe HTB
    $result = executeCommand("sudo tc class show dev $interface");
    $limited = false;
    
    foreach ($result['output'] as $line) {
        if (strpos($line, $ip) !== false) {
            $limited = true;
            break;
        }
    }
    
    return [
        'limited' => $limited,
        'message' => $limited ? 
            "L'IP $ip est actuellement limitée" : 
            "L'IP $ip n'est pas limitée"
    ];
}

/**
 * Vérifier si le système TC est initialisé
 * 
 * @param string $interface Interface réseau
 * @return bool True si initialisé
 */
function checkSystemStatus($interface) {
    $result = executeCommand("sudo tc qdisc show dev $interface");
    $is_initialized = false;
    
    foreach ($result['output'] as $line) {
        if (strpos($line, 'htb') !== false || strpos($line, 'qdisc') !== false) {
            $is_initialized = true;
            break;
        }
    }
    
    return $is_initialized;
}

/**
 * Supprimer toutes les règles TC de l'interface
 * 
 * @param string $interface Interface réseau
 * @return array Résultat de l'opération
 */
function clearAllTrafficControl($interface) {
    $commands = [
        "sudo tc qdisc del dev $interface root 2>/dev/null",
        "sudo tc qdisc del dev $interface ingress 2>/dev/null"
    ];
    
    $results = [];
    foreach ($commands as $cmd) {
        $result = executeCommand($cmd);
        $results[] = $result;
    }
    
    // Vider la session
    if (isset($_SESSION['limited_ips'])) {
        $_SESSION['limited_ips'] = [];
    }
    if (function_exists('writeProjectLog')) writeProjectLog('CLEAR_TC', 'ALL', 'cleared');
    
    return [
        'success' => true,
        'message' => "Toutes les règles TC ont été supprimées",
        'results' => $results
    ];
}

/**
 * Supprimer toutes les limitations actives
 * 
 * @param string $interface Interface réseau
 * @return array Résultat de l'opération
 */
function clearAllLimits($interface) {
    $success = true;
    $count = 0;
    
    if (isset($_SESSION['limited_ips']) && !empty($_SESSION['limited_ips'])) {
        foreach (array_keys($_SESSION['limited_ips']) as $limited_ip) {
            $result = unlimitIP($interface, $limited_ip);
            if ($result['success']) {
                $count++;
            } else {
                $success = false;
            }
        }
    }

    if (function_exists('writeProjectLog')) writeProjectLog('CLEAR_ALL_LIMITS', 'ALL', "count=$count");
    
    return [
        'success' => $success,
        'message' => $success ? 
            "Toutes les limitations ont été supprimées ($count IPs)" : 
            "Certaines limitations n'ont pas pu être supprimées",
        'count' => $count
    ];
}

/**
 * Obtenir les informations de l'interface réseau
 * 
 * @param string $interface Interface réseau
 * @return array Informations de l'interface
 */
function getInterfaceInfo($interface) {
    $result = executeCommand("ifconfig $interface 2>/dev/null | grep 'inet '");
    
    return [
        'interface' => $interface,
        'info' => !empty($result['output']) ? $result['output'][0] : 'Interface non trouvée'
    ];
}

/**
 * Obtenir toutes les IPs actuellement limitées
 * 
 * @return array Liste des IPs limitées avec leurs informations
 */
function getLimitedIPs() {
    if (!isset($_SESSION['limited_ips'])) {
        $_SESSION['limited_ips'] = [];
    }
    
    return $_SESSION['limited_ips'];
}

/**
 * Obtenir les limites actuelles depuis TC
 * 
 * @param string $interface Interface réseau
 * @return array Liste des limites actives
 */
function getCurrentLimitsFromTC($interface) {
    exec("sudo tc -s -d class show dev $interface", $output, $return);
    
    $limits = [];
    foreach ($output as $line) {
        if (preg_match('/class htb (1:\d+).*rate (\d+)([KMG]?)bit/', $line, $matches)) {
            $class_id = $matches[1];
            $rate = $matches[2];
            $unit = $matches[3] ?: 'K';
            
            // Conversion en kbps
            $multiplier = 1;
            if ($unit == 'M') $multiplier = 1000;
            if ($unit == 'G') $multiplier = 1000000;
            
            $limits[] = [
                'class_id' => $class_id,
                'rate_kbps' => $rate * $multiplier,
                'rate_mbps' => ($rate * $multiplier) / 1000
            ];
        }
    }
    
    return $limits;
}
?>