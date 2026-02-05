<?php
// traitement_limiter.php
// Utilise les nouvelles fonctions TC v2 avec IFB pour la limitation de débit
session_start();
require_once __DIR__ . '/../inc/tc_functions.php';

ini_set("display_errors", 1);
error_reporting(E_ALL);

$interface = 'wlan0';
$ip = $_REQUEST['ip'] ?? '';
$limit = $_REQUEST['lim'] ?? 8;
$action = $_REQUEST['action'] ?? 'limit';

$msg = '';
$success = false;
$res = null;

try {
    switch ($action) {
        case 'limit':
            $res = limitIPv2($interface, $ip, (int)$limit);
            $msg = $res['message'] ?? 'Opération terminée';
            $success = !empty($res['success']);
            break;
        case 'unlimit':
            $res = unlimitIPv2($interface, $ip);
            $msg = $res['message'] ?? 'Opération terminée';
            $success = !empty($res['success']);
            break;
        case 'init':
            $res = initTrafficControlV2($interface);
            $msg = $res['message'] ?? 'Contrôle de trafic initialisé';
            $success = !empty($res['success']);
            break;
        case 'status':
            $res = showTCStatus($interface);
            $msg = 'État TC récupéré';
            $success = true;
            break;
        default:
            $msg = 'Action inconnue. Actions: limit, unlimit, init, status';
            $success = false;
            break;
    }
} catch (Throwable $e) {
    $msg = 'Erreur: ' . $e->getMessage();
    $success = false;
}

// Prepare detailed debug output when available
$details = "";
if (isset($res) && is_array($res)) {
    if (!empty($res['results']) && is_array($res['results'])) {
        foreach ($res['results'] as $idx => $r) {
            $cmd = $r['command'] ?? ($r['cmd'] ?? '');
            $rc = $r['return_code'] ?? ($r['returnCode'] ?? '');
            $out = '';
            if (isset($r['output']) && is_array($r['output'])) {
                $out = implode("\n", $r['output']);
            }
            $details .= "Command #" . ($idx+1) . ": $cmd\nReturn code: $rc\nOutput:\n$out\n\n";
        }
    } elseif (!empty($res) && is_array($res)) {
        // For functions that return an array of results directly
        foreach ($res as $k => $v) {
            if (is_array($v) && isset($v['command'])) {
                $cmd = $v['command'];
                $rc = $v['return_code'] ?? '';
                $out = isset($v['output']) && is_array($v['output']) ? implode("\n", $v['output']) : '';
                $details .= "Command ($k): $cmd\nReturn code: $rc\nOutput:\n$out\n\n";
            }
        }
    }
}

// If there were PHP exceptions captured earlier, include them (already in $msg)

// Output plain text for debugging
header('Content-Type: text/plain; charset=utf-8');
echo "Message: " . $msg . "\n";
echo "Success: " . ($success ? '1' : '0') . "\n\n";
if ($details !== "") {
    echo "Details:\n" . $details;
} else {
    echo "No detailed command output available.\n";
}

// Also include last_stats if present
if (!empty($_SESSION['last_stats'])) {
    echo "\nLast stats (from session):\n";
    if (is_array($_SESSION['last_stats'])) {
        if (isset($_SESSION['last_stats']['output'])) {
            echo implode("\n", (array)$_SESSION['last_stats']['output']);
        } else {
            // If stats is an array of lines
            foreach ((array)$_SESSION['last_stats'] as $line) {
                if (is_string($line)) echo $line . "\n";
            }
        }
    }
}
header("Location: Limitation.php");
exit;