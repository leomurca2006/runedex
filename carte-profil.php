<?php
// Carte de profil (avatar, chiffres, vitrine), incluse par compte.php et joueurs.php.
// Attend $profil (profilJoueur() de commun.php, + 'rang' en option) et $champions (listeChampions(), peut être vide).
if (!isset($profil)) exit; // ouvert directement dans le navigateur : rien à afficher
$portrait = fn($carte) => 'https://ddragon.leagueoflegends.com/cdn/img/champion/loading/' . urlencode($carte['champion']) . '_' . (int) $carte['skin'] . '.jpg';
?>
<section class="panneau carte-profil">
    <div class="profil-tete">
        <?php if ($profil['avatar']): ?>
            <img class="avatar" src="<?= htmlspecialchars($portrait($profil['avatar'])) ?>" alt="" width="88" height="88">
        <?php else: ?>
            <span class="avatar avatar-vide" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($profil['pseudo'], 0, 1))) ?></span>
        <?php endif; ?>
        <div>
            <h1><?= htmlspecialchars($profil['pseudo']) ?></h1>
            <?php if (isset($profil['rang'])): ?><p class="profil-rang">#<?= $profil['rang'] ?> au classement</p><?php endif; ?>
        </div>
    </div>
    <div class="stats profil-stats">
        <p class="stat"><strong><?= $profil['champions'] ?></strong><span>champions</span></p>
        <p class="stat"><strong><?= $profil['skins'] ?></strong><span>skins</span></p>
        <p class="stat"><strong><?= $profil['objets'] ?></strong><span>objets complets</span></p>
        <p class="stat"><strong><?= $profil['defis'] ?></strong><span>défis</span></p>
        <p class="stat"><strong><?= min($profil['palier_duel'] - 1, PALIERS_DUEL) ?><small>/<?= PALIERS_DUEL ?></small></strong><span>bots battus</span></p>
    </div>
    <h2>Vitrine</h2>
    <?php if (!$profil['vitrine']): ?>
        <p class="info-expedition">Aucune carte en vitrine pour l'instant.</p>
    <?php endif; ?>
    <div class="vitrine">
        <?php foreach ($profil['vitrine'] as $carte): $cle = $carte['champion']; $nom = $champions[$cle]['name'] ?? $cle; $e = (int) $carte['etoiles']; ?>
            <div class="carte">
                <img src="<?= htmlspecialchars($portrait($carte)) ?>" alt="<?= htmlspecialchars($nom) ?>" width="154" height="280" loading="lazy">
                <h4><?= htmlspecialchars($nom) ?></h4>
                <span class="etoiles" role="img" aria-label="<?= $e ?> étoile<?= $e > 1 ? 's' : '' ?> sur <?= ETOILES_MAX ?>"><?= str_repeat('★', $e) . str_repeat('☆', ETOILES_MAX - $e) ?></span>
                <p><?= htmlspecialchars($champions[$cle]['title'] ?? '') ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>
