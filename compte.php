<?php
require 'commun.php';

$message = '';
$action = $_POST['action'] ?? '';
$pseudo = trim($_POST['pseudo'] ?? '');
$mdp = $_POST['mdp'] ?? '';

if ($action === 'deconnexion') {
    session_destroy();
    header('Location: compte.php');
    exit;
}

if ($action === 'inscription') {
    if (mb_strlen($pseudo) < 3 || mb_strlen($pseudo) > 20) {
        $message = 'Le pseudo doit faire entre 3 et 20 caractères.';
    } elseif (strlen($mdp) < 6) {
        $message = 'Le mot de passe doit faire au moins 6 caractères.';
    } else {
        $req = $pdo->prepare('SELECT 1 FROM comptes WHERE pseudo = ?');
        $req->execute([$pseudo]);
        if ($req->fetchColumn()) {
            $message = 'Ce pseudo est déjà pris.';
        } else {
            // password_hash : on ne stocke jamais le mot de passe en clair
            $req = $pdo->prepare('INSERT INTO comptes (pseudo, mdp) VALUES (?, ?)');
            $req->execute([$pseudo, password_hash($mdp, PASSWORD_DEFAULT)]);
            $action = 'connexion'; // on connecte directement après l'inscription
        }
    }
}

if ($action === 'connexion') {
    $req = $pdo->prepare('SELECT id, pseudo, mdp FROM comptes WHERE pseudo = ?');
    $req->execute([$pseudo]);
    $compte = $req->fetch();
    if ($compte && password_verify($mdp, $compte['mdp'])) {
        session_regenerate_id(true);
        $_SESSION['id'] = $compte['id'];
        $_SESSION['pseudo'] = $compte['pseudo'];
        header('Location: booster.html');
        exit;
    }
    $message = 'Pseudo ou mot de passe incorrect.';
}
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compte — Runedex</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&family=Dela+Gothic+One&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="entete">
        <a class="logo" href="booster.html">Runedex</a>
        <ul class="menu">
            <li><a class="menuItem" href="booster.html">Booster</a></li>
            <li><a class="menuItem" href="collection.php">Collection</a></li>
            <li><a class="menuItem" href="echanges.php">Échanges</a></li>
            <li><a class="menuItem" href="expeditions.php">Expéditions</a></li>
            <li><a class="menuItem" href="duels.php">Duels</a></li>
            <li><a class="menuItem" href="objets.html">Objets</a></li>
            <li><a class="menuItem" href="defis.php">Défis</a></li>
            <li><a class="menuItem" href="joueurs.php">Joueurs</a></li>
            <li><a class="menuItem" href="api.html">Rechercher un champion</a></li>
            <li><a class="menuItem" href="compte.php" aria-current="page">Compte</a></li>
        </ul>
        <button class="ChangementDeMode">Mode clair</button>
        <button class="hamburger" aria-label="Menu">
            <i class="menuIcon material-icons">menu</i>
            <i class="closeIcon material-icons">close</i>
        </button>
    </header>
    <main>
        <?php if ($message): ?>
            <p class="message" role="alert"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>

        <div class="formulaires">
        <?php if (isset($_SESSION['id'])): ?>
            <form class="panneau" method="post">
                <h1>Salut <?= htmlspecialchars($_SESSION['pseudo']) ?></h1>
                <p>Tu es connecté. Ta collection est sauvegardée sur ton compte.</p>
                <button class="bouton" name="action" value="deconnexion">Se déconnecter</button>
            </form>
        <?php else: ?>
            <form class="panneau" method="post">
                <h2>Connexion</h2>
                <label for="pseudoConnexion">Pseudo</label>
                <input class="champ" id="pseudoConnexion" name="pseudo" required autocomplete="username">
                <label for="mdpConnexion">Mot de passe</label>
                <input class="champ" id="mdpConnexion" type="password" name="mdp" required autocomplete="current-password">
                <button class="bouton" name="action" value="connexion">Se connecter</button>
            </form>
            <form class="panneau" method="post">
                <h2>Inscription</h2>
                <label for="pseudoInscription">Pseudo (3 à 20 caractères)</label>
                <input class="champ" id="pseudoInscription" name="pseudo" required minlength="3" maxlength="20" autocomplete="username">
                <label for="mdpInscription">Mot de passe (6 caractères min.)</label>
                <input class="champ" id="mdpInscription" type="password" name="mdp" required minlength="6" autocomplete="new-password">
                <button class="bouton" name="action" value="inscription">Créer mon compte</button>
            </form>
        <?php endif; ?>
        </div>
    </main>
    <script src="main.js"></script>
</body>
</html>
