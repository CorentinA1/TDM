# Données et base de données

Le module crée deux tables qui lui sont propres, pour le Big Shoot Off : `TdmBsoTeams` (équipes et cibles)
et `TdmBsoEnds` (volées). Ses réglages par compétition (départs, départ ouvert, configuration faite) sont
rangés dans la table IANSEO `ModulesParameters`, sous le nom de module `TDM` (dont les pile ou face, les catégories
sans BSO et les informations du fichier FFTA).

Pour s'appuyer sur IANSEO, il **écrit aussi dans des tables d'IANSEO**, toujours pour la compétition ouverte :

| Table | Écrit par |
|---|---|
| `Divisions`, `Classes`, `TargetFaces`, `TournamentDistances` | Configuration, étape 1 |
| `Session` (dont la fin de session), `DistanceInformation` (horaire, durée du tir, entraînement, volées), `Tournament` (nombre de sessions) | Configuration, étape 2 (fonctions d'IANSEO) |
| `Entries` (division, classe, blason, numéro d'équipe) | Configuration, composition des équipes |
| `Qualifications` (départ, cible, score d'équipe) | Configuration, cibles, saisie de la qualification |
| `Events`, `EventClass`, `RoundRobin*` (dont l'horaire des matchs de poule) | Configuration, étape 4 ; « Valider vers les poules » (validation d'IANSEO) ; byes 5-0 et départages des poules |
| `Teams`, `TeamComponent` | Recalcul des équipes par la fonction d'IANSEO après une saisie ; « Je confirme » (étape 4) supprime les équipes d'archers disparus |

Les pages Greffe et Résultats (PDF, extraction, fichier FFTA) ne font que lire.

---

[← Retour au README](../README.md)
