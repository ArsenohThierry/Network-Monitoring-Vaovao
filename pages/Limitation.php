<?php
ini_set("display_errors",1);
error_reporting(E_ALL);
session_start();
include '../inc/limit_functions.php';


// Configuration
$interface = 'wlan0'; // À modifier selon votre interface
$default_download_limit = 8; // Mbit/s

// Initialisation des variables
$message = '';
$log_type = 'info';
$stats = [];
$is_limited = false;
$ip = isset($_GET['ip']) ? $_GET['ip'] : '';
$limit = isset($_POST['lim']) ? $_POST['lim'] : $default_download_limit;

// Messages provenant de traitement_limiter.php (si redirection)
if (isset($_GET['msg'])) {
    $message = urldecode($_GET['msg']);
    $log_type = (isset($_GET['success']) && $_GET['success'] === '1') ? 'success' : 'danger';
}

// Vérifier si l'IP courante est limitée via la fonction fournie
if (!empty($ip) && function_exists('checkIPStatus')) {
    $status = checkIPStatus($interface, $ip);
    $is_limited = $status['limited'];
}

// Initialiser la session pour stocker les IPs limitées si nécessaire
if (!isset($_SESSION['limited_ips'])) {
    $_SESSION['limited_ips'] = [];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Traffic Shaper</title>
    <link rel="stylesheet" href="../assets/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-network-wired"></i>
                <h1>Network Traffic Shaper</h1>
            </div>
            <div class="status-indicator">
                <div class="status-dot"></div>
                <span>Système actif</span>
            </div>
        </header>
        
        <main>
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-sliders-h"></i> Contrôle du débit</h2>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="info-box">
                            <h3><i class="fas fa-info-circle log-<?= $log_type ?>"></i> Notification</h3>
                            <p><?= htmlspecialchars($message) ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" action="traitement_limiter.php" class="form-group">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                            <div>
                                <label for="ip">Adresse IP cible</label>
                                <input type="text" id="ip" name="ip" class="form-control"
                                    value="<?= htmlspecialchars($ip) ?>"
                                    placeholder="Ex: 192.168.1.100" required>
                            </div>
                            <div>
                                <label for="lim">Limite de débit (Mbit/s)</label>
                                <input type="number" id="lim" name="lim" class="form-control"
                                    value="<?= htmlspecialchars($limit) ?>"
                                    min="1" step="1">
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button type="submit" name="action" value="limit" class="btn btn-warning">
                                <i class="fas fa-tachometer-alt"></i> Appliquer la limitation
                            </button>
                            <button type="submit" name="action" value="unlimit" class="btn btn-danger">
                                <i class="fas fa-unlock"></i> Supprimer la limitation
                            </button>
                            <button type="submit" name="action" value="stats" class="btn btn-outline">
                                <i class="fas fa-chart-bar"></i> Statistiques
                            </button>
                            <button type="submit" name="action" value="check" class="btn btn-outline">
                                <i class="fas fa-search"></i> Vérifier l'état
                            </button>
                            
                            <a href="../index.php">
                                <button type="button" class="btn btn-success">
                                    <i class="fas fa-arrow-left"></i> Retour au menu
                                </button>
                            </a>
                        </div>
                    </form>
                    
                    <?php if (!empty($_SESSION['limited_ips'])): ?>
                        <div class="info-box">
                            <h3><i class="fas fa-list"></i> IPs actuellement limitées</h3>
                            <div class="ip-list">
                                <?php foreach ($_SESSION['limited_ips'] as $limited_ip => $data): ?>
                                    <div class="ip-tag">
                                        <i class="fas fa-desktop"></i>
                                        <?= htmlspecialchars($limited_ip) ?>
                                        <span style="color: var(--gray); font-size: 0.8rem;">
                                            (↓<?= $data['download'] ?>M/↑<?= $data['upload'] ?>M)
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($stats)): ?>
                        <div class="info-box">
                            <h3><i class="fas fa-terminal"></i> Informations système</h3>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto;">
                                <?php foreach ($stats as $line): ?>
                                    <div><?= htmlspecialchars($line) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="grid">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-bolt"></i> Actions rapides</h2>
                    </div>
                    <div class="card-body">
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <button type="button" onclick="quickLimit(1)" class="btn btn-outline">
                                <i class="fas fa-tachometer-alt"></i> Limiter à 1 Mbit/s
                            </button>
                            <button type="button" onclick="quickLimit(5)" class="btn btn-outline">
                                <i class="fas fa-tachometer-alt"></i> Limiter à 5 Mbit/s
                            </button>
                            <button type="button" onclick="quickLimit(10)" class="btn btn-outline">
                                <i class="fas fa-tachometer-alt"></i> Limiter à 10 Mbit/s
                            </button>
                            <button type="button" onclick="clearAllLimits()" class="btn btn-danger">
                                <i class="fas fa-trash-alt"></i> Supprimer toutes les limites
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-tools"></i> Maintenance</h2>
                    </div>
                    <div class="card-body">
                        <form method="post" style="display: flex; flex-direction: column; gap: 10px;">
                            <button type="submit" name="action" value="init" class="btn btn-outline">
                                <i class="fas fa-cogs"></i> Initialiser le contrôle de trafic
                            </button>
                            <button type="submit" onclick="return confirm('Vider toutes les règles TC ?')" 
                                    name="action" value="clear" class="btn btn-outline">
                                <i class="fas fa-broom"></i> Vider toutes les règles
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </main>
        
        <div class="sidebar">
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-info-circle"></i> Informations système</h2>
                </div>
                <div class="card-body">
                    <div class="info-box">
                        <h3><i class="fas fa-network-wired"></i> Interface réseau</h3>
                        <p><strong><?= htmlspecialchars($interface) ?></strong></p>
                        <?php 
                        $ifconfig = executeCommand("ifconfig $interface 2>/dev/null | grep 'inet '");
                        if (!empty($ifconfig['output'])) {
                            echo '<p style="font-size: 12px; color: var(--gray); margin-top: 5px;">';
                            echo htmlspecialchars($ifconfig['output'][0]);
                            echo '</p>';
                        }
                        ?>
                    </div>
                    
                    <div class="info-box">
                        <h3><i class="fas fa-cogs"></i> État actuel</h3>
                        <p>
                            <span class="device-status <?= $is_limited ? 'status-limited' : 'status-connected' ?>">
                                <?= $is_limited ? 'Limitation active' : 'Sans limitation' ?>
                            </span>
                        </p>
                        <?php if ($is_limited && !empty($ip)): ?>
                            <p style="font-size: 12px; color: var(--gray); margin-top: 5px;">
                                IP limitée : <strong><?= htmlspecialchars($ip) ?></strong><br>
                                Débit : <strong><?= htmlspecialchars($limit) ?> Mbit/s</strong>
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-box">
                        <h3><i class="fas fa-history"></i> Dernières actions</h3>
                        <div class="log-list">
                            <div class="log-item">
                                <div class="log-time"><?= date('H:i:s') ?></div>
                                <div class="log-icon log-info">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                <div class="log-message">Système initialisé</div>
                            </div>
                            <?php if ($message): ?>
                                <div class="log-item">
                                    <div class="log-time"><?= date('H:i:s') ?></div>
                                    <div class="log-icon log-<?= $log_type ?>">
                                        <i class="fas fa-<?= 
                                            $log_type === 'success' ? 'check-circle' : 
                                            ($log_type === 'danger' ? 'exclamation-circle' : 
                                            ($log_type === 'warning' ? 'exclamation-triangle' : 'info-circle')) 
                                        ?>"></i>
                                    </div>
                                    <div class="log-message"><?= htmlspecialchars($message) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-lightbulb"></i> Aide</h2>
                </div>
                <div class="card-body">
                    <div class="info-box">
                        <h3><i class="fas fa-question-circle"></i> Comment utiliser</h3>
                        <p style="font-size: 0.9rem; margin-bottom: 10px;">
                            1. Entrez une adresse IP et une limite en Mbit/s<br>
                            2. Cliquez sur "Appliquer la limitation"<br>
                            3. Vérifiez l'état avec "Vérifier l'état"<br>
                            4. Consultez les statistiques si besoin
                        </p>
                        <p style="font-size: 0.8rem; color: var(--gray);">
                            <i class="fas fa-exclamation-triangle"></i> Requiert les droits sudo
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <footer>
        <p>Network Traffic Shaper &copy; <?= date('Y') ?> | Interface de contrôle de débit réseau</p>
        <p style="font-size: 12px; margin-top: 5px; color: #95a5a6;">
            Utilise <code>tc</code> pour le contrôle de trafic sous Linux
        </p>
    </footer>
    
    <script>
        // Fonction pour appliquer rapidement une limite
        function quickLimit(speed) {
            const ipInput = document.getElementById('ip');
            const limitInput = document.getElementById('lim');
            
            if (ipInput.value) {
                limitInput.value = speed;
                // Soumettre le formulaire
                document.querySelector('form').submit();
            } else {
                alert('Veuillez d\'abord entrer une adresse IP');
                ipInput.focus();
            }
        }
        
        // Fonction pour supprimer toutes les limites
        function clearAllLimits() {
            if (confirm('Supprimer toutes les limitations de débit ?')) {
                // Créer un formulaire temporaire
                const form = document.createElement('form');
                form.method = 'post';
                form.style.display = 'none';
                
                const ipField = document.getElementById('ip');
                if (ipField.value) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ip';
                    input.value = ipField.value;
                    form.appendChild(input);
                }
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'clear_all';
                form.appendChild(actionInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Auto-complétion pour les IPs courantes
        const commonIPs = ['192.168.1.', '192.168.0.', '10.0.0.'];
        const ipInput = document.getElementById('ip');
        
        ipInput.addEventListener('input', function() {
            const value = this.value;
            if (value.length > 0 && !value.includes('.', value.lastIndexOf('.') + 1)) {
                for (const ipPrefix of commonIPs) {
                    if (ipPrefix.startsWith(value)) {
                        this.value = ipPrefix;
                        this.setSelectionRange(value.length, ipPrefix.length);
                        break;
                    }
                }
            }
        });
        
        // Focus sur le champ IP au chargement
        window.onload = function() {
            if (!ipInput.value) {
                ipInput.focus();
            }
        };
    </script>
    
    <?php
    // Traitement des actions supplémentaires
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'init') {
            $results = initTrafficControl($interface);
            $message = "Contrôle de trafic initialisé";
            $log_type = 'success';
        } elseif ($action === 'clear') {
            executeCommand("sudo tc qdisc del dev $interface root 2>/dev/null");
            executeCommand("sudo tc qdisc del dev $interface ingress 2>/dev/null");
            $_SESSION['limited_ips'] = [];
            $message = "Toutes les règles ont été supprimées";
            $log_type = 'success';
        } elseif ($action === 'clear_all') {
            // Supprimer toutes les limites
            if (!empty($_SESSION['limited_ips'])) {
                foreach (array_keys($_SESSION['limited_ips']) as $limited_ip) {
                    unlimitIP($interface, $limited_ip);
                }
                $message = "Toutes les limitations ont été supprimées";
                $log_type = 'success';
            }
        }
    }
    ?>
</body>
</html>