<?php
/**
 * qualif.php — TDM : saisie de la mini-qualification, un score par équipe.
 *
 * Une carte par catégorie, une ligne par équipe déclarée : score, nombre de 10
 * et de 9 (départage). L'enregistrement range le score sur les archers
 * (lib/qualif.php) puis laisse IANSEO reconstruire les équipes et leur rang.
 * Les égalités restantes se tranchent ensuite dans la page native
 * « Validation tour » (tirage au sort), qui remplit aussi les poules.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/targets.php';
require_once __DIR__ . '/lib/departs.php';
tdm_require(AclQualification, AclReadOnly);

$canWrite = hasFullACL(AclQualification, '', AclReadWrite) && !IsBlocked(BIT_BLOCK_QUAL);
$events = tdm_events();

// Départs (configuration, étape 2) : la saisie n'est ouverte que pour le départ choisi.
$depMap = tdm_departs_map();
$departs = $depMap ? tdm_qualif_sessions() : [];
$usedDeps = array_values(array_unique(array_intersect_key($depMap, $events)));
sort($usedDeps);
$openDep = tdm_open_depart();
/** Le départ de cette catégorie est-il ouvert à la saisie ? (sans départs : toujours) */
// Une catégorie sans départ reste toujours saisissable (configuration partielle).
$isOpen = fn($code) => !$depMap || !isset($depMap[$code]) || ($depMap[$code] === $openDep && $openDep > 0);

if (($_POST['action'] ?? '') === 'open') {
    tdm_check_token();
    if (!$canWrite) {
        http_response_code(403);
        exit('Droits insuffisants ou qualifications verrouillées.');
    }
    $n = intval($_POST['dep'] ?? 0);
    if ($n && !in_array($n, $usedDeps, true)) exit('Départ inconnu.');
    tdm_set_open_depart($n);
    tdm_flash(['ok' => $n ? 'Saisie ouverte pour le ' . tdm_depart_label($n, $departs[$n] ?? []) . '.' : 'Saisie des qualifications fermée pour tous les départs.']);
    header('Location: ' . $TDM_ROOT . 'qualif.php?dep=' . $n);
    exit;
}
$showDep = intval($_GET['dep'] ?? 0);
if (!in_array($showDep, $usedDeps, true)) $showDep = in_array($openDep, $usedDeps, true) ? $openDep : ($usedDeps[0] ?? 0);

// ── Enregistrement d'une catégorie ───────────────────────────────────────────
$errors = [];      // clé d'équipe => message
$posted = null;    // saisie à réafficher en cas d'erreur
$postedEv = '';
if (($_POST['action'] ?? '') === 'save') {
    tdm_check_token();
    if (!$canWrite) {
        http_response_code(403);
        exit('Droits insuffisants ou qualifications verrouillées.');
    }
    $postedEv = (string) ($_POST['ev'] ?? '');
    if (!isset($events[$postedEv])) exit('Épreuve inconnue.');
    if (!$isOpen($postedEv)) {
        tdm_flash(['ok' => '', 'err' => "{$events[$postedEv]->Name} : le départ de cette catégorie n'est pas ouvert, rien n'a été enregistré. "
            . 'Ouvrez d\'abord la saisie de ce départ.']);
        header('Location: ' . $TDM_ROOT . 'qualif.php?dep=' . ($depMap[$postedEv] ?? 0));
        exit;
    }

    $teams = tdm_event_teams($events[$postedEv]);
    $parsed = [];
    foreach ($teams as $key => $t) {
        $q = tdm_parse_qualif($_POST['s'][$key] ?? '', $_POST['t'][$key] ?? '', $_POST['n'][$key] ?? '');
        if (!$q['ok']) $errors[$key] = $q['error'];
        $parsed[$key] = $q;
    }

    if (!$errors) {
        $changed = [];
        foreach ($teams as $key => $t) {
            if (tdm_store_team_score($t, $parsed[$key])) $changed[] = $t;
        }
        if ($changed) tdm_rebuild_teams($changed);
        $n = count($changed);
        $msg = $n
            ? "{$events[$postedEv]->Name} : $n équipe(s) mise(s) à jour, classement recalculé."
            : "{$events[$postedEv]->Name} : aucun changement.";
        $warn = ($n && $events[$postedEv]->Validated)
            ? "{$events[$postedEv]->Name} : les poules étaient déjà remplies avec l'ancien classement. "
              . "Si l'ordre a changé, refaites la validation qualif → poules (cela efface les matchs de poule déjà saisis)."
            : '';
        tdm_flash(['ev' => $postedEv, 'ok' => $msg, 'warn' => $warn]);
        header('Location: ' . $TDM_ROOT . 'qualif.php?dep=' . ($depMap[$postedEv] ?? 0) . '#ev-' . rawurlencode($postedEv));
        exit;
    }
    $posted = $_POST;
}
$flash = tdm_flash();

$PAGE_TITLE = 'TDM - Qualifications';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
echo tdm_styles();
?>
<style>
#tdm .deps { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 14px; }
#tdm .dep { border:1px solid var(--bord); border-radius:8px; background:#fff; display:flex; align-items:center; gap:10px; padding:6px 10px; }
#tdm .dep.on { border:2px solid var(--bleu); box-shadow:0 0 0 3px var(--bleu-clair); }
#tdm .dep a { text-decoration:none; color:inherit; display:flex; flex-direction:column; }
#tdm .dep a span { font-size:12px; color:#7d8183; }
#tdm .dep a em { font-style:normal; font-size:12px; font-weight:600; color:#7d8183; }
#tdm .dep.open a em { color:var(--vert); }
#tdm .dep button { padding:5px 10px; font-size:12px; border-radius:6px; cursor:pointer; }
#tdm .dep button.close { background:#fff; border:1px solid var(--rouge); color:var(--rouge); font-weight:600; }
</style>
<div id="tdm">
    <?= tdm_header('qualif', 'Saisit la qualification par équipe, départ par départ.', [
        '<b>Attribution des cibles</b>, puis impression du plan du pas de tir et des feuilles de marque.',
        '<b>Ouvrez la saisie</b> du départ, puis saisissez le score de chaque équipe (5 volées de 4 flèches) et ses 10 et 9 pour le départage.',
        'Contrôlez la <b>répartition des poules</b>, puis faites la <b>Validation qualif → poules</b>.',
    ], 'Une case vide retire l\'équipe du classement. Les égalités restantes sont tirées au sort à la validation.') ?>
    <?php if ($events): ?>
    <div class="shortcuts">
        <a href="<?= tdm_h($TDM_ROOT . 'cibles.php') ?>">🎯 Attribution des cibles</a>
        <a href="<?= tdm_h($TDM_ROOT . 'pdf-pasdetir.php') ?>" target="_blank">🖨 Plan du pas de tir</a>
        <a href="<?= tdm_h($TDM_ROOT . 'pdf-qualif.php') ?>" target="_blank">🖨 Feuilles de marque de qualification — toutes les catégories</a>
        <?php // Classement natif (Qualification/PrnTeamAbs.php), limité aux épreuves TDM. ?>
        <a href="<?= tdm_h($CFG->ROOT_DIR . 'Qualification/PrnTeamAbs.php?' . implode('&', array_map(fn($c) => 'Event[]=' . rawurlencode($c), array_keys($events)))) ?>"
           target="_blank">🖨 Classement des qualifications — toutes les catégories</a>
    </div>
    <?php endif; ?>

    <?php if ($depMap && $usedDeps): ?>
    <div class="deps">
        <?php foreach ($usedDeps as $n):
            $cats = array_keys(array_filter($depMap, fn($d, $c) => $d === $n && isset($events[$c]), ARRAY_FILTER_USE_BOTH));
            $open = $n === $openDep; ?>
            <div class="dep<?= $n === $showDep ? ' on' : '' ?><?= $open ? ' open' : '' ?>">
                <a href="<?= tdm_h($TDM_ROOT . 'qualif.php?dep=' . $n) ?>">
                    <b><?= tdm_h(tdm_depart_label($n, $departs[$n] ?? [])) ?></b>
                    <span><?= tdm_h(implode(', ', $cats)) ?></span>
                    <em><?= $open ? '🟢 saisie ouverte' : '🔒 saisie fermée' ?></em>
                </a>
                <?php if ($canWrite): ?>
                <form method="post" action="<?= tdm_h($TDM_ROOT . 'qualif.php') ?>">
                    <?= tdm_token_field() ?><input type="hidden" name="action" value="open">
                    <input type="hidden" name="dep" value="<?= $open ? 0 : $n ?>">
                    <button type="submit" class="<?= $open ? 'close' : 'primary' ?>"
                        <?= !$open && $openDep ? 'onclick="return confirm(\'Ouvrir ce départ ferme la saisie du départ en cours. Continuer ?\');"' : '' ?>>
                        <?= $open ? 'Fermer la saisie' : 'Ouvrir la saisie' ?></button>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php elseif ($events): ?>
        <p class="help">Astuce : réglez les <a href="<?= tdm_h($TDM_ROOT . 'preconfig.php#step2') ?>">départs</a> (configuration,
            étape 2) pour n'ouvrir la saisie qu'au départ en cours.</p>
    <?php endif; ?>

    <?php if (!$events): ?>
        <div class="msg warn">Aucune épreuve par équipe en Round Robin dans cette compétition.
            Créez les épreuves TDM (équipe mixte, type d'élimination Round Robin) avant de saisir la qualification.</div>
    <?php endif; ?>
    <?php if (!$canWrite && $events): ?>
        <div class="msg warn">Lecture seule : vous n'avez pas le droit de modifier les qualifications,
            ou elles sont verrouillées.</div>
    <?php endif; ?>
    <?php if ($flash): ?>
        <?php if (!empty($flash['ok'])): ?><div class="msg ok"><?= tdm_h($flash['ok']) ?></div><?php endif; ?>
        <?php if (!empty($flash['err'])): ?><div class="msg err"><?= tdm_h($flash['err']) ?></div><?php endif; ?>
        <?php if (!empty($flash['warn'])): ?><div class="msg warn"><?= tdm_h($flash['warn']) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="msg err"><b>Rien n'a été enregistré pour <?= tdm_h($events[$postedEv]->Name) ?>.</b>
            Corrigez les lignes en rouge.</div>
    <?php endif; ?>

    <?php foreach ($events as $code => $ev):
        if ($depMap && isset($depMap[$code]) && $depMap[$code] !== $showDep) continue;
        $canEdit = $canWrite && $isOpen($code);
        $teams = tdm_event_teams($ev);
        $targets = tdm_team_targets($teams);
        $hasTargets = (bool) array_filter(array_column($targets, 0));
        // Tri : par cible si elles sont attribuées (ordre des feuilles de marque),
        // sinon équipes classées par rang, puis les autres par nom.
        uasort($teams, function ($a, $b) use ($targets, $hasTargets) {
            if ($hasTargets) {
                $ta = $targets[$a->Key] ?? [0, 'A'];
                $tb = $targets[$b->Key] ?? [0, 'A'];
                return [$ta[0] ?: PHP_INT_MAX, $ta[1]] <=> [$tb[0] ?: PHP_INT_MAX, $tb[1]] ?: strnatcasecmp($a->Name, $b->Name);
            }
            $ra = $a->Rank ?: PHP_INT_MAX;
            $rb = $b->Rank ?: PHP_INT_MAX;
            return $ra <=> $rb ?: strnatcasecmp($a->Name, $b->Name);
        });
        $rankCount = array_count_values(array_filter(array_map(fn($t) => $t->Rank, $teams)));
        $ties = array_sum(array_filter($rankCount, fn($n) => $n > 1));
        $scored = count(array_filter($teams, fn($t) => $t->Score !== null));
        $isPosted = $posted !== null && $postedEv === $code;
    ?>
    <div class="card" id="ev-<?= tdm_h($code) ?>">
        <h3>
            <?= tdm_h($ev->Name) ?>
            <span class="tag"><?= count($teams) ?> équipe(s), <?= $scored ?> avec score</span>
            <?php if ($depMap && !$isOpen($code)): ?><span class="tag warn">🔒 départ fermé</span><?php endif; ?>
            <?php if ($depMap && !isset($depMap[$code])): ?><span class="tag warn">sans départ</span><?php endif; ?>
            <?php if ($ev->Validated): ?>
                <span class="tag ok">Poules remplies</span>
            <?php elseif ($ties): ?>
                <span class="tag warn"><?= $ties ?> équipes à égalité</span>
            <?php endif; ?>
        </h3>
        <div>
            <div class="shortcuts">
                <a href="<?= tdm_h($TDM_ROOT . 'pdf-qualif.php?ev=' . rawurlencode($code)) ?>" target="_blank" title="Une feuille par équipe : 5 volées de 4 flèches, total, nombre de 10 et de 9">🖨 Feuilles de marque</a>
                <a href="<?= tdm_h($CFG->ROOT_DIR . 'Qualification/PrnTeamAbs.php?Event[]=' . rawurlencode($code)) ?>" target="_blank">🖨 Classement des qualifications (PDF)</a>
                <a href="<?= tdm_h($TDM_ROOT . 'repartition.php?ev=' . rawurlencode($code) . '&from=qualif') ?>">Répartition des poules</a>
            </div>
            <?php if (!$teams): ?>
                <p class="help">Aucune équipe déclarée : inscrivez les archers dans la division de cette épreuve,
                    avec « équipe mixte » coché et le même numéro d'équipe pour la femme et l'homme.</p>
            <?php else: ?>
            <form method="post" action="<?= tdm_h($TDM_ROOT . 'qualif.php') ?>" class="qform">
                <?= tdm_token_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="ev" value="<?= tdm_h($code) ?>">
                <table class="list">
                    <tr><th>Cible</th><th class="num">Rang</th><th>Équipe</th><th>Archers</th>
                        <th class="num">Score</th><th class="num">10</th><th class="num">9</th></tr>
                    <?php foreach ($teams as $key => $t):
                        $tie = $t->Rank && ($rankCount[$t->Rank] ?? 0) > 1;
                        $err = $isPosted ? ($errors[$key] ?? '') : '';
                        $vs = $isPosted ? (string) ($posted['s'][$key] ?? '') : (string) ($t->Score ?? '');
                        $vt = $isPosted ? (string) ($posted['t'][$key] ?? '') : ($t->Score === null ? '' : (string) $t->Tens);
                        $vn = $isPosted ? (string) ($posted['n'][$key] ?? '') : ($t->Score === null ? '' : (string) $t->Nines);
                    ?>
                    <tr class="<?= ($err || $t->Issues) ? 'issue' : '' ?><?= $tie ? ' tie' : '' ?>">
                        <td><b><?= tdm_h(tdm_team_target_label($targets[$key][0] ?? 0, $targets[$key][1] ?? 'A')) ?: '—' ?></b></td>
                        <td class="num rank"><?= $t->Rank ? $t->Rank . ($tie ? ' =' : '') : '' ?></td>
                        <td><span class="team"><?= tdm_h($t->Name) ?></span>
                            <?php foreach ($t->Issues as $i): ?><div class="note"><?= tdm_h($i) ?></div><?php endforeach; ?>
                            <?php if ($err): ?><div class="note"><?= tdm_h($err) ?></div><?php endif; ?>
                        </td>
                        <td class="archers"><?= implode('<br>', array_map(fn($m) => tdm_h($m->EnFirstName . ' ' . $m->EnName), $t->Members)) ?></td>
                        <td class="num"><input class="score" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3"
                            name="s[<?= tdm_h($key) ?>]" value="<?= tdm_h($vs) ?>"<?= $canEdit ? '' : ' disabled' ?>></td>
                        <td class="num"><input class="small" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2"
                            name="t[<?= tdm_h($key) ?>]" value="<?= tdm_h($vt) ?>"<?= $canEdit ? '' : ' disabled' ?>></td>
                        <td class="num"><input class="small" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2"
                            name="n[<?= tdm_h($key) ?>]" value="<?= tdm_h($vn) ?>"<?= $canEdit ? '' : ' disabled' ?>></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php if ($canWrite && !$canEdit): ?>
                <p class="help" style="margin-top:10px">🔒 Saisie fermée : ouvrez la saisie de ce départ (en haut de la page) pour enregistrer des scores.</p>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                <div class="savebar">
                    <button type="submit" class="primary">Enregistrer <?= tdm_h($ev->Name) ?></button>
                    <span class="dirty">Modifications non enregistrées</span>
                    <?php if ($ev->Validated): ?>
                        <span class="help" style="margin:0">Les poules sont déjà remplies : après une correction,
                            refaites la <b>validation qualif → poules</b>.</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<script>
// Signale une saisie non enregistrée ; Entrée passe à la case suivante au lieu d'envoyer.
document.querySelectorAll('#tdm form.qform').forEach(function (f) {
    var inputs = Array.prototype.slice.call(f.querySelectorAll('input[type=text]'));
    f.addEventListener('input', function () { var d = f.querySelector('.dirty'); if (d) d.style.display = 'inline'; });
    inputs.forEach(function (inp, i) {
        inp.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            if (inputs[i + 1]) { inputs[i + 1].focus(); inputs[i + 1].select(); }
            else if (f.querySelector('button[type=submit]')) f.querySelector('button[type=submit]').focus();
        });
    });
});
</script>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
