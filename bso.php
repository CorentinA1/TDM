<?php
/**
 * bso.php — TDM : configuration et contrôle du Big Shoot Off (ordinateur).
 *
 * Par catégorie :
 * - équipes proposées par le classement des poules (1ers de poule ; poule
 *   unique : les deux premiers), modifiables avant confirmation (forfait) ;
 * - première cible : les équipes s'enchaînent, une cible chacune, toute la durée du BSO ;
 * - une fois confirmé : déroulé des volées, places acquises, liens vers la
 *   saisie (téléphone) et l'écran public.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/bsodb.php';
tdm_require(AclRobin, AclReadOnly);

$canWrite = hasFullACL(AclRobin, '', AclReadWrite) && !IsBlocked(BIT_BLOCK_ROBIN);
$events = tdm_events();
tdm_schema();

// ── Actions ───────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
if ($action !== '') {
    tdm_check_token();
    if (!$canWrite) {
        http_response_code(403);
        exit('Droits insuffisants ou poules verrouillées.');
    }
    $code = (string) ($_POST['ev'] ?? '');
    if (!isset($events[$code])) exit('Épreuve inconnue.');
    $ev = $events[$code];
    $flash = ['ev' => $code];

    if ($action === 'confirm') {
        $keys = array_values(array_filter(array_map('strval', (array) ($_POST['team'] ?? [])), fn($k) => $k !== ''));
        $valid = array_keys(tdm_bso_proposed($ev)['names']);
        if (count($keys) < 2) {
            $flash['err'] = 'Il faut au moins deux équipes pour un BSO.';
        } elseif (count(array_unique($keys)) !== count($keys)) {
            $flash['err'] = 'La même équipe est choisie deux fois.';
        } elseif (array_diff($keys, $valid)) {
            $flash['err'] = 'Équipe inconnue dans cette catégorie.';
        } else {
            tdm_bso_confirm($ev, $keys, intval($_POST['start'] ?? 1));
            $flash['ok'] = "{$ev->Name} : " . count($keys) . " équipes confirmées pour le BSO.";
        }
    } elseif ($action === 'clear') {
        tdm_bso_clear($ev);
        $flash['ok'] = "{$ev->Name} : volées du BSO effacées.";
    } elseif ($action === 'delete') {
        tdm_bso_delete($ev);
        $flash['ok'] = "{$ev->Name} : BSO supprimé.";
    } elseif ($action === 'nobso' && (int) $ev->Pools === 1) {
        // Poule unique : BSO au choix de l'organisateur. Sans BSO, le classement de la poule fait foi.
        $on = ($_POST['on'] ?? '') === '1';
        if (!$on && tdm_bso_teams($ev)) {
            $flash['err'] = "{$ev->Name} : supprimez d'abord le BSO déjà confirmé.";
        } else {
            tdm_set_bso_enabled($ev, $on);
            $flash['ok'] = "{$ev->Name} : " . ($on ? 'BSO entre les deux premiers de la poule.'
                : 'pas de BSO, classement final = classement de la poule (1er 10 points, 2e 9 points).');
        }
    }
    tdm_flash($flash);
    header('Location: ' . $TDM_ROOT . 'bso.php#ev-' . rawurlencode($code));
    exit;
}
$flash = tdm_flash();
$edit = (string) ($_GET['edit'] ?? '');

/**
 * Lien à ouvrir sur un autre appareil (téléphone, écran) : ouvre la compétition
 * dans la session de cet appareil (Common/TourOn.php) puis va sur la page.
 */
function tdm_device_url($page, $code)
{
    global $CFG, $TDM_TOUR, $TDM_ROOT;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $back = $TDM_ROOT . $page . '?ev=' . rawurlencode($code);
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $CFG->ROOT_DIR . 'Common/TourOn.php?ToId=' . $TDM_TOUR
        . '&BackTo=' . rawurlencode($back);
}

/** QR code SVG d'une adresse (TCPDF, fourni avec ianseo). */
function tdm_qr_svg($url, $size = 180)
{
    require_once HTDOCS . '/Common/tcpdf/tcpdf_barcodes_2d.php';
    $qr = new TCPDF2DBarcode($url, 'QRCODE,M');
    $svg = $qr->getBarcodeSVGcode(3, 3, 'black');
    // TCPDF écrit une taille fixe sans viewBox : avec une marge CSS, le code était rogné.
    // On le rend redimensionnable (viewBox) et on retire le prologue XML, inutile dans la page.
    $svg = preg_replace('/^.*?(<svg)/s', '$1', $svg);
    return preg_replace('/<svg width="(\d+)" height="(\d+)"/',
        '<svg viewBox="0 0 $1 $2" width="' . (int) $size . '" height="' . (int) $size . '" shape-rendering="crispEdges"', $svg, 1);
}

$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$localHost = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);

$PAGE_TITLE = 'TDM - Big Shoot Off';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
echo tdm_styles();
?>
<style>
#tdm .slots { display:flex; flex-direction:column; gap:6px; margin-bottom:10px; }
#tdm .slots label { display:flex; gap:8px; align-items:center; }
#tdm .slots .n { width:70px; color:var(--sous); font-size:12px; }
#tdm select { padding:4px 6px; border:1px solid var(--bord); border-radius:4px; font-size:13px; min-width:240px; }
#tdm .rounds { display:flex; gap:14px; flex-wrap:wrap; }
#tdm .round { flex:1 1 240px; border:1px solid var(--bord); border-radius:6px; }
#tdm .round h4 { margin:0; padding:6px 10px; background:var(--bleu-clair); color:var(--bleu-fonce); font-size:13px; }
#tdm .round h4 small { font-weight:normal; color:var(--gris); }
#tdm .st { font-size:11px; font-weight:600; padding:1px 7px; border-radius:9px; white-space:nowrap; }
#tdm .st.q { background:var(--ok-fond); color:var(--vert); }
#tdm .st.e { background:var(--fond); color:var(--sous); }
#tdm .st.p { background:var(--warn-fond); color:var(--orange); }
#tdm .places td.place { font-weight:bold; color:var(--lien); }
#tdm .links { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:12px; }
#tdm .links form { margin:0; }
#tdm details.phone { margin:0 0 12px; }
#tdm details.phone summary { cursor:pointer; color:var(--lien); font-weight:600; }
#tdm .qr { display:flex; gap:16px; align-items:flex-start; margin-top:8px; }
#tdm .qr .qrbox { flex:0 0 auto; background:#FFFFFF; padding:10px; border:1px solid var(--bord); border-radius:6px; line-height:0; }
#tdm .qr .qrbox svg { display:block; }
#tdm .qr small { color:var(--sous); word-break:break-all; }
#tdm .qr small a { color:var(--lien); }
#tdm button.danger { background:var(--carte); border:1px solid var(--rouge); color:var(--rouge); font-weight:600;
                     padding:7px 14px; border-radius:6px; cursor:pointer; }
#tdm form.nobso { display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:var(--bleu-clair); border-radius:6px; padding:8px 12px; margin:0 0 12px; }
#tdm form.nobso button { padding:4px 12px; font-size:12px; border-radius:6px; cursor:pointer; background:var(--carte); border:1px solid var(--bleu); color:var(--lien); font-weight:600; }
</style>
<div id="tdm">
    <?= tdm_header('bso', 'Départage les 1ers de poule (poule unique : les deux premiers) par le Big Shoot Off.', [
        'Poules terminées : <b>confirmez les équipes</b> du BSO.',
        'Saisissez les volées sur <b>Saisie BSO</b>, au téléphone (QR code) ou à l\'ordinateur.',
        'Affichez l\'<b>écran public</b> : il suit en direct.',
    ], 'Volées de 2 flèches (une par archer) en 40 s : toutes les équipes → 4 → 2 → finale.') ?>

    <?php if ($flash): ?>
        <?php if (!empty($flash['ok'])): ?><div class="msg ok"><?= tdm_h($flash['ok']) ?></div><?php endif; ?>
        <?php if (!empty($flash['err'])): ?><div class="msg err"><?= tdm_h($flash['err']) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if (!$events): ?>
        <div class="msg warn">Aucune épreuve par équipe en Round Robin dans cette compétition.</div>
    <?php endif; ?>

    <?php foreach ($events as $code => $ev):
        $teams = tdm_bso_teams($ev);
        $prop = $ev->Pools ? tdm_bso_proposed($ev) : ['keys' => [], 'names' => [], 'ready' => false, 'why' => 'poules non configurées'];
        $state = $teams ? tdm_bso_state($ev) : null;
        $showForm = $canWrite && $ev->Pools && (!$teams || $edit === $code);
        $nSlots = tdm_bso_size($ev->Pools);
        $differs = $teams && $prop['ready'] && array_keys($teams) !== $prop['keys'];
    ?>
    <div class="card" id="ev-<?= tdm_h($code) ?>">
        <h3>
            <?= tdm_h($ev->Name) ?>
            <?php if (!tdm_bso_enabled($ev)): ?>
                <span class="tag">Sans BSO (poule unique)</span>
            <?php elseif ($state && $state['finished']): ?>
                <span class="tag ok">BSO terminé</span>
            <?php elseif ($state): ?>
                <span class="tag">Volée <?= $state['current'] ?> en cours</span>
            <?php elseif ($prop['ready']): ?>
                <span class="tag warn">Équipes à confirmer</span>
            <?php else: ?>
                <span class="tag warn">Poules en cours</span>
            <?php endif; ?>
        </h3>
        <div>
            <?php $bsoOn = tdm_bso_enabled($ev);
            if ((int) $ev->Pools === 1): ?>
            <form method="post" action="<?= tdm_h($TDM_ROOT . 'bso.php') ?>" class="nobso">
                <?= tdm_token_field() ?><input type="hidden" name="action" value="nobso">
                <input type="hidden" name="ev" value="<?= tdm_h($code) ?>"><input type="hidden" name="on" value="<?= $bsoOn ? 0 : 1 ?>">
                <span>Poule unique : le BSO est au choix de l'organisateur.
                    <b><?= $bsoOn ? 'BSO entre les deux premiers.' : 'Pas de BSO : le classement de la poule fait le classement final.' ?></b></span>
                <?php if ($canWrite): ?>
                    <button type="submit" class="ghost"><?= $bsoOn ? 'Ne pas faire de BSO' : 'Faire un BSO' ?></button>
                <?php endif; ?>
            </form>
            <?php endif; ?>
            <?php if (!$bsoOn): ?>
                <p class="help">Places : 1er de poule 1er (10 points), 2e 2e (9 points), 3e 3e (4 points), 4e 4e (3 points).
                    Voir les <a href="<?= tdm_h($TDM_ROOT . 'resultats.php#ev-' . rawurlencode($code)) ?>">résultats</a>.</p>
            <?php else: ?>
        <?php if ($showForm): ?>
            <?php if (!$prop['ready']): ?>
                <div class="msg warn">Poules pas terminées : <?= tdm_h($prop['why'] ?: 'aucune équipe classée') ?>.
                    Vous pouvez quand même choisir les équipes à la main.</div>
            <?php endif; ?>
            <?php if ($teams): ?>
                <div class="msg warn">Confirmer à nouveau remplace les équipes et <b>efface les volées déjà saisies</b>.</div>
            <?php endif; ?>
            <form method="post" action="<?= tdm_h($TDM_ROOT . 'bso.php') ?>"
                  onsubmit="return <?= $teams ? "confirm('Remplacer les équipes et effacer les volées saisies ?')" : 'true' ?>">
                <?= tdm_token_field() ?>
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="ev" value="<?= tdm_h($code) ?>">
                <p class="help"><?= $nSlots ?> équipe(s) au BSO, une cible chacune, dans cet ordre.</p>
                <div class="slots">
                <?php for ($i = 0; $i < $nSlots; $i++):
                    $sel = $teams ? (array_keys($teams)[$i] ?? '') : ($prop['keys'][$i] ?? ''); ?>
                    <label><span class="n">Cible +<?= $i ?></span>
                        <select name="team[]">
                            <option value="">—</option>
                            <?php foreach ($prop['names'] as $k => $name): ?>
                                <option value="<?= tdm_h($k) ?>"<?= $k === $sel ? ' selected' : '' ?>><?= tdm_h($name) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                <?php endfor; ?>
                </div>
                <label>Première cible : <input type="number" name="start" min="1" max="999" style="width:70px"
                    value="<?= tdm_h($teams ? (reset($teams)->Target ?? 1) : 1) ?>"></label>
                <div class="savebar">
                    <button type="submit" class="primary">Confirmer les équipes du BSO</button>
                    <?php if ($teams): ?><a class="secondary" href="<?= tdm_h($TDM_ROOT . 'bso.php#ev-' . $code) ?>">Annuler</a><?php endif; ?>
                </div>
            </form>
        <?php elseif (!$teams): ?>
            <p class="help"><?= $prop['ready'] ? 'Équipes prêtes à confirmer.' : 'Poules pas terminées : ' . tdm_h($prop['why']) . '.' ?></p>
        <?php else: ?>
            <?php if ($differs): ?>
                <div class="msg warn">Le classement des poules a changé depuis la confirmation : les équipes proposées ne sont
                    plus les mêmes. Vérifiez et, si besoin, changez les équipes.</div>
            <?php endif; ?>
            <div class="links">
                <a class="secondary" href="<?= tdm_h($TDM_ROOT . 'bso-saisie.php?ev=' . rawurlencode($code)) ?>">Saisie BSO</a>
                <a class="secondary" href="<?= tdm_h($TDM_ROOT . 'bso-ecran.php?ev=' . rawurlencode($code)) ?>" target="_blank">Écran public</a>
                <?php if ($canWrite): ?>
                    <a class="secondary" href="<?= tdm_h($TDM_ROOT . 'bso.php?edit=' . rawurlencode($code) . '#ev-' . $code) ?>">Changer les équipes</a>
                    <form method="post" action="<?= tdm_h($TDM_ROOT . 'bso.php') ?>" onsubmit="return confirm('Effacer toutes les volées saisies pour cette catégorie ?')">
                        <?= tdm_token_field() ?><input type="hidden" name="action" value="clear">
                        <input type="hidden" name="ev" value="<?= tdm_h($code) ?>">
                        <button type="submit" class="danger">Effacer les volées</button>
                    </form>
                    <form method="post" action="<?= tdm_h($TDM_ROOT . 'bso.php') ?>" onsubmit="return confirm('Supprimer le BSO de cette catégorie (équipes et volées) ?')">
                        <?= tdm_token_field() ?><input type="hidden" name="action" value="delete">
                        <input type="hidden" name="ev" value="<?= tdm_h($code) ?>">
                        <button type="submit" class="danger">Supprimer le BSO</button>
                    </form>
                <?php endif; ?>
            </div>

            <details class="phone">
                <summary>Ouvrir la saisie sur un téléphone (QR code)</summary>
                <?php if ($localHost): ?>
                    <div class="msg warn">Vous êtes sur « <?= tdm_h($host) ?> » : un téléphone ne peut pas joindre cette adresse.
                        Ouvrez IANSEO avec l'adresse réseau de l'ordinateur (par exemple http://192.168.x.x/) pour obtenir un QR code utilisable.</div>
                <?php else: $u = tdm_device_url('bso-saisie.php', $code); ?>
                    <div class="qr"><div class="qrbox"><?= tdm_qr_svg($u) ?></div>
                        <div><b>Scannez avec le téléphone</b> (même réseau Wi-Fi) : il ouvre la compétition puis la saisie
                            <?= tdm_h($ev->Name) ?>.<br><small><a href="<?= tdm_h($u) ?>" target="_blank"><?= tdm_h($u) ?></a></small><br><br>
                            Écran public sur un autre appareil :<br><small><a href="<?= tdm_h(tdm_device_url('bso-ecran.php', $code)) ?>" target="_blank"><?= tdm_h(tdm_device_url('bso-ecran.php', $code)) ?></a></small></div>
                    </div>
                <?php endif; ?>
            </details>

            <div class="rounds">
                <?php foreach ($state['rounds'] as $rd): ?>
                <div class="round">
                    <h4>Volée <?= $rd['round'] ?> <small>— <?= tdm_h($rd['label']) ?></small></h4>
                    <table class="list">
                        <tr><th class="num">Cible</th><th>Équipe</th><th class="num">Score</th><th></th></tr>
                        <?php foreach ($rd['rows'] as $row):
                            $pending = false;
                            foreach ($rd['pending'] as $p) if (in_array($row['key'], $p, true)) $pending = true; ?>
                        <tr>
                            <td class="num"><?= tdm_h($row['target'] ?? '') ?></td>
                            <td class="team"><?= tdm_h($row['name']) ?></td>
                            <td class="num"><?= $row['score'] === null ? '—' : $row['score'] ?></td>
                            <td><?php if ($pending): ?><span class="st p">égalité à trancher</span>
                                <?php elseif ($row['status'] === 'q'): ?><span class="st q"><?= $rd['qualifiers'] === 1 ? 'vainqueur' : 'qualifiée' ?></span>
                                <?php elseif ($row['status'] === 'e'): ?><span class="st e"><?= $row['rank'] ?>e</span>
                                <?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endforeach; ?>
                <?php if ($state['places']): ?>
                <div class="round places">
                    <h4>Places acquises</h4>
                    <table class="list">
                        <?php foreach ($state['places'] as $p): ?>
                        <tr><td class="num place"><?= $p['place'] ?></td><td class="team"><?= tdm_h($p['name']) ?></td>
                            <td class="num"><?= tdm_points(true, $p['place'], 1) ?> pts</td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?= tdm_footer() ?>
</div>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
