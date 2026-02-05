<?php
ini_set("display_errors", 1);
error_reporting(E_ALL);
session_start();
include '../inc/functions.php';

$user = $_SESSION['user'] ?? null;
if (!$user || $user['roles'] !== 'ADMIN') {
    header('Location: ../login.php');
    exit;
}

// Récupérer les appareils RÉELS
$connectedDevices = getCurrentConnectedDevices();
$devices = [];

foreach ($connectedDevices as $index => $device) {
    $devices[] = [
        'id' => $index + 1,
        'name' => 'Appareil ' . $device['ip'],
        'ip' => $device['ip'],
        'mac' => $device['mac'],
        'status' => isIPBlocked($device['ip']) ? 'blocked' : 'connected'
    ];
}

// Statistiques
$connectedCount = count(array_filter($devices, fn($d) => $d['status'] === 'connected'));
$blockedCount = count(array_filter($devices, fn($d) => $d['status'] === 'blocked'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carte Réseau | Network Monitor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .network-container {
            display: flex;
            height: calc(100vh - 120px);
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }

        .network-sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            overflow-y: auto;
            border-right: 2px solid #3f51b5;
        }

        .network-canvas {
            flex: 1;
            position: relative;
            overflow: hidden;
        }

        /* Nœuds */
        .node {
            position: absolute;
            width: 70px;
            height: 70px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            z-index: 10;
            text-align: center;
        }

        .node:hover {
            transform: scale(1.15);
            z-index: 100;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
        }

        .node.internet {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            width: 80px;
            height: 80px;
        }

        .node.router {
            background: linear-gradient(135deg, #10b981, #047857);
            width: 75px;
            height: 75px;
        }

        .node.connected {
            background: linear-gradient(135deg, #0ea5e9, #0369a1);
        }

        .node.blocked {
            background: linear-gradient(135deg, #ef4444, #b91c1c);
            opacity: 0.8;
        }

        .node-icon {
            font-size: 20px;
            margin-bottom: 5px;
        }

        .node-name {
            font-size: 10px;
            max-width: 60px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .node-ip {
            font-size: 9px;
            opacity: 0.9;
            margin-top: 2px;
        }

        /* Connexions */
        .connection {
            position: absolute;
            height: 2px;
            background: rgba(255, 255, 255, 0.4);
            transform-origin: 0 0;
            z-index: 1;
        }

        .connection.router {
            background: linear-gradient(90deg, #10b981, rgba(16, 185, 129, 0.3));
        }

        .connection.blocked {
            background: linear-gradient(90deg, #ef4444, rgba(239, 68, 68, 0.3));
            opacity: 0.6;
        }

        /* Flux de données */
        .data-flow {
            position: absolute;
            width: 6px;
            height: 6px;
            background: #22c55e;
            border-radius: 50%;
            z-index: 5;
            pointer-events: none;
        }

        /* Animations */
        @keyframes pulse {
            0%, 100% { opacity: 0.4; }
            50% { opacity: 0.8; }
        }

        @keyframes flow {
            0% { transform: translateX(0) translateY(0); opacity: 1; }
            100% { transform: translateX(var(--tx)) translateY(var(--ty)); opacity: 0; }
        }

        /* Sidebar */
        .device-list {
            margin-top: 20px;
        }

        .device-item {
            display: flex;
            align-items: center;
            padding: 10px;
            margin-bottom: 8px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid #0ea5e9;
            transition: all 0.2s;
        }

        .device-item.blocked {
            border-left-color: #ef4444;
            opacity: 0.7;
        }

        .device-item:hover {
            background: #e0f2fe;
            transform: translateX(2px);
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 10px;
        }

        .connected-dot { background: #22c55e; }
        .blocked-dot { background: #ef4444; }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #1e293b;
        }

        .stat-label {
            font-size: 12px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-project-diagram"></i>
                <h1>Carte Réseau</h1>
            </div>
            <div class="status-indicator">
                <div class="status-dot"></div>
                <span><?php echo count($devices); ?> appareils détectés</span>
            </div>
        </header>

        <nav class="nav-container">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="home.php?section=view-devices" class="nav-link">
                        <i class="fas fa-arrow-left"></i> Retour
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" onclick="refreshNetwork()" class="nav-link">
                        <i class="fas fa-sync-alt"></i> Actualiser
                    </a>
                </li>
            </ul>
        </nav>

        <div class="network-container">
            <div class="network-sidebar">
                <h3><i class="fas fa-chart-network"></i> Vue d'ensemble</h3>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($devices); ?></div>
                        <div class="stat-label">Appareils</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $connectedCount; ?></div>
                        <div class="stat-label">Connectés</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $blockedCount; ?></div>
                        <div class="stat-label">Bloqués</div>
                    </div>
                </div>

                <div class="device-list">
                    <h4><i class="fas fa-list"></i> Appareils détectés</h4>
                    <?php foreach ($devices as $device): ?>
                        <div class="device-item <?php echo $device['status']; ?>" 
                             data-ip="<?php echo $device['ip']; ?>"
                             onclick="showDeviceInfo('<?php echo $device['ip']; ?>')">
                            <div class="status-dot <?php echo $device['status']; ?>-dot"></div>
                            <div style="flex: 1;">
                                <div><strong><?php echo htmlspecialchars($device['name']); ?></strong></div>
                                <div style="font-size: 11px; color: #64748b;">
                                    <?php echo htmlspecialchars($device['ip']); ?>
                                </div>
                            </div>
                            <div>
                                <?php if ($device['status'] === 'connected'): ?>
                                    <button class="btn btn-danger btn-sm" onclick="blockDevice('<?php echo $device['ip']; ?>', event)">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-success btn-sm" onclick="allowDevice('<?php echo $device['ip']; ?>', event)">
                                        <i class="fas fa-check"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
                    <button class="btn btn-outline btn-sm" onclick="toggleAnimations()">
                        <i class="fas fa-play"></i> Animations
                    </button>
                    <button class="btn btn-outline btn-sm" onclick="exportNetwork()">
                        <i class="fas fa-download"></i> Exporter
                    </button>
                </div>
            </div>

            <div class="network-canvas" id="networkCanvas">
                <!-- Nœuds et connexions générés par JS -->
            </div>
        </div>
    </div>

    <script>
        // Données réelles des appareils
        const devices = <?php echo json_encode($devices); ?>;
        let animationsEnabled = true;
        let flows = [];

        function initNetwork() {
            const canvas = document.getElementById('networkCanvas');
            canvas.innerHTML = '';
            flows = [];
            
            const width = canvas.clientWidth;
            const height = canvas.clientHeight;
            
            // 1. Internet (en haut au centre)
            createNode('Internet', 'WAN', 'internet', width / 2, 80);
            
            // 2. Routeur (centre de l'écran)
            createNode('Routeur', 'LAN', 'router', width / 2, height / 2);
            
            // 3. Appareils (en bas, organisés)
            const rows = Math.ceil(Math.sqrt(devices.length));
            const cols = Math.ceil(devices.length / rows);
            const cellWidth = width / (cols + 1);
            const cellHeight = (height - 300) / rows;
            
            devices.forEach((device, index) => {
                const row = Math.floor(index / cols);
                const col = index % cols;
                const x = (col + 1) * cellWidth;
                const y = height - 150 - (row * cellHeight);
                createNode(device.name, device.ip, device.status, x, y);
            });
            
            // Créer les connexions
            createConnections();
            
            // Démarrer les animations
            startAnimations();
        }

        function createNode(name, ip, type, x, y) {
            const canvas = document.getElementById('networkCanvas');
            const node = document.createElement('div');
            
            node.className = `node ${type}`;
            node.style.left = (x - 35) + 'px';
            node.style.top = (y - 35) + 'px';
            node.dataset.ip = ip;
            node.dataset.type = type;
            
            // Icône selon le type
            let icon = 'fa-desktop';
            if (type === 'router') icon = 'fa-router';
            if (type === 'internet') icon = 'fa-globe';
            if (type === 'blocked') icon = 'fa-ban';
            
            node.innerHTML = `
                <div class="node-icon"><i class="fas ${icon}"></i></div>
                <div class="node-name">${name}</div>
                <div class="node-ip">${ip}</div>
            `;
            
            // Info-bulle
            node.title = `${name}\nIP: ${ip}\nType: ${type}`;
            
            // Clic pour afficher les infos
            node.onclick = () => {
                if (type !== 'internet' && type !== 'router') {
                    const device = devices.find(d => d.ip === ip);
                    if (device) {
                        showDeviceInfo(ip);
                    }
                }
            };
            
            canvas.appendChild(node);
        }

        function createConnections() {
            const canvas = document.getElementById('networkCanvas');
            const nodes = canvas.querySelectorAll('.node');
            
            // Trouver le routeur
            const router = Array.from(nodes).find(n => n.dataset.type === 'router');
            const internet = Array.from(nodes).find(n => n.dataset.type === 'internet');
            
            if (!router || !internet) return;
            
            // Connexion Internet → Routeur
            createConnection(internet, router, 'router');
            
            // Connexions Routeur → Appareils
            nodes.forEach(node => {
                if (node.dataset.type === 'connected' || node.dataset.type === 'blocked') {
                    createConnection(router, node, node.dataset.type);
                }
            });
        }

        function createConnection(fromNode, toNode, type) {
            const canvas = document.getElementById('networkCanvas');
            const connection = document.createElement('div');
            
            const fromRect = fromNode.getBoundingClientRect();
            const toRect = toNode.getBoundingClientRect();
            const canvasRect = canvas.getBoundingClientRect();
            
            const x1 = fromRect.left + fromRect.width/2 - canvasRect.left;
            const y1 = fromRect.top + fromRect.height/2 - canvasRect.top;
            const x2 = toRect.left + toRect.width/2 - canvasRect.left;
            const y2 = toRect.top + toRect.height/2 - canvasRect.top;
            
            const length = Math.sqrt(Math.pow(x2 - x1, 2) + Math.pow(y2 - y1, 2));
            const angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
            
            connection.className = `connection ${type}`;
            connection.style.width = length + 'px';
            connection.style.left = x1 + 'px';
            connection.style.top = y1 + 'px';
            connection.style.transform = `rotate(${angle}deg)`;
            connection.style.opacity = '0.7';
            
            canvas.appendChild(connection);
        }

        function startAnimations() {
            if (!animationsEnabled) return;
            
            const canvas = document.getElementById('networkCanvas');
            const nodes = canvas.querySelectorAll('.node');
            const router = Array.from(nodes).find(n => n.dataset.type === 'router');
            const internet = Array.from(nodes).find(n => n.dataset.type === 'internet');
            
            if (!router || !internet) return;
            
            // Animer un flux Internet → Routeur
            createFlow(internet, router, '#3b82f6');
            
            // Animer des flux Routeur → Appareils connectés
            nodes.forEach(node => {
                if (node.dataset.type === 'connected') {
                    createFlow(router, node, '#22c55e');
                }
            });
        }

        function createFlow(fromNode, toNode, color) {
            const canvas = document.getElementById('networkCanvas');
            const flow = document.createElement('div');
            
            const fromRect = fromNode.getBoundingClientRect();
            const toRect = toNode.getBoundingClientRect();
            const canvasRect = canvas.getBoundingClientRect();
            
            const x1 = fromRect.left + fromRect.width/2 - canvasRect.left;
            const y1 = fromRect.top + fromRect.height/2 - canvasRect.top;
            const x2 = toRect.left + toRect.width/2 - canvasRect.left;
            const y2 = toRect.top + toRect.height/2 - canvasRect.top;
            
            flow.className = 'data-flow';
            flow.style.background = color;
            flow.style.left = x1 + 'px';
            flow.style.top = y1 + 'px';
            flow.style.setProperty('--tx', (x2 - x1) + 'px');
            flow.style.setProperty('--ty', (y2 - y1) + 'px');
            flow.style.animation = `flow 2s linear infinite`;
            
            canvas.appendChild(flow);
            flows.push(flow);
            
            setTimeout(() => {
                if (flow.parentNode) {
                    flow.remove();
                    flows = flows.filter(f => f !== flow);
                }
            }, 2000);
        }

        // Actions
        function showDeviceInfo(ip) {
            const device = devices.find(d => d.ip === ip);
            if (device) {
                alert(
                    `📱 Appareil: ${device.name}\n` +
                    `📍 IP: ${device.ip}\n` +
                    `🔑 MAC: ${device.mac}\n` +
                    `📊 Statut: ${device.status === 'connected' ? '✅ Connecté' : '❌ Bloqué'}`
                );
            }
        }

        function blockDevice(ip, event) {
            event.stopPropagation();
            if (confirm(`Bloquer l'appareil ${ip} ?`)) {
                window.location.href = `traitement_bloquer.php?ip=${encodeURIComponent(ip)}&redirect=network-map.php`;
            }
        }

        function allowDevice(ip, event) {
            event.stopPropagation();
            if (confirm(`Autoriser l'appareil ${ip} ?`)) {
                window.location.href = `traitement_autoriser.php?ip=${encodeURIComponent(ip)}&redirect=network-map.php`;
            }
        }

        function refreshNetwork() {
            location.reload();
        }

        function toggleAnimations() {
            animationsEnabled = !animationsEnabled;
            const btn = event.target.closest('button');
            btn.innerHTML = `<i class="fas fa-${animationsEnabled ? 'pause' : 'play'}"></i> Animations`;
            
            if (!animationsEnabled) {
                flows.forEach(flow => flow.remove());
                flows = [];
            } else {
                startAnimations();
            }
        }

        function exportNetwork() {
            html2canvas(document.querySelector('.network-canvas')).then(canvas => {
                const link = document.createElement('a');
                link.download = 'carte-reseau-' + new Date().toISOString().slice(0,10) + '.png';
                link.href = canvas.toDataURL();
                link.click();
            });
        }

        // Redimensionnement
        window.addEventListener('resize', initNetwork);
        document.addEventListener('DOMContentLoaded', initNetwork);
        
        // Rafraîchissement automatique
        setInterval(refreshNetwork, 60000); // 1 minute
    </script>
    
    <!-- Pour l'export d'image -->
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
</body>
</html>