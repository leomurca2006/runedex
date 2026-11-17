// Ouverture d'un booster : 1. on déchire le paquet  2. on révèle les cartes une par une  3. récapitulatif.
// Le tirage est fait par APIBooster.php (côté serveur) : ici il n'y a que l'animation.
const $ = id => document.getElementById(id);
const reduit = matchMedia('(prefers-reduced-motion: reduce)').matches;
const attendre = ms => new Promise(fin => setTimeout(fin, reduit ? 0 : ms));

const idPaquet = new URLSearchParams(location.search).get('id');
let etat = null;     // stock, rubis... du joueur (donné par le serveur)
let typePaquet = 'champions'; // champions, composants ou objets
let boosters = [];   // [[5 cartes], [5 cartes], ...] tirées par le serveur
let holos = [];      // [false, true, ...] : quels boosters sont holographiques (50 cartes)
let dechire = false;

// ---------- Particules qui flottent ----------
for (let n = 0; n < 40; n++) {
    const particule = document.createElement('span');
    particule.style.left = `${Math.random() * 100}%`;
    particule.style.setProperty('--taille', `${2 + Math.random() * 4}px`);
    particule.style.animationDuration = `${14 + Math.random() * 16}s`;
    particule.style.animationDelay = `${-Math.random() * 30}s`; // négatif : elles sont déjà en route au chargement
    $('particules').append(particule);
}

// ---------- Chargement du paquet et du stock ----------
fetch('APIBooster.php')
    .then(response => response.json())
    .then(data => {
        const paquet = data.paquets[idPaquet];
        if (!paquet) {
            location.href = 'booster.html';
            return;
        }
        document.title = `${paquet.nom} — Runedex`;
        typePaquet = paquet.type;
        $('changer').href = `booster.html?paquet=${idPaquet}`;
        const html = htmlPaquet(idPaquet, paquet);
        $('paquetEntier').innerHTML = html;
        $('morceauHaut').innerHTML = html;
        $('morceauBas').innerHTML = html;
        decouper();
        if (!data.connecte) {
            bloquer('<a href="compte.php">Connecte-toi</a> pour ouvrir des boosters.');
            return;
        }
        etat = data;
        majQuantite();
    })
    .catch(err => {
        console.error('Erreur :', err);
        bloquer('Impossible de charger le booster. Recharge la page.');
    });

function bloquer(message) {
    $('infoStock').innerHTML = message;
    $('boutonOuvrir').disabled = true;
    $('scenePaquet').classList.add('bloque');
}

function nombreChoisi() {
    return Number(document.querySelector('input[name="nombre"]:checked').value);
}

// Combien de boosters de ce paquet on peut ouvrir : stock (champions), rubis (composants) ou boosters offerts (objets)
function disponibles() {
    if (typePaquet === 'composants') return Math.floor(etat.rubis / etat.prixBoosterComposants);
    if (typePaquet === 'objets') return etat.boostersObjets;
    return etat.stock;
}

// On ne peut pas choisir ×5 ou ×10 sans en avoir assez
function majQuantite() {
    const n = disponibles();
    document.querySelectorAll('input[name="nombre"]').forEach(choix => {
        choix.disabled = Number(choix.value) > n;
    });
    if (nombreChoisi() > n) document.querySelector('input[value="1"]').checked = true;
    if (n > 0) {
        $('infoStock').textContent = typePaquet === 'composants'
            ? `${etat.rubis} rubis : ${etat.prixBoosterComposants} rubis le booster`
            : `${n} booster${n > 1 ? 's' : ''} ${typePaquet === 'objets' ? 'offert' + (n > 1 ? 's' : '') : 'en stock'}`;
    } else if (typePaquet === 'composants') {
        bloquer(`Il te faut ${etat.prixBoosterComposants} rubis (tu en as ${etat.rubis}). <a href="collection.php">Recycle des cartes</a> pour en gagner.`);
    } else if (typePaquet === 'objets') {
        bloquer(`Aucun booster offert. Tu en gagnes un tous les ${etat.boostersPourUnObjets} boosters Composants achetés.`);
    } else {
        bloquer(`Plus de booster en stock. Le prochain arrive dans ${formatDuree(etat.reste * 1000)}.`);
    }
}

function peutOuvrir() {
    return etat !== null && disponibles() >= nombreChoisi() && !dechire;
}

// Bord dentelé : les deux morceaux ont le même zigzag, l'un garde le dessus, l'autre le dessous
function decouper() {
    const zigzag = [];
    for (let x = 0; x <= 100; x += 5) zigzag.push(`${x}% ${x % 10 ? 15 : 19}%`);
    $('morceauHaut').style.clipPath = `polygon(0 0, 100% 0, ${[...zigzag].reverse().join(', ')})`;
    $('morceauBas').style.clipPath = `polygon(${zigzag.join(', ')}, 100% 100%, 0 100%)`;
}

// ---------- 1. Déchirer : glisser le long du haut du paquet ----------
const bande = $('bande');
const ligne = $('ligne');
let departX = null;

bande.addEventListener('pointerdown', event => {
    if (!peutOuvrir()) return;
    departX = event.clientX;
    bande.setPointerCapture(event.pointerId); // on continue à suivre même si le doigt sort du paquet
    ligne.classList.add('visible');
    suivreGeste(event);
});
bande.addEventListener('pointermove', event => {
    if (departX !== null) suivreGeste(event);
});
bande.addEventListener('pointerup', relacher);
bande.addEventListener('pointercancel', relacher);

// La ligne lumineuse suit le geste ; à 60 % de la largeur du paquet, il se déchire
function suivreGeste(event) {
    const zone = $('scenePaquet').getBoundingClientRect();
    const gauche = Math.max(0, Math.min(departX, event.clientX) - zone.left);
    const droite = Math.min(zone.width, Math.max(departX, event.clientX) - zone.left);
    ligne.style.left = `${gauche}px`;
    ligne.style.width = `${Math.max(0, droite - gauche)}px`;
    if (droite - gauche >= zone.width * 0.6) {
        departX = null;
        dechirer();
    }
}

function relacher() {
    departX = null;
    if (!dechire) ligne.classList.remove('visible');
}

// Le bouton "Ouvrir" fait le même geste tout seul
$('boutonOuvrir').addEventListener('click', async () => {
    if (!peutOuvrir()) return;
    ligne.style.left = '0';
    ligne.style.width = '0';
    ligne.classList.add('visible', 'auto');
    requestAnimationFrame(() => requestAnimationFrame(() => { ligne.style.width = '100%'; }));
    await attendre(450);
    dechirer();
});

async function dechirer() {
    if (dechire) return;
    dechire = true;
    document.body.classList.add('en-cours');

    // On demande le tirage au serveur pendant que l'animation joue
    const requete = fetch('APIBooster.php', {
        method: 'POST',
        body: new URLSearchParams({ paquet: idPaquet, nombre: nombreChoisi() }),
    }).then(response => response.json());

    lancerEclats();
    $('scenePaquet').classList.add('dechire'); // le haut s'arrache, le faisceau de lumière sort
    await attendre(800);

    let data;
    try {
        data = await requete;
    } catch (err) {
        data = { erreur: 'reseau' };
    }
    if (!data.boosters) {
        document.body.classList.remove('en-cours');
        $('consigne').innerHTML = data.erreur === 'stock'
            ? 'Pas assez de boosters (ou de rubis). <a href="booster.html">Retour aux boosters</a>'
            : 'Le booster n\'a pas pu s\'ouvrir. <a href="">Réessayer</a>';
        return;
    }
    boosters = data.boosters;
    holos = data.holos;
    etat = data;

    // Booster holographique : le paquet déchiré devient arc-en-ciel avant de laisser sortir les cartes
    const nbHolos = holos.filter(Boolean).length;
    if (nbHolos) {
        document.querySelectorAll('#scenePaquet .paquet').forEach(paquet => paquet.classList.add('holo'));
        document.querySelectorAll('#scenePaquet .paquet-pastille').forEach(pastille => { pastille.textContent = '50 cartes'; });
        $('consigne').textContent = nbHolos > 1
            ? `${nbHolos} boosters holographiques ! 50 cartes chacun`
            : 'Booster holographique ! 50 cartes';
        $('consigne').classList.add('consigne-holo');
        await attendre(1800);
    }

    $('scenePaquet').classList.add('sorti'); // la pile de cartes monte, le paquet glisse vers le bas
    await attendre(1100);
    commencerRevelation();
}

function lancerEclats() {
    for (let n = 0; n < 16; n++) {
        const eclat = document.createElement('span');
        eclat.style.left = `${Math.random() * 100}%`;
        eclat.style.setProperty('--dx', `${(Math.random() - 0.5) * 320}px`);
        eclat.style.setProperty('--dy', `${-60 - Math.random() * 180}px`);
        eclat.style.setProperty('--r', `${(Math.random() - 0.5) * 720}deg`);
        $('eclats').append(eclat);
    }
}

// ---------- 2. Révélation ----------
const retournable = $('retournable');
let cartes = [];    // toutes les cartes à révéler, à la suite (×5 = 25 cartes)
let i = 0;          // nombre de cartes déjà retournées dans ce booster
let occupe = false; // une animation est en cours
let enAttente = false; // un appui arrivé pendant l'animation : on le joue juste après
let rapide = false; // Espace maintenu

// Joue une animation (Web Animations API) et attend sa fin.
// Plus courte en avance rapide, et rien du tout si l'utilisateur a demandé moins d'animations.
const DOUX = 'cubic-bezier(.22, 1, .36, 1)'; // démarre vite et ralentit en douceur
function animer(element, images, duree, options = {}) {
    if (reduit) return Promise.resolve();
    return element.animate(images, { duration: rapide ? duree * 0.35 : duree, easing: DOUX, ...options }).finished;
}

async function commencerRevelation() {
    // Fondu enchaîné : la scène du paquet s'efface, puis la révélation apparaît avec la 1re carte qui monte
    await animer($('scene'), [{ opacity: 1 }, { opacity: 0 }], 250);
    $('scene').hidden = true;
    $('revelation').hidden = false;
    cartes = boosters.flat();
    preparerRevelation();
    animer($('revelation'), [{ opacity: 0 }, { opacity: 1 }], 400);
}

function preparerRevelation() {
    i = 0;
    cartes.forEach(carte => { new Image().src = imageCarte(carte); }); // précharge les images
    $('numeroBooster').textContent = boosters.length > 1 ? `${boosters.length} boosters` : '';
    $('miniatures').innerHTML = cartes.map(() => '<span class="miniature"></span>').join('');
    $('miniatures').classList.toggle('nombreuses', cartes.length > 5);
    $('legende').innerHTML = '';
    majCompteur();
    montrerDos(cartes[0]);
}

function majCompteur() {
    $('compteur').textContent = `${i}/${cartes.length}`;
}

// La carte déjà révélée part en glissant vers la gauche.
// On anime une copie "à plat" de sa face : une carte en 3D qu'on rend transparente s'aplatit et montre son dos.
function faireSortir() {
    if (reduit) return;
    const copie = document.createElement('div');
    copie.className = 'sortante';
    copie.innerHTML = $('face').innerHTML;
    $('zoneCarte').append(copie);
    animer(copie, [
        { transform: 'none', opacity: 1 },
        { transform: 'translate(-75%, 30px) rotate(-14deg) scale(.85)', opacity: 0 },
    ], 550).then(() => copie.remove());
}

// Une nouvelle carte arrive de la pile, face cachée
function montrerDos(carte) {
    if (retournable.classList.contains('retournee')) faireSortir();
    // remet la carte face cachée sans rejouer le retournement à l'envers
    retournable.classList.add('sans-transition');
    retournable.classList.remove('retournee');
    $('face').innerHTML = htmlCarte(carte);
    void retournable.offsetWidth; // force le navigateur à appliquer le changement avant de remettre la transition
    retournable.classList.remove('sans-transition');
    return animer(retournable, [
        { transform: 'translateY(60px) scale(.88)', opacity: 0 },
        { transform: 'none', opacity: 1 },
    ], 450);
}

// Le retournement, avec le halo qui s'illumine et les infos qui apparaissent en douceur
function retourner(carte) {
    retournable.classList.add('retournee');
    animer(document.querySelector('.halo'), [
        { opacity: .4, transform: 'scale(.9)' },
        { opacity: 1, transform: 'scale(1.2)', offset: .35 },
        { opacity: .4, transform: 'scale(1)' },
    ], 900, { easing: 'ease-out' });

    $('legende').innerHTML = `
        <h2>${carte.nom}</h2>
        <p>${carte.titre}</p>
        <span class="etiquette${carte.nouveau ? ' etiquette-nouveau' : ''}">${carte.nouveau ? 'Nouveau' : 'Doublon'}</span>`;
    animer($('legende'), [{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'none' }], 400, { delay: rapide ? 0 : 150 });

    const miniature = $('miniatures').children[i];
    miniature.innerHTML = `<img src="${imageCarte(carte)}" alt="${carte.nom}">`;
    miniature.classList.add(carte.nouveau ? 'est-nouveau' : 'est-doublon');
    animer(miniature, [{ transform: 'scale(.6)', opacity: 0 }, { transform: 'scale(1.12)', offset: .6 }, { transform: 'none', opacity: 1 }], 400);
}

// Un clic / swipe / Espace = on révèle la carte suivante
async function avancer() {
    if ($('revelation').hidden) return;
    if (occupe) {
        enAttente = true;
        return;
    }
    occupe = true;

    if (retournable.classList.contains('retournee')) {
        if (i === cartes.length) { // toutes les cartes sont révélées
            occupe = false;
            afficherRecap();
            return;
        }
        // la carte suivante arrive pendant que la précédente s'en va, puis on la retourne
        montrerDos(cartes[i]);
        $('legende').innerHTML = '';
        await attendre(rapide ? 80 : 300);
    }

    retourner(cartes[i]);
    i++;
    majCompteur();
    await attendre(rapide ? 150 : 650);
    occupe = false;
    if (enAttente) {
        enAttente = false;
        avancer();
    }
}

// Clic ou swipe sur la carte
let gesteX = null;
$('zoneCarte').addEventListener('pointerdown', event => { gesteX = event.clientX; });
$('zoneCarte').addEventListener('pointerup', () => {
    if (gesteX === null) return;
    gesteX = null;
    avancer();
});

// Espace = carte suivante ; maintenu = avance rapide
let maintien = null;
let rafale = null;
document.addEventListener('keydown', event => {
    if (event.code !== 'Space' || $('revelation').hidden || event.target.tagName === 'BUTTON') return;
    event.preventDefault(); // sinon la page défile
    if (event.repeat) return;
    avancer();
    maintien = setTimeout(() => {
        rapide = true;
        document.body.classList.add('rapide');
        rafale = setInterval(avancer, 150);
    }, 400);
});
document.addEventListener('keyup', event => {
    if (event.code === 'Space') arreterRapide();
});

function arreterRapide() {
    clearTimeout(maintien);
    clearInterval(rafale);
    rapide = false;
    document.body.classList.remove('rapide');
}

$('toutReveler').addEventListener('click', afficherRecap);

// ---------- 3. Récapitulatif ----------
function afficherRecap() {
    arreterRapide();
    enAttente = false;
    $('revelation').hidden = true;
    $('recap').hidden = false;

    const toutes = boosters.flat();
    const nouvelles = toutes.filter(carte => carte.nouveau).length;
    const doublons = toutes.length - nouvelles;
    $('resume').textContent = `${nouvelles} nouvelle${nouvelles > 1 ? 's' : ''}, ${doublons} doublon${doublons > 1 ? 's' : ''}`;

    // Un éventail par booster
    $('recap').classList.toggle('recap-multi', boosters.length > 1);
    let n = 0;
    $('eventails').innerHTML = boosters.map((cartes, b) => `
        <div class="eventail${holos[b] ? ' eventail-holo' : ''}">
            ${holos[b] ? '<p class="titre-holo">Booster holographique</p>' : ''}
            ${cartes.map((carte, k) => {
                // éventail : -2, -1, 0, 1, 2 ; le booster holo (50 cartes) est une grille à plat
                const decalage = holos[b] ? 0 : k - (cartes.length - 1) / 2;
                return `
                    <button class="eventail-carte" data-cle="${carte.cle}" data-nouveau="${carte.nouveau}" data-type="${carte.type || 'champion'}"
                        style="--r:${decalage * 7}deg; --y:${Math.abs(decalage) * 10}px; --n:${Math.min(n++, 30)}"
                        aria-label="Voir la fiche de ${carte.nom}">
                        ${htmlCarte(carte)}
                        ${carte.nouveau ? '<span class="etiquette etiquette-nouveau">Nouveau</span>' : ''}
                    </button>`;
            }).join('')}
        </div>`).join('');

    if (disponibles() === 0) {
        $('encore').disabled = true;
        $('encore').textContent = typePaquet === 'composants' ? 'Pas assez de rubis' : 'Plus de booster disponible';
    }
    suggererRecyclage();
}

// ---------- Recyclage des doublons (rubis) ----------
// On propose de recycler les doublons du même type que le paquet ouvert (champions, composants ou objets)
const NOMS_TYPES = { champions: 'champion', composants: 'composant', objets: 'objet' };
function suggererRecyclage() {
    const doublons = etat.doublonsParType[typePaquet];
    const gain = doublons * etat.rubisRecyclage[typePaquet];
    $('suggestionRecyclage').hidden = doublons === 0;
    $('texteRecyclage').innerHTML = `Tu as <strong>${doublons} ${NOMS_TYPES[typePaquet]}${doublons > 1 ? 's' : ''} en double</strong>.
        Recycle-les pour gagner <strong>${gain} rubis</strong> (${etat.rubisRecyclage[typePaquet]} par exemplaire, tu gardes 1 exemplaire de chaque).
        Tu as ${etat.rubis} rubis.`;
}

$('recyclerDoublons').addEventListener('click', () => {
    $('recyclerDoublons').disabled = true;
    fetch('APIRubis.php', { method: 'POST', body: new URLSearchParams({ action: 'doublons', type: typePaquet }) })
        .then(response => response.json())
        .then(data => {
            etat = data;
            $('texteRecyclage').innerHTML = `<strong>+${data.gain} rubis !</strong> Tu en as maintenant ${data.rubis}.
                <a href="booster.html">Acheter un booster</a>`;
            $('recyclerDoublons').hidden = true;
        })
        .catch(err => {
            console.error('Erreur :', err);
            $('recyclerDoublons').disabled = false;
        });
});

$('encore').addEventListener('click', () => location.reload());

// ---------- Fiche d'un champion (modale) ----------
const modale = $('ficheModale');

$('eventails').addEventListener('click', event => {
    const carte = event.target.closest('.eventail-carte');
    if (!carte) return;
    if (carte.dataset.type === 'objet') {
        ouvrirFicheObjet(boosters.flat().find(c => c.cle === carte.dataset.cle));
    } else {
        const nouveau = carte.dataset.nouveau === 'true';
        ouvrirFiche(carte.dataset.cle, `<span class="etiquette${nouveau ? ' etiquette-nouveau' : ''}">${nouveau ? 'Nouveau' : 'Doublon'}</span>`);
    }
});
$('fermerModale').addEventListener('click', () => modale.close());
// Un clic sur le fond sombre (en dehors de la fenêtre) ferme aussi la modale
modale.addEventListener('click', event => {
    if (event.target === modale) modale.close();
});

// Fiche d'un objet : le serveur a déjà envoyé sa description avec la carte
function ouvrirFicheObjet(carte) {
    $('ficheContenu').innerHTML = `
        <div class="fiche-carte">${htmlCarte(carte)}</div>
        <div class="fiche-infos">
            <h2 id="ficheTitre">${carte.nom}</h2>
            <p class="fiche-titre">${carte.titre}</p>
            <p class="fiche-etiquettes">
                <span class="etiquette${carte.nouveau ? ' etiquette-nouveau' : ''}">${carte.nouveau ? 'Nouveau' : 'Doublon'}</span>
                <span class="etiquette">${carte.prix} po</span>
            </p>
            <div class="objet-description">${carte.description}</div>
            <a class="bouton bouton-secondaire" href="objets.html?objet=${carte.cle}">Voir l'arbre de fabrication</a>
        </div>`;
    modale.showModal();
}

