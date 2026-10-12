# Données et base de données

Le module crée deux tables qui lui sont propres, pour le Big Shoot Off : `TdmBsoTeams` (équipes et cibles)
et `TdmBsoEnds` (volées). Ses réglages par compétition (départs, départ ouvert, configuration faite) sont
rangés dans la table IANSEO `ModulesParameters`, sous le nom de module `TDM` (dont les pile ou face, les catégories
sans BSO et les informations du fichier FFTA).

Pour s'appuyer sur IANSEO, il **écrit aussi dans des tables d'IANSEO**, toujours pour la compétition ouverte :

| Table | Écrit par |
|---|---|
| `Divisions`, `Classes`, `TargetFaces`, `TournamentDistances` | Configuration, étape 1 |
| `Session`, `DistanceInformation`, `Tournament` (nombre de sessions) | Configuration, étape 2 (fonctions d'IANSEO) |
| `Entries` (division, classe, blason, numéro d'équipe) | Configuration, composition des équipes |
| `Qualifications` (départ, cible, score d'équipe) | Configuration, cibles, saisie de la qualification |
| `Events`, `EventClass`, `RoundRobin*` | Configuration, étape 4 ; byes 5-0 et départages des poules |
| `Teams` | Recalcul des équipes par la fonction d'IANSEO après une saisie |

---

[← Retour au README](../README.md)
