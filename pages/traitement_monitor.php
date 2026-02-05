<?php
/**
 * Traitement des actions de monitoring web
 * Actions: start, stop, clear, status, history
 */
session_start();
require_once __DIR__ . '/../inc/web_monitor.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';
$ip = $_REQUEST['ip'] ?? null;
$interface = $_REQUEST['interface'] ?? 'wlan0';

$response = ['success' => false, 'message' => 'Action inconnue'];

try {
    switch ($action) {
        case 'start':
            $response = startWebCapture($interface);
            break;
            
        case 'stop':
            $response = stopWebCapture();
            break;
            
        case 'status':
            $status = getCaptureStatus();
            $response = [
                'success' => true,
                'data' => $status
            ];
            break;
            
        case 'history':
            $limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 200;
            $history = getWebHistory($ip, $limit);
            $response = [
                'success' => true,
                'count' => count($history),
                'data' => $history
            ];
            break;
            
        case 'top':
            $topN = isset($_REQUEST['top']) ? (int)$_REQUEST['top'] : 20;
            $domains = getTopDomains($ip, $topN);
            $response = [
                'success' => true,
                'data' => $domains
            ];
            break;
            
        case 'clear':
            $result = clearWebHistory();
            $response = [
                'success' => $result,
                'message' => $result ? 'Historique effacé' : 'Erreur lors de l\'effacement'
            ];
            break;
            
        default:
            $response = [
                'success' => false,
                'message' => 'Action non reconnue. Actions disponibles: start, stop, status, history, top, clear'
            ];
    }
} catch (Throwable $e) {
    $response = [
        'success' => false,
        'message' => 'Erreur: ' . $e->getMessage()
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
