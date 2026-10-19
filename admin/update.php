<?php
/**
 * admin/update.php — TDM : mise à jour du module depuis GitHub.
 *
 * Réservé à l'administrateur : AclRoot en écriture et, si un module de comptes
 * (AUTH) est actif, la vue « administrateur serveur » (AUTH_ROOT), car AUTH
 * accorde AclRoot à tout organisateur connecté hors compétition.
 * Ne demande pas de compétition ouverte.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once HTDOCS . '/config.php';
require_once dirname(__DIR__) . '/lib/update.php';

checkFullACL(AclRoot, '', AclReadWrite);
if (trim((string) ($_SESSION['AUTH_User'] ?? '')) !== '' && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

$dir = dirname(__DIR__);
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
if (empty($_SESSION['TDM_UPD_TOKEN'])) $_SESSION['TDM_UPD_TOKEN'] = bin2hex(random_bytes(16));
$token = $_SESSION['TDM_UPD_TOKEN'];

$check = null;
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string) ($_POST['tdm_admin_token'] ?? ''))) {
        http_response_code(403);
        exit('Jeton de sécurité invalide : rechargez la page.');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update' || $action === 'force') $result = tdm_upd_apply($dir, $action === 'force');
    $check = tdm_upd_check($dir);   // après une mise à jour, relit la version installée
}

$cfg = tdm_upd_config($dir);
$local = tdm_upd_local($dir);
$isDev = is_dir($dir . '/.git');
$field = '<input type="hidden" name="tdm_admin_token" value="' . $h($token) . '">';

$PAGE_TITLE = 'TDM - Mise à jour du module';
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';
?>
<style>
#tdm-upd {
    /* Couleurs : variables IANSEO (Common/Styles/colors.css), redéfinies par le module THEME pour chaque palette
       et pour le mode sombre. États : --text-success / --text-danger / --text-warning mêlées à --black (texte) ou à
       --row-background (fonds), lisibles dans les deux modes. Seul le rose TDM (identité du trophée) reste fixe. */
    --bleu:var(--header-dark); --sur-bleu:var(--row-main-text); --bleu-fonce:var(--main-text); --bleu-clair:var(--table-background);
    --bleu-survol:color-mix(in srgb, var(--header-dark) 82%, var(--black)); --lien:var(--thm-link, var(--header-dark));
    --rose:#f90a72;
    --gris:var(--text-dark); --sous:var(--text-muted); --bord:var(--input-border); --fond:var(--header-light);
    --carte:var(--row-background); --champ:var(--text-white); --champ-texte:var(--black);
    --vert:color-mix(in srgb, var(--text-success) 60%, var(--black)); --ok-fond:color-mix(in srgb, var(--text-success) 14%, var(--row-background)); --ok-bord:color-mix(in srgb, var(--text-success) 45%, var(--row-background)); --ok-fort:color-mix(in srgb, var(--text-success) 28%, var(--row-background));
    --rouge:color-mix(in srgb, var(--text-danger) 70%, var(--black)); --err-fond:color-mix(in srgb, var(--text-danger) 14%, var(--row-background)); --err-bord:color-mix(in srgb, var(--text-danger) 45%, var(--row-background));
    --orange:color-mix(in srgb, var(--text-warning) 50%, var(--black)); --warn-fond:color-mix(in srgb, var(--text-warning) 16%, var(--row-background)); --warn-bord:color-mix(in srgb, var(--text-warning) 55%, var(--row-background));
}
#tdm-upd { max-width:760px; color:var(--gris); font-size:13px; }
#tdm-upd h2 { color:var(--bleu-fonce); font-size:18px; margin:6px 0 14px; }
#tdm-upd .card { border:1px solid var(--bord); border-radius:6px; background:var(--carte); padding:14px 16px; margin-bottom:14px; }
#tdm-upd .card h3 { margin:0 0 10px; font-size:14px; color:var(--bleu-fonce); }
#tdm-upd .msg { border-radius:6px; padding:10px 14px; margin-bottom:12px; }
#tdm-upd .ok   { background:var(--ok-fond); border:1px solid var(--ok-bord); color:var(--vert); }
#tdm-upd .err  { background:var(--err-fond); border:1px solid var(--err-bord); color:var(--rouge); }
#tdm-upd .warn { background:var(--warn-fond); border:1px solid var(--warn-bord); color:var(--orange); }
#tdm-upd .badge { display:inline-block; border-radius:10px; padding:1px 9px; font-size:11px; font-weight:600; margin-left:6px; }
#tdm-upd .badge.new { background:var(--warn-fond); color:var(--orange); }
#tdm-upd .badge.same { background:var(--ok-fond); color:var(--vert); }
#tdm-upd .notes { background:var(--fond); border-left:3px solid var(--bleu); padding:8px 12px; margin:10px 0 0; white-space:pre-line; }
#tdm-upd button { border-radius:6px; cursor:pointer; padding:8px 18px; font-size:13px; font-weight:600; }
#tdm-upd button.primary { background:var(--bleu); border:1px solid var(--bleu); color:var(--sur-bleu); }
#tdm-upd button.ghost { background:var(--carte); border:1px solid var(--bleu); color:var(--lien); }
#tdm-upd .sub { color:var(--sous); font-size:12px; }
#tdm-upd details { margin-top:10px; }
</style>

<div id="tdm-upd">
    <h2>Trophée des Mixtes — Mise à jour du module</h2>

    <?php if ($result): ?>
        <div class="msg <?= $result['ok'] ? 'ok' : 'err' ?>"><?= $h($result['message']) ?></div>
    <?php endif; ?>
    <?php if ($isDev): ?>
        <div class="msg warn">Cette installation est une copie de développement (dossier <code>.git</code>) :
            la mise à jour depuis cette page est désactivée. Utilisez Git.</div>
    <?php endif; ?>

    <div class="card">
        <h3>Version installée : <?= $h($local['version'] ?? 'inconnue') ?></h3>
        <div class="sub">Source : <?= $cfg['github_url'] !== '' ? $h($cfg['github_url']) : 'pas encore renseignée' ?> (branche <?= $h($cfg['github_branch']) ?>) — fichier <code>module.json</code>.</div>
        <form method="post" style="margin-top:12px">
            <?= $field ?><input type="hidden" name="action" value="check">
            <button type="submit" class="ghost">Vérifier les mises à jour</button>
        </form>

        <?php if ($check): ?>
            <?php if ($check['error']): ?>
                <div class="msg err" style="margin-top:12px"><?= $h($check['error']) ?></div>
            <?php else: ?>
                <p style="margin:14px 0 0">Version disponible : <b><?= $h($check['remote']) ?></b>
                    <?= $check['date'] !== '' ? '<span class="sub">(' . $h($check['date']) . ')</span>' : '' ?>
                    <span class="badge <?= $check['update'] ? 'new' : 'same' ?>"><?= $check['update'] ? 'Mise à jour disponible' : '✓ À jour' ?></span></p>
                <?php if ($check['notes'] !== ''): ?><div class="notes"><?= $h($check['notes']) ?></div><?php endif; ?>
                <?php if ($cfg['github_url'] !== ''): ?><p class="sub" style="margin:6px 0 0"><a href="<?= $h(rtrim($cfg['github_url'], '/') . '/blob/main/docs/NOUVEAUTES.md') ?>" target="_blank" rel="noopener">Toutes les nouveautés</a></p><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($check && !$check['error'] && !$isDev): ?>
    <div class="card">
        <?php if ($check['update']): ?>
            <h3>Installer la version <?= $h($check['remote']) ?></h3>
            <form method="post" onsubmit="return confirm('Mettre à jour le module TDM en version <?= $h($check['remote']) ?> ?');">
                <?= $field ?><input type="hidden" name="action" value="update">
                <button type="submit" class="primary">Mettre à jour</button>
            </form>
        <?php else: ?>
            <details><summary>Forcer la réinstallation de la version <?= $h($check['remote']) ?></summary>
                <form method="post" style="margin-top:10px" onsubmit="return confirm('Réinstaller tous les fichiers du module ?');">
                    <?= $field ?><input type="hidden" name="action" value="force">
                    <button type="submit" class="ghost">Réinstaller</button>
                </form>
            </details>
        <?php endif; ?>
        <p class="sub" style="margin:12px 0 0">Les fichiers du module sont remplacés par ceux du dépôt. Les données des compétitions
            (équipes, scores, poules, BSO, réglages), enregistrées en base, ne sont pas touchées. Le serveur web doit avoir le droit d'écrire dans le dossier du module.</p>
    </div>
    <?php endif; ?>

    <details class="card" style="border-color:var(--err-bord);background:var(--err-fond)">
        <summary style="color:var(--rouge);font-weight:600;cursor:pointer">Désinstaller le module</summary>
        <p style="margin:10px 0">Supprime le dossier du module. Ses données (tables du Big Shoot Off et réglages TDM)
            sont conservées, sauf si vous demandez leur suppression sur l'écran de confirmation.</p>
        <a href="uninstall.php" style="display:inline-block;background:var(--rouge);color:var(--champ);padding:8px 18px;border-radius:6px;font-weight:600;text-decoration:none">Désinstaller…</a>
    </details>
</div>
<?php include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'; ?>
