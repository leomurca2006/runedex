const darkmodebutton = document.querySelector(".ChangementDeMode");
let root = document.documentElement;
let toggle = 1

darkmodebutton.addEventListener('click', function () {
    // Le site est sombre par défaut ; les couleurs claires sont dans style.css ([data-theme="light"])
    if (toggle === 1) {
        root.dataset.theme = 'light';
        darkmodebutton.textContent = 'Mode sombre';
    } else {
        root.dataset.theme = 'dark';
        darkmodebutton.textContent = 'Mode clair';
    }

    toggle = -toggle;
});

const menu = document.querySelector(".menu");
const menuItems = document.querySelectorAll(".menuItem");
const hamburger= document.querySelector(".hamburger");
const closeIcon= document.querySelector(".closeIcon");
const menuIcon = document.querySelector(".menuIcon");

function toggleMenu() {
    console.log(menuItems);
  if (menu.classList.contains("showMenu")) {
    menu.classList.remove("showMenu");
    closeIcon.style.display = "none";
    menuIcon.style.display = "block";
  } else {
    menu.classList.add("showMenu");
    closeIcon.style.display = "block";
    menuIcon.style.display = "none";
  }
}

hamburger.addEventListener("click", toggleMenu);

menuItems.forEach( 

  function(menuItem) { 

    menuItem.addEventListener("click", toggleMenu);

  }

)




let prenomSauvegarde = localStorage.getItem("prenom");

if (prenomSauvegarde !== null) {
    document.getElementById("titre").textContent =
    "Bienvenue," + prenomSauvegarde + "!";
}

function sauvegarder() {
    let prenom = document.getElementById("inputPrenom").value;

    if (prenom.trim() === "") {
        alert("Entrez un prénom!");
        return;
    }
    localStorage.setItem("prenom", prenom);
    document.getElementById("titre").textContent = "Bienvenue, " + prenom + "!"
    document.getElementById("inputPrenom").value ="";
}

console.log("allo")