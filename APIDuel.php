<?php
// POST champion=<Cle>&objets[]=<id>&objets[]=<id>... : combat contre le bot de son palier
// Répond : victoire, coups (pour l'animation), stats des deux combattants, rubis gagnés, nouveau palier.
require 'commun.php';
header('Content-Type: application/json');

function erreur($code, $erreur) {
    http_response_code($code);
    echo json_encode(['erreur' => $erreur]);
    exit;
}

if (!isset($_SESSION['id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') erreur(401, 'connexion');
$id = $_SESSION['id'];

// On revérifie tout : le champion et les objets doivent être à nous
$champion = (string) ($_POST['champion'] ?? '');
$choix = $_POST['objets'] ?? [];
if (!is_array($choix) || count($choix) > OBJETS_MAX_DUEL) erreur(400, 'objets');
$choix = array_map('strval', $choix);
if (count(array_unique($choix)) !== count($choix)) erreur(400, 'objets'); // chaque objet une seule fois

$req = $pdo->prepare('SELECT etoiles FROM cartes WHERE compte_id = ? AND champion = ?');
$req->execute([$id, $champion]);
$etoiles = $req->fetchColumn();
if ($etoiles === false) erreur(400, 'champion');
$req = $pdo->prepare('SELECT objet FROM objets_debloques WHERE compte_id = ?');
$req->execute([$id]);
if (array_diff($choix, $req->fetchAll(PDO::FETCH_COLUMN))) erreur(400, 'objets');

$champions = listeChampions();
$objets = listeObjets();
if ($champions === null || $objets === null) erreur(502, 'api');

$req = $pdo->prepare('SELECT palier_duel FROM comptes WHERE id = ?');
$req->execute([$id]);
$palier = (int) $req->fetchColumn();
$bot = botDuPalier($id, $palier, $champions, $objets);
$stats = statsCombat($champions[$champion], array_map(fn($objet) => $objets[$objet], $choix), ($etoiles - 1) * BONUS_ETOILE);
[$victoire, $coups] = simulerDuel($stats, $bot['stats']);
avancerMission($pdo, $id, 'duel'); // victoire ou défaite : la mission compte les duels joués

// Victoire sur un nouveau palier : rubis + palier suivant.
// "AND palier_duel = :palier" : deux combats envoyés en même temps ne paient qu'une fois.
$gain = 0;
if ($victoire && $palier <= PALIERS_DUEL) {
    $req = $pdo->prepare('UPDATE comptes SET palier_duel = palier_duel + 1, rubis = rubis + :gain WHERE id = :id AND palier_duel = :palier');
    $req->execute(['gain' => RUBIS_PAR_PALIER * $palier, 'id' => $id, 'palier' => $palier]);
    if ($req->rowCount() === 1) $gain = RUBIS_PAR_PALIER * $palier;
}

echo json_encode([
    'victoire' => $victoire,
    'coups' => $coups,
    'joueur' => ['cle' => $champion, 'nom' => $champions[$champion]['name'], 'stats' => $stats],
    'bot' => $bot,
    'gain' => $gain,
    'palier' => $palier + ($gain ? 1 : 0),
]);
