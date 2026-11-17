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

// Les défis. "mesure" = ce qu'on compte (voir mesures()), "objectif" = nombre à atteindre.
// Pour les classes (Assassin, Mage...), objectif null = tous les champions de cette classe.
const DEFIS = [
    'champions10'   => ['nom' => 'Apprenti collectionneur', 'texte' => 'Posséder 10 champions différents',       'mesure' => 'champions',   'objectif' => 10,   'rubis' => 50],
    'champions50'   => ['nom' => 'Collectionneur',          'texte' => 'Posséder 50 champions différents',       'mesure' => 'champions',   'objectif' => 50,   'rubis' => 150],
    'champions100'  => ['nom' => 'Grand collectionneur',    'texte' => 'Posséder 100 champions différents',      'mesure' => 'champions',   'objectif' => 100,  'rubis' => 300],
    'assassins'     => ['nom' => 'Ombre parmi les ombres',  'texte' => 'Posséder tous les assassins',            'mesure' => 'Assassin',    'objectif' => null, 'rubis' => 200],
    'combattants'   => ['nom' => "Roi de l'arène",          'texte' => 'Posséder tous les combattants',          'mesure' => 'Fighter',     'objectif' => null, 'rubis' => 200],
    'mages'         => ['nom' => 'Archimage',               'texte' => 'Posséder tous les mages',                'mesure' => 'Mage',        'objectif' => null, 'rubis' => 200],
    'tireurs'       => ['nom' => 'Œil de lynx',             'texte' => 'Posséder tous les tireurs',              'mesure' => 'Marksman',    'objectif' => null, 'rubis' => 200],
    'supports'      => ['nom' => 'Ange gardien',            'texte' => 'Posséder tous les supports',             'mesure' => 'Support',     'objectif' => null, 'rubis' => 200],
    'tanks'         => ['nom' => 'Mur infranchissable',     'texte' => 'Posséder tous les tanks',                'mesure' => 'Tank',        'objectif' => null, 'rubis' => 200],
    'objets1'       => ['nom' => 'Premier objet',           'texte' => 'Posséder 1 objet complet',               'mesure' => 'objets',      'objectif' => 1,    'rubis' => 30],
    'objets10'      => ['nom' => 'Forgeron',                'texte' => 'Posséder 10 objets complets différents', 'mesure' => 'objets',      'objectif' => 10,   'rubis' => 150],
    'skins1'        => ['nom' => 'Nouvelle tenue',          'texte' => 'Débloquer 1 skin',                       'mesure' => 'skins',       'objectif' => 1,    'rubis' => 30],
    'skins10'       => ['nom' => 'Styliste',                'texte' => 'Débloquer 10 skins',                     'mesure' => 'skins',       'objectif' => 10,   'rubis' => 150],
    'expeditions5'  => ['nom' => 'Explorateur',             'texte' => 'Terminer 5 expéditions',                 'mesure' => 'expeditions', 'objectif' => 5,    'rubis' => 50],
    'expeditions25' => ['nom' => 'Aventurier',              'texte' => 'Terminer 25 expéditions',                'mesure' => 'expeditions', 'objectif' => 25,   'rubis' => 200],
    'duels1'        => ['nom' => 'Premier sang',            'texte' => 'Battre 1 bot en duel',                   'mesure' => 'duels',       'objectif' => 1,    'rubis' => 30],
    'duels10'       => ['nom' => "Champion de l'arène",     'texte' => 'Battre les 10 bots en duel',             'mesure' => 'duels',       'objectif' => PALIERS_DUEL, 'rubis' => 300],
    'echanges1'     => ['nom' => 'Poignée de main',         'texte' => 'Réussir 1 échange',                      'mesure' => 'echanges',    'objectif' => 1,    'rubis' => 30],
    'echanges10'    => ['nom' => 'Commerçant',              'texte' => 'Réussir 10 échanges',                    'mesure' => 'echanges',    'objectif' => 10,   'rubis' => 150],
];

// Ce que le joueur a déjà fait : ['champions' => 12, 'Mage' => 4, ...] et le nombre de champions par classe
function mesures($pdo, $id) {
    $compter = function ($sql) use ($pdo, $id) {
        $req = $pdo->prepare($sql);
        $req->execute(array_fill(0, substr_count($sql, '?'), $id));
        return (int) $req->fetchColumn();
    };
    $mesures = [
        'champions' => $compter('SELECT COUNT(*) FROM cartes WHERE compte_id = ?'),
        'objets' => $compter("SELECT COUNT(*) FROM objets_debloques WHERE compte_id = ? AND type = 'objets'"),
        'skins' => $compter('SELECT COUNT(*) FROM skins_debloques WHERE compte_id = ?'),
        'expeditions' => $compter('SELECT expeditions_finies FROM comptes WHERE id = ?'),
        'duels' => $compter('SELECT palier_duel - 1 FROM comptes WHERE id = ?'),
        'echanges' => $compter("SELECT COUNT(*) FROM echanges WHERE statut = 'accepte' AND (de_id = ? OR vers_id = ?)"),
    ];
    // Classes : on compte avec les rôles de Data Dragon (si Riot ne répond pas, ces défis sont indisponibles)
    $totaux = [];
    $champions = listeChampions();
    if ($champions !== null) {
        $req = $pdo->prepare('SELECT champion FROM cartes WHERE compte_id = ?');
        $req->execute([$id]);
        $possedes = array_flip($req->fetchAll(PDO::FETCH_COLUMN));
        foreach ($champions as $cle => $champion) {
            foreach ($champion['tags'] as $classe) {
                $totaux[$classe] = ($totaux[$classe] ?? 0) + 1;
                $mesures[$classe] = ($mesures[$classe] ?? 0) + (isset($possedes[$cle]) ? 1 : 0);
            }
        }
    }
    return [$mesures, $totaux];
}

// Où en est le joueur pour un défi : [actuel, objectif] (objectif null = indisponible)
function progres($defi, $mesures, $totaux) {
    return [$mesures[$defi['mesure']] ?? 0, $defi['objectif'] ?? ($totaux[$defi['mesure']] ?? null)];
}

// Récupère les défis réussis parmi $cles ; renvoie [nombre de défis, rubis gagnés]
function reclamer($pdo, $moi, $cles) {
    [$mesures, $totaux] = mesures($pdo, $moi);
    $nombre = 0;
    $gain = 0;
    $pdo->beginTransaction();
    // OR IGNORE + clé primaire : un défi ne rapporte qu'une seule fois, même avec un double clic
    $req = $pdo->prepare('INSERT OR IGNORE INTO defis_reussis (compte_id, defi) VALUES (?, ?)');
    foreach ($cles as $cle) {
        $defi = DEFIS[$cle] ?? null;
        [$actuel, $objectif] = $defi ? progres($defi, $mesures, $totaux) : [0, null];
        if ($objectif === null || $actuel < $objectif) continue; // pas encore réussi
        $req->execute([$moi, $cle]);
        if ($req->rowCount() === 1) {
            $nombre++;
            $gain += $defi['rubis'];
        }
    }
    $pdo->prepare('UPDATE comptes SET rubis = rubis + ? WHERE id = ?')->execute([$gain, $moi]);
    $pdo->commit();
    return [$nombre, $gain];
}

$action = $_POST['action'] ?? '';
if ($action === 'reclamer' || $action === 'tout') {
    $cle = (string) ($_POST['defi'] ?? '');
    [$nombre, $gain] = reclamer($pdo, $moi, $action === 'tout' ? array_keys(DEFIS) : [$cle]);
    if (!$nombre) {
        $_SESSION['message'] = 'Aucun défi à récupérer.';
    } elseif ($action === 'tout') {
        $_SESSION['message'] = "$nombre défi" . ($nombre > 1 ? 's' : '') . " récupéré" . ($nombre > 1 ? 's' : '') . " : +$gain rubis !";
    } else {
        $_SESSION['message'] = 'Défi « ' . DEFIS[$cle]['nom'] . " » réussi : +$gain rubis !";
    }
    header('Location: defis.php');
    exit;
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
[$mesures, $totaux] = mesures($pdo, $moi);
$req = $pdo->prepare('SELECT defi FROM defis_reussis WHERE compte_id = ?');
$req->execute([$moi]);
$reussis = array_flip($req->fetchAll(PDO::FETCH_COLUMN));
// Défis réussis mais pas encore récupérés (pour le bouton "Tout récupérer")
$prets = array_filter(DEFIS, function ($defi, $cle) use ($mesures, $totaux, $reussis) {
    [$actuel, $objectif] = progres($defi, $mesures, $totaux);
    return !isset($reussis[$cle]) && $objectif !== null && $actuel >= $objectif;
}, ARRAY_FILTER_USE_BOTH);
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Défis — Runedex</title>
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
            <li><a class="menuItem" href="defis.php" aria-current="page">Défis</a></li>
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
        <div class="album-tete">
            <h1>Défis</h1>
            <div class="stats panneau">
                <p class="stat"><strong><?= count($reussis) ?><small>/<?= count(DEFIS) ?></small></strong><span>défis réussis</span></p>
            </div>
        </div>
        <?php if ($message): ?>
            <p class="message" role="status"><?= h($message) ?></p>
        <?php endif; ?>
        <?php if (count($prets) > 1): ?>
            <form method="post" class="quotidien panneau">
                <i class="material-icons rubis-icone" aria-hidden="true">emoji_events</i>
                <p><strong><?= count($prets) ?> défis réussis</strong> à récupérer : +<?= array_sum(array_column($prets, 'rubis')) ?> rubis en tout.</p>
                <button class="bouton bouton-or" name="action" value="tout">Tout récupérer</button>
            </form>
        <?php endif; ?>
        <div class="grille-defis">
            <?php foreach (DEFIS as $cle => $defi):
                [$actuel, $objectif] = progres($defi, $mesures, $totaux);
                $fait = isset($reussis[$cle]);
                $pret = !$fait && $objectif !== null && $actuel >= $objectif;
                $pourcent = $objectif ? min(100, round(100 * $actuel / $objectif)) : 0;
            ?>
                <form method="post" class="defi panneau<?= $fait ? ' defi-fait' : '' ?><?= $pret ? ' defi-pret' : '' ?>">
                    <h2><?= h($defi['nom']) ?></h2>
                    <p class="defi-texte"><?= h($defi['texte']) ?></p>
                    <p class="defi-progres">
                        <span><?= $objectif === null ? 'Indisponible' : min($actuel, $objectif) . '/' . $objectif ?></span>
                        <span class="barre"><span style="width:<?= $fait ? 100 : $pourcent ?>%"></span></span>
                    </p>
                    <p class="defi-recompense"><i class="material-icons rubis-icone" aria-hidden="true">diamond</i>+<?= $defi['rubis'] ?> rubis</p>
                    <?php if ($fait): ?>
                        <p class="defi-statut"><i class="material-icons" aria-hidden="true">check_circle</i>Réussi</p>
                    <?php elseif ($pret): ?>
                        <input type="hidden" name="defi" value="<?= $cle ?>">
                        <button class="bouton bouton-or" name="action" value="reclamer">Récupérer</button>
                    <?php endif; ?>
                </form>
            <?php endforeach; ?>
        </div>
    </main>
    <script src="main.js"></script>
</body>
</html>
