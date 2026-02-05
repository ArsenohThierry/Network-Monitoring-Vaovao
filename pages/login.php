<?php
session_start();
// Minimal login page styled to match the rest of the dashboard
// Uses pages/traitement_login.php as backend
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Connexion - Network Monitoring</title>
    <link rel="stylesheet" href="../assets/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fa fa-server"></i>
                <h1>Network Monitoring</h1>
            </div>
            <div class="status-indicator">
                <div class="status-dot"></div>
                <div class="nav-text">Hotspot control</div>
            </div>
        </header>

        <main class="dashboard" style="margin-top:30px;">
            <div>
                <div class="card" style="max-width:480px;margin:0 auto;">
                    <div class="card-header">
                        <h2><i class="fa fa-lock"></i> Connexion</h2>
                    </div>
                    <div class="card-body">
                        <p style="color:var(--gray);margin-bottom:16px;">Veuillez vous identifier pour accéder au tableau de bord.</p>

                        <?php if (!empty($_SESSION['login_error'])): ?>
                            <div class="info-box" style="background:#ffe6e6;color:#900;padding:8px;border-radius:6px;margin-bottom:10px;">
                                <?php echo htmlspecialchars($_SESSION['login_error']); unset($_SESSION['login_error']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($_GET['error']) && $_GET['error'] == 1) {
                            echo '<div class="info-box" style="background:#ffe6e6;color:#900;padding:8px;border-radius:6px;margin-bottom:10px;">Nom d\'utilisateur ou mot de passe incorrect.</div>';
                        }?>
                        <form method="post" action="traitement_login.php">
                            <div class="form-group">
                                <label for="username">Nom d'utilisateur</label>
                                <input id="username" name="username" class="form-control" type="text" autocomplete="username" required>
                            </div>

                            <div class="form-group">
                                <label for="password">Mot de passe</label>
                                <input id="password" name="password" class="form-control" type="password" autocomplete="current-password" required>
                            </div>
                            <div style="display:flex;gap:8px;">
                                <button type="submit" class="btn btn-success">Se connecter</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <aside style="width:350px;">
                <div class="info-box">
                    <h3><i class="fa fa-info-circle"></i> Infos</h3>
                    <p>Ce tableau de bord permet de gérer les appareils connectés, bloquer ou limiter l'accès réseau et consulter les logs.</p>
                    <p style="margin-top:8px;color:var(--gray);font-size:13px">Assurez-vous que <strong>tshark</strong>, <strong>tc</strong> et <strong>iptables</strong> sont installés et que le service systemd pour la capture est active si vous utilisez la surveillance web.</p>
                </div>
            </aside>
        </main>

        <footer>
            &copy; Network Monitoring - 2026
        </footer>
    </div>
</body>
</html>