// Page Objets : tous les objets de la Faille de l'invocateur, leur arbre de fabrication,
// l'inventaire du joueur (APIObjets.php) et la fabrication d'un objet à partir de ses composants.
const VERSION = '14.8.1';
const liste = document.getElementById('listeObjets');
const info = document.getElementById('infoObjets');
const recherche = document.getElementById('rechercheObjet');
const modale = document.getElementById('ficheObjet');

let tous = {};         // tous les objets de Data Dragon (pour retrouver n'importe quel composant)
let objets = [];       // [id, objet] achetables sur la Faille, triés par prix
let inventaire = null; // {id: nombre d'exemplaires}, null si pas connecté

function icone(id) {
    return `https://ddragon.leagueoflegends.com/cdn/${VERSION}/img/item/${id}.png`;
}

function nombre(id) {
    return inventaire?.[id] || 0;
}

function estDebloque(id) {
    return nombre(id) > 0;
}

// Même principe que la recherche de champions : on ignore majuscules, accents et apostrophes
function simplifier(texte) {
    return texte.toLowerCase().normalize('NFD').replace(/[^a-z0-9]/g, '');
}

// Objet achetable sur la Faille de l'invocateur (carte 11), hors objets réservés à un champion
function surLaFaille(objet) {
    return objet.gold.purchasable && objet.maps?.['11'] && !objet.requiredChampion && !objet.requiredAlly && objet.inStore !== false;
}

// Base = pas de composant ; intermédiaire = sert à construire autre chose ; final = le reste
function niveau(objet) {
    if (!objet.from?.length) return 'base';
    const versFaille = (objet.into || []).filter(id => tous[id] && surLaFaille(tous[id]));
    return versFaille.length ? 'intermediaire' : 'final';
}
const SECTIONS = [
    ['final', 'Objets finaux'],
    ['intermediaire', 'Composants intermédiaires'],
    ['base', 'Objets de base'],
];

Promise.all([
    fetch('APISite.php?objets=1').then(response => response.json()),
    fetch('APIObjets.php').then(response => response.ok ? response.json() : null),
])
    .then(([data, compte]) => {
        tous = data.data;
        objets = Object.entries(tous)
            .filter(([id, objet]) => surLaFaille(objet))
            .sort(([, a], [, b]) => a.gold.total - b.gold.total);
        majCompte(compte);
        // objets.html?objet=3031 (lien depuis le récap d'un booster) : on ouvre directement sa fiche
        const demande = new URLSearchParams(location.search).get('objet');
        if (tous[demande]) ouvrirObjet(demande);
    })
    .catch(err => {
        console.error('Erreur :', err);
        info.textContent = 'Impossible de charger les objets. Recharge la page.';
    });

recherche.addEventListener('input', afficherListe);

// Inventaire + doublons donnés par APIObjets.php (null si pas connecté)
function majCompte(compte) {
    inventaire = compte ? compte.inventaire : null;
    document.getElementById('nbDebloques').textContent = inventaire
        ? `${objets.filter(([id]) => estDebloque(id)).length}/${objets.length}`
        : '–';
    // Suggestion de recyclage des composants / objets en double (le recyclage se fait dans la collection)
    const doublons = compte ? compte.doublonsParType : { composants: 0, objets: 0 };
    const total = doublons.composants + doublons.objets;
    const lien = document.getElementById('lienRecyclage');
    lien.hidden = total === 0;
    lien.href = `collection.php?type=${doublons.composants ? 'composants' : 'objets'}#recyclage`;
    lien.querySelector('span').textContent = `Tu as ${total} objet${total > 1 ? 's' : ''} en double : les recycler`;
    afficherListe();
}

// On a tous les composants directs de cet objet en inventaire : on peut le fabriquer tout de suite
function estFabricable(id) {
    return inventaire !== null && tous[id]?.from?.length > 0 && composantsManquants(id).length === 0;
}

function htmlTuile([id, objet]) {
    const ok = estDebloque(id);
    const fabricable = estFabricable(id);
    return `
        <button class="objet${ok ? '' : ' verrouille'}${fabricable ? ' fabricable' : ''}" data-id="${id}">
            ${fabricable ? '<span class="etiquette-fabricable"><i class="material-icons" aria-hidden="true">construction</i>Fabricable</span>' : ''}
            <img src="${icone(id)}" alt="" width="48" height="48" loading="lazy">
            <span class="objet-nom">${objet.name}</span>
            <span class="objet-prix">${objet.gold.total} po</span>
            ${ok ? '' : '<i class="material-icons cadenas" aria-label="Verrouillé">lock</i>'}
            ${nombre(id) > 1 ? `<span class="badge">×${nombre(id)}</span>` : ''}
        </button>`;
}

function afficherListe() {
    const texte = simplifier(recherche.value);
    const trouves = objets.filter(([id, objet]) => simplifier(objet.name).includes(texte));
    info.textContent = inventaire === null
        ? 'Connecte-toi pour voir quels objets tu as débloqués.'
        : `${trouves.length} objet${trouves.length > 1 ? 's' : ''}`;
    const nbFabricables = inventaire === null ? 0 : objets.filter(([id]) => estFabricable(id)).length;
    if (nbFabricables) info.textContent += ` · ${nbFabricables} que tu peux fabriquer maintenant`;
    // En premier : ce qu'on peut fabriquer tout de suite (ces objets restent aussi dans leur catégorie)
    const groupes = [['À fabriquer maintenant', trouves.filter(([id]) => estFabricable(id))]]
        .concat(SECTIONS.map(([cle, titre]) => [titre, trouves.filter(([id, objet]) => niveau(objet) === cle)]));
    liste.innerHTML = groupes.map(([titre, groupe]) => groupe.length ? `
        <section class="section-objets">
            <h2>${titre} <small>${groupe.length}</small></h2>
            <div class="grille-objets">${groupe.map(htmlTuile).join('')}</div>
        </section>` : '').join('');
}

// ---------- Fiche d'un objet + arbre de fabrication ----------
liste.addEventListener('click', event => {
    const tuile = event.target.closest('.objet');
    if (tuile) ouvrirObjet(tuile.dataset.id);
});
// Dans la fiche, cliquer sur un composant ou un objet "permet de construire" ouvre sa fiche
document.getElementById('contenuObjet').addEventListener('click', event => {
    const noeud = event.target.closest('[data-id]');
    if (noeud) ouvrirObjet(noeud.dataset.id);
});
document.getElementById('fermerObjet').addEventListener('click', () => modale.close());
modale.addEventListener('click', event => {
    if (event.target === modale) modale.close();
});

// Un objet de l'arbre et, en dessous, ses composants (récursif : les composants ont aussi des composants)
function htmlNoeud(id) {
    const objet = tous[id];
    if (!objet) return '';
    const ok = estDebloque(id);
    const enfants = (objet.from || []).map(htmlNoeud).join('');
    return `
        <li>
            <button class="noeud${ok ? '' : ' verrouille'}" data-id="${id}" title="${objet.name}${ok ? '' : ' (verrouillé)'}">
                <img src="${icone(id)}" alt="${objet.name}" width="44" height="44">
                <span>${objet.gold.total}</span>
                ${ok ? '' : '<i class="material-icons cadenas" aria-hidden="true">lock</i>'}
            </button>
            ${enfants ? `<ul>${enfants}</ul>` : ''}
        </li>`;
}

function ouvrirObjet(id) {
    const objet = tous[id];
    const ok = estDebloque(id);
    const construit = (objet.into || []).filter(cle => tous[cle] && surLaFaille(tous[cle]));
    const statut = inventaire === null ? '' : `<span class="etiquette${ok ? ' etiquette-nouveau' : ''}">${ok ? `Débloqué${nombre(id) > 1 ? ' ×' + nombre(id) : ''}` : 'Verrouillé'}</span>`;
    document.getElementById('contenuObjet').innerHTML = `
        <div class="objet-tete">
            <img class="${ok ? '' : 'verrouille'}" src="${icone(id)}" alt="" width="72" height="72">
            <div>
                <h2 id="titreObjet">${objet.name}</h2>
                <p class="fiche-titre">${objet.plaintext || ''}</p>
                <p class="fiche-etiquettes">
                    ${statut}
                    <span class="etiquette">${objet.gold.total} po</span>
                    ${objet.from?.length ? `<span class="etiquette">dont ${objet.gold.base} po d'assemblage</span>` : ''}
                </p>
            </div>
        </div>
        <div class="objet-description">${objet.description}</div>
        ${objet.from?.length ? `
            <h3>Arbre de fabrication</h3>
            <div class="arbre-defile"><ul class="arbre">${htmlNoeud(id)}</ul></div>
            ${htmlFabrication(id)}` : ''}
        ${construit.length ? `
            <h3>Permet de construire</h3>
            <div class="grille-objets grille-mini">${construit.map(cle => htmlTuile([cle, tous[cle]])).join('')}</div>` : ''}`;
    if (!modale.open) modale.showModal();
    modale.scrollTop = 0;
}

// ---------- Fabrication ----------
// Composants directs qui manquent (un même composant peut être demandé 2 fois, ex. 2 Épées longues)
function composantsManquants(id) {
    const besoin = {};
    tous[id].from.forEach(cle => { besoin[cle] = (besoin[cle] || 0) + 1; });
    return Object.entries(besoin)
        .filter(([cle, n]) => nombre(cle) < n)
        .map(([cle, n]) => `${tous[cle]?.name || cle}${n > 1 ? ` ×${n}` : ''}`);
}

function htmlFabrication(id) {
    if (inventaire === null) return '<p class="info-fabrication"><a href="compte.php">Connecte-toi</a> pour fabriquer des objets.</p>';
    const manque = composantsManquants(id);
    return `
        <div class="fabrication">
            <button class="bouton bouton-or" id="fabriquer" data-fabriquer="${id}"${manque.length ? ' disabled' : ''}>Fabriquer</button>
            <p class="info-fabrication" id="infoFabrication" aria-live="polite">${manque.length
                ? `Il te manque : ${manque.join(', ')}.`
                : 'Tu as tous les composants. Ils seront consommés.'}</p>
        </div>`;
}

// Le bouton Fabriquer est dans la fiche : on l'écoute avant le clic "ouvrir la fiche d'un objet"
document.getElementById('contenuObjet').addEventListener('click', event => {
    const bouton = event.target.closest('[data-fabriquer]');
    if (!bouton) return;
    event.stopPropagation();
    bouton.disabled = true;
    const id = bouton.dataset.fabriquer;
    fetch('APIObjets.php', { method: 'POST', body: new URLSearchParams({ action: 'fabriquer', objet: id }) })
        .then(response => response.json())
        .then(data => {
            majCompte(data);
            ouvrirObjet(id);
            document.getElementById('infoFabrication').textContent = data.erreur
                ? 'Fabrication impossible : il manque un composant.'
                : `${tous[id].name} fabriqué !`;
        })
        .catch(err => console.error('Erreur :', err));
}, true);
