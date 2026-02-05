<?php
ini_set("display_errors",1);
error_reporting(E_ALL);
session_start();
include '../inc/functions.php';
include '../inc/web_monitor.php';

$user = $_SESSION['user'] ?? null;
// Helper to read last N lines from the firewall log
function getFirewallLogLines($path, $maxLines = 200) {
    if (!file_exists($path) || !is_readable($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    $count = count($lines);
    if ($count <= $maxLines) return array_reverse($lines);
    return array_reverse(array_slice($lines, -$maxLines));
}

getAllConnectedDevices();
// Récupérer les appareils réellement connectés
$connectedDevices = getCurrentConnectedDevices();

// Initialiser les données des appareils si elles n'existent pas ou forcer la mise à jour
$devices = [];
$deviceId = 1;

foreach ($connectedDevices as $device) {
    // Vérifier si l'appareil existe déjà dans la session (pour garder son statut)
    $existingDevice = null;
    if (isset($_SESSION['devices'])) {
        foreach ($_SESSION['devices'] as $sessionDevice) {
            if ($sessionDevice['mac'] === $device['mac']) {
                $existingDevice = $sessionDevice;
                break;
            }
        }
    }

    // Si l'appareil existe, garder ses paramètres. Sinon: nouveau device => bloqué par défaut.
    if ($existingDevice) {
        $devices[] = $existingDevice;
    } else {
        $newDevice = [
            'id' => $deviceId++,
            'name' => 'Appareil ' . ($device['ip'] ?? 'inconnu'),
            'ip' => $device['ip'],
            'mac' => $device['mac'],
            'status' => 'blocked',
            'speedLimit' => null
        ];

        // Bloquer automatiquement l'appareil au premier affichage si on a une IP valide
        if (!empty($newDevice['ip']) && filter_var($newDevice['ip'], FILTER_VALIDATE_IP)) {
            if (!isIPBlocked($newDevice['ip'])) {
                blockConnectionForIP($newDevice['ip']);
            }

            // Log UI (session)
            if (!isset($_SESSION['logs'])) {
                $_SESSION['logs'] = [];
            }
            array_unshift($_SESSION['logs'], [
                'time' => date('H:i'),
                'icon' => 'fa-ban',
                'class' => 'log-warning',
                'message' => "Nouveau device détecté ({$newDevice['ip']}) : bloqué par défaut",
                'type' => 'warning'
            ]);
        }

        $devices[] = $newDevice;
    }
}

$_SESSION['devices'] = $devices;

// Synchroniser le statut en session avec l'état réel du firewall
// (permet d'afficher le bouton "Autoriser" après un blocage effectué via traitement_bloquer.php)
if (!empty($_SESSION['devices'])) {
    foreach ($_SESSION['devices'] as &$sdevice) {
        if (isset($sdevice['ip']) && filter_var($sdevice['ip'], FILTER_VALIDATE_IP)) {
            // isIPBlocked() retourne true si l'IP est présente dans les règles
            if (isIPBlocked($sdevice['ip'])) {
                $sdevice['status'] = 'blocked';
                $sdevice['speedLimit'] = null;
            } else {
                // Si l'IP n'est pas bloquée mais le statut en session indique blocked,
                // on remet en connected pour que l'UI propose l'action "Bloquer"
                if ($sdevice['status'] === 'blocked') {
                    $sdevice['status'] = 'connected';
                    $sdevice['speedLimit'] = null;
                }
            }
        }
    }
    unset($sdevice);
}

// Initialiser les logs si ils n'existent pas
if (!isset($_SESSION['logs'])) {
    $_SESSION['logs'] = [
        ['time' => date('H:i'), 'icon' => 'fa-wifi', 'class' => 'log-info', 'message' => 'Scan du réseau terminé - ' . count($devices) . ' appareils détectés', 'type' => 'info']
    ];
}

// Traiter les actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $deviceId = isset($_POST['device_id']) ? (int)$_POST['device_id'] : 0;

    foreach ($_SESSION['devices'] as &$device) {
        if ($device['id'] === $deviceId) {
            $time = date('H:i');

            switch ($action) {
                case 'allow':
                    $device['status'] = 'connected';
                    $device['speedLimit'] = null;
                    array_unshift($_SESSION['logs'], [
                        'time' => $time,
                        'icon' => 'fa-check',
                        'class' => 'log-success',
                        'message' => "Appareil '{$device['name']}' ({$device['ip']}) autorisé",
                        'type' => 'success'
                    ]);
                    break;

                case 'block':
                    $device['status'] = 'blocked';
                    $device['speedLimit'] = null;
                    array_unshift($_SESSION['logs'], [
                        'time' => $time,
                        'icon' => 'fa-ban',
                        'class' => 'log-danger',
                        'message' => "Appareil '{$device['name']}' ({$device['ip']}) bloqué",
                        'type' => 'danger'
                    ]);
                    break;

                case 'limit':
                    $speedLimit = isset($_POST['speed_limit']) ? (int)$_POST['speed_limit'] : 10;
                    $device['status'] = 'limited';
                    $device['speedLimit'] = $speedLimit;
                    array_unshift($_SESSION['logs'], [
                        'time' => $time,
                        'icon' => 'fa-tachometer-alt',
                        'class' => 'log-warning',
                        'message' => "Limitation appliquée à '{$device['name']}' ({$device['ip']}) - {$speedLimit} Mbps",
                        'type' => 'warning'
                    ]);
                    break;

                case 'remove_limit':
                    $device['status'] = 'connected';
                    $device['speedLimit'] = null;
                    array_unshift($_SESSION['logs'], [
                        'time' => $time,
                        'icon' => 'fa-unlock',
                        'class' => 'log-success',
                        'message' => "Limitation levée pour '{$device['name']}' ({$device['ip']})",
                        'type' => 'success'
                    ]);
                    break;
            }
            break;
        }
    }

    // Limiter le nombre de logs
    if (count($_SESSION['logs']) > 20) {
        $_SESSION['logs'] = array_slice($_SESSION['logs'], 0, 20);
    }

    // Redirection pour éviter la resoumission du formulaire
    header('Location: ' . $_SERVER['PHP_SELF'] . '?section=' . ($_GET['section'] ?? 'view-devices'));
    exit;
}

// Action pour lever toutes les limitations
if (isset($_GET['remove_all_limits'])) {
    $count = 0;
    foreach ($_SESSION['devices'] as &$device) {
        if ($device['status'] === 'limited') {
            $device['status'] = 'connected';
            $device['speedLimit'] = null;
            $count++;
        }
    }

    if ($count > 0) {
        array_unshift($_SESSION['logs'], [
            'time' => date('H:i'),
            'icon' => 'fa-unlock-alt',
            'class' => 'log-success',
            'message' => "$count limitation(s) levée(s)",
            'type' => 'success'
        ]);
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?section=remove-limits');
    exit;
}

// Action pour effacer les logs
if (isset($_GET['clear_logs'])) {
    $_SESSION['logs'] = [];
    array_unshift($_SESSION['logs'], [
        'time' => date('H:i'),
        'icon' => 'fa-trash',
        'class' => 'log-info',
        'message' => 'Journal effacé',
        'type' => 'info'
    ]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?section=view-logs');
    exit;
}

// Calculer les statistiques
$connectedCount = 0;
$limitedCount = 0;
$blockedCount = 0;

foreach ($devices as $device) {
    if (!isIPBlocked($device['ip'])) $connectedCount++;
    if ($device['status'] === 'limited') $limitedCount++;
    if (isIPBlocked($device['ip'])) $blockedCount++;
}

$totalDevices = count($devices);
$currentSection = $_GET['section'] ?? 'view-devices';

// Fonction pour obtenir l'icône selon l'adresse MAC ou IP
function getDeviceIcon($mac)
{
    // Tu peux personnaliser selon les préfixes MAC des constructeurs
    $prefix = strtoupper(substr($mac, 0, 8));

    // Quelques exemples de préfixes MAC connus
    if (in_array($prefix, ['00:50:56', '00:0C:29', '00:05:69'])) return 'fa-server'; // VMware
    if (in_array($prefix, ['08:00:27'])) return 'fa-server'; // VirtualBox
    if (in_array($prefix, ['B8:27:EB', 'DC:A6:32'])) return 'fa-microchip'; // Raspberry Pi

    return 'fa-desktop'; // Par défaut
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Monitor | Administration Réseau</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-network-wired"></i>
                <h1>Network Monitor</h1>
            </div>
            <div class="status-indicator">
                <div class="status-dot"></div>
                <span>Système actif - <?php echo $totalDevices; ?> appareils connectés</span>
            </div>
        </header>

        <nav class="nav-container">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="?section=view-devices" class="nav-link <?php echo $currentSection === 'view-devices' ? 'active' : ''; ?>">
                        <div class="nav-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div>
                            <div class="nav-text">Voir les PC connectés</div>
                            <div class="nav-description">Affiche tous les appareils sur le réseau</div>
                        </div>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- View Devices Section -->
        <section id="view-devices" class="content-section <?php echo $currentSection === 'view-devices' ? 'active' : ''; ?>">
            <div class="dashboard">
                <div class="main-content">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $totalDevices; ?></div>
                            <div class="stat-label">Appareils connectés</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $limitedCount; ?></div>
                            <div class="stat-label">Appareils limités</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $blockedCount; ?></div>
                            <div class="stat-label">Appareils bloqués</div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-desktop"></i> Appareils connectés</h2>
                            <a href="?section=view-devices" class="btn btn-outline">
                                <i class="fas fa-sync-alt"></i> Actualiser
                            </a>
                        </div>
                        <div class="card-body">
                            <table class="devices-table">
                                <thead>
                                    <tr>
                                        <th>Appareil</th>
                                        <th>Adresse IP</th>
                                        <th>MAC</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($_SESSION['devices']) > 0): ?>
                                        <?php foreach ($_SESSION['devices'] as $device): ?>
                                        <tr class="device-row">
                                            <td>
                                                <div class="device-info">
                                                    <div class="device-icon">
                                                        <i class="fas <?php echo getDeviceIcon($device['mac']); ?>"></i>
                                                    </div>
                                                    <div>
                                                        <div class="device-name"><?php echo htmlspecialchars($device['name']); ?></div>
                                                        <div class="device-mac"><?php echo htmlspecialchars($device['mac']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($device['ip']); ?></td>
                                            <td><?php echo htmlspecialchars($device['mac']); ?></td>
                                            <td>
                                                <?php
                                                if ($device['status'] === 'connected') {
                                                    echo '<span class="device-status status-connected">Connecté</span>';
                                                } elseif ($device['status'] === 'blocked') {
                                                    echo '<span class="device-status status-blocked">Bloqué</span>';
                                                } elseif ($device['status'] === 'limited') {
                                                    echo '<span class="device-status status-limited">Limité (' . $device['speedLimit'] . ' Mbps)</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                if ($user['roles'] === 'ADMIN') { ?>
                                                  <div class="actions-cell">
                                                    <?php if ($device['status'] === 'connected'): ?>
                                                        <a href="traitement_bloquer.php?ip=<?php echo urlencode($device['ip']); ?>" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-ban"></i> Bloquer
                                                        </a>
                                                        <a href="Limitation.php?ip=<?= $device['ip'] ?>" class="btn btn-warning btn-sm">
                                                            <i class="fas fa-tachometer-alt"></i> Limiter
                                                        </a>
                                                        <a href="?section=view-logs&filter_ip=<?php echo urlencode($device['ip']); ?>" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-clipboard-list"></i> Logs
                                                        </a>
                                                    <?php elseif ($device['status'] === 'blocked'): ?>
                                                        <a href="traitement_autoriser.php?ip=<?php echo urlencode($device['ip']); ?>" class="btn btn-success btn-sm">
                                                            <i class="fas fa-check"></i> Autoriser
                                                        </a>
                                                    <?php elseif ($device['status'] === 'limited'): ?>
                                                        <a href="traitement_bloquer.php?ip=<?php echo urlencode($device['ip']); ?>" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-ban"></i> Bloquer
                                                        </a>
                                                        <a href="traitement_lever_limite.php?ip=<?php echo urlencode($device['ip']); ?>" class="btn btn-success btn-sm">
                                                            <i class="fas fa-unlock"></i> Lever limite
                                                        </a>
                                                        <a href="?section=view-logs&filter_ip=<?php echo urlencode($device['ip']); ?>" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-clipboard-list"></i> Logs
                                                        </a>
                                                    <?php endif; ?>
                                                    </div>
                                                <?php } ?>
                                            </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center; padding: 20px;">
                                                <i class="fas fa-exclamation-circle"></i> Aucun appareil détecté sur le réseau
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="sidebar">

                    <a href="network-map.php" class="nav-link">
                        <i class="fas fa-project-diagram"></i> Carte Réseau
                    </a>
                    <div class="info-box">
                        <h3><i class="fas fa-info-circle"></i> Vue d'ensemble</h3>
                        <p>Cette section affiche tous les appareils actuellement connectés à votre réseau. Les données sont récupérées en temps réel via la commande <code>ip neigh</code>.</p>
                        <p><strong>Appareils détectés :</strong> <?php echo $totalDevices; ?></p>
                    </div>
                </div>
            </div>
        </section>

        <!-- View Logs Section -->
        <section id="view-logs" class="content-section <?php echo $currentSection === 'view-logs' ? 'active' : ''; ?>">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-clipboard-list"></i> Logs Firewall</h2>
                    <a href="?section=view-logs" class="btn btn-outline"><i class="fas fa-sync-alt"></i> Actualiser</a>
                </div>
                <div class="card-body">
                    <div class="info-box">
                        <h3>Derniers événements (session)</h3>
                        <?php if (!empty($_SESSION['logs'])): ?>
                            <ul class="log-list">
                                <?php foreach ($_SESSION['logs'] as $log): ?>
                                    <li>
                                        <strong>[<?php echo htmlspecialchars($log['time']); ?>]</strong>
                                        <?php echo htmlspecialchars($log['message']); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>Aucun log en session.</p>
                        <?php endif; ?>
                    </div>

                    <div class="info-box">
                        <h3>Firewall log (last lines)</h3>
                        <pre style="max-height:400px; overflow:auto; background:#f8f9fa; padding:10px;"><?php
                            $fwlog = getFirewallLogLines(__DIR__ . '/../logs/firewall.log', 300);
                            if (!empty($fwlog)) {
                                echo htmlspecialchars(implode("\n", $fwlog));
                            } else {
                                echo "Aucun log firewall trouvé ou fichier non lisible.";
                            }
                        ?></pre>
                    </div>
                </div>
            </div>
            
            <!-- Web History Section -->
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <h2><i class="fas fa-globe"></i> Historique Web des Appareils</h2>
                    <div style="display: flex; gap: 10px;">
                        <?php $captureStatus = getCaptureStatus(); ?>
                        <?php if ($captureStatus['running']): ?>
                            <span class="device-status status-connected">Capture active</span>
                            <a href="traitement_monitor.php?action=stop" class="btn btn-danger btn-sm">
                                <i class="fas fa-stop"></i> Arrêter
                            </a>
                        <?php else: ?>
                            <span class="device-status status-blocked">Capture inactive</span>
                            <a href="traitement_monitor.php?action=start" class="btn btn-success btn-sm">
                                <i class="fas fa-play"></i> Démarrer
                            </a>
                        <?php endif; ?>
                        <a href="?section=view-logs" class="btn btn-outline btn-sm"><i class="fas fa-sync-alt"></i></a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="info-box">
                        <h3><i class="fas fa-info-circle"></i> Comment ça marche</h3>
                        <p style="font-size: 0.9rem;">
                            Cette fonctionnalité utilise <code>tshark</code> (Wireshark CLI) pour capturer les requêtes DNS 
                            des appareils connectés à votre hotspot. Cela vous permet de voir quels sites web ils visitent.
                        </p>
                        <p style="font-size: 0.85rem; color: var(--gray);">
                            <i class="fas fa-shield-alt"></i> Note: Capture passive sur votre propre réseau. 
                            Seuls les noms de domaine (DNS) sont capturés, pas le contenu des pages.
                        </p>
                        <?php if ($captureStatus['log_lines'] > 0): ?>
                            <p style="font-size: 0.85rem;">
                                <strong>Stats:</strong> <?php echo $captureStatus['log_lines']; ?> entrées 
                                (<?php echo $captureStatus['log_size_human']; ?>)
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Filtres (appareil / date / domaine) -->
                    <div class="info-box">
                        <h3><i class="fas fa-filter"></i> Filtrer par appareil</h3>
                        <form method="get" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <input type="hidden" name="section" value="view-logs">
                            <select name="filter_ip" class="form-control" style="max-width: 200px;">
                                <option value="">Tous les appareils</option>
                                <?php foreach ($_SESSION['devices'] ?? [] as $dev): ?>
                                    <?php if (!empty($dev['ip'])): ?>
                                        <option value="<?php echo htmlspecialchars($dev['ip']); ?>"
                                            <?php echo (isset($_GET['filter_ip']) && $_GET['filter_ip'] === $dev['ip']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dev['ip']); ?>
                                            <?php if (!empty($dev['name'])): ?>(<?php echo htmlspecialchars($dev['name']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <input
                                type="date"
                                name="filter_date"
                                class="form-control"
                                style="max-width: 200px;"
                                value="<?php echo isset($_GET['filter_date']) ? htmlspecialchars($_GET['filter_date']) : ''; ?>"
                                title="Filtrer par date"
                            />
                            <input
                                type="text"
                                name="filter_domain"
                                class="form-control"
                                style="max-width: 240px;"
                                placeholder="Nom de domaine (ex: google.com)"
                                value="<?php echo isset($_GET['filter_domain']) ? htmlspecialchars($_GET['filter_domain']) : ''; ?>"
                                title="Filtrer par nom de domaine"
                            />
                            <button type="submit" class="btn btn-outline btn-sm">
                                <i class="fas fa-search"></i> Filtrer
                            </button>
                            <?php if ((isset($_GET['filter_ip']) && $_GET['filter_ip'] !== '') || (isset($_GET['filter_date']) && $_GET['filter_date'] !== '') || (isset($_GET['filter_domain']) && trim($_GET['filter_domain']) !== '')): ?>
                                <a href="?section=view-logs" class="btn btn-outline btn-sm">
                                    <i class="fas fa-times"></i> Réinitialiser
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <!-- Top domaines -->
                    <?php 
                    $filterIp = isset($_GET['filter_ip']) && $_GET['filter_ip'] !== '' ? $_GET['filter_ip'] : null;
                    $filterDate = isset($_GET['filter_date']) && $_GET['filter_date'] !== '' ? $_GET['filter_date'] : null; // YYYY-MM-DD
                    $filterDomain = isset($_GET['filter_domain']) && trim($_GET['filter_domain']) !== '' ? trim($_GET['filter_domain']) : null;

                    // On filtre l'historique côté PHP (sans toucher à inc/web_monitor.php)
                    $webHistoryAll = getWebHistory($filterIp, 2000);
                    $webHistoryFiltered = [];

                    foreach ($webHistoryAll as $entry) {
                        // Filtre domaine: contient la chaîne (insensible à la casse)
                        if ($filterDomain !== null && stripos($entry['domain'], $filterDomain) === false) {
                            continue;
                        }

                        // Filtre date: on tente de parser la date depuis le champ time
                        if ($filterDate !== null) {
                            $ts = strtotime($entry['time']);
                            if ($ts === false) {
                                continue;
                            }
                            if (date('Y-m-d', $ts) !== $filterDate) {
                                continue;
                            }
                        }

                        $webHistoryFiltered[] = $entry;
                    }

                    // Top domaines basé sur les entrées filtrées
                    $counts = [];
                    foreach ($webHistoryFiltered as $entry) {
                        $domain = $entry['domain'];
                        $parts = explode('.', $domain);
                        if (count($parts) > 2) {
                            $domain = implode('.', array_slice($parts, -2));
                        }
                        if (!isset($counts[$domain])) $counts[$domain] = 0;
                        $counts[$domain]++;
                    }
                    arsort($counts);
                    $topDomains = array_slice($counts, 0, 15, true);
                    ?>
                    <?php if (!empty($topDomains)): ?>
                        <div class="info-box">
                            <h3><i class="fas fa-chart-bar"></i> Sites les plus visités<?php echo $filterIp ? " par $filterIp" : ''; ?></h3>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php foreach ($topDomains as $domain => $count): ?>
                                    <span class="ip-tag" style="background: #e3f2fd; padding: 5px 10px; border-radius: 15px; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($domain); ?> 
                                        <small style="color: #666;">(<?php echo $count; ?>)</small>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Historique détaillé -->
                    <div class="info-box">
                        <h3><i class="fas fa-history"></i> Historique récent<?php echo $filterIp ? " pour $filterIp" : ''; ?></h3>
                        <?php $webHistory = array_slice($webHistoryFiltered, 0, 100); ?>
                        <?php if (!empty($webHistory)): ?>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <table class="devices-table" style="font-size: 0.85rem;">
                                    <thead>
                                        <tr>
                                            <th>Heure</th>
                                            <th>IP Source</th>
                                            <th>Domaine visité</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($webHistory as $entry): ?>
                                            <tr>
                                                <td style="white-space: nowrap;"><?php echo htmlspecialchars($entry['time']); ?></td>
                                                <td><?php echo htmlspecialchars($entry['ip']); ?></td>
                                                <td><?php echo htmlspecialchars($entry['domain']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="color: var(--gray);">
                                <i class="fas fa-info-circle"></i> Aucun historique disponible. 
                                <?php if (!$captureStatus['running']): ?>
                                    <a href="traitement_monitor.php?action=start">Démarrez la capture</a> pour commencer à enregistrer.
                                <?php else: ?>
                                    Attendez que des requêtes soient capturées.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Actions -->
                    <div style="margin-top: 15px; display: flex; gap: 10px;">
                        <a href="traitement_monitor.php?action=clear" class="btn btn-outline btn-sm" 
                           onclick="return confirm('Effacer tout l\'historique web ?');">
                            <i class="fas fa-trash"></i> Effacer historique web
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Le reste de ton code pour les autres sections... -->
    </div>
</body>

</html>