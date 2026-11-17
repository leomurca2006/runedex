// Page Duels : aperçu des stats du champion choisi (même calcul que statsCombat() dans commun.php),
// envoi du combat à APIDuel.php puis animation des coups renvoyés par le serveur.
const formDuel = document.getElementById('formDuel');
const OBJETS_MAX = 6;
const reduit = matchMedia('(prefers-reduced-motion: reduce)').matches;

const LIBELLES = [
    ['pv', 'PV'],
    ['ad', "Dégâts d'attaque"],
    ['pm', 'Puissance magique'],
    ['armure', 'Armure'],
    ['rm', 'Résistance magique'],
    ['vitesse', "Vitesse d'attaque"],
    ['critique', 'Critique'],
    ['volDeVie', 'Vol de vie'],
];

function formater(cle, valeur) {
    if (cle === 'vitesse') return valeur.toFixed(2);
    if (cle === 'critique' || cle === 'volDeVie') return `${Math.round(valeur * 100)} %`;
    return Math.round(valeur);
}

// Liste des stats ; avec "comparer", les stats meilleures que celles du bot sont en vert, les moins bonnes en rouge
function htmlStats(stats, comparer = null) {
    return LIBELLES.map(([cle, nom]) => {
        const ecart = comparer ? Math.round(stats[cle] * 100) - Math.round(comparer[cle] * 100) : 0;
        return `<div class="${ecart > 0 ? 'mieux' : ecart < 0 ? 'moins' : ''}"><dt>${nom}</dt><dd>${formater(cle, stats[cle])}</dd></div>`;
    }).join('');
}

if (formDuel) {
    const statsBot = JSON.parse(document.getElementById('coteBot').dataset.stats);
    const bouton = document.getElementById('combattre');
    const caseObjets = formDuel.querySelectorAll('[name="objets[]"]');
    document.getElementById('statsBot').innerHTML = htmlStats(statsBot);

    // Stats du champion choisi + objets cochés
    function calculer(choix) {
        const stats = { ...JSON.parse(choix.dataset.stats) };
        formDuel.querySelectorAll('[name="objets[]"]:checked').forEach(caseObjet => {
            const bonus = JSON.parse(caseObjet.dataset.bonus);
            for (const cle in bonus) stats[cle] += cle === 'vitesse' ? stats.vitesseBase * bonus[cle] : bonus[cle];
        });
        // Bonus des étoiles (+10 % par étoile au-dessus de 1), comme le $bonus de statsCombat()
        const bonusEtoiles = Number(choix.dataset.bonusEtoiles);
        for (const cle of ['pv', 'ad', 'pm', 'armure', 'rm']) stats[cle] *= 1 + bonusEtoiles / 100;
        stats.vitesse = Math.min(2.5, stats.vitesse);
        stats.critique = Math.min(1, stats.critique);
        return stats;
    }

    function majApercu() {
        const coches = formDuel.querySelectorAll('[name="objets[]"]:checked').length;
        caseObjets.forEach(caseObjet => { caseObjet.disabled = !caseObjet.checked && coches >= OBJETS_MAX; });
        document.getElementById('compteurObjets').textContent = `(${coches}/${OBJETS_MAX})`;
        const choix = formDuel.querySelector('[name="champion"]:checked');
        bouton.disabled = !choix;
        if (boutonStuff) boutonStuff.disabled = !choix;
        if (!choix) return;
        document.getElementById('imageJoueur').src = choix.dataset.image;
        document.getElementById('nomJoueur').textContent = choix.dataset.nom;
        document.getElementById('statsJoueur').innerHTML = htmlStats(calculer(choix), statsBot);
    }
    formDuel.addEventListener('change', event => {
        // Nouveau champion choisi : on coche son stuff sauvegardé (s'il en a un, sinon on garde les objets cochés)
        if (event.target.name === 'champion') {
            const stuff = JSON.parse(event.target.dataset.stuff);
            if (stuff.length) caseObjets.forEach(caseObjet => { caseObjet.checked = stuff.includes(caseObjet.value); });
        }
        majApercu();
    });

    // Sauvegarde du stuff du champion choisi (bouton absent si on n'a aucun objet)
    const boutonStuff = document.getElementById('sauverStuff');
    boutonStuff?.addEventListener('click', () => {
        const choix = formDuel.querySelector('[name="champion"]:checked');
        const donnees = new FormData(formDuel);
        donnees.append('action', 'sauver');
        const info = document.getElementById('infoDuel');
        fetch('APIDuel.php', { method: 'POST', body: donnees })
            .then(response => response.json())
            .then(data => {
                if (data.erreur) {
                    info.textContent = 'Sauvegarde impossible : recharge la page.';
                    return;
                }
                choix.dataset.stuff = JSON.stringify(donnees.getAll('objets[]')); // pour le resélectionner sans recharger
                info.textContent = donnees.getAll('objets[]').length
                    ? `Stuff sauvegardé pour ${choix.dataset.nom}.`
                    : `Stuff de ${choix.dataset.nom} effacé.`;
            })
            .catch(() => { info.textContent = 'Sauvegarde impossible : vérifie ta connexion.'; });
    });

    // Recherche : on cache les champions dont le nom ne commence pas par la saisie (même règle que api.html)
    const simplifier = texte => texte.toLowerCase().normalize('NFD').replace(/[^a-z0-9]/g, '');
    const rechercheChampion = document.getElementById('rechercheChampion');
    rechercheChampion.addEventListener('input', () => {
        const debut = simplifier(rechercheChampion.value);
        document.querySelectorAll('#grilleChoix > label').forEach(choix => {
            choix.hidden = !simplifier(choix.dataset.nom).startsWith(debut);
        });
    });
    // Le champ est dans le formulaire : sans ça, Entrée lancerait le combat
    rechercheChampion.addEventListener('keydown', event => {
        if (event.key === 'Enter') event.preventDefault();
    });

    formDuel.addEventListener('submit', event => {
        event.preventDefault();
        bouton.disabled = true;
        fetch('APIDuel.php', { method: 'POST', body: new FormData(formDuel) })
            .then(response => response.json())
            .then(data => {
                if (data.erreur) {
                    document.getElementById('infoDuel').textContent = data.erreur === 'api'
                        ? 'Impossible de joindre Riot. Réessaie dans un instant.'
                        : 'Combat impossible : recharge la page.';
                    return;
                }
                jouerCombat(data);
            })
            .catch(err => console.error('Erreur :', err))
            .finally(() => { bouton.disabled = false; });
    });
}

// ---------- Animation du combat ----------
const arene = document.getElementById('arene');
let resultatVictoire = false;
let minuteurs = []; // coups programmés, annulés si on relance un combat avant la fin du précédent

function majPv(cote, pv, max) {
    cote.querySelector('.barre-pv span').style.width = `${100 * pv / max}%`;
    cote.querySelector('.pv-texte').textContent = `${pv} / ${Math.round(max)} PV`;
}

function jouerCombat(data) {
    minuteurs.forEach(clearTimeout);
    minuteurs = [];
    const cotes = [document.getElementById('areneJoueur'), document.getElementById('areneBot')];
    const combattants = [data.joueur, data.bot];
    const pvMax = combattants.map(c => c.stats.pv);
    cotes.forEach((cote, i) => {
        cote.classList.remove('vaincu');
        cote.querySelector('img').src = i === 0
            ? document.getElementById('imageJoueur').src // avec le skin équipé
            : `https://ddragon.leagueoflegends.com/cdn/img/champion/loading/${combattants[i].cle}_0.jpg`;
        cote.querySelector('strong').textContent = combattants[i].nom;
        majPv(cote, Math.round(pvMax[i]), pvMax[i]);
    });
    const resultat = document.getElementById('resultatDuel');
    resultat.textContent = 'Combat en cours…';
    document.getElementById('actionsArene').hidden = true;
    arene.showModal();

    // Le combat dure souvent 20 à 40 s "de jeu" : on le rejoue en 9 s maximum
    const fin = data.coups.length ? data.coups[data.coups.length - 1].t : 0;
    const echelle = reduit ? 0 : Math.min(400, 9000 / Math.max(fin, 1)); // ms par seconde de combat
    data.coups.forEach(coup => minuteurs.push(setTimeout(() => {
        majPv(cotes[0], coup.pv[0], pvMax[0]);
        majPv(cotes[1], coup.pv[1], pvMax[1]);
        if (reduit) return;
        const cible = cotes[1 - coup.qui].querySelector('.arene-image');
        const nombre = document.createElement('span');
        nombre.className = `degats${coup.critique ? ' critique' : ''}`;
        nombre.textContent = `-${coup.degats}`;
        nombre.style.left = `${30 + Math.random() * 40}%`;
        cible.append(nombre);
        nombre.addEventListener('animationend', () => nombre.remove());
        cible.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(0)' }], { duration: 160 });
    }, coup.t * echelle)));

    resultatVictoire = data.victoire;
    minuteurs.push(setTimeout(() => {
        cotes[data.victoire ? 1 : 0].classList.add('vaincu');
        resultat.innerHTML = data.victoire
            ? `<strong>Victoire !</strong>${data.gain ? ` +${data.gain} rubis.` : ''}`
            : '<strong>Défaite…</strong> Change de champion ou d\'objets et retente ta chance.';
        document.getElementById('continuer').textContent = data.victoire ? 'Continuer' : 'Réessayer';
        document.getElementById('actionsArene').hidden = false;
    }, fin * echelle + (reduit ? 0 : 600)));
}

// Après une victoire on recharge (nouveau bot, nouveau palier) ; après une défaite on garde son build.
// Sur "close" : marche aussi si on ferme avec Échap.
document.getElementById('continuer').addEventListener('click', () => arene.close());
arene.addEventListener('close', () => {
    if (resultatVictoire) location.reload();
});
