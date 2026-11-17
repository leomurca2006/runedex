<?php
if (isset($_GET['champion'])) {
    $key = $_GET['champion'];
    $version = '14.8.1';
    $url = "https://ddragon.leagueoflegends.com/cdn/{$version}/data/fr_FR/champion/{$key}.json";
    $response = file_get_contents($url);
    header('Content-Type: application/json');
    echo $response;
    exit;
}

if (isset($_GET['objets'])) {
    $version = '14.8.1';
    $url = "https://ddragon.leagueoflegends.com/cdn/{$version}/data/fr_FR/item.json";
    $response = file_get_contents($url);
    header('Content-Type: application/json');
    echo $response;
    exit;
}

if (isset($_GET['champions'])) {
    $version = '14.8.1';
    $url = "https://ddragon.leagueoflegends.com/cdn/{$version}/data/fr_FR/champion.json";
    $response = file_get_contents($url);
    header('Content-Type: application/json');
    echo $response;
    exit;
}
?>