# Nouveautés de chaque version

## 1.1.0

**Horaires, liés au programme horaire d'IANSEO** (aucune valeur gardée à part) :
- **Configuration, étape 2** : pour chaque départ, l'**entraînement** (heure et durée) et la **durée du tir**, en plus
  de l'heure de début. Ce sont l'échauffement, la durée de la qualification et la fin de la session dans IANSEO.
  Contrôles : l'entraînement commence avant le tir ; alerte si un tir déborde sur le départ suivant.
- **Plan du pas de tir** (écran et PDF) : « Entraînement 08:30 (30 min) · Tir 09:00 à 11:00 ».
- **Configuration, étape 4** : **horaire des poules** par catégorie, avec l'heure du 1er tour et la durée d'un match
  (15 min proposées ; un tour dure entre 10 et 20 min). Toutes les poules tirent en même temps, les tours
  s'enchaînent sans pause, l'aperçu donne l'heure de fin. Il est écrit dans l'horaire des matchs d'IANSEO
  (Affectation des cibles) ; un horaire ajusté à la main dans IANSEO (une pause par exemple) est gardé.

**Composition des équipes :**
- bouton **« Retirer toutes les équipes »** : tous les archers reviennent dans « Non placés » ;
- **équipes bloquées dès que la compétition a commencé** (score de qualification, poules validées ou match saisi) :
  plus de glisser-déposer, de composition automatique ni de retrait. En cas de vraie erreur, effacer d'abord les
  scores dans IANSEO.

**Interface :** sur les pages Répartition et Résultats, « Aucune épreuve TDM » renvoie à l'étape 4 de la configuration.

## 1.0.4

**Nouvelle page Greffe** (étape 3, entre Équipes et Qualifications), pour l'accueil des archers et le concours :
- **Feuille de greffe** : par club, les équipes (cellule fusionnée sur leurs archers), archers, licences, départ, cible
  et une case « Payé » à cocher au règlement de l'inscription ;
- **Liste des archers** (alphabétique) et **équipes inscrites** (par catégorie), pour toute la compétition ou par départ ;
- raccourcis vers les **listes de base d'IANSEO** (par départ et cible, par club, alphabétique, par catégorie,
  contrôle des inscriptions) ;
- nombre d'archers et d'équipes par catégorie, alerte pour les archers sans équipe.

**Interface :** la page Configuration rappelle que la configuration et la composition des équipes se font avant
le début de la compétition. Le déroulé passe à 9 étapes.

## 1.0.3

- **Fichier de résultats pour la FFTA** (page Résultats) : classeur Excel au format du modèle FFTA, à envoyer à
  mixite@ffta.fr. Feuille 1 : le vainqueur de chaque catégorie ; feuille 2 : toutes les équipes, « OUI » pour les
  vainqueurs. N° et nom du comité, lieu et date pré-remplis, puis mémorisés.
- **Correction de la mise à jour du module** : les fichiers sont téléchargés depuis la version publiée. Une mise à
  jour lancée juste après une publication pouvait recevoir des fichiers de la version précédente.

## 1.0.2

- **Qualifications** : pile ou face à égalité parfaite (même score, mêmes 10, mêmes 9), colonne affichée seulement
  dans ce cas ; bouton **« Valider vers les poules »** avec le classement du règlement, grisé tant qu'un pile ou face
  reste à faire.
- **Composition des équipes** :
  - les archers saisis ou importés dans IANSEO arrivent dans « Non placés » (IANSEO coche « Épreuve Équipe Mixte »
    par défaut, sans numéro d'équipe : ils se retrouvaient tous dans l'équipe 1) ;
  - « Composer automatiquement » associe toutes les femmes et tous les hommes non placés d'un club ;
  - alerte quand des équipes déjà formées contiennent des archers supprimés ou réimportés.
- **Configuration, étape 4** : « Je confirme » vide les poules d'une catégorie même sans changer sa configuration,
  et supprime les équipes d'archers disparus.
- **Poules** : saisie des matchs directement par poule ; message tant que la catégorie n'est pas validée.
- **Tableaux de poules (PDF)** : plus de « 0 » pour un bye tant que le 5-0 n'est pas appliqué.
- **Classement final** : avec une poule unique, le Big Shoot Off est au choix de l'organisateur ; sans BSO, le
  classement de la poule fait le classement final (1er 10 points, 2e 9, 3e 4, 4e 3).

## 1.0.1

- Documentation réorganisée : README court, détails dans `docs/` (installation, utilisation, données).

## 1.0.0

Première version :
- **Configuration** d'une manche en 4 étapes : division des néo-licenciés, départs et horaires, archers et équipes,
  épreuves et poules ; menu et barre TDM après la 1re étape.
- **Composition des équipes** par glisser-déposer, enregistrement automatique.
- **Qualifications** : cibles (tirage automatique ou à la main), plan du pas de tir, feuilles de marque avec QR code
  Scorekeeper, saisie par départ.
- **Répartition des poules** en serpentin, **poules** (feuilles de marque, tableaux, byes 5-0, départage par la
  qualification).
- **Big Shoot Off** : saisie au téléphone, écran public.
- **Résultats** : classement final et points du règlement, PDF, extraction Excel.
- **Mise à jour et désinstallation** depuis IANSEO.

---

[← Retour au README](../README.md)
