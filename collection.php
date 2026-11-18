<?php
require 'commun.php';

if (!isset($_SESSION['id'])) {
    header('Location: compte.php');
    exit;
}

$action = $_POST['action'] ?? '';
// Après une action on revient sur le même album (normal ou holo)
$retour = 'collection.php' . (str_contains($_SERVER['HTTP_REFERER'] ?? '', 'album=holo') ? '?album=holo' : '');

// Équiper un skin : seulement le skin de base (0) ou un skin débloqué
if ($action === 'skin') {
    $cle = (string) ($_POST['cle'] ?? '');
    $req = $pdo->prepare('UPDATE cartes SET skin = :num WHERE compte_id = :id AND champion = :cle
        AND (:num = 0 OR EXISTS (SELECT 1 FROM skins_debloques WHERE compte_id = :id AND champion = :cle AND num = :num))');
    $req->bindValue('num', (int) ($_POST['num'] ?? 0), PDO::PARAM_INT);
    $req->bindValue('id', $_SESSION['id'], PDO::PARAM_INT);
    $req->bindValue('cle', $cle);
    $req->execute();
    $_SESSION['message'] = $req->rowCount() ? 'Skin équipé !' : 'Tu n\'as pas débloqué ce skin.';
    header('Location: ' . $retour . '#carte-' . urlencode($cle));
    exit;
}

// Favori : on inverse l'état de la carte (0 ↔ 1)
if ($action === 'favori') {
    $cle = (string) ($_POST['cle'] ?? '');
    $pdo->prepare('UPDATE cartes SET favori = 1 - favori WHERE compte_id = ? AND champion = ?')->execute([$_SESSION['id'], $cle]);
    header('Location: ' . $retour . '#carte-' . urlencode($cle));
    exit;
}

// Étoile : on fusionne des doublons pour faire monter le champion
// (etoile_holo : étoiles holo, payées uniquement avec des exemplaires holo)
if ($action === 'etoile' || $action === 'etoile_holo') {
    $cle = (string) ($_POST['cle'] ?? '');
    $holo = $action === 'etoile_holo';
    $_SESSION['message'] = ameliorerCarte($pdo, $_SESSION['id'], $cle, $holo)
        ? ($holo ? 'Une étoile holo de plus !' : 'Une étoile de plus !')
        : ($holo ? 'Pas assez de doublons holo.' : 'Pas assez de doublons.');
    header('Location: ' . $retour . '#carte-' . urlencode($cle));
    exit;
}

// Améliorer tout : on monte chaque champion d'autant d'étoiles que ses doublons le permettent
if ($action === 'etoile_tout' || $action === 'etoile_holo_tout') {
    $holo = $action === 'etoile_holo_tout';
    $req = $pdo->prepare('SELECT champion FROM cartes WHERE compte_id = ?');
    $req->execute([$_SESSION['id']]);
    $gagnees = 0;
    foreach ($req->fetchAll(PDO::FETCH_COLUMN) as $cle) {
        while (ameliorerCarte($pdo, $_SESSION['id'], $cle, $holo)) $gagnees++;
    }
    $_SESSION['message'] = $gagnees ? "+$gagnees étoile" . ($gagnees > 1 ? 's' : '') . ($holo ? ' holo' : '') . ' !' : 'Pas assez de doublons.';
    header('Location: ' . $retour);
    exit;
}

// Recyclage : on traite le formulaire puis on recharge la page (évite de recycler 2 fois avec F5)
if ($action === 'recycler' || $action === 'doublons') {
    $gain = $action === 'doublons'
        ? recyclerDoublons($pdo, $_SESSION['id'], $_POST['type'] ?? 'champions')
        : recyclerCarte($pdo, $_SESSION['id'], $_POST['cle'] ?? '');
    $_SESSION['message'] = $gain ? "+$gain rubis !" : 'Rien à recycler.';
    header('Location: ' . $retour . ($action === 'recycler' ? '#carte-' . urlencode($_POST['cle'] ?? '') : ''));
    exit;
}
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$rubis = etatRubis($pdo, $_SESSION['id']);

$req = $pdo->prepare('SELECT champion, nombre, skin, favori, etoiles, holo, etoiles_holo FROM cartes WHERE compte_id = ? ORDER BY champion');
$req->execute([$_SESSION['id']]);
$lignes = $req->fetchAll();
$possedes = array_column($lignes, 'nombre', 'champion'); // ['Ahri' => 2, ...]
$skinsEquipes = array_column($lignes, 'skin', 'champion'); // ['Ahri' => 0, ...]
$favoris = array_filter(array_column($lignes, 'favori', 'champion')); // ['Ahri' => 1, ...] (seulement les favoris)
$etoiles = array_column($lignes, 'etoiles', 'champion'); // ['Ahri' => 3, ...]
$holos = array_filter(array_column($lignes, 'holo', 'champion')); // exemplaires holo : ['Ahri' => 3, ...] (seulement ceux qui en ont)
$etoilesHolo = array_column($lignes, 'etoiles_holo', 'champion'); // ['Ahri' => 2, ...]
$vueHolo = ($_GET['album'] ?? '') === 'holo'; // collection.php?album=holo : l'album holo
// Skins débloqués : ['Ahri' => [1, 4], ...]
$mesSkins = [];
$req = $pdo->prepare('SELECT champion, num FROM skins_debloques WHERE compte_id = ? ORDER BY num');
$req->execute([$_SESSION['id']]);
foreach ($req->fetchAll() as $ligne) {
    $mesSkins[$ligne['champion']][] = (int) $ligne['num'];
}
$req = $pdo->prepare('SELECT champion FROM expeditions WHERE compte_id = ?');
$req->execute([$_SESSION['id']]);
$partis = $req->fetchAll(PDO::FETCH_COLUMN); // champions en expédition

// Une case par champion du jeu ; si Riot ne répond pas, on n'affiche que les cartes possédées
$liste = listeChampions() ?? array_fill_keys(array_keys($possedes), []);
// Les favoris d'abord (array_merge garde la place de la 1re apparition de chaque clé)
$liste = array_merge(array_intersect_key($liste, $favoris), $liste);
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collection — Runedex</title>
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
            <li><a class="menuItem" href="collection.php" aria-current="page">Collection</a></li>
            <li><a class="menuItem" href="echanges.php">Échanges</a></li>
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
    <main>
        <?php
            $pourcent = round(100 * count($possedes) / max(1, count($liste)), 1);
            $doublons = array_sum($possedes) - count($possedes);
        ?>
        <div class="album-tete">
            <h1>Collection</h1>
            <div class="stats panneau">
                <p class="stat"><strong><?= count($possedes) ?><small>/<?= count($liste) ?></small></strong><span>champions uniques</span></p>
                <p class="stat stat-completion">
                    <strong><?= str_replace('.', ',', $pourcent) ?> %</strong><span>complétion</span>
                    <span class="barre"><span style="width:<?= $pourcent ?>%"></span></span>
                </p>
                <p class="stat"><strong><?= $doublons ?></strong><span>doublons</span></p>
                <p class="stat stat-rubis"><strong><i class="material-icons rubis-icone" aria-hidden="true">diamond</i><?= $rubis['rubis'] ?></strong><span>rubis</span></p>
            </div>
        </div>
        <?php if ($message): ?>
            <p class="message" role="status"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
        <?php
            // Suggestion automatique dès qu'il y a des doublons, avec le choix de ce qu'on recycle
            $parType = $rubis['doublonsParType'];
            $nomsTypes = ['champions' => 'Champions', 'composants' => 'Composants', 'objets' => 'Objets'];
            $typeChoisi = (string) ($_GET['type'] ?? '');
            if (empty($parType[$typeChoisi])) $typeChoisi = array_key_first(array_filter($parType)) ?? '';
        ?>
        <?php if (array_sum($parType)): ?>
            <form method="post" class="suggestion-recyclage panneau" id="recyclage">
                <i class="material-icons rubis-icone" aria-hidden="true">diamond</i>
                <fieldset class="choix-recyclage">
                    <legend>Tu as des doublons. Que veux-tu recycler ? (tu gardes 1 exemplaire de chaque, et tes exemplaires holo ne sont jamais recyclés)</legend>
                    <?php foreach ($nomsTypes as $type => $nomType): $n = $parType[$type]; ?>
                        <label>
                            <input type="radio" name="type" value="<?= $type ?>"<?= $type === $typeChoisi ? ' checked' : '' ?><?= $n ? '' : ' disabled' ?>>
                            <span><strong><?= $nomType ?></strong> <?= $n ?> en double · +<?= $n * RUBIS_RECYCLAGE[$type] ?> rubis</span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <button class="bouton bouton-or" name="action" value="doublons">Recycler les doublons</button>
            </form>
        <?php endif; ?>
        <?php if (!$possedes): ?>
            <p class="message">Ton album est vide. <a href="booster.html">Ouvre un booster</a> pour obtenir tes 5 premiers champions.</p>
        <?php endif; ?>
        <nav class="onglets-album" aria-label="Albums">
            <a href="collection.php"<?= $vueHolo ? '' : ' aria-current="page"' ?>>Album <small><?= count($possedes) ?>/<?= count($liste) ?></small></a>
            <a href="collection.php?album=holo"<?= $vueHolo ? ' aria-current="page"' : '' ?>>Album holo <small><?= count($holos) ?>/<?= count($liste) ?></small></a>
        </nav>
        <?php
            // Cartes qu'on peut améliorer tout de suite dans l'album affiché (mêmes règles que ameliorerCarte)
            $ameliorables = count(array_filter($lignes, fn($l) => $vueHolo
                ? $l['etoiles_holo'] < ETOILES_MAX && $l['holo'] - 1 >= coutEtoile($l['etoiles_holo'])
                : $l['etoiles'] < ETOILES_MAX && $l['nombre'] - $l['holo'] - 1 >= coutEtoile($l['etoiles'])));
        ?>
        <?php if ($ameliorables): ?>
            <form method="post">
                <button class="bouton bouton-or" name="action" value="<?= $vueHolo ? 'etoile_holo_tout' : 'etoile_tout' ?>">
                    Améliorer tout<?= $vueHolo ? ' (holo)' : '' ?> · <?= $ameliorables ?> carte<?= $ameliorables > 1 ? 's' : '' ?>
                </button>
            </form>
        <?php endif; ?>
        <?php if ($vueHolo): ?>
            <p class="info-expedition">Chaque carte d'un booster holographique (1 chance sur <?= CHANCE_HOLO ?>, 1 sur <?= CHANCE_HOLO_SAISON ?> avec le paquet de saison) débloque la version holo du champion.</p>
        <?php endif; ?>
        <div class="recherche recherche-objets">
            <input class="champ" type="search" id="rechercheChampion" placeholder="Chercher un champion, ex. Ah" aria-label="Chercher un champion" autocomplete="off">
        </div>
        <div class="album" id="album">
        <?php foreach ($liste as $cle => $champ):
            $nom = $champ['name'] ?? $cle;
            $nb = $possedes[$cle] ?? 0;
            $e = (int) ($etoiles[$cle] ?? 1);
            $estHolo = isset($holos[$cle]);
            if ($vueHolo && !$estHolo) $nb = 0; // album holo : un champion sans sa version holo est une case vide
            $pertes = array_filter([$e > 1 ? "ses $e étoiles" : '', $estHolo ? 'sa version holo' : '']); // perdu si on recycle le dernier exemplaire
        ?>
            <?php if ($nb): ?>
            <div class="case-possedee" id="carte-<?= htmlspecialchars($cle) ?>" data-nom="<?= htmlspecialchars($nom) ?>">
                <?php
                    // Deux cartes séparées : la normale dans l'Album, la holo dans l'Album holo, chacune avec ses étoiles et ses exemplaires
                    $eh = (int) ($etoilesHolo[$cle] ?? 1);
                    $exemplaires = $vueHolo ? $holos[$cle] : $nb - ($holos[$cle] ?? 0);
                ?>
                <div class="carte<?= $vueHolo ? ' carte-holo' : '' ?>">
                    <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/loading/<?= urlencode($cle) ?>_<?= (int) $skinsEquipes[$cle] ?>.jpg" alt="<?= htmlspecialchars($nom) ?>" width="154" height="280" loading="lazy">
                    <h4><?= htmlspecialchars($nom) ?></h4>
                    <?= $vueHolo ? htmlEtoiles($eh, true) : htmlEtoiles($e) ?>
                    <?php if ($vueHolo): ?><span class="niveau-holo">+<?= $eh * BONUS_HOLO ?> % en duel</span><?php endif; ?>
                    <p><?= htmlspecialchars($champ['title'] ?? '') ?></p>
                    <?php if ($exemplaires > 1): ?><span class="badge" aria-label="<?= $exemplaires ?> exemplaires<?= $vueHolo ? ' holo' : '' ?>">×<?= $exemplaires ?></span><?php endif; ?>
                    <?php if (in_array($cle, $partis, true)): ?><a class="etiquette-expedition" href="expeditions.php">En expédition</a><?php endif; ?>
                </div>
                <?php if (!$vueHolo): // on recycle depuis l'Album normal (un exemplaire normal part en premier) ?>
                <form method="post">
                    <input type="hidden" name="cle" value="<?= htmlspecialchars($cle) ?>">
                    <?php if ($nb == 1 && in_array($cle, $partis, true)): // occupé : son dernier exemplaire ne peut pas être recyclé ?>
                    <button class="bouton-recycler" disabled title="En expédition">Recycler <span>+<?= RUBIS_PAR_CARTE ?></span></button>
                    <?php else: ?>
                    <button class="bouton-recycler" name="action" value="recycler"
                        <?php if ($nb == 1): ?>onclick="return confirm('C\'est ton seul exemplaire de <?= htmlspecialchars(addslashes($nom)) ?>. Le recycler quand même ?<?= $pertes ? ' Tu perdras ' . implode(' et ', $pertes) . '.' : '' ?>')"<?php endif; ?>>
                        Recycler <span>+<?= RUBIS_PAR_CARTE ?></span>
                    </button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
                <?php if (!$vueHolo && $e < ETOILES_MAX): $cout = coutEtoile($e); $normaux = max(0, $nb - ($holos[$cle] ?? 0) - 1); // doublons normaux ?>
                <form method="post">
                    <input type="hidden" name="cle" value="<?= htmlspecialchars($cle) ?>">
                    <button class="bouton-recycler bouton-etoile" name="action" value="etoile"<?= $normaux < $cout ? ' disabled' : '' ?>
                        title="Fusionne <?= $cout ?> doublon<?= $cout > 1 ? 's' : '' ?> normal<?= $cout > 1 ? 'aux' : '' ?> pour passer à <?= $e + 1 ?> étoiles (+<?= BONUS_ETOILE ?> % de stats en duel)">
                        Améliorer <span>★ <?= min($normaux, $cout) ?>/<?= $cout ?></span>
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($vueHolo && $eh < ETOILES_MAX): $coutHolo = coutEtoile($eh); $doublonsHolo = $holos[$cle] - 1; ?>
                <form method="post">
                    <input type="hidden" name="cle" value="<?= htmlspecialchars($cle) ?>">
                    <button class="bouton-recycler bouton-etoile bouton-etoile-holo" name="action" value="etoile_holo"<?= $doublonsHolo < $coutHolo ? ' disabled' : '' ?>
                        title="Fusionne <?= $coutHolo ?> doublon<?= $coutHolo > 1 ? 's' : '' ?> holo pour passer à <?= $eh + 1 ?> étoiles holo (+<?= BONUS_HOLO ?> % de stats en duel)">
                        Améliorer holo <span>★ <?= min($doublonsHolo, $coutHolo) ?>/<?= $coutHolo ?></span>
                    </button>
                </form>
                <?php endif; ?>
                <?php $nbSkins = count($mesSkins[$cle] ?? []); ?>
                <button class="bouton-recycler bouton-skins" data-cle="<?= htmlspecialchars($cle) ?>" data-nom="<?= htmlspecialchars($nom) ?>"
                    data-skins="<?= htmlspecialchars(json_encode($mesSkins[$cle] ?? [])) ?>" data-equipe="<?= (int) $skinsEquipes[$cle] ?>">
                    Skins<?php if ($nbSkins): ?> <span><?= $nbSkins ?></span><?php endif; ?>
                </button>
                <?php $fav = isset($favoris[$cle]); ?>
                <form method="post">
                    <input type="hidden" name="cle" value="<?= htmlspecialchars($cle) ?>">
                    <button class="bouton-recycler bouton-favori" name="action" value="favori" aria-pressed="<?= $fav ? 'true' : 'false' ?>">
                        <i class="material-icons" aria-hidden="true"><?= $fav ? 'star' : 'star_border' ?></i> Favori
                    </button>
                </form>
            </div>
            <?php else: ?>
            <div class="case-vide" data-nom="<?= htmlspecialchars($nom) ?>">
                <img src="https://ddragon.leagueoflegends.com/cdn/<?= VERSION ?>/img/champion/<?= urlencode($cle) ?>.png" alt="" width="56" height="56" loading="lazy">
                <?= htmlspecialchars($nom) ?>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    </main>

    <!-- Skins d'un champion : débloqués (à équiper) ou verrouillés -->
    <dialog class="fiche-modale" id="modaleSkins" aria-labelledby="titreSkins">
        <button class="fermer fermer-modale" id="fermerSkins" aria-label="Fermer"><i class="material-icons">close</i></button>
        <div class="skins-contenu">
            <h2 id="titreSkins">Skins</h2>
            <p class="info-skins" id="infoSkins" aria-live="polite"></p>
            <div class="grille-skins" id="grilleSkins"></div>
        </div>
    </dialog>
    <script>
        // Recherche : on cache les cases dont le nom ne commence pas par la saisie (même règle que api.html)
        const simplifier = texte => texte.toLowerCase().normalize('NFD').replace(/[^a-z0-9]/g, '');
        document.getElementById('rechercheChampion').addEventListener('input', event => {
            const debut = simplifier(event.target.value);
            document.querySelectorAll('#album > div').forEach(cas => {
                cas.hidden = !simplifier(cas.dataset.nom).startsWith(debut);
            });
        });

        // Bouton "Skins" d'une carte : on charge la liste des skins du champion (APISite.php) et on l'affiche
        const modaleSkins = document.getElementById('modaleSkins');
        const grilleSkins = document.getElementById('grilleSkins');
        const infoSkins = document.getElementById('infoSkins');
        document.querySelectorAll('.bouton-skins').forEach(bouton => bouton.addEventListener('click', () => {
            const cle = bouton.dataset.cle;
            const debloques = JSON.parse(bouton.dataset.skins);
            const equipe = Number(bouton.dataset.equipe);
            document.getElementById('titreSkins').textContent = `Skins ${/^[aeiouyéè]/i.test(bouton.dataset.nom) ? "d'" : 'de '}${bouton.dataset.nom}`; // "d'Ahri", "de Zed"
            infoSkins.textContent = 'Chargement…';
            grilleSkins.innerHTML = '';
            modaleSkins.showModal();
            fetch(`APISite.php?champion=${encodeURIComponent(cle)}`)
                .then(response => response.json())
                .then(data => {
                    const skins = data.data[cle].skins;
                    infoSkins.textContent = `${debloques.length}/${skins.length - 1} skins débloqués. Envoie ${bouton.dataset.nom} en expédition pour en trouver d'autres.`;
                    grilleSkins.innerHTML = skins.map(skin => {
                        const ok = skin.num === 0 || debloques.includes(skin.num);
                        let action = '<i class="material-icons cadenas" aria-label="Verrouillé">lock</i>';
                        if (ok) action = skin.num === equipe
                            ? '<span class="etiquette etiquette-nouveau">Équipé</span>'
                            : '<button class="bouton-recycler">Équiper</button>';
                        return `
                            <form method="post" class="skin${ok ? '' : ' verrouille'}">
                                <input type="hidden" name="action" value="skin">
                                <input type="hidden" name="cle" value="${cle}">
                                <input type="hidden" name="num" value="${skin.num}">
                                <img src="https://ddragon.leagueoflegends.com/cdn/img/champion/loading/${cle}_${skin.num}.jpg" alt="" width="154" height="280" loading="lazy">
                                <span class="skin-nom">${skin.num === 0 ? 'Skin de base' : skin.name}</span>
                                ${action}
                            </form>`;
                    }).join('');
                })
                .catch(() => { infoSkins.textContent = 'Impossible de charger les skins. Réessaie.'; });
        }));
        document.getElementById('fermerSkins').addEventListener('click', () => modaleSkins.close());
        modaleSkins.addEventListener('click', event => {
            if (event.target === modaleSkins) modaleSkins.close();
        });
    </script>
    <script src="main.js"></script>
</body>
</html>
