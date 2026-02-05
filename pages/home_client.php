
<?php
// Page client simple reprenant le même design que les autres pages
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Monitor | Espace Client</title>
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
                <span>Accès client</span>
            </div>
        </header>

        <nav class="nav-container">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="home_client.php" class="nav-link active">
                        <div class="nav-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <div class="nav-text">Espace client</div>
                            <div class="nav-description">Demandez l'autorisation d'accès</div>
                        </div>
                    </a>
                </li>
            </ul>
        </nav>

        <section class="content-section active">
            <div class="dashboard" style="grid-template-columns: 1fr;">
                <div class="main-content">
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-user-check"></i> Demande d'autorisation</h2>
                        </div>
                        <div class="card-body" style="text-align: center;">
                            <p style="color: var(--gray); margin-bottom: 20px;">Pour obtenir l'accès réseau, cliquez sur le bouton ci-dessous pour envoyer une demande d'autorisation.</p>
                            <a href="traitement_autoriser.php" class="btn btn-success btn-sm">
                                <i class="fas fa-paper-plane"></i> Demander autorisation
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </div>
</body>

</html>