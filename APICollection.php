<?php
// Liste des champions débloqués par le joueur connecté : {"Ahri": 2, "Zed": 1, ...}
require 'commun.php';
header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['erreur' => 'connexion']);
    exit;
}

$req = $pdo->prepare('SELECT champion, nombre FROM cartes WHERE compte_id = ?');
$req->execute([$_SESSION['id']]);
// (object) : une collection vide doit donner {} et pas []
echo json_encode((object) array_column($req->fetchAll(), 'nombre', 'champion'));
