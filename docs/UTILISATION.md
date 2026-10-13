# Utilisation pas à pas

Une fois la configuration faite, une **barre TDM** s'affiche sous le menu IANSEO sur toutes les pages
(y compris celles d'IANSEO) : elle suit le déroulé d'une manche et surligne la page en cours.
Chaque page du module rappelle en tête son rôle et ses étapes.

| Étape | Page | Rôle |
|---|---|---|
| 1 | Configuration | Préparer la compétition pour le TDM. |
| 2 | Composition des équipes | Former les équipes femme + homme du même club. |
| 3 | Greffe | Imprimer la feuille de greffe, la liste des archers et des équipes inscrites. |
| 4 | Qualifications | Attribuer les cibles, imprimer, saisir les scores par équipe. |
| 5 | Répartition des poules | Voir comment la qualification remplit les poules. |
| 6 | Validation qualif → poules | Page IANSEO : départager les égalités et remplir les poules. |
| 7 | Poules | Imprimer, saisir les matchs, byes 5-0, départage. |
| 8 | Big Shoot Off | Départager les 1ers de poule, saisie au téléphone, écran public. |
| 9 | Résultats | Classement final à points, PDF et extraction Excel. |

## Avant de commencer

Créer la compétition dans IANSEO, type **« Salle 18 m »** FFTA (avec les épreuves par équipe).

## 1. Configuration

**À faire avant le début de la compétition**, comme la composition des équipes. Quatre étapes, dans l'ordre. Chacune peut être relancée : ce qui est déjà en place n'est pas recréé.

1. **Division NE, classes et distances** : crée la division des néo-licenciés (arc classique), ouverte aux
   classes U13 à S3, avec les distances des seniors classiques et un blason unique de 60 cm. **Son enregistrement active le menu complet et la barre TDM.**
2. **Départs et horaires** : pour chaque catégorie (NEO, CL, CO, BB), le départ, la date, l'heure et le
   nombre de cibles. Le module crée les sessions de qualification d'IANSEO et contrôle la cohérence avec les
   dates de la compétition. Une alerte signale un départ qui n'a pas assez de cibles.
   L'**entraînement** (facultatif : heure et durée en minutes) est l'échauffement de la session dans le
   programme horaire d'IANSEO ; il doit commencer avant le tir et s'affiche sur le plan du pas de tir. Une durée
   saisie seule propose l'heure juste avant le tir.
   La **durée du tir** (facultative, en minutes) est la durée de la qualification dans le programme horaire d'IANSEO ;
   elle fixe aussi la fin de la session. Le plan du pas de tir affiche « Tir 09:00 à 11:00 », et une alerte signale un
   tir qui déborde sur le départ suivant (entraînement compris).
3. **Archers et composition des équipes** : saisir ou importer les archers dans IANSEO (menu Participants),
   puis composer les équipes (étape 2 du déroulé). Le bouton **Placer les archers dans la session de leur départ**
   range chaque archer dans le départ de sa catégorie.
4. **Épreuves d'équipe et poules** : choisir le nombre de poules et leur taille pour chaque catégorie.
   Le module crée les épreuves par équipe en Round Robin et leurs cibles de poules. Seules les catégories
   cochées sont créées ou mises à jour (modifier une ligne la coche). Pour une catégorie dont les poules sont déjà
   remplies, la case « Je confirme » vide les poules et efface les matchs : obligatoire pour changer la configuration,
   elle sert aussi à repartir de zéro (puis refaire la validation qualif → poules).
   **Horaire** (facultatif) : l'heure du 1er tour et la durée d'un match (15 min proposées ; un tour dure entre
   10 et 20 min). Sans heure, pas d'horaire. Toutes les poules de la catégorie tirent en
   même temps, les tours s'enchaînent sans pause (l'aperçu donne l'heure de fin). Il est écrit dans l'horaire des matchs
   d'IANSEO (**Matchs de Poules → Affectation des cibles**), où l'on peut l'ajuster match par match : cet horaire
   ajusté est gardé tant que l'heure ou la durée ne changent pas à l'étape 4 (mention « horaire personnalisé »).

> **Archers supprimés puis réimportés après une validation :** les équipes déjà formées pointent vers des archers
> qui n'existent plus. Une alerte rouge le signale (Configuration, Équipes, Qualifications). Recomposez les équipes,
> puis cochez la catégorie et « Je confirme » à l'étape 4 : les poules sont vidées et ces équipes supprimées.

## 2. Composition des équipes

Une équipe = **une femme et un homme du même club**, dans la même catégorie. Tout est enregistré automatiquement.

- **Glisser-déposer** chaque archer dans une équipe ; au doigt : toucher l'archer, puis la case.
  Les cases possibles s'éclairent en vert.
- **Composer automatiquement** (un club, ou tous les clubs) associe dans chaque catégorie les femmes et les hommes
  non placés, dans l'ordre de la liste. Les archers en trop restent non placés ; ajustez ensuite par glisser-déposer.
- Un archer ne va que dans une équipe de **sa division d'inscription**. Seule exception : un archer
  d'arc classique placé en NEO passe en division NE (et inversement), après confirmation.
- Les archers inscrits dans une division hors TDM sont signalés.
- Un archer saisi ou importé dans IANSEO arrive dans **« Non placés »**, même si « Épreuve Équipe Mixte » est cochée
  (IANSEO la coche par défaut, sans numéro d'équipe).
- **Retirer toutes les équipes** remet tous les archers dans « Non placés » (après confirmation), pour recomposer
  depuis le début.
- **Équipes bloquées dès que la compétition a commencé** : un score de qualification saisi, des poules validées ou un
  match de poule saisi, dans n'importe quelle catégorie. Plus aucun changement n'est possible (glisser-déposer,
  composer automatiquement, retirer toutes les équipes). En cas de vraie erreur, effacez d'abord les scores dans IANSEO.

## 3. Greffe

Les impressions utiles à l'accueil des archers et pendant le concours, pour toute la compétition ou pour un départ :

- **Feuille de greffe** : par club, les équipes et leurs archers (licence, départ, cible), avec une case « Payé »
  à cocher au règlement de l'inscription. Les archers sans équipe sont en fin de club.
- **Liste des archers** : ordre alphabétique, avec licence, club, catégorie, équipe et cible.
- **Équipes inscrites** : par catégorie, avec n° d'agrément, archers et licences, départ et cible.
- **Listes de base d'IANSEO** (une ligne par archer) : par départ et cible, par club, alphabétique, par catégorie,
  contrôle des inscriptions.

La page rappelle aussi le nombre d'archers et d'équipes par catégorie, et signale les archers sans équipe.

## 4. Qualifications

Mini-qualification par équipe : 5 volées de 4 flèches (2 par archer).

1. **🎯 Attribution des cibles** : deux équipes par cible (positions A et B, par exemple « 3A », « 3B »).
   Tirage automatique pour toutes les catégories ou une seule, puis ajustements à la main. Deux équipes
   du même club et de la même catégorie sur une cible : simple avertissement.
2. **Impressions** : plan du pas de tir (un par départ, avec les blasons), feuilles de marque de qualification
   (une par équipe, dans l'ordre des cibles, avec le QR code Scorekeeper s'il est activé) et classement.
3. **Saisie** : **Ouvrir la saisie** du départ, puis entrer le score de chaque équipe et ses nombres de 10 et de 9
   (départage). Un seul départ est ouvert à la fois. Une case vide retire l'équipe du classement.
4. **Pile ou face** : à égalité parfaite (même score, mêmes 10, mêmes 9), une colonne « 🪙 Pile ou face »
   apparaît pour les équipes concernées : cochez le vainqueur (à trois équipes ou plus, choisissez l'ordre),
   puis enregistrez. Sans égalité, la colonne n'est pas affichée. Le pile ou face est à refaire si un score change.
5. **✅ Valider vers les poules** : remplit les poules avec ce classement (score, 10, 9, pile ou face). Le bouton
   reste grisé tant qu'un pile ou face n'est pas fait. « Refaire la validation » efface les matchs de poule déjà saisis.

## 5. Répartition des poules

Montre, pour chaque configuration possible, comment le classement de qualification remplit les poules en
serpentin (1, 2, 3 puis 6, 5, 4…), comme la feuille « Poules (aide) » du classeur Excel. La configuration
retenue est encadrée en rose ; elle se change à l'étape 4 de la Configuration, **avant** la validation.
Impression en PDF.

## 6. Validation qualif → poules

Le bouton **✅ Valider vers les poules** de la page Qualifications fait la validation IANSEO avec le classement du
règlement. La page native (barre TDM, « Validation ») reste utilisable : il faut alors y reporter le pile ou face.

## 7. Poules

1. **Imprimer** les feuilles de marque (dans l'ordre des rencontres) et les tableaux de poules, par poule
   ou toutes poules. Les matchs contre un bye n'ont pas de feuille.
2. **Appliquer les byes 5-0** : un match contre une place vide compte comme une victoire 5-0
   (2 points + 5 points de set). À refaire si la validation est refaite.
3. **✎ Saisir les matchs** (par poule ou toutes) : saisie IANSEO, ouverte seulement une fois la catégorie validée vers les poules.
4. Poule terminée : **Départager par la qualif**. À égalité de points et de points de set, la meilleure
   qualification passe devant. Une nouvelle saisie de match efface ce départage.

## 8. Big Shoot Off

Le BSO réunit les **1ers de poule** (poule unique : les **deux premiers**).
Avec une **poule unique**, le BSO est au choix de l'organisateur : bouton « Ne pas faire de BSO » sur la carte de la
catégorie. Sans BSO, le classement de la poule fait le classement final (1er 10 points, 2e 9 points, 3e 4, 4e 3). Volées de 2 flèches
(une par archer) en 40 s : toutes les équipes → 4 → 2 → finale.

1. Poules terminées : **Confirmer les équipes du BSO** (le module propose les équipes et leurs cibles).
2. **Saisie BSO** : au téléphone (scanner le QR code de la page) ou à l'ordinateur. Une égalité se tranche
   en indiquant l'ordre des équipes.
3. **Écran public** : à afficher sur un autre écran, il suit le BSO en direct.

## 9. Résultats

Classement final de chaque catégorie, avec son état : **définitif** ou **provisoire** (et pourquoi).

- Le BSO classe ses équipes ; ensuite, tous les 2es de poule sont ex aequo, puis tous les 3es, etc. (« = »).
- Points : 10, 9, 8, 7 pour les quatre premiers du BSO, 6 pour les autres équipes du BSO, puis 5 / 4 / 3
  pour les 2es / 3es / 4es de poule, 0 au-delà.
- **🖨 PDF** (une catégorie par page) et **⬇ Excel** (fichier CSV `;`), par catégorie ou toutes catégories.
- **Fichier de résultats pour la FFTA** (bas de la page) : le classeur Excel au format du modèle FFTA, à envoyer à
  mixite@ffta.fr. Feuille 1 : le vainqueur de chaque catégorie ; feuille 2 : toutes les équipes, « OUI » pour les
  vainqueurs. Le n° et le nom du comité, le lieu et la date sont pré-remplis et gardés pour la fois suivante.

## Droits d'accès

| Page | Droit IANSEO |
|---|---|
| Configuration | Compétition (lecture ; écriture pour appliquer les étapes) |
| Composition des équipes, Greffe | Participants |
| Qualifications, cibles, répartition, résultats | Qualification |
| Poules, Big Shoot Off | Round Robin |
| Mise à jour et désinstallation | Administrateur |

---

[← Retour au README](../README.md)
