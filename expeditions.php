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

// Message gardé en session car on recharge la page après chaque action (évite le double envoi au F5)
function terminer($message) {
    $_SESSION['message'] = $message;
    header('Location: expeditions.php');
    exit;
}

// Les royaumes de Runeterra = destinations des expéditions.
// x, y = position sur la carte (en % de sa largeur / hauteur), icone = Material Icons.
// La destination ne change pas la récompense : c'est la durée choisie qui compte.
const ROYAUMES = [
    'freljord'   => ['nom' => 'Freljord',       'x' => 43, 'y' => 16, 'icone' => 'ac_unit',          'texte' => 'Toundra glacée où les tribus se disputent le trône.'],
    'demacia'    => ['nom' => 'Demacia',        'x' => 25, 'y' => 40, 'icone' => 'shield',           'texte' => 'Royaume fier et ordonné qui pourchasse la magie.'],
    'noxus'      => ['nom' => 'Noxus',          'x' => 54, 'y' => 38, 'icone' => 'military_tech',    'texte' => 'Empire conquérant où seule la force compte.'],
    'piltover'   => ['nom' => 'Piltover',       'x' => 69, 'y' => 41, 'icone' => 'settings',         'texte' => 'Cité du progrès et des inventions hextech.'],
    'zaun'       => ['nom' => 'Zaun',           'x' => 64, 'y' => 55, 'icone' => 'science',          'texte' => 'Ville basse toxique, sous les pieds de Piltover.'],
    'ionia'      => ['nom' => 'Ionia',          'x' => 87, 'y' => 32, 'icone' => 'spa',              'texte' => 'Terres sacrées où la magie coule partout.'],
    'bandle'     => ['nom' => 'Bandle',         'x' => 93, 'y' => 54, 'icone' => 'auto_awesome',     'texte' => 'Cité cachée des yordles, très difficile à trouver.'],
    'targon'     => ['nom' => 'Targon',         'x' => 20, 'y' => 73, 'icone' => 'landscape',        'texte' => 'Montagne sacrée dont le sommet touche les étoiles.'],
    'shurima'    => ['nom' => 'Shurima',        'x' => 41, 'y' => 77, 'icone' => 'wb_sunny',         'texte' => 'Empire du désert qui renaît de ses ruines.'],
    'ixtal'      => ['nom' => 'Ixtal',          'x' => 53, 'y' => 91, 'icone' => 'forest',           'texte' => 'Jungle secrète des mages élémentaires.'],
    'neant'      => ['nom' => 'Le Néant',       'x' => 66, 'y' => 76, 'icone' => 'cyclone',          'texte' => 'Abîme affamé qui ronge Runeterra depuis Icathia.'],
    'bilgewater' => ['nom' => 'Bilgewater',     'x' => 83, 'y' => 75, 'icone' => 'sailing',          'texte' => 'Port de pirates et de chasseurs de monstres marins.'],
    'iles'       => ['nom' => 'Îles obscures',  'x' => 93, 'y' => 92, 'icone' => 'dark_mode',        'texte' => 'Archipel maudit, hanté par la Brume noire.'],
];

$action = $_POST['action'] ?? '';
$champion = (string) ($_POST['champion'] ?? '');

if ($action === 'envoyer') {
    $sorte = (string) ($_POST['sorte'] ?? '');
    $royaume = (string) ($_POST['royaume'] ?? '');
    if (!isset(EXPEDITIONS[$sorte])) terminer('Choisis une expédition.');
    if (!isset(ROYAUMES[$royaume])) terminer('Choisis un royaume sur la carte.');
    $objet = (string) ($_POST['objet'] ?? ''); // '' = pas d'objet
    if ($objet !== '') {
        $objetsJeu = listeObjets();
        if ($objetsJeu === null) terminer('Impossible de joindre Riot. Réessaie dans un instant.');
        if (($objetsJeu[$objet]['type'] ?? '') !== 'objets') terminer('Seul un objet complet peut partir en expédition.');
    }
    $pdo->beginTransaction();
    // L'objet quitte l'inventaire pendant l'expédition : il est rendu au retour
    if ($objet !== '' && !retirerObjet($pdo, $moi, $objet)) {
        $pdo->rollBack();
        terminer('Tu n\'as pas cet objet.');
    }
    // Une seule requête qui vérifie tout : le joueur a le champion, il reste une place,
    // et le champion n'est pas déjà parti (OR IGNORE + clé primaire compte_id/champion)
    $req = $pdo->prepare('INSERT OR IGNORE INTO expeditions (compte_id, champion, sorte, royaume, objet, fin)
        SELECT :id, :champion, :sorte, :royaume, :objet, :fin
        WHERE EXISTS (SELECT 1 FROM cartes WHERE compte_id = :id AND champion = :champion)
        AND (SELECT COUNT(*) FROM expeditions WHERE compte_id = :id) < ' . EXPEDITIONS_MAX);
    $req->bindValue('id', $moi, PDO::PARAM_INT);
    $req->bindValue('champion', $champion);
    $req->bindValue('sorte', $sorte);
    $req->bindValue('royaume', $royaume);
    $req->bindValue('objet', $objet);
    $req->bindValue('fin', time() + EXPEDITIONS[$sorte]['duree'], PDO::PARAM_INT);
    $req->execute();
    if ($req->rowCount() !== 1) {
        $pdo->rollBack(); // l'objet retiré revient dans l'inventaire
        terminer('Impossible : champion déjà parti, pas à toi, ou plus de place libre.');
    }
    avancerMission($pdo, $moi, 'expedition');
    $pdo->commit();
    terminer('C\'est parti pour ' . ROYAUMES[$royaume]['nom'] . ' ! Reviens quand l\'expédition est terminée.');
}

if ($action === 'recuperer') {
    $req = $pdo->prepare('SELECT sorte, objet FROM expeditions WHERE compte_id = ? AND champion = ? AND fin <= ?');
    $req->execute([$moi, $champion, time()]);
    $ligne = $req->fetch();
    $expedition = $ligne ? EXPEDITIONS[$ligne['sorte']] ?? null : null;
    if ($expedition === null) terminer('Rien à récupérer : l\'expédition n\'est pas terminée.');
    // Bonus si un objet complet est parti avec le champion
    $objet = $ligne['objet'];
    $rubis = $objet === '' ? $expedition['rubis'] : (int) round($expedition['rubis'] * (100 + BONUS_OBJET_RUBIS) / 100);
    $chanceSkin = $expedition['skin'] + ($objet === '' ? 0 : BONUS_OBJET_SKIN);
    // Composants ramenés : on prend la liste AVANT de supprimer l'expédition (si Riot ne répond pas, rien n'est perdu)
    $composants = [];
    if ($expedition['composants'] || $objet !== '') {
        $objets = listeObjets();
        if ($objets === null) terminer('Impossible de joindre Riot. Réessaie dans un instant.');
    }
    if ($expedition['composants']) {
        $tirables = array_filter($objets, fn($objet) => $objet['type'] === 'composants');
        for ($i = 0; $i < $expedition['composants']; $i++) {
            $composants[] = (string) array_rand($tirables);
        }
    }
    // Skin : on cherche ceux du champion qu'on n'a pas encore, puis on tire la chance
    $skins = skinsChampion($champion);
    if ($skins === null) terminer('Impossible de joindre Riot. Réessaie dans un instant.');
    $req = $pdo->prepare('SELECT num FROM skins_debloques WHERE compte_id = ? AND champion = ?');
    $req->execute([$moi, $champion]);
    $manquants = array_diff_key($skins, array_flip($req->fetchAll(PDO::FETCH_COLUMN)));
    $skin = $manquants && random_int(1, 100) <= $chanceSkin ? array_rand($manquants) : null;

    $pdo->beginTransaction();
    // On supprime d'abord : si la récompense a déjà été prise (double clic), rowCount = 0 et on s'arrête
    $req = $pdo->prepare('DELETE FROM expeditions WHERE compte_id = ? AND champion = ? AND fin <= ?');
    $req->execute([$moi, $champion, time()]);
    if ($req->rowCount() === 0) {
        $pdo->rollBack();
        terminer('Récompense déjà récupérée.');
    }
    $pdo->prepare('UPDATE comptes SET rubis = rubis + ?, expeditions_finies = expeditions_finies + 1 WHERE id = ?')->execute([$rubis, $moi]);
    if ($objet !== '') {
        ajouterObjet($pdo, $moi, $objet, 'objets'); // l'objet revient dans l'inventaire
    }
    foreach ($composants as $cle) {
        ajouterObjet($pdo, $moi, $cle, 'composants');
    }
    if ($skin !== null) {
        $pdo->prepare('INSERT OR IGNORE INTO skins_debloques (compte_id, champion, num) VALUES (?, ?, ?)')->execute([$moi, $champion, $skin]);
    }
    $pdo->commit();
    $noms = array_map(fn($cle) => $objets[$cle]['name'], $composants);
    terminer("Retour d'expédition : +$rubis rubis" . ($objet !== '' ? ' (bonus de ' . $objets[$objet]['name'] . ')' : '')
        . ($noms ? ' et ' . implode(', ', $noms) : '') . ' !'
        . ($skin !== null ? " Skin trouvé : {$skins[$skin]} ! Équipe-le depuis ta collection." : ''));
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$champions = listeChampions() ?? [];
$nom = fn($cle) => $champions[$cle]['name'] ?? $cle;
$image = fn($cle) => 'https://ddragon.leagueoflegends.com/cdn/img/champion/loading/' . urlencode($cle) . '_0.jpg';

$req = $pdo->prepare('SELECT champion, sorte, royaume, objet, fin FROM expeditions WHERE compte_id = ? ORDER BY fin');
$req->execute([$moi]);
$enCours = $req->fetchAll();
$partis = array_column($enCours, 'champion');
$icone = fn($cle) => 'https://ddragon.leagueoflegends.com/cdn/' . VERSION . '/img/champion/' . urlencode($cle) . '.png';

// Champions qu'on peut envoyer : ceux qu'on possède et qui ne sont pas déjà partis
$req = $pdo->prepare('SELECT champion FROM cartes WHERE compte_id = ? ORDER BY champion');
$req->execute([$moi]);
$disponibles = array_diff($req->fetchAll(PDO::FETCH_COLUMN), $partis);

// Objets complets qu'on peut emporter : ['3031' => 1, ...]
$req = $pdo->prepare("SELECT objet, nombre FROM objets_debloques WHERE compte_id = ? AND type = 'objets'");
$req->execute([$moi]);
$mesObjets = array_column($req->fetchAll(), 'nombre', 'objet');
// Noms des objets : on ne charge la liste de Riot que si on en a besoin
$objetsJeu = $mesObjets || array_filter(array_column($enCours, 'objet')) ? listeObjets() ?? [] : [];
$nomObjet = fn($cle) => $objetsJeu[$cle]['name'] ?? 'Objet';
$iconeObjet = fn($cle) => 'https://ddragon.leagueoflegends.com/cdn/' . VERSION . '/img/item/' . urlencode($cle) . '.png';

// Texte de la récompense, ex. "60 rubis + 1 composant"
$texteRecompense = fn($e) => $e['rubis'] . ' rubis' . ($e['composants'] ? " + {$e['composants']} composant" . ($e['composants'] > 1 ? 's' : '') : '')
    . " · {$e['skin']} % de chance de skin";
$texteDuree = fn($secondes) => ($secondes / 3600) . ' h';
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expéditions — Runedex</title>
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
            <li><a class="menuItem" href="expeditions.php" aria-current="page">Expéditions</a></li>
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
    <main class="page-echanges page-expeditions">
        <h1>Expéditions</h1>
        <?php if ($message): ?>
            <p class="message" role="status"><?= h($message) ?></p>
        <?php endif; ?>

        <?php if ($enCours): ?>
        <section class="panneau bloc-echanges">
            <h2>En cours <small><?= count($enCours) ?>/<?= EXPEDITIONS_MAX ?></small></h2>
            <?php foreach ($enCours as $e): $exp = EXPEDITIONS[$e['sorte']]; $fini = $e['fin'] <= time(); ?>
                <div class="expedition">
                    <span class="mini-carte"><img src="<?= h($image($e['champion'])) ?>" alt="" width="54" height="98" loading="lazy"><span><?= h($nom($e['champion'])) ?></span></span>
                    <p class="echange-texte"><strong><?= h($exp['nom']) ?></strong><?= isset(ROYAUMES[$e['royaume']]) ? ' · ' . h(ROYAUMES[$e['royaume']]['nom']) : '' ?> · <?= $texteRecompense($exp) ?>
                        <?php if ($e['objet'] !== ''): ?>
                            <span class="objet-emporte"><img src="<?= h($iconeObjet($e['objet'])) ?>" alt="" width="20" height="20">avec <?= h($nomObjet($e['objet'])) ?> (+<?= BONUS_OBJET_RUBIS ?> % rubis, +<?= BONUS_OBJET_SKIN ?> % skin)</span>
                        <?php endif; ?><br>
                        <span class="retour" data-reste="<?= $e['fin'] - time() ?>"><?= $fini ? 'Terminée !' : 'Retour dans ' . ceil(($e['fin'] - time()) / 60) . ' min' ?></span></p>
                    <form method="post" class="echange-actions">
                        <input type="hidden" name="champion" value="<?= h($e['champion']) ?>">
                        <button class="bouton bouton-or" name="action" value="recuperer"<?= $fini ? '' : ' disabled' ?>>Récupérer</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="panneau bloc-echanges">
            <h2>Carte de Runeterra</h2>
            <?php $libres = EXPEDITIONS_MAX - count($enCours); ?>
            <p class="info-expedition">Clique sur un royaume pour y envoyer un champion (<?= $libres ?> place<?= $libres > 1 ? 's' : '' ?> libre<?= $libres > 1 ? 's' : '' ?>).</p>
            <div class="carte-defile">
                <div class="carte-monde">
                    <!-- Carte dessinée à la main (formes simples), les royaumes sont des boutons posés par-dessus -->
                    <svg viewBox="0 0 1000 620" aria-hidden="true" preserveAspectRatio="none">
                        <defs>
                            <radialGradient id="lueurNeant"><stop offset="0" stop-color="#9b5cff" stop-opacity=".55"/><stop offset="1" stop-color="#9b5cff" stop-opacity="0"/></radialGradient>
                            <radialGradient id="lueurIles"><stop offset="0" stop-color="#3fe0b0" stop-opacity=".45"/><stop offset="1" stop-color="#3fe0b0" stop-opacity="0"/></radialGradient>
                        </defs>
                        <rect class="mer" width="1000" height="620"/>
                        <g class="vagues">
                            <path d="M40 80 q15 -8 30 0 t30 0"/><path d="M760 60 q15 -8 30 0 t30 0"/><path d="M60 420 q15 -8 30 0 t30 0"/>
                            <path d="M740 380 q15 -8 30 0 t30 0"/><path d="M820 560 q15 -8 30 0 t30 0"/><path d="M30 600 q15 -8 30 0 t30 0"/>
                        </g>
                        <!-- Valoran (Freljord, Demacia, Noxus, Piltover, Zaun) -->
                        <path class="terre" d="M110 250 C95 180 140 120 210 105 C250 55 340 38 420 45 C500 32 600 42 660 75 C715 100 745 150 730 200 C750 240 740 290 705 310 C685 325 670 345 655 360 L625 360 C590 345 545 350 490 340 C430 362 365 345 305 352 C240 368 180 345 148 312 C120 292 106 272 110 250 Z"/>
                        <!-- Continent du sud (Shurima, Targon, Ixtal, Néant) -->
                        <path class="terre" d="M625 372 L660 372 C700 395 725 430 715 470 C740 510 720 565 660 585 C590 610 500 608 420 598 C330 608 245 592 190 565 C135 540 115 500 135 458 C155 420 205 402 260 398 C325 388 385 395 445 385 C520 380 580 378 625 372 Z"/>
                        <!-- Ionia, Bandle, Bilgewater, Îles obscures -->
                        <path class="terre" d="M805 115 C855 92 925 112 950 165 C970 215 945 278 905 300 C865 322 815 302 798 262 C782 222 775 145 805 115 Z"/>
                        <ellipse class="terre" cx="932" cy="342" rx="30" ry="16"/>
                        <path class="terre" d="M790 450 C815 428 862 438 868 462 C874 488 842 505 810 497 C784 490 774 466 790 450 Z"/>
                        <ellipse class="terre" cx="885" cy="505" rx="14" ry="8"/>
                        <ellipse class="terre" cx="760" cy="498" rx="10" ry="6"/>
                        <path class="terre" d="M888 562 C905 540 958 542 972 566 C982 592 945 608 915 601 C888 595 876 578 888 562 Z"/>
                        <!-- Frontières entre royaumes -->
                        <g class="frontiere">
                            <path d="M135 175 C260 150 420 168 520 150 C600 138 680 150 725 165"/>
                            <path d="M400 168 C385 220 420 290 395 348"/>
                            <path d="M620 158 C610 220 630 280 680 318"/>
                            <path d="M285 395 C270 450 300 520 262 592"/>
                            <path d="M590 378 C580 430 610 480 590 530"/>
                            <path d="M430 598 C460 560 560 540 640 535"/>
                        </g>
                        <!-- Le Néant et la Brume noire -->
                        <circle cx="660" cy="470" r="75" fill="url(#lueurNeant)"/>
                        <circle cx="930" cy="572" r="65" fill="url(#lueurIles)"/>
                        <!-- Rose des vents -->
                        <g class="rose" transform="translate(70 520)">
                            <path d="M0 -34 L7 0 L0 34 L-7 0 Z M-34 0 L0 -7 L34 0 L0 7 Z"/>
                            <text y="-40" text-anchor="middle">N</text>
                        </g>
                    </svg>
                    <?php foreach (ROYAUMES as $cle => $r):
                        $ici = array_filter($enCours, fn($e) => $e['royaume'] === $cle); ?>
                        <button class="royaume" style="--x:<?= $r['x'] ?>;--y:<?= $r['y'] ?>" data-royaume="<?= $cle ?>"
                            data-nom="<?= h($r['nom']) ?>" data-texte="<?= h($r['texte']) ?>" data-icone="<?= $r['icone'] ?>">
                            <span class="royaume-embleme"><i class="material-icons" aria-hidden="true"><?= $r['icone'] ?></i></span>
                            <span class="royaume-nom"><?= h($r['nom']) ?></span>
                            <?php if ($ici): ?>
                                <span class="royaume-partis">
                                    <?php foreach ($ici as $e): ?>
                                        <img class="<?= $e['fin'] <= time() ? 'fini' : '' ?>" src="<?= h($icone($e['champion'])) ?>" alt="<?= h($nom($e['champion'])) ?> est ici" width="24" height="24">
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>

    <!-- Envoi d'un champion dans le royaume cliqué (le royaume est rempli par le script) -->
    <dialog class="fiche-modale modale-expedition" id="modaleExpedition" aria-labelledby="titreRoyaume">
        <button class="fermer fermer-modale" id="fermerExpedition" aria-label="Fermer"><i class="material-icons">close</i></button>
        <div class="expedition-contenu">
            <div class="royaume-tete">
                <span class="royaume-embleme"><i class="material-icons" id="iconeRoyaume" aria-hidden="true"></i></span>
                <div>
                    <h2 id="titreRoyaume">Expédition</h2>
                    <p id="texteRoyaume"></p>
                </div>
            </div>
            <?php if ($libres <= 0): ?>
                <p>Tes <?= EXPEDITIONS_MAX ?> places sont occupées. Attends le retour d'un champion.</p>
            <?php elseif (!$disponibles): ?>
                <p>Aucun champion disponible. <a href="booster.html">Ouvre un booster</a> pour en obtenir.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="royaume" id="champRoyaume">
                    <fieldset class="choix-recyclage choix-expedition">
                        <legend>Durée</legend>
                        <?php $premiere = true; foreach (EXPEDITIONS as $cle => $exp): ?>
                            <label>
                                <input type="radio" name="sorte" value="<?= $cle ?>"<?= $premiere ? ' checked' : '' ?>>
                                <span><strong><?= h($exp['nom']) ?></strong> <?= $texteDuree($exp['duree']) ?> · <?= $texteRecompense($exp) ?></span>
                            </label>
                        <?php $premiere = false; endforeach; ?>
                    </fieldset>
                    <?php if ($mesObjets): ?>
                        <fieldset class="choix-recyclage choix-expedition choix-objet">
                            <legend>Objet emporté (facultatif) : +<?= BONUS_OBJET_RUBIS ?> % de rubis et +<?= BONUS_OBJET_SKIN ?> % de chance de skin. Il revient avec le champion.</legend>
                            <label><input type="radio" name="objet" value="" checked><span>Aucun</span></label>
                            <?php foreach ($mesObjets as $cle => $n): ?>
                                <label>
                                    <input type="radio" name="objet" value="<?= h($cle) ?>">
                                    <span><img src="<?= h($iconeObjet($cle)) ?>" alt="" width="22" height="22"><?= h($nomObjet($cle)) ?><?= $n > 1 ? " ×$n" : '' ?></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php else: ?>
                        <p class="info-expedition">Astuce : un objet complet emporté donne +<?= BONUS_OBJET_RUBIS ?> % de rubis et +<?= BONUS_OBJET_SKIN ?> % de chance de skin. <a href="objets.html">Fabrique-en un</a>.</p>
                    <?php endif; ?>
                    <p class="info-expedition">Pendant l'expédition, le champion est occupé : tu ne peux pas recycler ni échanger son dernier exemplaire.</p>
                    <div class="grille-choix">
                        <?php $premier = true; foreach ($disponibles as $cle): ?>
                            <label class="choix-carte"><input type="radio" name="champion" value="<?= h($cle) ?>"<?= $premier ? ' required' : '' ?>>
                                <img src="<?= h($image($cle)) ?>" alt="" width="77" height="140" loading="lazy">
                                <span class="choix-nom"><?= h($nom($cle)) ?></span></label>
                        <?php $premier = false; endforeach; ?>
                    </div>
                    <button class="bouton bouton-or bouton-envoyer" name="action" value="envoyer">Envoyer en expédition</button>
                </form>
            <?php endif; ?>
        </div>
    </dialog>

    <script>
        // Clic sur un royaume de la carte : on ouvre la fenêtre d'envoi pour ce royaume
        const modale = document.getElementById('modaleExpedition');
        document.querySelectorAll('.royaume').forEach(bouton => bouton.addEventListener('click', () => {
            document.getElementById('titreRoyaume').textContent = bouton.dataset.nom;
            document.getElementById('texteRoyaume').textContent = bouton.dataset.texte;
            document.getElementById('iconeRoyaume').textContent = bouton.dataset.icone;
            const champ = document.getElementById('champRoyaume');
            if (champ) champ.value = bouton.dataset.royaume;
            modale.showModal();
        }));
        document.getElementById('fermerExpedition').addEventListener('click', () => modale.close());
        modale.addEventListener('click', event => {
            if (event.target === modale) modale.close();
        });

        // Compte à rebours de chaque expédition ; à la fin on recharge pour activer "Récupérer".
        // Le serveur donne le temps restant (pas l'heure de fin) : marche même si l'horloge du PC est décalée.
        const retours = document.querySelectorAll('[data-reste]');
        retours.forEach(span => { span.fin = Date.now() + span.dataset.reste * 1000; });
        function majRetours() {
            retours.forEach(span => {
                const reste = span.fin - Date.now();
                if (reste <= 0 && span.textContent !== 'Terminée !') location.reload();
                const s = Math.ceil(Math.max(0, reste) / 1000);
                const h = Math.floor(s / 3600), min = Math.floor(s % 3600 / 60);
                span.textContent = reste <= 0 ? 'Terminée !'
                    : `Retour dans ${h ? h + ' h ' : ''}${min} min ${String(s % 60).padStart(2, '0')} s`;
            });
        }
        if (retours.length) setInterval(majRetours, 1000);
        majRetours();
    </script>
    <script src="main.js"></script>
</body>
</html>
