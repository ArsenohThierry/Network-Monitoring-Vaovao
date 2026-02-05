<?php
include 'connection.php';

/**
 * Récupère tous les appareils connectés et les enregistre en base de données
 */
function getAllConnectedDevices() {
    exec("ip neigh | awk '{print $1, $3 ,$5}'", $output);
    $DBO = dbconnect();

    foreach ($output as $line) {
        $parts = explode(" ", $line);
        if (count($parts) >= 2) {
            $ip = $parts[0];
            $interface = $parts[1];
            $mac = isset($parts[2]) ? $parts[2] : "";

            // Vérifier si le device existe déjà
            $stmt = $DBO->prepare("SELECT COUNT(*) FROM devices WHERE ip = ? AND mac = ?");
            $stmt->execute([$ip, $mac]);
            $count = $stmt->fetchColumn();

            if ($count == 0) {
                $insert = $DBO->prepare("INSERT INTO devices (ip, mac, interface) VALUES (?, ?, ?)");
                $insert->execute([$ip, $mac, $interface]);
                blockConnectionForIP($ip); // Bloquer l'IP par défaut
            }
        }
    }
}

/**
 * Récupère les appareils actuellement connectés au WiFi
 */
function getCurrentConnectedDevices() {
    exec("iw dev wlan0 station dump | awk '/Station/ {print $2}'", $output);
    $DBO = dbconnect();
    $devices = [];

    foreach ($output as $mac) {
        $stmt = $DBO->prepare("SELECT ip, mac, nom FROM devices WHERE mac = ?");
        $stmt->execute([$mac]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($device) {
            $devices[] = $device;
        } else {
            $devices[] = [
                'ip' => null,
                'mac' => $mac,
                'nom' => null
            ];
        }
    }

    return $devices;
}

/**
 * Détecte les interfaces réseau du hotspot
 */
function getHotspotInterfaces() {
    $interfaces = [
        'wifi' => 'wlan0',
        'internet' => null
    ];
    
    exec("ip route | grep default | awk '{print $5}' | head -n1", $output);
    if (!empty($output[0])) {
        $interfaces['internet'] = trim($output[0]);
    }
    
    return $interfaces;
}

/**
 * Bloquer une adresse IP
 */
function blockConnectionForIP($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return [
            'success' => false,
            'message' => 'Format d\'adresse IP invalide'
        ];
    }
    
    if (isIPBlocked($ip)) {
        return [
            'success' => false,
            'message' => "L'IP $ip est déjà bloquée"
        ];
    }
    
    $escapedIP = escapeshellarg($ip);
    $interfaces = getHotspotInterfaces();
    
    if ($interfaces['wifi'] && $interfaces['internet']) {
        $wifi = escapeshellarg($interfaces['wifi']);
        $internet = escapeshellarg($interfaces['internet']);
        
        $commands = [
            "sudo iptables -I FORWARD 1 -i $wifi -o $internet -s $escapedIP -j REJECT --reject-with icmp-host-prohibited",
            "sudo iptables -I FORWARD 1 -i $internet -o $wifi -d $escapedIP -j REJECT --reject-with icmp-host-prohibited",
        ];
    } else {
        $commands = [
            "sudo iptables -I FORWARD 1 -s $escapedIP -j REJECT --reject-with icmp-host-prohibited",
            "sudo iptables -I FORWARD 1 -d $escapedIP -j REJECT --reject-with icmp-host-prohibited",
        ];
    }
    
    $allSuccess = true;
    $outputs = [];
    
    foreach ($commands as $command) {
        exec($command . " 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            $allSuccess = false;
        }
        $outputs[] = implode("\n", $output);
        $output = [];
    }
    
    logFirewallAction('block', $ip, $allSuccess);
    // also write a simple project-level log with outputs
    writeProjectLog('BLOCK', $ip, implode("\n---\n", $outputs));
    
    return [
        'success' => $allSuccess,
        'ip' => $ip,
        'message' => $allSuccess ? "IP $ip bloquée avec succès" : "Échec du blocage de l'IP $ip"
    ];
}

/**
 * Débloquer une adresse IP
 */
function unblockConnectionForIP($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return [
            'success' => false,
            'message' => 'Format d\'adresse IP invalide'
        ];
    }
    
    if (!isIPBlocked($ip)) {
        return [
            'success' => false,
            'message' => "L'IP $ip n'est pas bloquée"
        ];
    }
    
    $escapedIP = escapeshellarg($ip);
    
    // Méthode 1: Supprimer par numéro de règle
    exec("sudo iptables -L FORWARD -n --line-numbers 2>&1", $output, $returnCode);
    
    $rulesToDelete = [];
    foreach ($output as $line) {
        if ((stripos($line, 'DROP') !== false || stripos($line, 'REJECT') !== false) && 
            stripos($line, $ip) !== false) {
            if (preg_match('/^(\d+)/', $line, $matches)) {
                $rulesToDelete[] = (int)$matches[1];
            }
        }
    }
    
    rsort($rulesToDelete);
    $deletedCount = 0;
    
    foreach ($rulesToDelete as $ruleNum) {
        $command = "sudo iptables -D FORWARD $ruleNum 2>&1";
        exec($command, $output, $returnCode);
        if ($returnCode === 0) {
            $deletedCount++;
        }
        $output = [];
    }
    
    // Méthode 2: Suppression directe (backup)
    if ($deletedCount === 0) {
        $commands = [
            "sudo iptables -D FORWARD -s $escapedIP -j REJECT --reject-with icmp-host-prohibited 2>&1",
            "sudo iptables -D FORWARD -d $escapedIP -j REJECT --reject-with icmp-host-prohibited 2>&1",
        ];
        
        foreach ($commands as $command) {
            exec($command, $output, $returnCode);
            if ($returnCode === 0) {
                $deletedCount++;
            }
            $output = [];
        }
    }
    
    $success = $deletedCount > 0;
    logFirewallAction('unblock', $ip, $success);
    writeProjectLog('UNBLOCK', $ip, "deleted_rules=$deletedCount");
    
    return [
        'success' => $success,
        'message' => $success ? "IP $ip débloquée avec succès" : "Impossible de débloquer l'IP $ip"
    ];
}

/**
 * Vérifie si une IP est bloquée
 */
function isIPBlocked($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    
    $escapedIP = escapeshellarg($ip);
    exec("sudo iptables -L FORWARD -n 2>&1 | grep -w $escapedIP", $output, $returnCode);
    
    if ($returnCode === 0 && !empty($output)) {
        foreach ($output as $line) {
            if (stripos($line, 'DROP') !== false || stripos($line, 'REJECT') !== false) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Enregistre les actions du firewall
 */
function logFirewallAction($action, $ip, $success) {
    $logFile = __DIR__ . '/../logs/firewall.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'SUCCESS' : 'FAILED';
    $user = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    
    $logMessage = "[$timestamp] $status - $action IP: $ip - Requested by: $user\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Append a simple project-level log to the repo root (network_actions.txt)
 * @param string $action
 * @param string $ip
 * @param string $details
 */
function writeProjectLog($action, $ip, $details = '') {
    $rootLog = __DIR__ . '/../network_actions.txt';
    $timestamp = date('Y-m-d H:i:s');
    $user = $_SERVER['REMOTE_ADDR'] ?? (php_sapi_name() === 'cli' ? 'CLI' : 'WEB');
    $entry = "[$timestamp] $action IP:$ip by:$user";
    if ($details !== '') {
        // sanitize newlines
        $safe = str_replace("\r", '', $details);
        $safe = str_replace("\n", ' | ', $safe);
        $entry .= " - $safe";
    }
    $entry .= "\n";
    // ensure file exists and is appendable
    @file_put_contents($rootLog, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Récupère toutes les IPs bloquées
 */
function getBlockedIPs() {
    exec("sudo iptables -L FORWARD -n --line-numbers 2>&1", $output, $returnCode);
    
    if ($returnCode !== 0) {
        return [];
    }
    
    $blockedIPs = [];
    
    foreach ($output as $line) {
        if (preg_match('/^(\d+)\s+(DROP|REJECT)\s+\w+\s+--\s+([\d\.]+)/', $line, $matches)) {
            $ip = $matches[3];
            if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $blockedIPs)) {
                $blockedIPs[] = $ip;
            }
        }
    }
    
    return $blockedIPs;
}

/**
 * Diagnostic réseau simple
 */
function diagnosticNetwork() {
    $commands = [
        'interfaces' => 'ip addr show',
        'routing' => 'ip route',
        'connected_devices' => 'ip neigh show',
        'wifi_clients' => 'iw dev wlan0 station dump 2>&1 || echo "WiFi non disponible"'
    ];
    
    $results = [];
    
    foreach ($commands as $name => $command) {
        exec($command . ' 2>&1', $output, $returnCode);
        $results[$name] = [
            'command' => $command,
            'output' => implode("\n", $output)
        ];
        $output = [];
    }
    
    return $results;
}

function limitDeviceSpeed($device_ip, $download_kbps, $upload_kbps = null) {
    // Configuration
    $interface = "wlan0";  // Your hotspot interface
    $upload_kbps = $upload_kbps ?? round($download_kbps * 0.5); // Default: 50% of download
    
    // Validate IP
    if (!filter_var($device_ip, FILTER_VALIDATE_IP)) {
        return [
            'success' => false,
            'message' => "Invalid IP address"
        ];
    }
    
    // Generate unique class ID from IP
    $class_id = "1:" . str_replace('.', '', $device_ip);
    
    // Commands to limit the device
    $commands = [
        // Download limit (egress)
        "sudo tc class add dev $interface parent 1:1 classid $class_id htb rate {$download_kbps}kbit ceil {$download_kbps}kbit",
        "sudo tc filter add dev $interface protocol ip parent 1:0 prio 1 u32 match ip dst $device_ip flowid $class_id",
        
        // Upload limit (ingress) - match by source IP
        "sudo tc filter add dev $interface parent ffff: protocol ip prio 2 u32 match ip src $device_ip police rate {$upload_kbps}kbit burst 10k drop flowid :1"
    ];
    
    $results = [];
    $success = true;
    
    foreach ($commands as $command) {
        exec($command . " 2>&1", $output, $return_code);
        
        $results[] = [
            'command' => $command,
            'output' => $output,
            'return_code' => $return_code
        ];
        
        // Ignore "File exists" errors (rule already exists)
        if ($return_code !== 0 && !strpos(implode(' ', $output), 'File exists')) {
            $success = false;
        }
    }
    
    // Log the action
    $log_msg = date('Y-m-d H:i:s') . " - Device $device_ip limited to: {$download_kbps}kbps down / {$upload_kbps}kbps up";
    error_log($log_msg);
    
    return [
        'success' => $success,
        'message' => $success ? 
            "Device $device_ip limited successfully" : 
            "Failed to limit device $device_ip",
        'limits' => [
            'download' => "{$download_kbps} kbps",
            'upload' => "{$upload_kbps} kbps"
        ],
        'details' => $results
    ];
}

// Function to remove limits from a device
function removeDeviceLimit($device_ip) {
    $interface = "wlan0";
    $class_id = "1:" . str_replace('.', '', $device_ip);
    
    $commands = [
        // Remove download class
        "sudo tc class del dev $interface classid $class_id 2>/dev/null",
        // Remove upload police filter
        "sudo tc filter del dev $interface parent ffff: pref 2 2>/dev/null"
    ];
    
    foreach ($commands as $cmd) {
        exec($cmd);
    }
    
    error_log(date('Y-m-d H:i:s') . " - Removed limits from device $device_ip");
    
    return [
        'success' => true,
        'message' => "Limits removed from $device_ip"
    ];
}

function getCurrentLimits() {
    exec("sudo tc -s -d class show dev wlan0", $output, $return);
    
    $limits = [];
    foreach ($output as $line) {
        if (preg_match('/class htb (1:\d+).*rate (\d+)([KMG]?)bit/', $line, $matches)) {
            $class_id = $matches[1];
            $rate = $matches[2];
            $unit = $matches[3] ?: 'K';
            
            // Convert to kbps
            $multiplier = 1;
            if ($unit == 'M') $multiplier = 1000;
            if ($unit == 'G') $multiplier = 1000000;
            
            $limits[] = [
                'class_id' => $class_id,
                'rate_kbps' => $rate * $multiplier
            ];
        }
    }
    
    return $limits;
}
?>