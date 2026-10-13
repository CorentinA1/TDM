<?php
/**
 * lib/uninstall.php — désinstallation du module.
 *
 * Même principe que les modules de la communauté ianseo, avec du code propre :
 * écran de confirmation, suppression des tables au choix, nom du module à
 * taper, jeton CSRF, accès administrateur, suppression confinée au dossier du
 * module.
 *
 * SUPPRIMER SON PROPRE DOSSIER. Sous Windows, Apache garde la main sur le
 * dossier du script en cours d'exécution : le script peut effacer les fichiers,
 * pas les dossiers. La suppression est donc confiée à un script jetable écrit
 * dans Modules/Custom/, HORS du module : il supprime le dossier puis s'efface
 * lui-même. Il n'agit qu'avec son jeton, une seule fois, et pendant 10 minutes.
 * Si Modules/Custom/ n'est pas accessible en écriture (cas courant sous Linux),
 * la suppression se fait directement, ce que Linux permet.
 *
 * Suppose ianseo chargé (safe_r_sql, safe_w_sql, StrSafe_DB).
 */

/** Seules tables que le module peut supprimer : les siennes (Big Shoot Off). */
function tdm_uns_tables()
{
    return ['TdmBsoTeams', 'TdmBsoEnds'];
}

/** Clé des réglages du module dans la table ianseo ModulesParameters. */
const TDM_UNS_PARAM_MODULE = 'TDM';

/**
 * Nombre de lignes de chaque table du module (null si la table n'existe pas),
 * plus les réglages du module rangés dans ModulesParameters.
 *
 * @return array nom => int|null
 */
function tdm_uns_counts()
{
    $out = [];
    foreach (tdm_uns_tables() as $t) {
        $rs = safe_r_sql("SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . StrSafe_DB($t));
        if (!safe_fetch($rs)) { $out[$t] = null; continue; }
        $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM `$t`"));
        $out[$t] = $r ? intval($r->n) : 0;
    }
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM ModulesParameters WHERE MpModule = " . StrSafe_DB(TDM_UNS_PARAM_MODULE)));
    $out['ModulesParameters'] = $r ? intval($r->n) : 0;
    return $out;
}

/** Une ligne INSERT pour la sauvegarde. */
function tdm_uns_insert($t, array $row)
{
    $vals = array_map(function ($v) { return $v === null ? 'NULL' : StrSafe_DB($v); }, $row);
    return "INSERT INTO `$t` (`" . implode('`, `', array_keys($row)) . "`) VALUES (" . implode(', ', $vals) . ");\n";
}

/**
 * Sauvegarde SQL des données du module, envoyée au navigateur : ses tables
 * (structure + données) et ses réglages de ModulesParameters (les lignes TDM
 * seulement, sans toucher au reste de cette table d'ianseo). À appeler avant
 * tout affichage.
 */
function tdm_uns_send_backup($filename)
{
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "-- TDM (ianseo) : sauvegarde des données du module\n-- Restauration : importer ce fichier dans la base ianseo.\n\nSET NAMES utf8mb4;\n\n";
    foreach (tdm_uns_counts() as $t => $n) {
        if ($n === null || $t === 'ModulesParameters') continue;
        $c = safe_fetch_assoc(safe_r_sql("SHOW CREATE TABLE `$t`"));
        echo "DROP TABLE IF EXISTS `$t`;\n" . $c['Create Table'] . ";\n\n";
        $rs = safe_r_sql("SELECT * FROM `$t`");
        while ($row = safe_fetch_assoc($rs)) echo tdm_uns_insert($t, $row);
        echo "\n";
    }
    $m = StrSafe_DB(TDM_UNS_PARAM_MODULE);
    echo "-- Réglages du module (table ianseo ModulesParameters, lignes TDM)\nDELETE FROM `ModulesParameters` WHERE `MpModule` = $m;\n";
    $rs = safe_r_sql("SELECT * FROM ModulesParameters WHERE MpModule = $m");
    while ($row = safe_fetch_assoc($rs)) echo tdm_uns_insert('ModulesParameters', $row);
}

/**
 * Supprime les données du module : ses tables et ses lignes de ModulesParameters.
 *
 * @return string[] ce qui a été supprimé.
 */
function tdm_uns_drop_tables()
{
    $done = [];
    foreach (tdm_uns_counts() as $t => $n) {
        if ($t === 'ModulesParameters') {
            if ($n) {
                safe_w_sql("DELETE FROM ModulesParameters WHERE MpModule = " . StrSafe_DB(TDM_UNS_PARAM_MODULE));
                $done[] = "réglages TDM ($n)";
            }
            continue;
        }
        if ($n === null) continue;
        safe_w_sql("DROP TABLE IF EXISTS `$t`");
        $done[] = $t;
    }
    return $done;
}

/**
 * Le dossier peut-il être supprimé ? Il doit être un sous-dossier direct de
 * Modules/Custom/ et contenir les fichiers de ce module.
 *
 * @return string|null Chemin réel, ou null si refusé.
 */
function tdm_uns_target($moduleDir)
{
    $real = realpath($moduleDir);
    $custom = realpath(dirname($moduleDir));
    if ($real === false || $custom === false || dirname($real) !== $custom) return null;
    if (basename($custom) !== 'Custom' || basename(dirname($custom)) !== 'Modules') return null;
    foreach (['menu.php', 'lib/uninstall.php', 'version.json'] as $f) {
        if (!is_file($real . '/' . $f)) return null;
    }
    return $real;
}

/** Suppression récursive (fichiers puis dossiers). @return bool dossier disparu. */
function tdm_uns_rrmdir($dir)
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
    clearstatcache();
    return !is_dir($dir);
}

/**
 * Écrit le script jetable de suppression dans Modules/Custom/.
 *
 * @param string $target Dossier du module (chemin réel, validé).
 * @param string $home URL d'accueil d'ianseo, pour le lien final.
 * @return array|null ['file' => chemin, 'name' => nom de fichier, 'key' => jeton], ou null.
 */
function tdm_uns_write_remover($target, $home)
{
    $key = bin2hex(random_bytes(16));
    $name = '_tdm_uninstall_' . bin2hex(random_bytes(8)) . '.php';
    $file = dirname($target) . '/' . $name;
    $code = '<?php
// Script jetable de désinstallation de TDM : supprime le dossier du module,
// puis s\'efface. Une seule utilisation, avec son jeton, pendant 10 minutes.
$target = ' . var_export($target, true) . ';
$hash = ' . var_export(hash('sha256', $key), true) . ';
$expires = ' . (time() + 600) . ';
$ok = isset($_GET["k"]) && is_string($_GET["k"]) && hash_equals($hash, hash("sha256", $_GET["k"])) && time() < $expires;
$gone = false;
if ($ok && is_dir($target) && dirname($target) === __DIR__) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($target);
    clearstatcache();
    $gone = !is_dir($target);
}
if ($ok || time() >= $expires) @unlink(__FILE__);
header("Content-Type: text/html; charset=UTF-8");
echo "<!DOCTYPE html><meta charset=utf-8><title>TDM</title><body style=\"font:14px Verdana,Arial,sans-serif;margin:40px\">";
if (!$ok) { http_response_code(403); echo "<p>Lien de désinstallation invalide ou expiré.</p>"; }
elseif ($gone) { echo "<h2 style=\"color:#1e5e33\">TDM est désinstallé.</h2><p>Le dossier du module a été supprimé.</p>"; }
else { echo "<h2 style=\"color:#b3261e\">Suppression incomplète</h2><p>Supprimez à la main le dossier : <code>" . htmlspecialchars($target) . "</code></p>"; }
echo "<p><a href=\"' . htmlspecialchars($home, ENT_QUOTES) . '\">Retour à ianseo</a></p></body>";
';
    if (@file_put_contents($file, $code) === false) return null;
    return ['file' => $file, 'name' => $name, 'key' => $key];
}
