// Page "Boosters" : grille des paquets + panneau du paquet choisi.
// L'ouverture se fait sur ouvrir.html ; le tirage et le stock sont gérés par APIBooster.php (côté serveur).
const grille = document.getElementById('paquets');
const bouton = document.getElementById('ouvrirBooster');
const minuteur = document.getElementById('minuteur');
const affichageStock = document.getElementById('stock');
const nomPaquet = document.getElementById('nomPaquet');
const sousTitre = document.getElementById('sousTitre');
const stockLabel = document.getElementById('stockLabel');
const pitie = document.getElementById('pitie');

let paquets = {};
let choisi = new URLSearchParams(location.search).get('paquet') || 'tous';
let connecte = false;
let etat = { stock: 0, max: 10, attente: 600 }; // donné par le serveur
let finAttente = 0; // date (ms) du prochain booster gagné

function majMinuteur() {
    if (!connecte) {
        bouton.disabled = true;
        affichageStock.textContent = '–';
        minuteur.innerHTML = '<a href="compte.php">Connecte-toi</a> pour ouvrir des boosters.';
        majAchat();
        return;
    }
    // Le temps est écoulé : on gagne 1 booster et le minuteur repart (comme le calcule le serveur)
    if (etat.stock < etat.max && Date.now() >= finAttente) {
        etat.stock++;
        finAttente += etat.attente * 1000;
    }
    // Le panneau change selon le type de paquet choisi (champions, composants ou objets complets)
    const type = paquets[choisi]?.type || 'champions';
    const avantObjets = etat.boostersPourUnObjets - etat.composantsAchetes % etat.boostersPourUnObjets;
    const texteOffert = `Encore ${avantObjets} booster${avantObjets > 1 ? 's' : ''} Composants à acheter avant un booster Objets complets offert.`;
    if (type === 'champions') {
        affichageStock.textContent = `${etat.stock}/${etat.max}`;
        stockLabel.textContent = 'boosters en stock, +1 toutes les 10 min';
        bouton.textContent = 'Ouvrir';
        bouton.disabled = etat.stock === 0;
        minuteur.textContent = etat.stock === etat.max
            ? 'Stock plein !'
            : `+1 booster dans ${formatDuree(finAttente - Date.now())}`;
    } else if (type === 'composants') {
        affichageStock.textContent = etat.prixBoosterComposants;
        stockLabel.textContent = 'rubis le booster';
        bouton.textContent = `Acheter et ouvrir · ${etat.prixBoosterComposants} rubis`;
        bouton.disabled = etat.rubis < etat.prixBoosterComposants;
        minuteur.textContent = texteOffert;
    } else {
        affichageStock.textContent = etat.boostersObjets;
        stockLabel.textContent = `booster${etat.boostersObjets > 1 ? 's' : ''} offert${etat.boostersObjets > 1 ? 's' : ''} à ouvrir`;
        bouton.textContent = 'Ouvrir';
        bouton.disabled = etat.boostersObjets === 0;
        minuteur.textContent = texteOffert;
    }
    // Pitié holo (boosters de champions seulement) : le holo est garanti au bout de pitieMax boosters sans holo
    const restePitie = etat.pitieMax - etat.pitieHolo;
    pitie.hidden = type !== 'champions';
    pitie.innerHTML = `<strong>Holo garanti</strong> dans ${restePitie} booster${restePitie > 1 ? 's' : ''} au plus
        <span class="barre"><span style="width:${100 * etat.pitieHolo / etat.pitieMax}%"></span></span>`;
    // L'achat à 180 rubis ajoute un booster de champions au stock : seulement pour ces paquets
    boutonAchat.hidden = type !== 'champions';
    infoAchat.hidden = type !== 'champions';    majAchat();
}

// ---------- Achat d'un booster avec des rubis ----------
const boutonAchat = document.getElementById('acheterBooster');
const infoAchat = document.getElementById('infoAchat');
let achatEnCours = false;
let messageAchat = ''; // "Booster acheté !" reste affiché (majAchat tourne chaque seconde)

function majAchat() {
    document.getElementById('rubis').textContent = connecte ? etat.rubis : '–';
    const manque = etat.prixBooster - etat.rubis;
    boutonAchat.disabled = !connecte || achatEnCours || manque > 0 || etat.stock === etat.max;
    if (!connecte) {
        infoAchat.textContent = '';
    } else if (etat.stock === etat.max) {
        infoAchat.textContent = 'Ton stock est plein : ouvre un booster avant d\'en acheter.';
    } else if (manque > 0) {
        infoAchat.innerHTML = `Il te manque ${manque} rubis. <a href="collection.php">Recycle des cartes</a> pour en gagner.`;
    } else {
        infoAchat.textContent = messageAchat;
    }
}

boutonAchat.addEventListener('click', () => {
    achatEnCours = true;
    majAchat();
    fetch('APIRubis.php', { method: 'POST', body: new URLSearchParams({ action: 'acheter' }) })
        .then(response => response.json())
        .then(data => {
            etat = data; // le serveur renvoie les rubis et le stock à jour
            finAttente = Date.now() + data.reste * 1000;
            messageAchat = data.erreur ? 'Achat impossible.' : 'Booster acheté !';
        })
        .catch(err => console.error('Erreur :', err))
        .finally(() => {
            achatEnCours = false;
            majMinuteur();
        });
});

// ---------- Récompense quotidienne ----------
const blocQuotidien = document.getElementById('quotidien');
const boutonQuotidien = document.getElementById('boutonQuotidien');

function majQuotidien(gain = 0) {
    blocQuotidien.hidden = !connecte;
    if (!connecte) return;
    const serie = etat.serie > 1 ? ` · ${etat.serie} jours de suite` : '';
    document.getElementById('texteQuotidien').innerHTML = etat.quotidienDisponible
        ? `<strong>Récompense du jour : +${etat.gainQuotidien} rubis</strong>${serie}. Reviens chaque jour pour gagner plus.`
        : `<strong>${gain ? `+${gain} rubis !` : 'Récompense du jour récupérée.'}</strong>${serie}. Reviens demain pour +${etat.gainDemain} rubis.`;
    boutonQuotidien.hidden = !etat.quotidienDisponible;
}

boutonQuotidien.addEventListener('click', () => {
    boutonQuotidien.disabled = true;
    fetch('APIRubis.php', { method: 'POST', body: new URLSearchParams({ action: 'quotidien' }) })
        .then(response => response.json())
        .then(data => {
            etat = data; // rubis, stock et récompense à jour
            finAttente = Date.now() + data.reste * 1000;
            majQuotidien(data.gain);
            majMinuteur();
        })
        .catch(err => console.error('Erreur :', err))
        .finally(() => { boutonQuotidien.disabled = false; });
});

function choisir(id) {
    choisi = id;
    grille.querySelectorAll('.choix-paquet').forEach(b => b.setAttribute('aria-pressed', b.dataset.id === id));
    nomPaquet.textContent = paquets[id].nom;
    sousTitre.textContent = paquets[id].sousTitre;
    boutonPersos.hidden = paquets[id].type !== 'champions'; // les paquets d'objets n'ont pas de personnages
    history.replaceState(null, '', `?paquet=${id}`); // garde le choix si on recharge la page
    majMinuteur();
}

grille.addEventListener('click', event => {
    const choix = event.target.closest('.choix-paquet');
    if (choix) choisir(choix.dataset.id);
});

bouton.addEventListener('click', () => {
    location.href = `ouvrir.html?id=${choisi}`;
});

// ---------- Personnages du booster choisi (modale) ----------
const boutonPersos = document.getElementById('voirPersos');
const modalePersos = document.getElementById('modalePersos');
const listePersos = document.getElementById('listePersos');
let donneesPersos = null; // chargées au premier clic seulement

boutonPersos.addEventListener('click', () => {
    document.getElementById('titrePersos').textContent = `Personnages : ${paquets[choisi].nom}`;
    listePersos.innerHTML = '';
    document.getElementById('infoPersos').textContent = 'Chargement…';
    modalePersos.showModal();
    donneesPersos ??= Promise.all([
        fetch('APISite.php?champions=1').then(response => response.json()),
        fetch('APICollection.php').then(response => response.ok ? response.json() : null) // null = pas connecté
    ]);
    donneesPersos
        .then(([data, collection]) => {
            const { classe, champions } = paquets[choisi]; // classe null = tous ; "champions" = liste fixe (paquet de saison)
            const liste = Object.entries(data.data).filter(([cle, champ]) =>
                champions ? champions.includes(cle) : !classe || champ.tags.includes(classe));
            const possedes = collection ? liste.filter(([cle]) => collection[cle]).length : 0;
            document.getElementById('infoPersos').textContent = collection
                ? `${liste.length} champions, tu en as ${possedes}.`
                : `${liste.length} champions. Connecte-toi pour voir ceux que tu as.`;
            listePersos.innerHTML = liste.map(([cle, champ]) => `
                <button class="skin${collection && !collection[cle] ? ' verrouille' : ''}" data-cle="${cle}" aria-label="Voir la fiche de ${champ.name}">
                    <img src="https://ddragon.leagueoflegends.com/cdn/14.8.1/img/champion/${cle}.png" alt="" loading="lazy">
                    <span class="skin-nom">${champ.name}</span>
                </button>`).join('');
        })
        .catch(err => {
            console.error('Erreur :', err);
            donneesPersos = null; // on pourra réessayer
            document.getElementById('infoPersos').textContent = 'Impossible de charger les personnages.';
        });
});
document.getElementById('fermerPersos').addEventListener('click', () => modalePersos.close());

// Clic sur un personnage : sa fiche s'ouvre par-dessus la liste
listePersos.addEventListener('click', event => {
    const perso = event.target.closest('.skin');
    if (!perso) return;
    donneesPersos.then(([data, collection]) => {
        const nombre = collection?.[perso.dataset.cle] || 0;
        const etiquette = !collection ? ''
            : nombre ? `<span class="etiquette etiquette-nouveau">Débloqué${nombre > 1 ? ` ×${nombre}` : ''}</span>`
            : '<span class="etiquette">Pas encore</span>';
        ouvrirFiche(perso.dataset.cle, etiquette);
    });
});
document.getElementById('fermerFiche').addEventListener('click', () => document.getElementById('ficheModale').close());

fetch('APIBooster.php')
    .then(response => response.json())
    .then(data => {
        paquets = data.paquets;
        connecte = data.connecte;
        if (connecte) {
            etat = data;
            finAttente = Date.now() + data.reste * 1000;
        }
        if (!paquets[choisi]) choisi = 'tous';
        grille.innerHTML = Object.entries(paquets).map(([id, paquet]) => `
            <button class="choix-paquet" data-id="${id}" aria-pressed="false" aria-label="${paquet.nom}">
                ${htmlPaquet(id, paquet)}
            </button>`).join('');
        choisir(choisi);
        majMinuteur();
        majQuotidien();
        setInterval(majMinuteur, 1000);
    })
    .catch(err => {
        console.error('Erreur :', err);
        minuteur.textContent = 'Impossible de charger les boosters. Recharge la page.';
    });
