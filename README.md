# TDM — Trophée Départemental des Mixtes pour IANSEO

**Version 1.0.0**

Module IANSEO pour organiser une manche de **Trophée Départemental des Mixtes** (règlement FFTA 2026) :
qualification par équipe mixte, tour de poules en sets, Big Shoot Off et classement final à points.

Le module s'appuie sur IANSEO autant que possible : sessions, cibles, épreuves par équipe, poules
Round Robin et saisie des matchs restent ceux d'IANSEO. Il ajoute ce qu'IANSEO ne fait pas pour le TDM.

## Installation

Il faut une installation IANSEO qui fonctionne (PHP 8, MySQL ou MariaDB).

Le module doit se trouver dans `Modules/Custom/TDM`. Le nom du dossier, `TDM`, compte : les liens
du module l'utilisent. `Modules/Custom/` est le seul dossier que les mises à jour d'IANSEO ne réécrivent pas.

### Windows

1. Télécharger `TDM-vX.Y.Z.zip` dans **Releases**. Le dossier y est déjà nommé `TDM`.
2. Le décompresser dans `C:\ianseo\htdocs\Modules\Custom\`, ce qui donne `C:\ianseo\htdocs\Modules\Custom\TDM\menu.php`.

Le bouton **Code → Download ZIP** convient aussi, à condition de renommer en `TDM` le dossier `TDM-main` obtenu.

### Linux (ligne de commande)

Adapter le chemin à votre installation (par exemple `/var/www/html/ianseo`) :

```bash
cd /var/www/html/ianseo/Modules/Custom
sudo git clone https://github.com/CorentinA1/TDM.git TDM
```

Sans Git :

```bash
cd /var/www/html/ianseo/Modules/Custom
wget https://github.com/CorentinA1/TDM/archive/refs/heads/main.tar.gz
tar xzf main.tar.gz && mv TDM-main TDM && rm main.tar.gz
sudo chown -R www-data:www-data TDM   # pour les mises à jour depuis IANSEO
```

### Premier lancement

Ouvrir une compétition dans IANSEO : le menu **Modules → Trophée des Mixtes → Configuration** apparaît.
Les tables du module se créent toutes seules à l'ouverture de la première page qui en a besoin.

### Mise à jour

**Depuis IANSEO :** **Modules → Trophée des Mixtes → Mise à jour du module**, réservé à l'administrateur.
1. **Vérifier les mises à jour** compare la version installée à celle de GitHub et affiche les nouveautés.
2. **Mettre à jour** télécharge et remplace les fichiers du module. Tous les fichiers sont récupérés
   avant d'en remplacer un seul : en cas d'échec, rien n'est modifié.

Le serveur web doit pouvoir écrire dans le dossier `TDM` (sous Linux : `chown www-data`, voir l'installation).
La source est indiquée dans `module.json`, que les mises à jour ne remplacent jamais.
Un dossier géré par Git se met à jour avec `git pull` : la page d'IANSEO le refuse.

Les données des compétitions, enregistrées en base, sont toujours conservées.

### Désinstallation

**Modules → Trophée des Mixtes → Mise à jour du module → Désinstaller le module** (administrateur).
L'écran de confirmation :
- indique ce qui sera supprimé : le dossier du module et, **seulement si vous cochez la case**, ses données
  (tables du Big Shoot Off et réglages TDM de toutes les compétitions) ;
- propose de **télécharger une sauvegarde** de ces données (.sql) avant de les supprimer ;
- demande de taper le nom du module (`TDM`) pour confirmer.

Les équipes, scores, poules et matchs restent dans les compétitions IANSEO.

## Utilisation pas à pas

Une fois la configuration faite, une **barre TDM** s'affiche sous le menu IANSEO sur toutes les pages
(y compris celles d'IANSEO) : elle suit le déroulé d'une manche et surligne la page en cours.
Chaque page du module rappelle en tête son rôle et ses étapes.

| Étape | Page | Rôle |
|---|---|---|
| 1 | Configuration | Préparer la compétition pour le TDM. |
| 2 | Composition des équipes | Former les équipes femme + homme du même club. |
| 3 | Qualifications | Attribuer les cibles, imprimer, saisir les scores par équipe. |
| 4 | Répartition des poules | Voir comment la qualification remplit les poules. |
| 5 | Validation qualif → poules | Page IANSEO : départager les égalités et remplir les poules. |
| 6 | Poules | Imprimer, saisir les matchs, byes 5-0, départage. |
| 7 | Big Shoot Off | Départager les 1ers de poule, saisie au téléphone, écran public. |
| 8 | Résultats | Classement final à points, PDF et extraction Excel. |

### Avant de commencer

Créer la compétition dans IANSEO, type **« Salle 18 m »** FFTA (avec les épreuves par équipe).

### 1. Configuration

Quatre étapes, dans l'ordre. Chacune peut être relancée : ce qui est déjà en place n'est pas recréé.

1. **Division NE, classes et distances** : crée la division des néo-licenciés (arc classique), ouverte aux
   classes U13 à S3, avec les distances des seniors classiques et un blason unique de 60 cm. **Son enregistrement active le menu complet et la barre TDM.**
2. **Départs et horaires** : pour chaque catégorie (NEO, CL, CO, BB), le départ, la date, l'heure et le
   nombre de cibles. Le module crée les sessions de qualification d'IANSEO et contrôle la cohérence avec les
   dates de la compétition. Une alerte signale un départ qui n'a pas assez de cibles.
3. **Archers et composition des équipes** : saisir ou importer les archers dans IANSEO (menu Participants),
   puis composer les équipes (étape 2 du déroulé). Le bouton **Placer les archers dans la session de leur départ**
   range chaque archer dans le départ de sa catégorie.
4. **Épreuves d'équipe et poules** : choisir le nombre de poules et leur taille pour chaque catégorie.
   Le module crée les épreuves par équipe en Round Robin et leurs cibles de poules. Seules les catégories
   cochées sont créées ou mises à jour (modifier une ligne la coche). Si les poules d'une catégorie sont déjà
   remplies, il faut cocher « Je confirme » : elles seront vidées et leurs matchs effacés.

### 2. Composition des équipes

Une équipe = **une femme et un homme du même club**, dans la même catégorie. Tout est enregistré automatiquement.

- **Glisser-déposer** chaque archer dans une équipe ; au doigt : toucher l'archer, puis la case.
  Les cases possibles s'éclairent en vert.
- **Composer automatiquement tous les clubs** place les clubs qui ont exactement une femme et un homme non placés.
- Un archer ne va que dans une équipe de **sa division d'inscription**. Seule exception : un archer
  d'arc classique placé en NEO passe en division NE (et inversement), après confirmation.
- Les archers inscrits dans une division hors TDM sont signalés.

### 3. Qualifications

Mini-qualification par équipe : 5 volées de 4 flèches (2 par archer).

1. **🎯 Attribution des cibles** : deux équipes par cible (positions A et B, par exemple « 3A », « 3B »).
   Tirage automatique pour toutes les catégories ou une seule, puis ajustements à la main. Deux équipes
   du même club et de la même catégorie sur une cible : simple avertissement.
2. **Impressions** : plan du pas de tir (un par départ, avec les blasons), feuilles de marque de qualification
   (une par équipe, dans l'ordre des cibles, avec le QR code Scorekeeper s'il est activé) et classement.
3. **Saisie** : **Ouvrir la saisie** du départ, puis entrer le score de chaque équipe et ses nombres de 10 et de 9
   (départage). Un seul départ est ouvert à la fois. Une case vide retire l'équipe du classement.

### 4. Répartition des poules

Montre, pour chaque configuration possible, comment le classement de qualification remplit les poules en
serpentin (1, 2, 3 puis 6, 5, 4…), comme la feuille « Poules (aide) » du classeur Excel. La configuration
retenue est encadrée en rose ; elle se change à l'étape 4 de la Configuration, **avant** la validation.
Impression en PDF.

### 5. Validation qualif → poules (page IANSEO)

La page native d'IANSEO départage les égalités restantes (tirage au sort) et remplit les poules.

### 6. Poules

1. **Imprimer** les feuilles de marque (dans l'ordre des rencontres) et les tableaux de poules, par poule
   ou toutes poules. Les matchs contre un bye n'ont pas de feuille.
2. **Appliquer les byes 5-0** : un match contre une place vide compte comme une victoire 5-0
   (2 points + 5 points de set). À refaire si la validation est refaite.
3. **✎ Saisir les matchs** : saisie IANSEO.
4. Poule terminée : **Départager par la qualif**. À égalité de points et de points de set, la meilleure
   qualification passe devant. Une nouvelle saisie de match efface ce départage.

### 7. Big Shoot Off

Le BSO réunit les **1ers de poule** (poule unique : les **deux premiers**). Volées de 2 flèches
(une par archer) en 40 s : toutes les équipes → 4 → 2 → finale.

1. Poules terminées : **Confirmer les équipes du BSO** (le module propose les équipes et leurs cibles).
2. **Saisie BSO** : au téléphone (scanner le QR code de la page) ou à l'ordinateur. Une égalité se tranche
   en indiquant l'ordre des équipes.
3. **Écran public** : à afficher sur un autre écran, il suit le BSO en direct.

### 8. Résultats

Classement final de chaque catégorie, avec son état : **définitif** ou **provisoire** (et pourquoi).

- Le BSO classe ses équipes ; ensuite, tous les 2es de poule sont ex aequo, puis tous les 3es, etc. (« = »).
- Points : 10, 9, 8, 7 pour les quatre premiers du BSO, 6 pour les autres équipes du BSO, puis 5 / 4 / 3
  pour les 2es / 3es / 4es de poule, 0 au-delà.
- **🖨 PDF** (une catégorie par page) et **⬇ Excel** (fichier CSV `;`), par catégorie ou toutes catégories.

## Droits d'accès

| Page | Droit IANSEO |
|---|---|
| Configuration | Compétition (lecture ; écriture pour appliquer les étapes) |
| Composition des équipes | Participants |
| Qualifications, cibles, répartition, résultats | Qualification |
| Poules, Big Shoot Off | Round Robin |
| Mise à jour et désinstallation | Administrateur |

## Base de données

Le module crée deux tables qui lui sont propres, pour le Big Shoot Off : `TdmBsoTeams` (équipes et cibles)
et `TdmBsoEnds` (volées). Ses réglages par compétition (départs, départ ouvert, configuration faite) sont
rangés dans la table IANSEO `ModulesParameters`, sous le nom de module `TDM`.

Pour s'appuyer sur IANSEO, il **écrit aussi dans des tables d'IANSEO**, toujours pour la compétition ouverte :

| Table | Écrit par |
|---|---|
| `Divisions`, `Classes`, `TargetFaces`, `TournamentDistances` | Configuration, étape 1 |
| `Session`, `DistanceInformation`, `Tournament` (nombre de sessions) | Configuration, étape 2 (fonctions d'IANSEO) |
| `Entries` (division, classe, blason, numéro d'équipe) | Configuration, composition des équipes |
| `Qualifications` (départ, cible, score d'équipe) | Configuration, cibles, saisie de la qualification |
| `Events`, `EventClass`, `RoundRobin*` | Configuration, étape 4 ; byes 5-0 et départages des poules |
| `Teams` | Recalcul des équipes par la fonction d'IANSEO après une saisie |

## Licence

MIT non commerciale : voir le fichier [LICENSE](LICENSE). Libre d'utilisation, de modification et de
redistribution, sans usage commercial et en conservant la mention de copyright.
