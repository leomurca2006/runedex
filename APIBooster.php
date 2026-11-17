<?php
// GET  : liste des paquets + état du stock et des rubis si connecté
// POST paquet=<id>&nombre=1|5|10 : ouvre des boosters
//      (le tirage, le stock et les rubis sont gérés ici, côté serveur, donc impossibles à tricher)
//      - champions  : payés avec le stock (1 booster toutes les 10 min)
//      - composants : payés 300 rubis chacun ; tous les 5 achetés, 1 booster d'objets complets offert
//      - objets     : payés avec les boosters d'objets offerts
require 'commun.php';
header('Content-Type: application/json');

$connecte = isset($_SESSION['id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Les paquets sont visibles par tout le monde, le stock seulement si on est connecté
    $reponse = ['connecte' => $connecte, 'paquets' => PAQUETS];
    echo json_encode($connecte ? $reponse + etatStock($pdo, $_SESSION['id']) + etatRubis($pdo, $_SESSION['id']) + etatQuotidien($pdo, $_SESSION['id']) : $reponse);
    exit;
}

if (!$connecte) {
    http_response_code(401);
    echo json_encode(['erreur' => 'connexion']);
    exit;
}
$id = $_SESSION['id'];

// On ne fait jamais confiance à ce qu'envoie le navigateur : on vérifie le paquet et le nombre
$paquet = PAQUETS[(string) ($_POST['paquet'] ?? '')] ?? null;
$nombre = (int) ($_POST['nombre'] ?? 1);
if ($paquet === null || !in_array($nombre, NOMBRES_AUTORISES, true)) {
    http_response_code(400);
    echo json_encode(['erreur' => 'parametres']);
    exit;
}
$type = $paquet['type'];

// On récupère les cartes possibles AVANT de payer : si Riot ne répond pas, le joueur ne perd rien
if ($type === 'champions') {
    $champions = listeChampions();
    $tirables = $champions === null ? null : ($paquet['classe'] === null
        ? $champions
        : array_filter($champions, fn($champ) => in_array($paquet['classe'], $champ['tags'], true)));
} else {
    $objets = listeObjets();
    $tirables = $objets === null ? null : array_filter($objets, fn($objet) => $objet['type'] === $type);
}
if ($tirables === null) {
    http_response_code(502);
    echo json_encode(['erreur' => 'api']);
    exit;
}

// Paiement : une seule requête qui vérifie ET retire (stock, rubis ou boosters offerts).
// Deux clics en même temps ne peuvent donc pas ouvrir plus que ce qu'on a.
$pdo->beginTransaction();
if ($type === 'champions') {
    // MAX(...) : si le stock est plein, on repart de "il y a 10 boosters" pour ne pas compter le temps en trop.
    $req = $pdo->prepare('UPDATE comptes SET dernier_booster = MAX(dernier_booster, :plein) + :cout
        WHERE id = :id AND MAX(dernier_booster, :plein) + :cout <= :maintenant');
    $valeurs = ['plein' => time() - STOCK_MAX * ATTENTE, 'cout' => $nombre * ATTENTE, 'id' => $id, 'maintenant' => time()];
} elseif ($type === 'composants') {
    // Division entière : passer de 4 à 6 achats franchit le cap des 5 => +1 booster d'objets
    $req = $pdo->prepare('UPDATE comptes SET rubis = rubis - :cout,
        boosters_objets = boosters_objets + (composants_achetes + :nombre) / :cap - composants_achetes / :cap,
        composants_achetes = composants_achetes + :nombre
        WHERE id = :id AND rubis >= :cout');
    $valeurs = ['cout' => $nombre * PRIX_BOOSTER_COMPOSANTS, 'nombre' => $nombre, 'cap' => BOOSTERS_POUR_UN_OBJETS, 'id' => $id];
} else {
    $req = $pdo->prepare('UPDATE comptes SET boosters_objets = boosters_objets - :nombre WHERE id = :id AND boosters_objets >= :nombre');
    $valeurs = ['nombre' => $nombre, 'id' => $id];
}
// PARAM_INT obligatoire : sinon PDO envoie du texte, et pour SQLite un texte est toujours "plus grand" qu'un nombre dans MAX()
foreach ($valeurs as $nom => $valeur) {
    $req->bindValue($nom, $valeur, PDO::PARAM_INT);
}
$req->execute();
if ($req->rowCount() === 0) {
    $pdo->rollBack();
    http_response_code(429);
    echo json_encode(['erreur' => 'stock'] + etatStock($pdo, $id) + etatRubis($pdo, $id));
    exit;
}

// Ce que le joueur possède déjà, pour savoir si chaque carte est "Nouveau" ou "Doublon"
$req = $type === 'champions'
    ? $pdo->prepare('SELECT champion FROM cartes WHERE compte_id = ?')
    : $pdo->prepare('SELECT objet FROM objets_debloques WHERE compte_id = ?');
$req->execute([$id]);
$possedes = array_flip($req->fetchAll(PDO::FETCH_COLUMN));

$boosters = [];
$holos = []; // true/false pour chaque booster
for ($b = 0; $b < $nombre; $b++) {
    $holo = random_int(1, CHANCE_HOLO) === 1;
    $holos[] = $holo;
    $n = $holo ? NB_CARTES_HOLO : NB_CARTES;
    // Cartes différentes dans un booster ; si le paquet en a moins de 50 (ex. Supports), on complète au hasard
    $cles = (array) array_rand($tirables, min($n, count($tirables)));
    while (count($cles) < $n) $cles[] = array_rand($tirables);
    $cartes = [];
    foreach ($cles as $cle) {
        $cle = (string) $cle; // PHP transforme la clé "3031" en nombre : on la remet en texte
        if ($type === 'champions') {
            ajouterCarte($pdo, $id, $cle);
            $carte = ['cle' => $cle, 'nom' => $champions[$cle]['name'], 'titre' => $champions[$cle]['title']];
        } else {
            ajouterObjet($pdo, $id, $cle, $type);
            $objet = $tirables[$cle];
            $carte = ['cle' => $cle, 'nom' => $objet['name'], 'titre' => $objet['plaintext'] ?: $objet['gold']['total'] . ' po',
                'type' => 'objet', 'description' => $objet['description'], 'prix' => $objet['gold']['total']];
        }
        $carte['nouveau'] = !isset($possedes[$cle]);
        $possedes[$cle] = true; // s'il ressort dans le booster suivant, ce sera un doublon
        $cartes[] = $carte;
    }
    shuffle($cartes); // array_rand renvoie les clés dans l'ordre
    $boosters[] = $cartes;
}
$pdo->commit();

echo json_encode(['boosters' => $boosters, 'holos' => $holos] + etatStock($pdo, $id) + etatRubis($pdo, $id));
