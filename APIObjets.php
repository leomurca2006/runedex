<?php
// GET  : inventaire d'objets du joueur connecté : {"3031": 1, "1038": 2, ...}
// POST action=fabriquer&objet=<id> : fabrique un objet en consommant ses composants directs
require 'commun.php';
header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['erreur' => 'connexion']);
    exit;
}
$id = $_SESSION['id'];

function inventaire($pdo, $id) {
    $req = $pdo->prepare('SELECT objet, nombre FROM objets_debloques WHERE compte_id = ?');
    $req->execute([$id]);
    // (object) : un inventaire vide doit donner {} et pas []
    return (object) array_map('intval', array_column($req->fetchAll(), 'nombre', 'objet'));
}

$reponse = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fabriquer') {
    $objets = listeObjets();
    $cle = (string) ($_POST['objet'] ?? '');
    $cible = $objets[$cle] ?? null;
    if ($objets === null || $cible === null || empty($cible['from'])) {
        http_response_code(400);
        echo json_encode(['erreur' => 'objet']);
        exit;
    }
    // Ex. Lame d'infini : BF Glaive + Pioche + Cape d'agilité. Un composant peut être demandé 2 fois.
    $pdo->beginTransaction();
    foreach ($cible['from'] as $composant) {
        if (!retirerObjet($pdo, $id, (string) $composant)) {
            $pdo->rollBack(); // il manque un composant : on rend ceux déjà retirés
            http_response_code(409);
            echo json_encode(['erreur' => 'composants', 'inventaire' => inventaire($pdo, $id)] + etatRubis($pdo, $id));
            exit;
        }
    }
    ajouterObjet($pdo, $id, $cle, $cible['type']);
    avancerMission($pdo, $id, 'fabrication');
    $pdo->commit();
    $reponse['fabrique'] = $cle;
}

echo json_encode($reponse + ['inventaire' => inventaire($pdo, $id)] + etatRubis($pdo, $id));
