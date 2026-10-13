<?php
/**
 * cibles.php — TDM : attribution des cibles de la mini-qualification, par équipe.
 *
 * Deux équipes par cible (positions A et B). Attribution automatique
 * (tdm_auto_targets() : chaque catégorie sur ses cibles, tirage au sort,
 * clubs séparés) ou manuelle (« 3A », « 3B »), contrôles, plan des cibles.
 * Les cibles sont écrites sur les archers comme le fait IANSEO (lib/targets.php).
 * Chaque départ (session, lib/departs.php) a son propre pas de tir, à partir de la cible 1.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/targets.php';
require_once __DIR__ . '/lib/departs.php';
tdm_require(AclQualification, AclReadOnly);

$canWrite = hasFullACL(AclParticipants, '', AclReadWrite) && !IsBlocked(BIT_BLOCK_PARTICIPANT);
$events = tdm_events();
$session = tdm_qualif_session();
$sessions = tdm_qualif_sessions();
$depMap = tdm_departs_map();
$maxBySes = array_map(fn($s) => $s['targets'], $sessions);

/** Toutes les équipes : clé « CAT|CoId-sub » => [ev, team, target, pos, ok, ses (départ)]. */
function tdm_all_team_targets(array $events)
{
    $all = [];
    foreach ($events as $code => $ev) {
        $teams = tdm_event_teams($ev);
        foreach (tdm_team_targets($teams) as $key => [$target, $pos, $ok]) {
            $all["$code|$key"] = ['ev' => $ev, 'team' => $teams[$key], 'target' => $target, 'pos' => $pos, 'ok' => $ok,
                                   'ses' => tdm_event_session($code)];
        }
    }
    return $all;
}

// ── Actions ───────────────────────────────────────────────────────────────────
$posted = null;
$postErrors = [];
$action = $_POST['action'] ?? '';
if ($action !== '') {
    tdm_check_token();
    if (!$canWrite) {
        http_response_code(403);
        exit('Droits insuffisants ou liste des participants verrouillée.');
    }
    if (!$session) exit('Aucune session de qualification.');
    $all = tdm_all_team_targets($events);
    $flash = [];

    if ($action === 'auto') {
        $onlyMissing = ($_POST['scope'] ?? '') === 'missing';
        $first = max(1, intval($_POST['first'] ?? $session->First));
        // Une seule catégorie (bouton de sa carte) ou toutes (attribution globale).
        $cat = (string) ($_POST['ev'] ?? '');
        if ($cat !== '' && !isset($events[$cat])) exit('Catégorie inconnue.');
        // Chaque départ a son propre pas de tir, à partir de la première cible.
        $bySes = [];
        foreach ($all as $k => $a) $bySes[$a['ses']][$k] = $a;
        ksort($bySes);
        $okMsg = $warnMsg = [];
        foreach ($bySes as $ses => $list) {
            $todo = [];
            $used = 0;
            foreach ($list as $k => $a) {
                $mine = $cat === '' || $a['ev']->EvCode === $cat;
                // Cibles déjà prises : par la catégorie (mode « sans cible ») ou par les autres catégories du départ.
                if ($a['target'] && (!$mine || $onlyMissing)) { $used = max($used, $a['target']); continue; }
                if ($mine) $todo[] = [$k, $a['ev']->EvCode, $a['team']->CoId];
            }
            $start = $onlyMissing ? max($first, $used + 1) : $first;
            $filled = [];
            if ($onlyMissing && $todo) {
                // D'abord les places libres des cibles déjà commencées par la catégorie.
                shuffle($todo);
                $placed = [];
                foreach ($list as $a) if ($a['target']) $placed[] = [$a['ev']->EvCode, $a['team']->CoId, $a['target'], $a['pos']];
                [$filled, $todo] = tdm_fill_free_slots($placed, $todo);
            }
            $res = $filled + tdm_auto_targets($todo, array_keys($events), $start, random_int(1, PHP_INT_MAX));
            foreach ($res as $k => [$tg, $pos]) tdm_set_team_target($list[$k]['team'], $ses, $tg, $pos);
            if (!$res) continue;
            $last = max(array_column($res, 0));
            $okMsg[] = ($cat !== '' ? $events[$cat]->Name . ' : ' : ($depMap ? tdm_depart_label($ses, $sessions[$ses] ?? []) . ' : ' : ''))
                . count($res) . ' équipe(s), ' . (count($res) <= 4
                    ? 'cible(s) ' . implode(', ', array_map(fn($x) => $x[0] . $x[1], $res))
                    : 'cibles ' . min(array_column($res, 0)) . ' à ' . $last);
            $max = $sessions[$ses]['targets'] ?? 0;
            if ($max && $last > $max) $warnMsg[] = "la session $ses n'a que $max cibles : augmentez son nombre de cibles dans IANSEO (Compétition › Sessions)";
        }
        $flash['ok'] = $okMsg ? 'Tirage au sort — ' . implode(' ; ', $okMsg) . '.' : 'Aucune équipe à placer.';
        // Une catégorie tirée seule peut chevaucher les autres : contrôle immédiat.
        if ($cat !== '') {
            $after = [];
            foreach (tdm_all_team_targets($events) as $k => $a) {
                $after[$k] = [$a['ev']->EvCode, $a['team']->CoId, $a['team']->Name, $a['target'], $a['pos'], $a['ses']];
            }
            $c = tdm_check_targets($after, $maxBySes);
            if ($c['errors']) $warnMsg[] = 'conflit avec une autre catégorie : ' . implode(' ; ', $c['errors']) . ' — relancez avec une autre première cible';
        }
        if ($warnMsg) $flash['warn'] = ucfirst(implode(' ; ', $warnMsg)) . '.';

    } elseif ($action === 'clear') {
        foreach ($all as $a) tdm_set_team_target($a['team'], $a['ses'], 0, 'A');
        $flash['ok'] = 'Toutes les cibles de qualification des équipes TDM ont été retirées.';

    } elseif ($action === 'manual') {
        $new = $check = [];
        foreach ($all as $k => $a) {
            $raw = trim((string) ($_POST['t'][$k] ?? ''));
            if ($raw === '') {
                $new[$k] = [0, 'A'];
            } elseif ($p = tdm_parse_team_target($raw)) {
                $new[$k] = $p;
            } else {
                $postErrors[$k] = 'cible illisible (ex. : 3A, 3B)';
            }
            $tg = $new[$k] ?? [0, 'A'];
            $check[$k] = [$a['ev']->EvCode, $a['team']->CoId, $a['team']->Name, $tg[0], $tg[1], $a['ses']];
        }
        $c = tdm_check_targets($check, $maxBySes);
        if ($postErrors || $c['errors']) {
            $posted = (array) ($_POST['t'] ?? []);
            $flash = null;
            $manualErrors = $c['errors'];
        } else {
            $n = 0;
            foreach ($all as $k => $a) {
                if ($new[$k][0] === $a['target'] && ($new[$k][1] === $a['pos'] || !$a['target']) && $a['ok']) continue;
                tdm_set_team_target($a['team'], $a['ses'], $new[$k][0], $new[$k][1]);
                $n++;
            }
            $flash['ok'] = $n ? "$n équipe(s) mise(s) à jour." : 'Aucune modification.';
            if ($c['warnings']) $flash['warn'] = 'Enregistré, mais à vérifier : ' . implode(' ; ', $c['warnings']) . '.';
        }
    }
    if ($flash !== null) {
        tdm_flash($flash);
        header('Location: ' . $TDM_ROOT . 'cibles.php');
        exit;
    }
}
$flash = tdm_flash();

// ── État ──────────────────────────────────────────────────────────────────────
$all = tdm_all_team_targets($events);
$check = [];
foreach ($all as $k => $a) $check[$k] = [$a['ev']->EvCode, $a['team']->CoId, $a['team']->Name, $a['target'], $a['pos'], $a['ses']];
$state = tdm_check_targets($check, $maxBySes);
$without = count(array_filter($all, fn($a) => !$a['target']));
$broken = array_filter($all, fn($a) => !$a['ok']);
$plan = [];
foreach ($all as $k => $a) if ($a['target']) $plan[$a['ses']][$a['target']][$a['pos']][] = $a;
ksort($plan);
foreach ($plan as &$p) ksort($p);
unset($p);
/**
 * Première cible proposée pour le tirage d'une catégorie : celle qu'elle occupe
 * déjà, sinon la première libre après les autres catégories de son départ.
 */
$catFirst = function ($code) use ($all) {
    $mine = $others = [];
    $ses = tdm_event_session($code);
    foreach ($all as $a) {
        if (!$a['target'] || $a['ses'] !== $ses) continue;
        if ($a['ev']->EvCode === $code) $mine[] = $a['target']; else $others[] = $a['target'];
    }
    return $mine ? min($mine) : ($others ? max($others) + 1 : 1);
};
/** Libellé du départ d'une session (vide sans départs). */
$depLabel = fn($ses) => $depMap ? tdm_depart_label($ses, $sessions[$ses] ?? []) : '';

$PAGE_TITLE = 'TDM - Cibles de qualification';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
echo tdm_styles();
?>
<style>
#tdm .auto { display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap; }
#tdm .auto label { display:flex; flex-direction:column; gap:3px; font-size:12px; color:#7d8183; }
#tdm .auto select { padding:4px; border:1px solid var(--bord); border-radius:4px; }
#tdm .catauto { background:var(--bleu-clair); border-radius:6px; padding:8px 12px; margin-bottom:12px; }
#tdm .catauto b { align-self:center; color:var(--bleu-fonce); }
#tdm input.tgt { width:58px; text-align:center; text-transform:uppercase; font-weight:600; }
#tdm .plan { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:8px; }
#tdm .plan .t { border:1px solid var(--bord); border-radius:6px; overflow:hidden; font-size:12px; }
#tdm .plan .t b { display:block; background:var(--bleu); color:#fff; padding:2px 8px; }
#tdm .plan .t div { padding:2px 8px; border-top:1px solid #eceeef; }
#tdm .plan .t div i { color:#7d8183; font-style:normal; margin-right:4px; }
#tdm .plan .t.bad { border-color:var(--rouge); }
#tdm .plan .cat { color:#7d8183; font-size:10px; float:right; }
</style>
<div id="tdm">
    <?= tdm_header('cibles', 'Place deux équipes par cible pour la qualification, positions A et B (« 3A », « 3B »).', [
        '<b>Tirage automatique</b> : toutes les catégories, ou une seule.',
        'Ajustez à la main si besoin, puis enregistrez.',
        'Imprimez le plan du pas de tir et les feuilles de marque (dans l\'ordre des cibles).',
    ], 'Dans IANSEO, les archers de l\'équipe A occupent les places A et B de la cible, ceux de l\'équipe B les places C et D. '
        . 'Deux équipes du même club sur une cible : simple avertissement.', [$TDM_ROOT . 'qualif.php', 'Qualifications']) ?>
    <div class="shortcuts">
        <a href="<?= tdm_h($TDM_ROOT . 'pdf-pasdetir.php') ?>" target="_blank">🖨 Plan du pas de tir</a>
        <a href="<?= tdm_h($TDM_ROOT . 'pdf-qualif.php') ?>" target="_blank">🖨 Feuilles de marque de qualification</a>
    </div>

    <?php if ($flash): ?>
        <?php if (!empty($flash['ok'])): ?><div class="msg ok"><?= tdm_h($flash['ok']) ?></div><?php endif; ?>
        <?php if (!empty($flash['warn'])): ?><div class="msg warn"><?= tdm_h($flash['warn']) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if ($posted !== null): ?>
        <div class="msg err"><b>Rien n'a été enregistré.</b>
            <?php if (!empty($manualErrors)): ?><ul><?php foreach ($manualErrors as $e): ?><li><?= tdm_h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($postErrors): ?>Corrigez les cases en rouge.<?php endif; ?></div>
    <?php endif; ?>
    <?php if (!$session): ?>
        <div class="msg err">Aucune session de qualification dans cette compétition : créez-en une dans IANSEO (Compétition › Sessions).</div>
    <?php endif; ?>
    <?php if (!$canWrite): ?>
        <div class="msg warn">Lecture seule : il faut le droit d'écriture sur les participants (et que la liste ne soit pas verrouillée).</div>
    <?php endif; ?>

    <?php if ($session && $all): ?>
    <!-- Contrôles -->
    <div class="card">
        <h3>Contrôle
            <span class="tag <?= !$without && !$state['errors'] && !$broken ? 'ok' : 'warn' ?>">
                <?= count($all) - $without ?> / <?= count($all) ?> équipe(s) placée(s)</span>
            <?php foreach (array_unique(array_column($all, 'ses')) as $ses): ?>
                <span class="tag"><?= tdm_h($depLabel($ses) ?: "session $ses") ?> : <?= (int) ($sessions[$ses]['targets'] ?? 0) ?> cibles</span>
            <?php endforeach; ?></h3>
        <div>
            <?php if (!$without && !$state['errors'] && !$state['warnings'] && !$broken): ?>
                <p style="margin:0">✅ Toutes les équipes ont une cible, sans conflit.</p>
            <?php endif; ?>
            <?php if ($without): ?><div class="msg warn"><?= $without ?> équipe(s) sans cible.</div><?php endif; ?>
            <?php foreach ($state['errors'] as $e): ?><div class="msg err"><?= tdm_h($e) ?></div><?php endforeach; ?>
            <?php foreach ($state['warnings'] as $w): ?><div class="msg warn"><?= tdm_h($w) ?></div><?php endforeach; ?>
            <?php foreach ($broken as $a): ?>
                <div class="msg warn"><?= tdm_h($a['team']->Name) ?> (<?= tdm_h($a['ev']->EvCode) ?>) : ses archers ne sont pas sur la même
                    cible ou pas aux bonnes places (modifié hors du module ?) — réenregistrez sa cible.</div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($canWrite): ?>
    <!-- Automatique -->
    <div class="card">
        <h3>Attribution automatique — toutes les catégories</h3>
        <div>
            <p class="help">Chaque catégorie sur ses propres cibles (<?= tdm_h(implode(', ', array_map(fn($e) => $e->Name, $events))) ?>),
                équipes tirées au sort, deux par cible, en évitant deux équipes du même club sur une cible.
                <?php if ($depMap): ?><b>Chaque départ repart de la première cible</b> (même pas de tir le matin et l'après-midi).<?php endif; ?></p>
            <form method="post" action="<?= tdm_h($TDM_ROOT . 'cibles.php') ?>" class="auto"
                  onsubmit="return this.scope.value === 'missing' || confirm('Remplacer les cibles de toutes les catégories par un nouveau tirage ?');">
                <?= tdm_token_field() ?><input type="hidden" name="action" value="auto">
                <label>Première cible <input type="number" name="first" min="1" max="999" value="<?= $session->First ?: 1 ?>" class="small"></label>
                <label>Équipes
                    <select name="scope">
                        <option value="all">toutes (nouveau tirage)</option>
                        <option value="missing"<?= $without && $without < count($all) ? ' selected' : '' ?>>seulement celles sans cible</option>
                    </select></label>
                <button type="submit" class="primary">Attribuer automatiquement</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Plan -->
    <?php foreach ($plan as $ses => $sesPlan): ?>
    <div class="card">
        <h3>Plan des cibles<?= $depMap ? ' — ' . tdm_h($depLabel($ses)) : '' ?></h3>
        <?php if ($sched = tdm_depart_schedule($sessions[$ses] ?? [])): ?><p class="help">🕘 <?= tdm_h($sched) ?></p><?php endif; ?>
        <div class="plan">
            <?php foreach ($sesPlan as $target => $slots):
                $bad = count($slots['A'] ?? []) > 1 || count($slots['B'] ?? []) > 1; ?>
                <div class="t<?= $bad ? ' bad' : '' ?>"><b>Cible <?= $target ?></b>
                    <?php foreach (['A', 'B'] as $p): ?>
                        <div><i><?= $p ?></i><?php foreach ($slots[$p] ?? [] as $a): ?><?= tdm_h($a['team']->Name) ?> <span class="cat"><?= tdm_h($a['ev']->EvCode) ?></span><?php endforeach; ?>
                            <?= empty($slots[$p]) ? '<i>—</i>' : '' ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Manuel -->
    <form method="post" action="<?= tdm_h($TDM_ROOT . 'cibles.php') ?>">
        <?= tdm_token_field() ?><input type="hidden" name="action" value="manual">
        <?php foreach ($events as $code => $ev):
            $rows = array_filter($all, fn($a) => $a['ev']->EvCode === $code);
            uasort($rows, fn($x, $y) => [$x['target'] ?: PHP_INT_MAX, $x['pos'], $x['team']->Name] <=> [$y['target'] ?: PHP_INT_MAX, $y['pos'], $y['team']->Name]);
            if (!$rows) continue; ?>
        <div class="card">
            <h3><?= tdm_h($ev->Name) ?> <span class="tag"><?= count($rows) ?> équipe(s)</span>
                <?php if ($depMap): ?><span class="tag"><?= tdm_h($depLabel(tdm_event_session($code))) ?></span><?php endif; ?></h3>
            <div>
                <?php if ($canWrite):
                    $catWithout = count(array_filter($rows, fn($a) => !$a['target'])); ?>
                <div class="auto catauto">
                    <b>Attribution automatique de cette catégorie</b>
                    <label>Première cible <input type="number" form="auto-<?= tdm_h($code) ?>" name="first" min="1" max="999"
                           value="<?= $catFirst($code) ?>" class="small"></label>
                    <label>Équipes
                        <select form="auto-<?= tdm_h($code) ?>" name="scope">
                            <option value="all">toutes (nouveau tirage)</option>
                            <option value="missing"<?= $catWithout && $catWithout < count($rows) ? ' selected' : '' ?>>seulement celles sans cible</option>
                        </select></label>
                    <button type="submit" form="auto-<?= tdm_h($code) ?>" class="primary">Tirer <?= tdm_h($code) ?></button>
                </div>
                <?php endif; ?>
                <table class="list">
                    <tr><th>Cible</th><th>Équipe</th><th>Archers</th></tr>
                    <?php foreach ($rows as $k => $a):
                        $val = $posted !== null ? (string) ($posted[$k] ?? '') : tdm_team_target_label($a['target'], $a['pos']); ?>
                    <tr class="<?= isset($postErrors[$k]) ? 'issue' : '' ?>">
                        <td><input type="text" class="tgt" name="t[<?= tdm_h($k) ?>]" value="<?= tdm_h($val) ?>" maxlength="5"
                                   placeholder="—" <?= $canWrite ? '' : 'disabled' ?>>
                            <?php if (isset($postErrors[$k])): ?><span class="note"><?= tdm_h($postErrors[$k]) ?></span><?php endif; ?></td>
                        <td class="team"><?= tdm_h($a['team']->Name) ?></td>
                        <td class="archers"><?= tdm_h(implode(' – ', array_map(fn($m) => trim($m->EnFirstName . ' ' . $m->EnName), $a['team']->Members))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($canWrite): ?>
        <div class="savebar">
            <button type="submit" class="primary">Enregistrer les cibles</button>
            <span class="help" style="margin:0">Format : numéro de cible + position, par exemple 3A ou 3B. Case vide = pas de cible.</span>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($canWrite): foreach ($events as $code => $ev): ?>
    <form method="post" action="<?= tdm_h($TDM_ROOT . 'cibles.php') ?>" id="auto-<?= tdm_h($code) ?>"
          onsubmit="var s = document.querySelector('select[form=&quot;auto-<?= tdm_h($code) ?>&quot;]'); return (s &amp;&amp; s.value === 'missing') || confirm(<?= tdm_h(json_encode('Remplacer les cibles de « ' . $ev->Name . ' » par un nouveau tirage ?', JSON_UNESCAPED_UNICODE)) ?>);">
        <?= tdm_token_field() ?><input type="hidden" name="action" value="auto"><input type="hidden" name="ev" value="<?= tdm_h($code) ?>">
    </form>
    <?php endforeach; endif; ?>
    <?php if ($canWrite): ?>
    <form method="post" action="<?= tdm_h($TDM_ROOT . 'cibles.php') ?>" style="margin-top:20px"
          onsubmit="return confirm('Retirer les cibles de qualification de toutes les équipes TDM ?');">
        <?= tdm_token_field() ?><input type="hidden" name="action" value="clear">
        <button type="submit" class="primary" style="background:var(--rouge);border-color:var(--rouge)">Retirer toutes les cibles</button>
    </form>
    <?php endif; ?>
    <?php elseif ($session): ?>
        <div class="msg warn">Aucune équipe : composez d'abord les équipes (Configuration › Composition des équipes).</div>
    <?php endif; ?>
</div>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
