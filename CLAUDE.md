# booster-lol

Reconstruction du site de Léo, ancien nom « LOL c'est bien », renommé « Runedex » (nov. 2026) (projet final de 1re année IIM, version finale perdue).
Base reprise du devoir d'avril 2026 : `Desktop\Mur-a_L-o_g1_introduction_back_end-main\` (dossier original intact, avec toutes les pages et images).
L'énoncé du devoir est dans `Devoir-front-back.pdf`.

## Fichiers
- `APISite.php` : proxy vers Data Dragon (Riot), version figée 14.8.1. `?champions=1` (liste) et `?champion=Cle` (détail).
- `api.html` : recherche en direct (à chaque lettre, champions dont le nom commence par la saisie, avec débloqué ou non). Clic = fiche détaillée (code d'origine de Léo).
- `APICollection.php` : champions débloqués du joueur connecté `{cle: nombre}` (401 si pas connecté).
- `booster.html` + `booster.js` : page Boosters (grille de paquets + panneau du paquet choisi, stock et minuteur). Le bouton Ouvrir mène à `ouvrir.html?id=<paquet>`.
- `ouvrir.html` + `ouvrir.js` : ouverture plein écran (déchirure au geste ou bouton, ×1/×5/×10, révélation carte par carte avec clic/swipe/Espace, récap en éventail ; clic sur une carte du récap = modale `<dialog>` avec la fiche du champion via `APISite.php?champion=`). Respecte prefers-reduced-motion. Pas de main.js sur cette page.
- `paquets.js` : `htmlPaquet()` (dessin d'un paquet), `formatDuree()`, `htmlCarte()` et `ouvrirFiche(cle, etiquette)` (fiche champion dans `#ficheModale`), partagés par les deux pages. Sur `booster.html`, bouton « Voir les personnages » = modale des champions du paquet (grisés si pas débloqués), clic = fiche. Cartes = classe `.carte` de `style.css`.
- `commun.php` : session, connexion PDO SQLite, création des tables, `listeChampions()`. Base dans `../booster-lol-donnees.sqlite` (hors du site exprès).
- `APIBooster.php` : GET = paquets + stock (`connecte` false si pas connecté). POST `paquet` + `nombre` (1/5/10) = tirage de 5 champions différents par booster dans la classe du paquet, avec `nouveau` true/false par carte. Stock de 10 boosters max, +1 toutes les 10 min, calculé à partir de `dernier_booster` (`etatStock()` dans `commun.php`). Paquets = constante `PAQUETS` dans `commun.php` (pas de rareté de cartes). Booster holographique : 1 chance sur 100 par booster (`CHANCE_HOLO`), 50 cartes (`NB_CARTES_HOLO`, complétées au hasard si le paquet en a moins), réponse `holos` [bool par booster] ; `ouvrir.js` passe le paquet en classe `.holo` et affiche ce booster en grille (`.eventail-holo`) dans le récap.
- Design des paquets : style Hextech (bleu nuit, cadre doré, médaillon hexagonal) dans `style.css`, `--base` = couleur de la lueur par paquet.
- `echanges.php` : échanges 1 contre 1 entre joueurs (proposer / accepter / refuser / annuler, historique). Table `echanges` ; à l'acceptation tout est revérifié dans une transaction (`retirerCarte` / `ajouterCarte` dans `commun.php`).
- `dos-carte.svg` : visuel du dos des cartes (formes seulement, utilisé par `.dos` dans `style.css`).
- Rubis : colonne `comptes.rubis` (ajoutée automatiquement dans `commun.php`). Recycler 1 carte = +3 (`RUBIS_PAR_CARTE`), 1 booster = 180 (`PRIX_BOOSTER`, +1 dans le stock, refusé si stock plein). Fonctions `etatRubis`, `recyclerCarte`, `recyclerDoublons`, `acheterBooster` dans `commun.php`.
- `APIRubis.php` : POST `action=doublons` (recycle tous les doublons) ou `action=acheter`. Recyclage carte par carte et suggestion des doublons dans `collection.php` ; suggestion aussi dans le récap d'`ouvrir.html` ; achat dans le panneau de `booster.html`.
- `objets.html` + `objets.js` : objets achetables sur la Faille (carte 11), classés base / intermédiaires / finaux, recherche, fiche en modale avec arbre de fabrication (`from`), « permet de construire » (`into`), quantité possédée et bouton Fabriquer (consomme les composants directs, `APIObjets.php` POST `action=fabriquer`). `objets.html?objet=<id>` ouvre directement une fiche.
- Boosters d'objets (paquets `composants` et `objets` dans `PAQUETS`, champ `type`) : Composants = 300 rubis (`PRIX_BOOSTER_COMPOSANTS`), Objets complets = offert tous les 5 boosters Composants achetés (`composants_achetes`, `boosters_objets` dans `comptes`). Type d'un objet calculé par `listeObjets()` (`objets` = a des composants et ne construit rien, sinon `composants`), même règle que `objets.js`. Inventaire dans `objets_debloques` (colonnes `nombre`, `type`).
- Recyclage par type (`recyclerDoublons($pdo, $id, $type)`, `RUBIS_RECYCLAGE`) : champions 3, composants 5, objets 10 rubis par exemplaire en double. Choix du type dans `collection.php` (`?type=` présélectionne).
- `expeditions.php` : envoyer un champion en expédition (constante `EXPEDITIONS` dans `commun.php` : 1 h / 4 h / 8 h, rubis + composants aléatoires), 3 à la fois max (`EXPEDITIONS_MAX`), puis Récupérer. Carte de Runeterra en SVG dessinée à la main + un bouton par royaume (constante `ROYAUMES` en haut d'`expeditions.php` : nom, position x/y en %, icône) ; clic = modale d'envoi. Le royaume ne change pas la récompense. Table `expeditions` (colonne `royaume`, ligne supprimée à la récupération). Pendant l'expédition, `retirerCarte` refuse de retirer le dernier exemplaire (pas de recyclage ni d'échange) ; badge « En expédition » dans `collection.php`.
- Skins : débloqués uniquement par les expéditions (chance `skin` en % dans `EXPEDITIONS` : 10 / 25 / 50, un skin du champion envoyé pas encore possédé, via `skinsChampion()` dans `commun.php`). Table `skins_debloques` (compte_id, champion, num). Colonne `cartes.skin` = skin équipé, affiché dans `collection.php` (bouton Skins = modale, liste chargée par `APISite.php?champion=`, POST `action=skin`).
- Objet en expédition : on peut emporter 1 objet complet (colonne `expeditions.objet`), retiré de l'inventaire au départ et rendu au retour ; bonus `BONUS_OBJET_RUBIS` (+50 % rubis) et `BONUS_OBJET_SKIN` (+20 points de chance de skin). `comptes.expeditions_finies` compte les retours (défis).
- Récompense quotidienne : `etatQuotidien` / `recupererQuotidien` dans `commun.php` (colonnes `comptes.dernier_quotidien` + `serie`, 20 rubis puis +10 par jour de suite, max 80), bandeau en haut de `booster.html`, POST `APIRubis.php` `action=quotidien`. Fuseau Europe/Paris.
- `defis.php` : défis (constante `DEFIS` : champions, classes complètes, objets complets, skins, expéditions, échanges) avec récompense en rubis, réclamés une seule fois (table `defis_reussis`).
- `joueurs.php` : classement des joueurs (champions, skins, objets complets, défis) ; `?id=` = collection d'un joueur avec ses skins équipés + lien « Proposer un échange ».
- Duels contre des bots : `duels.php` + `duels.js` (aperçu des stats, animation du combat) + `APIDuel.php` (POST `champion` + `objets[]`, 6 max, objets non consommés). Logique dans `commun.php` : `statsChampion` (niveau 18, puissance magique = note magie × 15), `bonusObjet` (stats Data Dragon des objets), `statsCombat`, `botDuPalier` (graine fixe par joueur + palier : palier p = p-1 objets complets, paliers 8-10 +10/20/30 % de stats), `simulerDuel` (dégâts × 100/(100+armure), 60 % de la PM réduite par la RM, critique ×1,75, vol de vie). `comptes.palier_duel` (11 = tous battus), 30 × palier rubis la 1re fois. Défis `duels1` / `duels10`.
- Étoiles : colonne `cartes.etoiles` (1 à 5, `ETOILES_MAX`). Bouton Améliorer dans `collection.php` (POST `action=etoile`, `ameliorerCarte()` dans `commun.php`) : passer de e à e+1 étoiles consomme `coutEtoile(e)` = 1/2/4/8 doublons. En duel, +`BONUS_ETOILE` (10 %) de stats par étoile au-dessus de 1 (paramètre `$bonus` de `statsCombat`, recopié dans `duels.js`). Recycler ou échanger son dernier exemplaire fait perdre les étoiles.
- Menu : 10 liens, hamburger sous 1340px.
- `compte.php` : inscription / connexion / déconnexion (`password_hash`).
- `collection.php` : cartes du joueur connecté (doublons comptés). Bouton Favori par carte (colonne `cartes.favori`, POST `action=favori` inverse l'état), favoris affichés en premier.
- `style.css` : design sombre inspiré de kyara.games (refait en nov. 2026, les visuels de Léo sont dans le dossier d'origine). Sombre par défaut, thème clair via `data-theme="light"` posé par `main.js`.
- `main.js` : dark mode et menu hamburger.

## Lancer
`php -S localhost:8000` puis http://localhost:8000/booster.html (PHP 8.3 installé, pas XAMPP).

## Fait
- Comptes, collection en base, délai de 10 min côté serveur (novembre 2026).

## Préférences
- Léo est francophone, étudiant : expliquer simplement, garder son code et son style plutôt que tout réécrire.
- Ne pas ajouter de fonctionnalités non demandées (pas de raretés pour l'instant).
