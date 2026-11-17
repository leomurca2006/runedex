<?php
require 'commun.php';

if (!isset($_SESSION['id'])) {
    header('Location: compte.php');
    exit;
}
$moi = $_SESSION['id'];

function h($texte) {
    return htmlspecialchars($texte);
}

// Tous les joueurs avec leurs chiffres, classés par nombre de champions (puis de skins)
$joueurs = $pdo->query("SELECT c.id, c.pseudo,
    (SELECT COUNT(*) FROM cartes WHERE compte_id = c.id) AS champions,
    (SELECT COUNT(*) FROM skins_debloques WHERE compte_id = c.id) AS skins,
    (SELECT COUNT(*) FROM objets_debloques WHERE compte_id = c.id AND type = 'objets') AS objets,
    (SELECT COUNT(*) FROM defis_reussis WHERE compte_id = c.id) AS defis
    FROM comptes c ORDER BY champions DESC, skins DESC, c.pseudo")->fetchAll();

// joueurs.php?id=3 : la collection de ce joueur
$id = (int) ($_GET['id'] ?? 0);
$profil = null;
foreach ($joueurs as $rang => $joueur) {
    if ((int) $joueur['id'] === $id) {
        $profil = $joueur + ['rang' => $rang + 1];
    }
}
if ($profil) {
    $req = $pdo->prepare('SELECT champion, nombre, skin FROM cartes WHERE compte_id = ? ORDER BY champion');
    $req->execute([$id]);
    $cartes = $req->fetchAll();
    $champions = listeChampions() ?? [];
}
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $profil ? h($profil['pseudo']) : 'Joueurs' ?> — Runedex</title>
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
            <li><a class="menuItem" href="joueurs.php" aria-current="page">Joueurs</a></li>
            <li><a class="menuItem" href="api.html">Rechercher un champion</a></li>
            <li><a class="menuItem" href="compte.php">Compte</a></li>
        </ul>
        <button class="ChangementDeMode">Mode clair</button>
        <button class="hamburger" aria-label="Menu">
            <i class="menuIcon material-icons">menu</i>
            <i class="closeIcon material-icons">close</i>
        </button>
    </header>
    <main class="page-echanges">
    <?php if ($profil): ?>
        <a class="lien-retour" href="joueurs.php"><i class="material-icons" aria-hidden="true">arrow_back</i>Tous les joueurs</a>
        <div class="album-tete">
            <h1><?= h($profil['pseudo']) ?></h1>
            <div class="stats panneau">
                <p class="stat"><strong>#<?= $profil['rang'] ?></strong><span>classement</span></p>
                <p class="stat"><strong><?= $profil['champions'] ?></strong><span>champions</span></p>
                <p class="stat"><strong><?= $profil['skins'] ?></strong><span>skins</span></p>
                <p class="stat"><strong><?= $profil['objets'] ?></strong><span>objets complets</span></p>
            </div>
        </div>
        <?php if ($id !== $moi): ?>
            <p><a class="bouton bouton-or bouton-lien" href="echanges.php?avec=<?= $id ?>">Proposer un échange</a></p>
        <?php endif; ?>
        <?php if (!$cartes): ?>
            <p class="message">Ce joueur n'a pas encore de carte.</p>
        <?php endif; ?>
        <div class="album">
            <?php foreach ($cartes as $carte): $cle = $carte['champion']; $nom = $champions[$cle]['name'] ?? $cle; ?>
                <div class="carte">
                    <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/loading/<?= urlencode($cle) ?>_<?= (int) $carte['skin'] ?>.jpg" alt="<?= h($nom) ?>" width="154" height="280" loading="lazy">
                    <h4><?= h($nom) ?></h4>
                    <p><?= h($champions[$cle]['title'] ?? '') ?></p>
                    <?php if ($carte['nombre'] > 1): ?><span class="badge" aria-label="<?= $carte['nombre'] ?> exemplaires">×<?= $carte['nombre'] ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <h1>Joueurs</h1>
        <section class="panneau bloc-echanges">
            <h2>Classement</h2>
            <table class="classement">
                <thead>
                    <tr><th>#</th><th>Joueur</th><th>Champions</th><th>Skins</th><th>Objets complets</th><th>Défis</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($joueurs as $rang => $joueur): ?>
                        <tr<?= (int) $joueur['id'] === $moi ? ' class="moi"' : '' ?>>
                            <td><?= $rang + 1 ?></td>
                            <td><a href="joueurs.php?id=<?= $joueur['id'] ?>"><?= h($joueur['pseudo']) ?></a><?= (int) $joueur['id'] === $moi ? ' (toi)' : '' ?></td>
                            <td><?= $joueur['champions'] ?></td>
                            <td><?= $joueur['skins'] ?></td>
                            <td><?= $joueur['objets'] ?></td>
                            <td><?= $joueur['defis'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
    </main>
    <script src="main.js"></script>
</body>
</html>
