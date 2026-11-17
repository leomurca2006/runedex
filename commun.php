<?php
// Inclus au début de chaque page PHP : session + base de données
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);

const ATTENTE = 10 * 60; // 1 booster gagné toutes les 10 min (en secondes)
const STOCK_MAX = 10;    // on ne peut pas en garder plus de 10 en réserve
const NB_CARTES = 5;
const CHANCE_HOLO = 100;     // 1 booster sur 100 est holographique...
const NB_CARTES_HOLO = 50;   // ...et donne 50 cartes
const VERSION = '14.8.1';

// Les boosters proposés.
// "type" : champions (payés avec le stock), composants (payés en rubis) ou objets (offerts, voir BOOSTERS_POUR_UN_OBJETS).
// "classe" = rôle Data Dragon des champions (null = tous), "image" = icône du médaillon (dossier img de Data Dragon).
// Pas de rareté : dans un paquet, chaque carte a la même chance de sortir.
const PAQUETS = [
    'tous'       => ['nom' => 'Tous les champions', 'sousTitre' => 'Tout Runeterra',         'type' => 'champions', 'classe' => null,       'image' => 'champion/Ahri.png'],
    'assassin'   => ['nom' => 'Assassins',          'sousTitre' => 'Rapides et mortels',     'type' => 'champions', 'classe' => 'Assassin', 'image' => 'champion/Zed.png'],
    'combattant' => ['nom' => 'Combattants',        'sousTitre' => 'Au corps à corps',       'type' => 'champions', 'classe' => 'Fighter',  'image' => 'champion/Garen.png'],
    'mage'       => ['nom' => 'Mages',              'sousTitre' => 'Maîtres des sorts',      'type' => 'champions', 'classe' => 'Mage',     'image' => 'champion/Lux.png'],
    'tireur'     => ['nom' => 'Tireurs',            'sousTitre' => 'Dégâts à distance',      'type' => 'champions', 'classe' => 'Marksman', 'image' => 'champion/Jinx.png'],
    'support'    => ['nom' => 'Supports',           'sousTitre' => "Au service de l'équipe", 'type' => 'champions', 'classe' => 'Support',  'image' => 'champion/Janna.png'],
    'tank'       => ['nom' => 'Tanks',              'sousTitre' => 'Encaissent tout',        'type' => 'champions', 'classe' => 'Tank',     'image' => 'champion/Malphite.png'],
    'composants' => ['nom' => 'Composants',         'sousTitre' => 'De quoi fabriquer',      'type' => 'composants', 'classe' => null,      'image' => 'item/1038.png'],
    'objets'     => ['nom' => 'Objets complets',    'sousTitre' => 'Offert tous les 5 boosters Composants', 'type' => 'objets', 'classe' => null, 'image' => 'item/3031.png'],
];
const NOMBRES_AUTORISES = [1, 5, 10]; // ×1, ×5, ×10

// La base est rangée HORS du dossier du site : sinon n'importe qui pourrait la télécharger (avec les mots de passe)
$pdo = new PDO('sqlite:' . dirname(__DIR__) . '/booster-lol-donnees.sqlite');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE IF NOT EXISTS comptes (
    id INTEGER PRIMARY KEY,
    pseudo TEXT NOT NULL UNIQUE COLLATE NOCASE,
    mdp TEXT NOT NULL,
    dernier_booster INTEGER NOT NULL DEFAULT 0 -- départ du stock : stock = temps écoulé depuis / ATTENTE (voir etatStock)
)');
// Une ligne par champion possédé, "nombre" compte les doublons
$pdo->exec('CREATE TABLE IF NOT EXISTS cartes (
    compte_id INTEGER NOT NULL REFERENCES comptes(id),
    champion TEXT NOT NULL,
    nombre INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (compte_id, champion)
)');

// Échanges entre joueurs : "de" donne carte_donnee, "vers" donne carte_demandee
$pdo->exec("CREATE TABLE IF NOT EXISTS echanges (
    id INTEGER PRIMARY KEY,
    de_id INTEGER NOT NULL REFERENCES comptes(id),
    vers_id INTEGER NOT NULL REFERENCES comptes(id),
    carte_donnee TEXT NOT NULL,
    carte_demandee TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'attente', -- attente, accepte, refuse, annule, impossible
    cree INTEGER NOT NULL
)");

// Rubis : monnaie gagnée en recyclant des cartes, qui sert à acheter des boosters.
// La colonne est ajoutée aux bases déjà créées (SQLite n'a pas de "ADD COLUMN IF NOT EXISTS")
const RUBIS_PAR_CARTE = 3;
const RUBIS_RECYCLAGE = ['champions' => RUBIS_PAR_CARTE, 'composants' => 5, 'objets' => 10]; // rubis par exemplaire recyclé
const PRIX_BOOSTER = 180;            // booster de champions (ajouté au stock)
const PRIX_BOOSTER_COMPOSANTS = 300; // booster de composants
const BOOSTERS_POUR_UN_OBJETS = 5;   // 1 booster d'objets complets offert tous les 5 boosters de composants achetés

// Ajoute une colonne aux bases déjà créées (SQLite n'a pas de "ADD COLUMN IF NOT EXISTS")
function ajouterColonne($pdo, $table, $colonne, $definition) {
    $colonnes = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    if (!in_array($colonne, $colonnes, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $colonne $definition");
    }
}
ajouterColonne($pdo, 'comptes', 'rubis', 'INTEGER NOT NULL DEFAULT 0');
ajouterColonne($pdo, 'comptes', 'composants_achetes', 'INTEGER NOT NULL DEFAULT 0'); // total de boosters composants achetés
ajouterColonne($pdo, 'comptes', 'boosters_objets', 'INTEGER NOT NULL DEFAULT 0');    // boosters d'objets complets à ouvrir

// Exemplaires d'un champion protégés du recyclage des doublons : 1 normal + 1 par niveau holo (les holos sont à part)
const GARDE_CHAMPIONS = 'holo + 1';

// Rubis, boosters spéciaux et nombre d'exemplaires en trop (les doublons qu'on peut recycler) par type
function etatRubis($pdo, $id) {
    $req = $pdo->prepare("SELECT rubis, composants_achetes, boosters_objets,
        (SELECT COALESCE(SUM(MAX(0, nombre - (" . GARDE_CHAMPIONS . "))), 0) FROM cartes WHERE compte_id = comptes.id) AS champions,
        (SELECT COALESCE(SUM(nombre - 1), 0) FROM objets_debloques WHERE compte_id = comptes.id AND type = 'composants') AS composants,
        (SELECT COALESCE(SUM(nombre - 1), 0) FROM objets_debloques WHERE compte_id = comptes.id AND type = 'objets') AS objets
        FROM comptes WHERE id = ?");
    $req->execute([$id]);
    $ligne = array_map('intval', $req->fetch());
    return [
        'rubis' => $ligne['rubis'],
        'doublons' => $ligne['champions'], // doublons de champions (gardé pour les pages existantes)
        'doublonsParType' => ['champions' => $ligne['champions'], 'composants' => $ligne['composants'], 'objets' => $ligne['objets']],
        'rubisParCarte' => RUBIS_PAR_CARTE,
        'rubisRecyclage' => RUBIS_RECYCLAGE,
        'prixBooster' => PRIX_BOOSTER,
        'prixBoosterComposants' => PRIX_BOOSTER_COMPOSANTS,
        'composantsAchetes' => $ligne['composants_achetes'],
        'boostersPourUnObjets' => BOOSTERS_POUR_UN_OBJETS,
        'boostersObjets' => $ligne['boosters_objets'],
    ];
}

// Recycle 1 exemplaire d'une carte ; renvoie les rubis gagnés (0 si le joueur ne l'a pas)
function recyclerCarte($pdo, $id, $cle) {
    $pdo->beginTransaction();
    if (!retirerCarte($pdo, $id, $cle)) {
        $pdo->rollBack();
        return 0;
    }
    $pdo->prepare('UPDATE comptes SET rubis = rubis + ? WHERE id = ?')->execute([RUBIS_PAR_CARTE, $id]);
    avancerMission($pdo, $id, 'recyclage');
    $pdo->commit();
    return RUBIS_PAR_CARTE;
}

// Recycle tous les doublons d'un type (champions, composants ou objets) en gardant 1 exemplaire de chaque
// (et, pour les champions, les exemplaires holo en plus). Renvoie les rubis gagnés.
function recyclerDoublons($pdo, $id, $type = 'champions') {
    if (!is_string($type) || !isset(RUBIS_RECYCLAGE[$type])) return 0; // is_string : un tableau envoyé à la main ferait planter isset
    // les champions sont dans "cartes", les composants et objets dans "objets_debloques" (colonne type)
    // $garde = combien d'exemplaires on garde (expression SQL)
    [$table, $filtre, $valeurs, $garde] = $type === 'champions'
        ? ['cartes', '', [$id], GARDE_CHAMPIONS]
        : ['objets_debloques', 'AND type = ?', [$id, $type], '1'];
    $pdo->beginTransaction();
    $req = $pdo->prepare("SELECT COALESCE(SUM(nombre - ($garde)), 0) FROM $table WHERE compte_id = ? $filtre AND nombre > $garde");
    $req->execute($valeurs);
    $recycles = (int) $req->fetchColumn();
    $gain = $recycles * RUBIS_RECYCLAGE[$type];
    $pdo->prepare("UPDATE $table SET nombre = $garde WHERE compte_id = ? $filtre AND nombre > $garde")->execute($valeurs);
    $pdo->prepare('UPDATE comptes SET rubis = rubis + ? WHERE id = ?')->execute([$gain, $id]);
    avancerMission($pdo, $id, 'recyclage', $recycles);
    $pdo->commit();
    return $gain;
}

// Achète 1 booster : -180 rubis, +1 dans le stock (en reculant "dernier_booster" de 10 min).
// Une seule requête qui vérifie les rubis ET que le stock n'est pas plein : pas de double achat possible.
function acheterBooster($pdo, $id) {
    $req = $pdo->prepare('UPDATE comptes SET rubis = rubis - :prix, dernier_booster = dernier_booster - :attente
        WHERE id = :id AND rubis >= :prix AND dernier_booster > :plein');
    // PARAM_INT : sinon SQLite compare du texte avec des nombres (voir APIBooster.php)
    foreach (['prix' => PRIX_BOOSTER, 'attente' => ATTENTE, 'id' => $id, 'plein' => time() - STOCK_MAX * ATTENTE] as $nom => $valeur) {
        $req->bindValue($nom, $valeur, PDO::PARAM_INT);
    }
    $req->execute();
    return $req->rowCount() === 1;
}

// Inventaire d'objets de chaque joueur (identifiant Data Dragon, ex. "3031") : "débloqué" = au moins 1 exemplaire.
// type = composants ou objets (objets complets), pour le recyclage.
$pdo->exec('CREATE TABLE IF NOT EXISTS objets_debloques (
    compte_id INTEGER NOT NULL REFERENCES comptes(id),
    objet TEXT NOT NULL,
    PRIMARY KEY (compte_id, objet)
)');
ajouterColonne($pdo, 'objets_debloques', 'nombre', 'INTEGER NOT NULL DEFAULT 1');
ajouterColonne($pdo, 'objets_debloques', 'type', "TEXT NOT NULL DEFAULT 'composants'");

function ajouterObjet($pdo, $compteId, $cle, $type) {
    $pdo->prepare('INSERT INTO objets_debloques (compte_id, objet, type) VALUES (?, ?, ?)
        ON CONFLICT (compte_id, objet) DO UPDATE SET nombre = nombre + 1')->execute([$compteId, $cle, $type]);
}

// Retire 1 exemplaire d'un objet ; renvoie false si le joueur ne l'a pas (ou plus)
function retirerObjet($pdo, $compteId, $cle) {
    $req = $pdo->prepare('UPDATE objets_debloques SET nombre = nombre - 1 WHERE compte_id = ? AND objet = ? AND nombre >= 1');
    $req->execute([$compteId, $cle]);
    if ($req->rowCount() === 0) return false;
    $pdo->prepare('DELETE FROM objets_debloques WHERE compte_id = ? AND objet = ? AND nombre = 0')->execute([$compteId, $cle]);
    return true;
}

// Expéditions : un champion part un moment et revient avec des rubis (et des composants pour les longues).
// "skin" = chance en % qu'il ramène un de ses skins pas encore débloqué.
// Pendant ce temps il est occupé : on ne peut ni recycler ni échanger son dernier exemplaire (voir retirerCarte).
const EXPEDITIONS = [
    'patrouille'  => ['nom' => 'Patrouille',        'duree' => 3600,     'rubis' => 20,  'composants' => 0, 'skin' => 10],
    'exploration' => ['nom' => 'Exploration',       'duree' => 4 * 3600, 'rubis' => 60,  'composants' => 1, 'skin' => 25],
    'grande'      => ['nom' => 'Grande expédition', 'duree' => 8 * 3600, 'rubis' => 100, 'composants' => 2, 'skin' => 50],
];
const EXPEDITIONS_MAX = 3; // champions en expédition en même temps
// Une ligne par champion parti ; elle est supprimée quand on récupère la récompense
$pdo->exec('CREATE TABLE IF NOT EXISTS expeditions (
    compte_id INTEGER NOT NULL REFERENCES comptes(id),
    champion TEXT NOT NULL,
    sorte TEXT NOT NULL, -- clé de EXPEDITIONS
    fin INTEGER NOT NULL, -- heure de retour (timestamp)
    PRIMARY KEY (compte_id, champion)
)');
ajouterColonne($pdo, 'expeditions', 'royaume', "TEXT NOT NULL DEFAULT ''"); // destination (voir ROYAUMES dans expeditions.php)
ajouterColonne($pdo, 'expeditions', 'objet', "TEXT NOT NULL DEFAULT ''");   // objet complet emporté ('' = aucun), rendu au retour
ajouterColonne($pdo, 'comptes', 'expeditions_finies', 'INTEGER NOT NULL DEFAULT 0'); // pour les défis
// Un objet complet emporté en expédition : +50 % de rubis et +20 points de chance de skin
const BONUS_OBJET_RUBIS = 50;
const BONUS_OBJET_SKIN = 20;

// Skins débloqués (rapportés par les expéditions). num = numéro du skin chez Data Dragon (0 = skin de base, toujours possédé).
$pdo->exec('CREATE TABLE IF NOT EXISTS skins_debloques (
    compte_id INTEGER NOT NULL REFERENCES comptes(id),
    champion TEXT NOT NULL,
    num INTEGER NOT NULL,
    PRIMARY KEY (compte_id, champion, num)
)');
ajouterColonne($pdo, 'cartes', 'skin', 'INTEGER NOT NULL DEFAULT 0'); // skin équipé : celui affiché sur la carte dans la collection
ajouterColonne($pdo, 'cartes', 'favori', 'INTEGER NOT NULL DEFAULT 0'); // 1 = carte en favori (affichée en premier dans la collection)
// "nombre" = tous les exemplaires, dont "holo" exemplaires holo (0 = pas de version holo ; toujours holo <= nombre)
ajouterColonne($pdo, 'cartes', 'holo', 'INTEGER NOT NULL DEFAULT 0');

// Étoiles : on fusionne des doublons pour faire monter un champion de 1 à 5 étoiles.
// Passer de e à e+1 étoiles coûte 2^(e-1) doublons : 1, 2, 4 puis 8 (15 en tout).
// Deux niveaux séparés :
//  - étoiles normales ("etoiles") : avec des doublons normaux, +10 % de stats en duel par étoile au-dessus de 1 ;
//  - étoiles holo ("etoiles_holo") : uniquement avec des doublons holo, +50 % par étoile (1 étoile = +50 %, 5 = +250 %).
const ETOILES_MAX = 5;
const BONUS_ETOILE = 10;
const BONUS_HOLO = 50;
ajouterColonne($pdo, 'cartes', 'etoiles', 'INTEGER NOT NULL DEFAULT 1');
ajouterColonne($pdo, 'cartes', 'etoiles_holo', 'INTEGER NOT NULL DEFAULT 1');

// Bonus de stats d'une carte en duel, en % : étoiles normales + étoiles holo, additionnés (ex. 5 étoiles + 2 étoiles holo = 40 + 100)
function bonusCarte($carte) {
    return ($carte['etoiles'] - 1) * BONUS_ETOILE + ($carte['holo'] > 0 ? $carte['etoiles_holo'] * BONUS_HOLO : 0);
}

function coutEtoile($etoiles) {
    return 2 ** ($etoiles - 1);
}

// +1 étoile en consommant des doublons ; renvoie false s'il n'y en a pas assez.
// $holo = false : étoiles normales, payées avec les exemplaires normaux (on en garde toujours 1, les holos ne sont pas touchés).
// $holo = true  : étoiles holo, payées uniquement avec des exemplaires holo (on garde toujours 1 holo).
// Une seule requête : deux clics en même temps ne dépensent pas deux fois les mêmes doublons.
// En SQL, 1 << (e - 1) = coutEtoile(e).
function ameliorerCarte($pdo, $compteId, $cle, $holo = false) {
    $req = $pdo->prepare($holo
        ? 'UPDATE cartes SET nombre = nombre - (1 << (etoiles_holo - 1)), holo = holo - (1 << (etoiles_holo - 1)), etoiles_holo = etoiles_holo + 1
            WHERE compte_id = ? AND champion = ? AND etoiles_holo < ? AND holo - 1 >= (1 << (etoiles_holo - 1))'
        : 'UPDATE cartes SET nombre = nombre - (1 << (etoiles - 1)), etoiles = etoiles + 1
            WHERE compte_id = ? AND champion = ? AND etoiles < ? AND nombre - holo - 1 >= (1 << (etoiles - 1))');
    $req->execute([$compteId, $cle, ETOILES_MAX]);
    if ($req->rowCount() !== 1) return false;
    avancerMission($pdo, $compteId, 'etoile');
    return true;
}

// Skins d'un champion : [num => nom], sans le skin de base. null si Riot ne répond pas.
function skinsChampion($cle) {
    $json = @file_get_contents('https://ddragon.leagueoflegends.com/cdn/' . VERSION . '/data/fr_FR/champion/' . rawurlencode($cle) . '.json');
    if ($json === false) return null;
    $skins = array_column(json_decode($json, true)['data'][$cle]['skins'] ?? [], 'name', 'num');
    unset($skins[0]);
    return $skins;
}


// Récompense quotidienne : 1 fois par jour, de plus en plus grosse si on revient plusieurs jours de suite
// (jour 1 = 20 rubis, puis +10 par jour, maximum 80 au jour 7).
const QUOTIDIEN_BASE = 20;
const QUOTIDIEN_BONUS = 10;
const QUOTIDIEN_SERIE_MAX = 7;
date_default_timezone_set('Europe/Paris'); // le "jour" change à minuit, heure française
ajouterColonne($pdo, 'comptes', 'dernier_quotidien', "TEXT NOT NULL DEFAULT ''"); // date de la dernière récompense (2026-11-17)
ajouterColonne($pdo, 'comptes', 'serie', 'INTEGER NOT NULL DEFAULT 0');            // jours de suite

function gainQuotidien($serie) {
    return QUOTIDIEN_BASE + QUOTIDIEN_BONUS * (min($serie, QUOTIDIEN_SERIE_MAX) - 1);
}

// "serie" = numéro du jour de la récompense à prendre aujourd'hui (ou déjà prise)
function etatQuotidien($pdo, $id) {
    $req = $pdo->prepare('SELECT dernier_quotidien, serie FROM comptes WHERE id = ?');
    $req->execute([$id]);
    $ligne = $req->fetch();
    $disponible = $ligne['dernier_quotidien'] !== date('Y-m-d');
    $serie = !$disponible ? (int) $ligne['serie']
        : ($ligne['dernier_quotidien'] === date('Y-m-d', strtotime('-1 day')) ? $ligne['serie'] + 1 : 1);
    return ['quotidienDisponible' => $disponible, 'serie' => $serie, 'gainQuotidien' => gainQuotidien($serie), 'gainDemain' => gainQuotidien($serie + 1)];
}

// Donne la récompense du jour ; renvoie les rubis gagnés (0 si déjà prise aujourd'hui)
function recupererQuotidien($pdo, $id) {
    $pdo->beginTransaction();
    // Une seule requête qui vérifie (pas encore pris aujourd'hui) ET met à jour la série : pas de double récompense
    $req = $pdo->prepare('UPDATE comptes SET serie = CASE WHEN dernier_quotidien = :hier THEN serie + 1 ELSE 1 END,
        dernier_quotidien = :aujourdhui WHERE id = :id AND dernier_quotidien != :aujourdhui');
    $req->execute(['hier' => date('Y-m-d', strtotime('-1 day')), 'aujourdhui' => date('Y-m-d'), 'id' => $id]);
    if ($req->rowCount() === 0) {
        $pdo->rollBack();
        return 0;
    }
    $req = $pdo->prepare('SELECT serie FROM comptes WHERE id = ?');
    $req->execute([$id]);
    $gain = gainQuotidien((int) $req->fetchColumn());
    $pdo->prepare('UPDATE comptes SET rubis = rubis + ? WHERE id = ?')->execute([$gain, $id]);
    $pdo->commit();
    return $gain;
}

// Défis réclamés (la liste des défis est dans defis.php)
$pdo->exec('CREATE TABLE IF NOT EXISTS defis_reussis (
    compte_id INTEGER NOT NULL REFERENCES comptes(id),
    defi TEXT NOT NULL,
    PRIMARY KEY (compte_id, defi)
)');

// Ajoute 1 exemplaire d'un champion à la collection d'un joueur.
// $holo = carte sortie d'un booster holographique : c'est un exemplaire holo (album holo, étoiles holo).
function ajouterCarte($pdo, $compteId, $cle, $holo = false) {
    $pdo->prepare('INSERT INTO cartes (compte_id, champion, holo) VALUES (?, ?, ?)
        ON CONFLICT (compte_id, champion) DO UPDATE SET nombre = nombre + 1, holo = holo + excluded.holo')->execute([$compteId, $cle, (int) $holo]);
}

// Retire 1 exemplaire ; renvoie false si le joueur ne l'a pas (ou plus), ou si c'est le dernier et qu'il est en expédition.
// Un exemplaire normal part en premier : un holo n'est retiré que s'il ne reste que des holos (holo = MIN(holo, nombre restant)).
function retirerCarte($pdo, $compteId, $cle) {
    $req = $pdo->prepare('UPDATE cartes SET nombre = nombre - 1, holo = MIN(holo, nombre - 1) WHERE compte_id = ? AND champion = ? AND nombre >= 1
        AND (nombre > 1 OR NOT EXISTS (SELECT 1 FROM expeditions e WHERE e.compte_id = cartes.compte_id AND e.champion = cartes.champion))');
    $req->execute([$compteId, $cle]);
    if ($req->rowCount() === 0) return false;
    $pdo->prepare('DELETE FROM cartes WHERE compte_id = ? AND champion = ? AND nombre = 0')->execute([$compteId, $cle]);
    return true;
}

// Liste des champions de Data Dragon (même URL que APISite.php), null si Riot ne répond pas
function listeChampions() {
    $json = @file_get_contents('https://ddragon.leagueoflegends.com/cdn/' . VERSION . '/data/fr_FR/champion.json');
    return $json === false ? null : json_decode($json, true)['data'];
}

// Objets achetables sur la Faille de l'invocateur (carte 11), avec leur type :
// "objets" = objet complet (a des composants et ne sert à rien construire), "composants" = tout le reste.
// Même règle que objets.js. Renvoie null si Riot ne répond pas. Les clés sont des chaînes ("3031").
function listeObjets() {
    $json = @file_get_contents('https://ddragon.leagueoflegends.com/cdn/' . VERSION . '/data/fr_FR/item.json');
    if ($json === false) return null;
    $tous = json_decode($json, true)['data'];
    $surLaFaille = fn($o) => $o['gold']['purchasable'] && !empty($o['maps']['11']) && empty($o['requiredChampion'])
        && empty($o['requiredAlly']) && ($o['inStore'] ?? true) !== false;
    $objets = [];
    foreach ($tous as $cle => $o) {
        if (!$surLaFaille($o)) continue;
        $construit = array_filter($o['into'] ?? [], fn($c) => isset($tous[$c]) && $surLaFaille($tous[$c]));
        $o['type'] = !empty($o['from']) && !$construit ? 'objets' : 'composants';
        $objets[(string) $cle] = $o;
    }
    return $objets;
}

// Le stock n'est pas stocké : on le calcule à partir de "dernier_booster".
// Chaque tranche de 10 min écoulée depuis cette date = 1 booster, avec un maximum de 10.
// Ouvrir un booster avance cette date de 10 min (= retire 1 booster du stock).
function etatStock($pdo, $id) {
    $req = $pdo->prepare('SELECT dernier_booster FROM comptes WHERE id = ?');
    $req->execute([$id]);
    $ecoule = time() - $req->fetchColumn();
    $stock = min(STOCK_MAX, intdiv($ecoule, ATTENTE));
    return [
        'stock' => $stock,
        'max' => STOCK_MAX,
        'attente' => ATTENTE,
        'reste' => $stock < STOCK_MAX ? ATTENTE - $ecoule % ATTENTE : 0, // secondes avant le prochain booster
    ];
}

// ---------- Duels contre des bots ----------
// On choisit un champion + jusqu'à 6 objets de son inventaire, et on affronte le bot de son palier.
// Le combat est calculé ici, côté serveur, avec les vraies stats de Data Dragon (champion niveau 18).
const PALIERS_DUEL = 10;
const OBJETS_MAX_DUEL = 6;
const RUBIS_PAR_PALIER = 30; // palier p battu pour la 1re fois = 30 × p rubis
ajouterColonne($pdo, 'cartes', 'stuff', "TEXT NOT NULL DEFAULT '[]'"); // objets sauvegardés pour ce champion en duel (JSON)
// Tour infinie : après les 10 paliers, ça continue (palier 11 = étage 1, 12 = étage 2...) sans fin.
const BONUS_ETAGE = 5;      // +5 % de stats en plus pour le bot à chaque étage
const RUBIS_PAR_ETAGE = 100; // chaque nouvel étage battu
ajouterColonne($pdo, 'comptes', 'palier_duel', 'INTEGER NOT NULL DEFAULT 1'); // palier du prochain bot (au-delà de 10 = tour infinie)

// Rubis gagnés en battant ce palier pour la première fois
function gainPalier($palier) {
    return $palier <= PALIERS_DUEL ? RUBIS_PAR_PALIER * $palier : RUBIS_PAR_ETAGE;
}

// Meilleur étage de la tour battu (0 = pas encore dans la tour)
function recordTour($palierDuel) {
    return max(0, $palierDuel - 1 - PALIERS_DUEL);
}

// Stats d'un champion au niveau 18, sans objet.
// Les champions n'ont pas de puissance magique de base : on la tire de leur note "magie" (0 à 10) de Data Dragon.
function statsChampion($champion) {
    $s = $champion['stats'];
    $n = 17; // niveau 18 = niveau 1 + 17 niveaux
    return [
        'pv' => $s['hp'] + $s['hpperlevel'] * $n,
        'ad' => $s['attackdamage'] + $s['attackdamageperlevel'] * $n,
        'pm' => $champion['info']['magic'] * 15,
        'armure' => $s['armor'] + $s['armorperlevel'] * $n,
        'rm' => $s['spellblock'] + $s['spellblockperlevel'] * $n,
        'vitesse' => $s['attackspeed'] * (1 + $s['attackspeedperlevel'] * $n / 100),
        'vitesseBase' => $s['attackspeed'], // les % de vitesse d'attaque des objets s'appliquent à cette valeur
        'critique' => 0,
        'volDeVie' => 0,
    ];
}

// Ce qu'un objet ajoute (mêmes clés que statsChampion, la vitesse est en % de la vitesse de base)
function bonusObjet($objet) {
    $s = $objet['stats'] ?? [];
    return [
        'pv' => $s['FlatHPPoolMod'] ?? 0,
        'ad' => $s['FlatPhysicalDamageMod'] ?? 0,
        'pm' => $s['FlatMagicDamageMod'] ?? 0,
        'armure' => $s['FlatArmorMod'] ?? 0,
        'rm' => $s['FlatSpellBlockMod'] ?? 0,
        'vitesse' => $s['PercentAttackSpeedMod'] ?? 0,
        'critique' => $s['FlatCritChanceMod'] ?? 0,
        'volDeVie' => $s['PercentLifeStealMod'] ?? 0,
    ];
}

// Stats finales : champion + objets (+ bonus en % pour les bots des derniers paliers). Même calcul dans duels.js pour l'aperçu.
function statsCombat($champion, $objets, $bonus = 0) {
    $stats = statsChampion($champion);
    foreach ($objets as $objet) {
        foreach (bonusObjet($objet) as $cle => $valeur) {
            $stats[$cle] += $cle === 'vitesse' ? $stats['vitesseBase'] * $valeur : $valeur;
        }
    }
    foreach (['pv', 'ad', 'pm', 'armure', 'rm'] as $cle) {
        $stats[$cle] *= 1 + $bonus / 100;
    }
    $stats['vitesse'] = min(2.5, $stats['vitesse']); // limite du jeu
    $stats['critique'] = min(1, $stats['critique']);
    return array_map(fn($valeur) => round($valeur, 3), $stats);
}

// Le bot d'un palier : toujours le même pour un joueur et un palier (on peut donc préparer son build).
// Palier 1 = sans objet, puis 1 objet complet de plus par palier (6 max) ; paliers 8 à 10 : +10/20/30 % de stats.
function botDuPalier($compteId, $palier, $champions, $objets) {
    mt_srand(crc32("bot-$compteId-$palier")); // graine fixe = même tirage à chaque fois
    $cle = (string) array_rand($champions);
    $complets = array_keys(array_filter($objets, fn($objet) => $objet['type'] === 'objets'));
    $nb = min(OBJETS_MAX_DUEL, $palier - 1);
    $choix = $nb ? array_map(fn($i) => (string) $complets[$i], (array) array_rand($complets, $nb)) : [];
    mt_srand(); // on remet du vrai hasard pour la suite (coups critiques)
    // Tour infinie : on repart des +30 % du palier 10 et on ajoute 5 % par étage (étage 1 = +35 %, étage 10 = +80 %...)
    $bonus = $palier <= PALIERS_DUEL ? max(0, $palier - 7) * 10 : 30 + ($palier - PALIERS_DUEL) * BONUS_ETAGE;
    $stats = statsCombat($champions[$cle], array_map(fn($id) => $objets[$id], $choix), $bonus);
    return ['palier' => $palier, 'cle' => $cle, 'nom' => $champions[$cle]['name'], 'objets' => $choix, 'bonus' => $bonus, 'stats' => $stats];
}

// Combat automatique : chacun attaque à son rythme (vitesse d'attaque) jusqu'à ce qu'un des deux tombe.
// Dégâts d'un coup = dégâts d'attaque réduits par l'armure (vraie formule de LoL : × 100 / (100 + armure))
//                  + 60 % de la puissance magique réduite par la résistance magique.
// Renvoie [victoire du joueur, liste des coups pour l'animation].
function simulerDuel($joueur, $bot) {
    $combattants = [$joueur, $bot];
    $pv = [$joueur['pv'], $bot['pv']];
    $prochain = [1 / $joueur['vitesse'], 1 / $bot['vitesse']]; // moment de la prochaine attaque (en secondes)
    $coups = [];
    while ($pv[0] > 0 && $pv[1] > 0 && count($coups) < 400) {
        $i = $prochain[0] <= $prochain[1] ? 0 : 1; // celui qui attaque
        $j = 1 - $i;
        [$a, $d] = [$combattants[$i], $combattants[$j]];
        $critique = random_int(1, 100) <= $a['critique'] * 100;
        $degats = (int) round($a['ad'] * 100 / (100 + $d['armure']) * ($critique ? 1.75 : 1)
            + $a['pm'] * 0.6 * 100 / (100 + $d['rm']));
        $pv[$j] = max(0, $pv[$j] - $degats);
        $pv[$i] = min($a['pv'], $pv[$i] + $degats * $a['volDeVie']);
        $coups[] = ['t' => round($prochain[$i], 2), 'qui' => $i, 'degats' => $degats, 'critique' => $critique, 'pv' => [(int) round($pv[0]), (int) round($pv[1])]];
        $prochain[$i] += 1 / $a['vitesse'];
    }
    // Personne n'est tombé (deux tanks) : celui qui a gardé le plus de % de PV gagne
    $victoire = $pv[1] <= 0 || ($pv[0] > 0 && $pv[0] / $joueur['pv'] > $pv[1] / $bot['pv']);
    return [$victoire, $coups];
}

// Profil : un champion possédé comme avatar + une vitrine de 3 cartes, choisis dans compte.php,
// affichés par carte-profil.php (page Compte et profil public dans joueurs.php).
const VITRINE_MAX = 3;
ajouterColonne($pdo, 'comptes', 'avatar', "TEXT NOT NULL DEFAULT ''");   // clé du champion ('' = pas d'avatar)
ajouterColonne($pdo, 'comptes', 'vitrine', "TEXT NOT NULL DEFAULT '[]'"); // clés des champions en vitrine (JSON)

// Tout ce qu'affiche la carte de profil. Avatar et vitrine ne gardent que les champions encore possédés
// (le joueur a pu les recycler ou les échanger depuis). Renvoie null si le compte n'existe pas.
function profilJoueur($pdo, $id) {
    $req = $pdo->prepare("SELECT pseudo, avatar, vitrine, palier_duel,
        (SELECT COUNT(*) FROM cartes WHERE compte_id = c.id) AS champions,
        (SELECT COUNT(*) FROM skins_debloques WHERE compte_id = c.id) AS skins,
        (SELECT COUNT(*) FROM objets_debloques WHERE compte_id = c.id AND type = 'objets') AS objets,
        (SELECT COUNT(*) FROM defis_reussis WHERE compte_id = c.id) AS defis
        FROM comptes c WHERE id = ?");
    $req->execute([$id]);
    $profil = $req->fetch();
    if (!$profil) return null;
    $req = $pdo->prepare('SELECT champion, skin, etoiles, holo FROM cartes WHERE compte_id = ?');
    $req->execute([$id]);
    $cartes = array_column($req->fetchAll(), null, 'champion'); // ['Ahri' => ['champion' => 'Ahri', 'skin' => 0, 'etoiles' => 3, 'holo' => 1], ...]
    $profil['avatar'] = $cartes[$profil['avatar']] ?? null;
    $profil['vitrine'] = array_values(array_filter(array_map(fn($cle) => $cartes[$cle] ?? null, json_decode($profil['vitrine'], true) ?: [])));
    return $profil;
}

// Missions : 3 du jour + 3 de la semaine, tirées au hasard dans MISSIONS, renouvelées à minuit et le lundi (heure de Paris).
// Chaque action du joueur fait avancer un compteur (avancerMission). Affichées et récupérées dans defis.php.
const MISSIONS = [
    'booster'     => ['texte' => 'Ouvrir %d boosters',                 'jour' => 5,  'semaine' => 25],
    'duel'        => ['texte' => 'Faire %d duels',                     'jour' => 3,  'semaine' => 15],
    'expedition'  => ['texte' => 'Envoyer %d champions en expédition', 'jour' => 2,  'semaine' => 10],
    'recyclage'   => ['texte' => 'Recycler %d cartes',                 'jour' => 10, 'semaine' => 50],
    'etoile'      => ['texte' => 'Améliorer %d fois un champion',      'jour' => 1,  'semaine' => 5],
    'fabrication' => ['texte' => 'Fabriquer %d objet%s',               'jour' => 1,  'semaine' => 5], // %s = "s" au pluriel
];
const MISSIONS_PAR_PERIODE = 3;
const RUBIS_MISSION = ['jour' => 30, 'semaine' => 120];

// Une ligne par compte, période et action : "nombre" = combien de fois c'est fait, "reclamee" = récompense prise
$pdo->exec('CREATE TABLE IF NOT EXISTS missions (
    compte_id INTEGER NOT NULL REFERENCES comptes(id),
    periode TEXT NOT NULL, -- "j2026-11-17" (jour) ou "s2026-W47" (semaine)
    action TEXT NOT NULL,
    nombre INTEGER NOT NULL DEFAULT 0,
    reclamee INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (compte_id, periode, action)
)');

function periodesMissions() {
    return ['jour' => 'j' . date('Y-m-d'), 'semaine' => 's' . date('o-\WW')]; // o-\WW = année et numéro de semaine ISO
}

// Les missions en cours : toujours le même tirage pour un joueur pendant toute la période (graine fixe, comme botDuPalier)
function missionsDuMoment($compteId) {
    $missions = [];
    foreach (periodesMissions() as $sorte => $periode) {
        mt_srand(crc32("missions-$compteId-$periode"));
        foreach ((array) array_rand(MISSIONS, MISSIONS_PAR_PERIODE) as $action) {
            $objectif = MISSIONS[$action][$sorte];
            $missions["$periode/$action"] = ['sorte' => $sorte, 'periode' => $periode, 'action' => $action, 'objectif' => $objectif,
                'texte' => sprintf(MISSIONS[$action]['texte'], $objectif, $objectif > 1 ? 's' : ''), 'rubis' => RUBIS_MISSION[$sorte]];
        }
    }
    mt_srand(); // on remet du vrai hasard pour la suite
    return $missions;
}

// Le joueur a fait $nombre fois l'action : on compte pour le jour, la semaine (même si la mission n'est pas tirée,
// c'est plus simple) et la saison (points du classement mensuel, voir POINTS_SAISON)
function avancerMission($pdo, $compteId, $action, $nombre = 1) {
    if ($nombre <= 0) return;
    $req = $pdo->prepare('INSERT INTO missions (compte_id, periode, action, nombre) VALUES (?, ?, ?, ?)
        ON CONFLICT (compte_id, periode, action) DO UPDATE SET nombre = nombre + excluded.nombre');
    foreach ([...array_values(periodesMissions()), saisonActuelle()] as $periode) {
        $req->execute([$compteId, $periode, $action, $nombre]);
    }
}

// Missions en cours avec la progression ("fait" et "reclamee") ; efface au passage les compteurs des jours et semaines finis
// (ceux des saisons, "m...", sont gardés : ils servent au classement et au podium)
function etatMissions($pdo, $compteId) {
    $periodes = array_values(periodesMissions());
    $pdo->prepare("DELETE FROM missions WHERE compte_id = ? AND periode NOT IN (?, ?) AND periode NOT LIKE 'm%'")->execute([$compteId, ...$periodes]);
    $req = $pdo->prepare('SELECT periode, action, nombre, reclamee FROM missions WHERE compte_id = ?');
    $req->execute([$compteId]);
    $compteurs = [];
    foreach ($req->fetchAll() as $ligne) $compteurs["$ligne[periode]/$ligne[action]"] = $ligne;
    $missions = missionsDuMoment($compteId);
    foreach ($missions as $cle => &$mission) {
        $mission['fait'] = (int) ($compteurs[$cle]['nombre'] ?? 0);
        $mission['reclamee'] = (bool) ($compteurs[$cle]['reclamee'] ?? false);
    }
    unset($mission); // fin du & : sinon $mission reste lié au dernier élément
    return $missions;
}

// Récupère la récompense d'une mission terminée ("j2026-11-17/duel") ; renvoie les rubis gagnés (0 si impossible)
function reclamerMission($pdo, $compteId, $cle) {
    $mission = missionsDuMoment($compteId)[$cle] ?? null; // une mission d'hier ou inventée est refusée
    if (!$mission) return 0;
    $pdo->beginTransaction();
    // "reclamee = 0" dans la même requête : un double clic ne paie qu'une fois
    $req = $pdo->prepare('UPDATE missions SET reclamee = 1 WHERE compte_id = ? AND periode = ? AND action = ? AND reclamee = 0 AND nombre >= ?');
    $req->execute([$compteId, $mission['periode'], $mission['action'], $mission['objectif']]);
    if ($req->rowCount() === 0) {
        $pdo->rollBack();
        return 0;
    }
    $pdo->prepare('UPDATE comptes SET rubis = rubis + ? WHERE id = ?')->execute([$mission['rubis'], $compteId]);
    avancerMission($pdo, $compteId, 'mission'); // points de saison
    $pdo->commit();
    return $mission['rubis'];
}

// ---------- Saisons (une par mois, heure de Paris) ----------
// Classement remis à zéro chaque mois : les points viennent des compteurs de la table "missions" (période "m2026-11").
// Seulement des actions limitées (stock, rubis, temps...) : pas de points pour les duels perdus, on pourrait en faire à l'infini.
const POINTS_SAISON = ['booster' => 1, 'expedition' => 5, 'fabrication' => 5, 'etoile' => 10, 'mission' => 10, 'victoire' => 20];
const RECOMPENSES_SAISON = [500, 300, 150]; // rubis du 1er, 2e et 3e, versés au début du mois suivant
const CHAMPIONS_SAISON = 20;   // le paquet de saison ne contient que 20 champions, différents chaque mois...
const CHANCE_HOLO_SAISON = 25; // ...et donne un booster holographique 1 fois sur 25 (au lieu de 100)
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

// Une ligne par saison : les champions de son paquet (JSON) et le podium une fois la saison finie (JSON, null avant)
$pdo->exec('CREATE TABLE IF NOT EXISTS saisons (
    saison TEXT PRIMARY KEY, -- "m2026-11"
    champions TEXT,
    podium TEXT
)');

function saisonActuelle() {
    return 'm' . date('Y-m');
}
function saisonPrecedente() {
    return 'm' . date('Y-m', strtotime('first day of last month'));
}
// "m2026-11" => "novembre 2026"
function nomSaison($saison) {
    [$annee, $mois] = explode('-', substr($saison, 1));
    return MOIS[(int) $mois - 1] . " $annee";
}

// Classement d'une saison : [['id' => 3, 'pseudo' => 'Léo', 'points' => 120], ...], du meilleur au moins bon
function classementSaison($pdo, $saison) {
    $cas = '';
    foreach (POINTS_SAISON as $action => $points) $cas .= ' WHEN ' . $pdo->quote($action) . ' THEN ' . (int) $points;
    $req = $pdo->prepare("SELECT c.id, c.pseudo, SUM(m.nombre * CASE m.action $cas ELSE 0 END) AS points
        FROM missions m JOIN comptes c ON c.id = m.compte_id
        WHERE m.periode = ? GROUP BY c.id HAVING points > 0 ORDER BY points DESC, c.pseudo");
    $req->execute([$saison]);
    return $req->fetchAll();
}

// Fin de saison : au premier passage du mois, on paie le top 3 du mois dernier et on garde le podium.
// "podium IS NULL" dans l'UPDATE : même si deux pages se chargent en même temps, on ne paie qu'une fois.
function cloturerSaison($pdo) {
    $saison = saisonPrecedente();
    $req = $pdo->prepare('SELECT podium FROM saisons WHERE saison = ?');
    $req->execute([$saison]);
    if (is_string($req->fetchColumn())) return; // déjà fait (le cas le plus courant : une seule petite requête)
    $pdo->beginTransaction();
    $pdo->prepare('INSERT OR IGNORE INTO saisons (saison) VALUES (?)')->execute([$saison]);
    $podium = array_slice(classementSaison($pdo, $saison), 0, count(RECOMPENSES_SAISON));
    foreach ($podium as $rang => &$joueur) $joueur['rubis'] = RECOMPENSES_SAISON[$rang];
    unset($joueur);
    $req = $pdo->prepare('UPDATE saisons SET podium = ? WHERE saison = ? AND podium IS NULL');
    $req->execute([json_encode($podium), $saison]);
    if ($req->rowCount() === 1) {
        $paie = $pdo->prepare('UPDATE comptes SET rubis = rubis + ? WHERE id = ?');
        foreach ($podium as $joueur) $paie->execute([$joueur['rubis'], $joueur['id']]);
    }
    $pdo->commit();
}
cloturerSaison($pdo);

// Le paquet de la saison (même format que PAQUETS + "champions" et "chanceHolo"), null si Riot ne répond pas.
// Les 20 champions sont tirés une fois par mois (graine fixe) puis gardés en base : pas d'appel à Riot à chaque page.
function paquetSaison($pdo) {
    $saison = saisonActuelle();
    $req = $pdo->prepare('SELECT champions FROM saisons WHERE saison = ?');
    $req->execute([$saison]);
    $champions = json_decode($req->fetchColumn() ?: 'null', true);
    if (!$champions) {
        $liste = listeChampions();
        if ($liste === null) return null;
        mt_srand(crc32("saison-$saison"));
        $champions = array_map('strval', array_rand($liste, CHAMPIONS_SAISON));
        mt_srand();
        $pdo->prepare('INSERT INTO saisons (saison, champions) VALUES (?, ?) ON CONFLICT (saison) DO UPDATE SET champions = excluded.champions')
            ->execute([$saison, json_encode($champions)]);
    }
    return ['nom' => 'Saison', 'sousTitre' => ucfirst(nomSaison($saison)) . ' seulement', 'type' => 'champions', 'classe' => null,
        'image' => "champion/$champions[0].png", 'champions' => $champions, 'chanceHolo' => CHANCE_HOLO_SAISON];
}

// Tous les paquets à proposer : celui de la saison en premier, puis ceux de PAQUETS
function tousLesPaquets($pdo) {
    $saison = paquetSaison($pdo);
    return $saison ? ['saison' => $saison] + PAQUETS : PAQUETS;
}
