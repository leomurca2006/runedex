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

$champions = listeChampions();
$objets = listeObjets();
$riotOk = $champions !== null && $objets !== null;

$req = $pdo->prepare('SELECT palier_duel, rubis FROM comptes WHERE id = ?');
$req->execute([$moi]);
$compte = $req->fetch();
$palier = (int) $compte['palier_duel'];
$termine = $palier > PALIERS_DUEL; // tous les bots battus : on peut rejouer le dernier pour le plaisir

if ($riotOk) {
    $bot = botDuPalier($moi, $palier, $champions, $objets);

    // Mes champions (avec leur skin équipé) et mes objets, avec de quoi calculer l'aperçu des stats en JS
    $req = $pdo->prepare('SELECT champion, skin FROM cartes WHERE compte_id = ? ORDER BY champion');
    $req->execute([$moi]);
    $mesCartes = array_filter($req->fetchAll(), fn($carte) => isset($champions[$carte['champion']]));
    $req = $pdo->prepare('SELECT objet, nombre FROM objets_debloques WHERE compte_id = ?');
    $req->execute([$moi]);
    $mesObjets = array_filter(array_column($req->fetchAll(), 'nombre', 'objet'), fn($nombre, $cle) => isset($objets[$cle]), ARRAY_FILTER_USE_BOTH);
    // Objets complets d'abord, puis les plus chers
    uksort($mesObjets, fn($a, $b) => [$objets[$a]['type'] !== 'objets', -$objets[$a]['gold']['total']] <=> [$objets[$b]['type'] !== 'objets', -$objets[$b]['gold']['total']]);
}

$image = fn($cle, $skin = 0) => 'https://ddragon.leagueoflegends.com/cdn/img/champion/loading/' . urlencode($cle) . '_' . (int) $skin . '.jpg';
$iconeObjet = fn($cle) => 'https://ddragon.leagueoflegends.com/cdn/' . VERSION . '/img/item/' . urlencode($cle) . '.png';
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duels — Runedex</title>
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
            <li><a class="menuItem" href="duels.php" aria-current="page">Duels</a></li>
            <li><a class="menuItem" href="objets.html">Objets</a></li>
            <li><a class="menuItem" href="defis.php">Défis</a></li>
            <li><a class="menuItem" href="joueurs.php">Joueurs</a></li>
            <li><a class="menuItem" href="api.html">Rechercher un champion</a></li>
            <li><a class="menuItem" href="compte.php">Compte</a></li>
        </ul>
        <button class="ChangementDeMode">Mode clair</button>
        <button class="hamburger" aria-label="Menu">
            <i class="menuIcon material-icons">menu</i>
            <i class="closeIcon material-icons">close</i>
        </button>
    </header>
    <main class="page-echanges page-duels">
        <div class="album-tete">
            <div>
                <h1>Duels</h1>
                <p class="info-expedition">Bats les <?= PALIERS_DUEL ?> bots, du plus faible au plus fort. Chaque bot battu pour la première fois rapporte des rubis.</p>
            </div>
            <div class="stats panneau">
                <p class="stat"><strong><?= min($palier, PALIERS_DUEL) ?><small>/<?= PALIERS_DUEL ?></small></strong><span>palier</span></p>
                <p class="stat stat-rubis"><strong><i class="material-icons rubis-icone" aria-hidden="true">diamond</i><?= $compte['rubis'] ?></strong><span>rubis</span></p>
            </div>
        </div>

        <?php if (!$riotOk): ?>
            <p class="message">Impossible de joindre Riot pour charger les champions. Recharge la page.</p>
        <?php elseif (!$mesCartes): ?>
            <p class="message">Il te faut au moins un champion. <a href="booster.html">Ouvre un booster</a> d'abord.</p>
        <?php else: ?>
        <div class="face-a-face">
            <section class="panneau combattant" id="coteJoueur">
                <h2>Ton combattant</h2>
                <img class="combattant-image" id="imageJoueur" src="dos-carte.svg" alt="" width="154" height="280">
                <p class="combattant-nom" id="nomJoueur">Choisis un champion</p>
                <dl class="stats-duel" id="statsJoueur"></dl>
            </section>
            <p class="vs" aria-hidden="true">VS</p>
            <section class="panneau combattant" id="coteBot" data-stats="<?= h(json_encode($bot['stats'])) ?>">
                <h2><?= $termine ? 'Bot final' : 'Bot du palier ' . $palier ?></h2>
                <img class="combattant-image" src="<?= h($image($bot['cle'])) ?>" alt="" width="154" height="280">
                <p class="combattant-nom"><?= h($bot['nom']) ?><?= $bot['bonus'] ? ' <span class="etiquette">+' . $bot['bonus'] . ' % de stats</span>' : '' ?></p>
                <p class="objets-bot">
                    <?php foreach ($bot['objets'] as $cle): ?>
                        <img src="<?= h($iconeObjet($cle)) ?>" alt="<?= h($objets[$cle]['name']) ?>" title="<?= h($objets[$cle]['name']) ?>" width="32" height="32">
                    <?php endforeach; ?>
                    <?php if (!$bot['objets']): ?>Aucun objet<?php endif; ?>
                </p>
                <dl class="stats-duel" id="statsBot"></dl>
                <p class="recompense-duel"><?= $termine
                    ? 'Tous les bots sont battus ! Tu peux rejouer celui-ci pour le plaisir.'
                    : '<i class="material-icons rubis-icone" aria-hidden="true">diamond</i>+' . RUBIS_PAR_PALIER * $palier . ' rubis si tu gagnes' ?></p>
            </section>
        </div>

        <form class="panneau formulaire-duel" id="formDuel">
            <fieldset>
                <legend>1. Choisis ton champion</legend>
                <div class="grille-choix">
                    <?php foreach ($mesCartes as $carte): $cle = $carte['champion']; ?>
                        <label class="choix-carte"><input type="radio" name="champion" value="<?= h($cle) ?>" required
                            data-nom="<?= h($champions[$cle]['name']) ?>" data-image="<?= h($image($cle, $carte['skin'])) ?>"
                            data-stats="<?= h(json_encode(statsChampion($champions[$cle]))) ?>">
                            <img src="<?= h($image($cle, $carte['skin'])) ?>" alt="" width="77" height="140" loading="lazy">
                            <span class="choix-nom"><?= h($champions[$cle]['name']) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset>
                <legend>2. Équipe jusqu'à <?= OBJETS_MAX_DUEL ?> objets <span id="compteurObjets">(0/<?= OBJETS_MAX_DUEL ?>)</span></legend>
                <?php if (!$mesObjets): ?>
                    <p class="info-expedition">Tu n'as pas encore d'objet. Achète des boosters Composants pour en obtenir, ça rend ton champion bien plus fort.</p>
                <?php endif; ?>
                <div class="grille-objets-duel">
                    <?php foreach ($mesObjets as $cle => $nombre): ?>
                        <label class="choix-objet-duel">
                            <input type="checkbox" name="objets[]" value="<?= h($cle) ?>" data-bonus="<?= h(json_encode(bonusObjet($objets[$cle]))) ?>">
                            <img src="<?= h($iconeObjet($cle)) ?>" alt="" width="40" height="40" loading="lazy">
                            <span><?= h($objets[$cle]['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <button class="bouton bouton-or" id="combattre" disabled>Combattre</button>
            <p class="info-expedition" id="infoDuel" aria-live="polite">Les objets ne sont pas consommés : tu les gardes après le combat.</p>
        </form>
        <?php endif; ?>
    </main>

    <!-- Le combat, rejoué coup par coup à partir de la réponse du serveur -->
    <dialog class="fiche-modale arene" id="arene" aria-labelledby="resultatDuel">
        <div class="arene-combat">
            <?php foreach (['areneJoueur', 'areneBot'] as $cote): ?>
                <div class="arene-cote" id="<?= $cote ?>">
                    <div class="arene-image"><img src="dos-carte.svg" alt="" width="154" height="280"></div>
                    <strong></strong>
                    <div class="barre-pv"><span></span></div>
                    <span class="pv-texte"></span>
                </div>
                <?php if ($cote === 'areneJoueur'): ?><p class="vs" aria-hidden="true">VS</p><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <p class="arene-resultat" id="resultatDuel" aria-live="polite"></p>
        <div class="actions" id="actionsArene" hidden>
            <button class="bouton bouton-or" id="continuer">Continuer</button>
        </div>
    </dialog>

    <script src="duels.js"></script>
    <script src="main.js"></script>
</body>
</html>
