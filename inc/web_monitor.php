<?php
/**
 * Web Monitoring Functions
 * Capture et affichage de l'historique des sites web visités via DNS/HTTP
 * Utilise tshark (Wireshark CLI) pour la capture passive
 */

define('WEB_HISTORY_FILE', __DIR__ . '/../web_history.txt');
define('CAPTURE_PID_FILE', __DIR__ . '/../capture.pid');

/**
 * Démarre la capture tshark en arrière-plan
 * Capture les requêtes DNS et HTTP Host headers
 * 
 * @param string $interface Interface réseau (ex: wlan0)
 * @return array Résultat de l'opération
 */
function startWebCapture($interface = 'wlan0') {
    // Vérifier si tshark est disponible
    exec("which tshark 2>/dev/null", $output, $ret);
    if ($ret !== 0) {
        return [
            'success' => false,
            'message' => 'tshark non trouvé. Installez wireshark-cli: sudo apt install tshark'
        ];
    }
    
    // Vérifier si capture déjà en cours
    if (isCaptureRunning()) {
        return [
            'success' => false,
            'message' => 'Capture déjà en cours'
        ];
    }
    
    $logFile = WEB_HISTORY_FILE;
    $pidFile = CAPTURE_PID_FILE;
    
    // Commande tshark pour capturer les requêtes DNS uniquement
    // Les requêtes DNS sont faites AVANT toute connexion (HTTP ou HTTPS)
    // -i interface : interface réseau
    // -f "port 53" : filtre BPF pour DNS uniquement
    // -Y "dns.flags.response == 0" : seulement les requêtes (pas les réponses)
    // -T fields -e ... : format de sortie
    $cmd = "sudo tshark -i $interface -f 'port 53' " .
           "-Y 'dns.flags.response == 0 and dns.qry.name' " .
           "-T fields -e frame.time -e ip.src -e dns.qry.name " .
           "-E separator='|' -l 2>/dev/null >> $logFile &";
    
    // Exécuter en arrière-plan
    exec($cmd, $out, $retCode);
    
    // Récupérer le PID du processus tshark
    sleep(1); // Attendre un peu que le processus démarre
    exec("pgrep -f 'tshark.*$interface' | head -1", $pidOut);
    
    if (!empty($pidOut[0])) {
        file_put_contents($pidFile, trim($pidOut[0]));
        return [
            'success' => true,
            'message' => 'Capture démarrée',
            'pid' => trim($pidOut[0])
        ];
    }
    
    return [
        'success' => false,
        'message' => 'Échec du démarrage de la capture'
    ];
}

/**
 * Arrête la capture tshark
 * 
 * @return array Résultat de l'opération
 */
function stopWebCapture() {
    $pidFile = CAPTURE_PID_FILE;
    
    if (file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        if (is_numeric($pid)) {
            exec("sudo kill $pid 2>/dev/null", $out, $ret);
            unlink($pidFile);
            return [
                'success' => true,
                'message' => 'Capture arrêtée'
            ];
        }
    }
    
    // Essayer de tuer tous les processus tshark au cas où
    exec("sudo pkill -f tshark 2>/dev/null");
    if (file_exists($pidFile)) unlink($pidFile);
    
    return [
        'success' => true,
        'message' => 'Capture arrêtée (cleanup)'
    ];
}

/**
 * Vérifie si la capture est en cours
 * 
 * @return bool
 */
function isCaptureRunning() {
    exec("pgrep -f tshark", $output, $ret);
    return $ret === 0 && !empty($output);
}

/**
 * Récupère l'historique web pour une IP spécifique
 * 
 * @param string|null $ip Filtrer par IP source (null = tous)
 * @param int $limit Nombre max de lignes à retourner
 * @return array Liste des sites visités avec timestamps
 */
function getWebHistory($ip = null, $limit = 500) {
    $logFile = WEB_HISTORY_FILE;
    
    if (!file_exists($logFile)) {
        return [];
    }
    
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    
    $history = [];
    $seen = []; // Pour éviter les doublons consécutifs
    
    // Parcourir les lignes (les plus récentes d'abord)
    $lines = array_reverse($lines);
    
    foreach ($lines as $line) {
        if (count($history) >= $limit) break;
        
        // Format: timestamp|ip_src|dns_query|http_host
        $parts = explode('|', $line);
        if (count($parts) < 3) continue;
        
        $timestamp = trim($parts[0] ?? '');
        $srcIp = trim($parts[1] ?? '');
        $domain = trim($parts[2] ?? ''); // DNS query
        if (empty($domain) && isset($parts[3])) {
            $domain = trim($parts[3]); // HTTP host si DNS vide
        }
        
        // Ignorer les entrées vides ou locales
        if (empty($domain)) continue;
        if (strpos($domain, '.local') !== false) continue;
        if (strpos($domain, '.lan') !== false) continue;
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $domain)) continue; // IP brute
        
        // Filtrer par IP si demandé
        if ($ip !== null && $srcIp !== $ip) continue;
        
        // Éviter doublons consécutifs
        $key = "$srcIp|$domain";
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        
        // Parser le timestamp
        $time = $timestamp;
        // Format: "Feb  5, 2026 05:21:28.752794923 GMT"
        if (preg_match('/(\w+\s+\d+,?\s+\d+\s+\d+:\d+:\d+)/', $timestamp, $m)) {
            $time = $m[1];
        }
        
        $history[] = [
            'time' => $time,
            'ip' => $srcIp,
            'domain' => $domain
        ];
    }
    
    return $history;
}

/**
 * Récupère les domaines les plus visités par une IP
 * 
 * @param string|null $ip Filtrer par IP
 * @param int $topN Nombre de domaines à retourner
 * @return array Domaines triés par fréquence
 */
function getTopDomains($ip = null, $topN = 20) {
    $history = getWebHistory($ip, 2000);
    
    $counts = [];
    foreach ($history as $entry) {
        $domain = $entry['domain'];
        // Simplifier le domaine (garder les 2-3 derniers segments)
        $parts = explode('.', $domain);
        if (count($parts) > 2) {
            $domain = implode('.', array_slice($parts, -2));
        }
        if (!isset($counts[$domain])) $counts[$domain] = 0;
        $counts[$domain]++;
    }
    
    arsort($counts);
    return array_slice($counts, 0, $topN, true);
}

/**
 * Efface l'historique web
 * 
 * @return bool
 */
function clearWebHistory() {
    $logFile = WEB_HISTORY_FILE;
    if (file_exists($logFile)) {
        return file_put_contents($logFile, '') !== false;
    }
    return true;
}

/**
 * Récupère le statut de la capture
 * 
 * @return array
 */
function getCaptureStatus() {
    $running = isCaptureRunning();
    $logFile = WEB_HISTORY_FILE;
    $size = file_exists($logFile) ? filesize($logFile) : 0;
    $lines = 0;
    if (file_exists($logFile)) {
        exec("wc -l < " . escapeshellarg($logFile), $out);
        $lines = isset($out[0]) ? (int)$out[0] : 0;
    }
    
    return [
        'running' => $running,
        'log_size' => $size,
        'log_lines' => $lines,
        'log_size_human' => formatBytes($size)
    ];
}

/**
 * Formate les bytes en taille lisible
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
