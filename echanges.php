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

// Collection d'un joueur : ['Ahri' => 2, ...]
function cartesDe($pdo, $compteId) {
    $req = $pdo->prepare('SELECT champion, nombre FROM cartes WHERE compte_id = ? ORDER BY champion');
    $req->execute([$compteId]);
    return array_column($req->fetchAll(), 'nombre', 'champion');
}

// Message affiché après une action (gardé en session car on redirige pour éviter le double envoi au F5)
function terminer($message, $avec = '') {
    $_SESSION['message'] = $message;
    header('Location: echanges.php' . ($avec ? '?avec=' . (int) $avec : ''));
    exit;
}

$action = $_POST['action'] ?? '';
$idEchange = (int) ($_POST['echange'] ?? 0);

if ($action === 'proposer') {
    $autre = (int) ($_POST['autre'] ?? 0);
    $donnee = $_POST['donnee'] ?? '';
    $demandee = $_POST['demandee'] ?? '';
    // On revérifie tout ici : le formulaire peut avoir été modifié dans le navigateur
    if ($autre === $moi) terminer('Tu ne peux pas échanger avec toi-même.');
    if (!isset(cartesDe($pdo, $moi)[$donnee])) terminer('Tu ne possèdes pas la carte que tu veux donner.', $autre);
    if (!isset(cartesDe($pdo, $autre)[$demandee])) terminer('Ce joueur ne possède pas la carte demandée.', $autre);
    $pdo->prepare('INSERT INTO echanges (de_id, vers_id, carte_donnee, carte_demandee, cree) VALUES (?, ?, ?, ?, ?)')
        ->execute([$moi, $autre, $donnee, $demandee, time()]);
    terminer('Proposition envoyée !');
}

if ($action === 'accepter') {
    $pdo->beginTransaction();
    // On passe l'échange à "accepte" d'abord : s'il était déjà traité, rowCount = 0 et on s'arrête (pas de double échange)
    $req = $pdo->prepare("UPDATE echanges SET statut = 'accepte' WHERE id = ? AND vers_id = ? AND statut = 'attente'");
    $req->execute([$idEchange, $moi]);
    if ($req->rowCount() === 0) {
        $pdo->rollBack();
        terminer('Cet échange n\'existe plus ou a déjà été traité.');
    }
    $req = $pdo->prepare('SELECT * FROM echanges WHERE id = ?');
    $req->execute([$idEchange]);
    $e = $req->fetch();
    // Les deux cartes doivent encore être là (elles ont pu être échangées ailleurs entre-temps)
    if (!retirerCarte($pdo, $e['de_id'], $e['carte_donnee']) || !retirerCarte($pdo, $moi, $e['carte_demandee'])) {
        $pdo->rollBack(); // annule tout ce qui a été fait dans la transaction
        $pdo->prepare("UPDATE echanges SET statut = 'impossible' WHERE id = ?")->execute([$idEchange]);
        terminer('Échange impossible : une des deux cartes n\'est plus disponible.');
    }
    ajouterCarte($pdo, $moi, $e['carte_donnee']);
    ajouterCarte($pdo, $e['de_id'], $e['carte_demandee']);
    $pdo->commit();
    terminer('Échange réussi ! La carte est dans ta collection.');
}

if ($action === 'refuser') {
    $pdo->prepare("UPDATE echanges SET statut = 'refuse' WHERE id = ? AND vers_id = ? AND statut = 'attente'")
        ->execute([$idEchange, $moi]);
    terminer('Échange refusé.');
}

if ($action === 'annuler') {
    $pdo->prepare("UPDATE echanges SET statut = 'annule' WHERE id = ? AND de_id = ? AND statut = 'attente'")
        ->execute([$idEchange, $moi]);
    terminer('Proposition annulée.');
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$champions = listeChampions() ?? [];
$nom = fn($cle) => $champions[$cle]['name'] ?? $cle;
$image = fn($cle) => 'https://ddragon.leagueoflegends.com/cdn/img/champion/loading/' . urlencode($cle) . '_0.jpg';

$mesCartes = cartesDe($pdo, $moi);

// Les autres joueurs
$req = $pdo->prepare('SELECT id, pseudo FROM comptes WHERE id != ? ORDER BY pseudo');
$req->execute([$moi]);
$joueurs = array_column($req->fetchAll(), 'pseudo', 'id');
$avec = (int) ($_GET['avec'] ?? 0);
$sesCartes = isset($joueurs[$avec]) ? cartesDe($pdo, $avec) : null;

// Échanges en attente, reçus et envoyés, avec le pseudo de l'autre joueur
$liste = function ($colonneMoi, $colonneAutre, $statut) use ($pdo, $moi) {
    $req = $pdo->prepare("SELECT e.*, c.pseudo FROM echanges e JOIN comptes c ON c.id = e.$colonneAutre
        WHERE e.$colonneMoi = ? AND e.statut $statut ORDER BY e.cree DESC LIMIT 20");
    $req->execute([$moi]);
    return $req->fetchAll();
};
$recus = $liste('vers_id', 'de_id', "= 'attente'");
$envoyes = $liste('de_id', 'vers_id', "= 'attente'");
$req = $pdo->prepare("SELECT e.*, d.pseudo AS de_pseudo, v.pseudo AS vers_pseudo FROM echanges e
    JOIN comptes d ON d.id = e.de_id JOIN comptes v ON v.id = e.vers_id
    WHERE (e.de_id = ? OR e.vers_id = ?) AND e.statut != 'attente' ORDER BY e.cree DESC LIMIT 10");
$req->execute([$moi, $moi]);
$historique = $req->fetchAll();
$statuts = ['accepte' => 'Accepté', 'refuse' => 'Refusé', 'annule' => 'Annulé', 'impossible' => 'Impossible'];

// Petite carte affichée dans les listes d'échanges
$miniCarte = fn($cle) => '<span class="mini-carte"><img src="' . h($image($cle)) . '" alt="" width="54" height="98" loading="lazy"><span>' . h($nom($cle)) . '</span></span>';

// Grille de cartes à choisir (boutons radio)
function grilleChoix($nomChamp, $cartes, $nom, $image, $mesCartes = null) {
    $html = '';
    $premier = true;
    foreach ($cartes as $cle => $nombre) {
        $info = $nombre > 1 ? "×$nombre" : '';
        if ($mesCartes !== null) $info = isset($mesCartes[$cle]) ? 'Tu l\'as déjà' : 'Nouveau pour toi';
        $html .= '<label class="choix-carte"><input type="radio" name="' . $nomChamp . '" value="' . h($cle) . '"' . ($premier ? ' required' : '') . '>'
            . '<img src="' . h($image($cle)) . '" alt="" width="77" height="140" loading="lazy">'
            . '<span class="choix-nom">' . h($nom($cle)) . '</span>'
            . ($info ? '<span class="choix-info">' . h($info) . '</span>' : '') . '</label>';
        $premier = false;
    }
    return $html;
}
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Échanges — Runedex</title>
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
            <li><a class="menuItem" href="echanges.php" aria-current="page">Échanges</a></li>
            <li><a class="menuItem" href="expeditions.php">Expéditions</a></li>
            <li><a class="menuItem" href="duels.php">Duels</a></li>
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
    <main class="page-echanges">
        <h1>Échanges</h1>
        <?php if ($message): ?>
            <p class="message" role="status"><?= h($message) ?></p>
        <?php endif; ?>

        <?php if ($recus): ?>
        <section class="panneau bloc-echanges">
            <h2>Propositions reçues</h2>
            <?php foreach ($recus as $e): ?>
                <div class="echange">
                    <?= $miniCarte($e['carte_donnee']) ?>
                    <i class="material-icons fleche" aria-hidden="true">swap_horiz</i>
                    <?= $miniCarte($e['carte_demandee']) ?>
                    <p class="echange-texte"><strong><?= h($e['pseudo']) ?></strong> te donne <strong><?= h($nom($e['carte_donnee'])) ?></strong>
                        contre ton <strong><?= h($nom($e['carte_demandee'])) ?></strong><?= ($mesCartes[$e['carte_demandee']] ?? 0) === 1 ? ' (ton seul exemplaire)' : '' ?>.</p>
                    <form method="post" class="echange-actions">
                        <input type="hidden" name="echange" value="<?= $e['id'] ?>">
                        <button class="bouton bouton-or" name="action" value="accepter">Accepter</button>
                        <button class="bouton bouton-secondaire" name="action" value="refuser">Refuser</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="panneau bloc-echanges">
            <h2>Proposer un échange</h2>
            <?php if (!$joueurs): ?>
                <p>Il n'y a pas encore d'autre joueur. Invite un ami à créer un compte !</p>
            <?php elseif (!$mesCartes): ?>
                <p>Tu n'as pas encore de carte à échanger. <a href="booster.html">Ouvre un booster</a> d'abord.</p>
            <?php else: ?>
                <form method="get" class="choix-joueur">
                    <label for="avec">Échanger avec</label>
                    <select class="champ" id="avec" name="avec" onchange="this.form.submit()">
                        <option value="">Choisis un joueur…</option>
                        <?php foreach ($joueurs as $id => $pseudo): ?>
                            <option value="<?= $id ?>"<?= $id === $avec ? ' selected' : '' ?>><?= h($pseudo) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="bouton">Voir sa collection</button></noscript>
                </form>

                <?php if ($sesCartes !== null && !$sesCartes): ?>
                    <p><?= h($joueurs[$avec]) ?> n'a pas encore de carte.</p>
                <?php elseif ($sesCartes): ?>
                    <form method="post">
                        <input type="hidden" name="autre" value="<?= $avec ?>">
                        <div class="choix-colonnes">
                            <fieldset>
                                <legend>Tu donnes</legend>
                                <div class="grille-choix"><?= grilleChoix('donnee', $mesCartes, $nom, $image) ?></div>
                            </fieldset>
                            <fieldset>
                                <legend>Tu reçois de <?= h($joueurs[$avec]) ?></legend>
                                <div class="grille-choix"><?= grilleChoix('demandee', $sesCartes, $nom, $image, $mesCartes) ?></div>
                            </fieldset>
                        </div>
                        <button class="bouton bouton-or" name="action" value="proposer">Proposer l'échange</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($envoyes): ?>
        <section class="panneau bloc-echanges">
            <h2>Tes propositions en attente</h2>
            <?php foreach ($envoyes as $e): ?>
                <div class="echange">
                    <?= $miniCarte($e['carte_donnee']) ?>
                    <i class="material-icons fleche" aria-hidden="true">swap_horiz</i>
                    <?= $miniCarte($e['carte_demandee']) ?>
                    <p class="echange-texte">Tu proposes <strong><?= h($nom($e['carte_donnee'])) ?></strong> à <strong><?= h($e['pseudo']) ?></strong>
                        contre <strong><?= h($nom($e['carte_demandee'])) ?></strong>.</p>
                    <form method="post" class="echange-actions">
                        <input type="hidden" name="echange" value="<?= $e['id'] ?>">
                        <button class="bouton bouton-secondaire" name="action" value="annuler">Annuler</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?php if ($historique): ?>
        <section class="panneau bloc-echanges">
            <h2>Derniers échanges</h2>
            <ul class="historique">
                <?php foreach ($historique as $e): ?>
                    <li>
                        <span class="statut-echange statut-<?= h($e['statut']) ?>"><?= $statuts[$e['statut']] ?? h($e['statut']) ?></span>
                        <?= h($e['de_pseudo']) ?> : <?= h($nom($e['carte_donnee'])) ?> contre <?= h($nom($e['carte_demandee'])) ?> de <?= h($e['vers_pseudo']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </main>
    <script src="main.js"></script>
</body>
</html>
