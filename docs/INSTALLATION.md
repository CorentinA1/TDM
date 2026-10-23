# Installation, mise à jour et désinstallation

Il faut une installation IANSEO qui fonctionne (PHP 8, MySQL ou MariaDB). Lisez d'abord
[À lire avant d'utiliser le module](AVERTISSEMENT.md) : module indépendant d'IANSEO, sans garantie, sauvegardes.

Le module doit se trouver dans `Modules/Custom/TDM`. Le nom du dossier, `TDM`, compte : les liens
du module l'utilisent. `Modules/Custom/` est le seul dossier que les mises à jour d'IANSEO ne réécrivent pas.

## Windows

1. Télécharger `TDM-vX.Y.Z.zip` dans **Releases**. Le dossier y est déjà nommé `TDM`.
2. Le décompresser dans `C:\ianseo\htdocs\Modules\Custom\`, ce qui donne `C:\ianseo\htdocs\Modules\Custom\TDM\menu.php`.

Le bouton **Code → Download ZIP** convient aussi, à condition de renommer en `TDM` le dossier `TDM-main` obtenu.

## Linux (ligne de commande)

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

## Premier lancement

Ouvrir une compétition dans IANSEO : le menu **Modules → Trophée des Mixtes → Configuration** apparaît.
Les tables du module se créent toutes seules à l'ouverture de la première page qui en a besoin.

## Mise à jour

**Pastille dans le menu** (administrateur) : quand une version plus récente est publiée, un point rouge apparaît sur
le titre **Modules** et sur le nom du module, et une pastille rouge **⬆** sur « Mise à jour du module » ; au survol, une
bulle donne la version installée et la version disponible. La vérification se fait en arrière-plan, sans ralentir les
pages, au plus une fois par jour (une heure après un échec) ; ouvrir la page de mise à jour vérifie tout de suite. Sans
Internet, pas de pastille : la dernière information connue est gardée.

**Depuis IANSEO :** **Modules → Trophée des Mixtes → Mise à jour du module**, réservé à l'administrateur.
1. **Vérifier les mises à jour** compare la version installée à celle de GitHub et affiche les nouveautés.
2. **Mettre à jour** télécharge et remplace les fichiers du module. Tous les fichiers sont récupérés
   avant d'en remplacer un seul : en cas d'échec, rien n'est modifié.

Le serveur web doit pouvoir écrire dans le dossier `TDM` (sous Linux : `chown www-data`, voir l'installation).
La source est indiquée dans `module.json`, que les mises à jour ne remplacent jamais.
Un dossier géré par Git se met à jour avec `git pull` : la page d'IANSEO le refuse.

Les données des compétitions, enregistrées en base, sont toujours conservées.

## Désinstallation

**Modules → Trophée des Mixtes → Mise à jour du module → Désinstaller le module** (administrateur).
L'écran de confirmation :
- indique ce qui sera supprimé : le dossier du module et, **seulement si vous cochez la case**, ses données
  (tables du Big Shoot Off et réglages TDM de toutes les compétitions) ;
- propose de **télécharger une sauvegarde** de ces données (.sql) avant de les supprimer ;
- demande de taper le nom du module (`TDM`) pour confirmer.

Les équipes, scores, poules et matchs restent dans les compétitions IANSEO.

---

[← Retour au README](../README.md)
