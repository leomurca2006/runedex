// Dessin d'un paquet, des cartes et fiche d'un champion : utilisé par booster.html et ouvrir.html.
// La liste des paquets vient du serveur (APIBooster.php, constante PAQUETS dans commun.php).
const VERSION_DD = '14.8.1';

function htmlPaquet(id, paquet) {
    return `
        <div class="paquet paquet-${id}">
            <span class="paquet-marque">RUNEDEX</span>
            <span class="medaillon">
                <img src="https://ddragon.leagueoflegends.com/cdn/${VERSION_DD}/img/${paquet.image}" alt="">
            </span>
            <span class="paquet-nom">${paquet.nom}</span>
            <span class="paquet-sous-titre">${paquet.sousTitre}</span>
            <span class="paquet-pastille">5 cartes</span>
        </div>`;
}

// "8 min 05 s" à partir d'un nombre de millisecondes
function formatDuree(ms) {
    const s = Math.ceil(Math.max(0, ms) / 1000);
    return `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, '0')} s`;
}

// Illustration d'une carte : grande image du champion, ou icône de l'objet
function imageCarte(carte) {
    return carte.type === 'objet'
        ? `https://ddragon.leagueoflegends.com/cdn/${VERSION_DD}/img/item/${carte.cle}.png`
        : `https://ddragon.leagueoflegends.com/cdn/img/champion/loading/${carte.cle}_0.jpg`;
}

function htmlCarte(carte) {
    return `
        <div class="carte${carte.type === 'objet' ? ' carte-objet' : ''}${carte.holo ? ' carte-holo' : ''}">
            <img src="${imageCarte(carte)}" alt="${carte.nom}" width="154" height="280">
            <h4>${carte.nom}</h4>
            <p>${carte.titre}</p>
        </div>`;
}

// ---------- Fiche d'un champion (modale #ficheModale / #ficheContenu, sur booster.html et ouvrir.html) ----------
// Les rôles Data Dragon sont en anglais, même en fr_FR
const ROLES = { Assassin: 'Assassin', Fighter: 'Combattant', Mage: 'Mage', Marksman: 'Tireur', Support: 'Support', Tank: 'Tank' };

// Barre de 0 à 10 pour une statistique
function htmlStat(nom, valeur) {
    return `
        <div class="stat-barre">
            <span>${nom}</span>
            <span class="jauge" role="img" aria-label="${valeur} sur 10"><span style="width:${valeur * 10}%"></span></span>
            <strong>${valeur}</strong>
        </div>`;
}

// etiquette : HTML d'une étiquette en plus des rôles (ex. « Nouveau »), ou ''
function ouvrirFiche(cle, etiquette = '') {
    const contenu = document.getElementById('ficheContenu');
    contenu.innerHTML = '<p class="fiche-chargement">Chargement…</p>';
    document.getElementById('ficheModale').showModal(); // showModal : Échap ferme, le focus reste dans la fenêtre

    fetch(`APISite.php?champion=${cle}`) // même appel que la page Rechercher
        .then(response => response.json())
        .then(detail => {
            const champ = detail.data[cle];
            const img = `https://ddragon.leagueoflegends.com/cdn/${VERSION_DD}/img`;
            contenu.innerHTML = `
                <div class="fiche-carte">${htmlCarte({ cle, nom: champ.name, titre: champ.title })}</div>
                <div class="fiche-infos">
                    <h2 id="ficheTitre">${champ.name}</h2>
                    <p class="fiche-titre">${champ.title}</p>
                    <p class="fiche-etiquettes">
                        ${etiquette}
                        ${champ.tags.map(tag => `<span class="etiquette">${ROLES[tag] || tag}</span>`).join('')}
                    </p>
                    <div class="fiche-stats-barres">
                        ${htmlStat('Attaque', champ.info.attack)}
                        ${htmlStat('Défense', champ.info.defense)}
                        ${htmlStat('Magie', champ.info.magic)}
                        ${htmlStat('Difficulté', champ.info.difficulty)}
                    </div>
                    <p class="fiche-histoire">${champ.lore}</p>
                    <h3>Compétences</h3>
                    <div class="sort">
                        <img src="${img}/passive/${champ.passive.image.full}" width="40" height="40" alt="">
                        <div><strong>${champ.passive.name}</strong> <small>(passif)</small><p>${champ.passive.description}</p></div>
                    </div>
                    ${champ.spells.map((sort, n) => `
                        <div class="sort">
                            <img src="${img}/spell/${sort.image.full}" width="40" height="40" alt="">
                            <div><strong>${sort.name}</strong> <small>(${'QWER'[n]})</small><p>${sort.description}</p></div>
                        </div>`).join('')}
                </div>`;
        })
        .catch(err => {
            console.error('Erreur :', err);
            contenu.innerHTML = '<p class="fiche-chargement">Impossible de charger la fiche. Réessaie.</p>';
        });
}
