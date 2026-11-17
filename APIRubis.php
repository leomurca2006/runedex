<?php
// POST action=doublons&type=champions|composants|objets : recycle tous les doublons de ce type
// POST action=acheter  : achète 1 booster avec des rubis
// POST action=quotidien : récompense du jour
// Répond avec l'état des rubis et du stock.
require 'commun.php';
header('Content-Type: application/json');

if (!isset($_SESSION['id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(401);
    echo json_encode(['erreur' => 'connexion']);
    exit;
}
$id = $_SESSION['id'];
$action = $_POST['action'] ?? '';
$reponse = [];

if ($action === 'doublons') {
    $reponse['gain'] = recyclerDoublons($pdo, $id, $_POST['type'] ?? 'champions');
} elseif ($action === 'acheter') {
    if (!acheterBooster($pdo, $id)) {
        http_response_code(409);
        $reponse['erreur'] = 'achat';
    }
} elseif ($action === 'quotidien') {
    $reponse['gain'] = recupererQuotidien($pdo, $id);
    if (!$reponse['gain']) {
        http_response_code(409);
        $reponse['erreur'] = 'deja';
    }
} else {
    http_response_code(400);
    $reponse['erreur'] = 'action';
}

echo json_encode($reponse + etatRubis($pdo, $id) + etatStock($pdo, $id) + etatQuotidien($pdo, $id));
