<?php
/**
 * preconfig.php — TDM : configuration d'une nouvelle compétition.
 *
 * Quatre étapes, chacune avec son contrôle ; les étapes 2 à 4 restent grisées
 * tant que l'étape 1 n'est pas en ordre (voir lib/setup.php, lib/departs.php) :
 *  1. division NE, classes, distances (un bouton) ;
 *  2. départs et horaires : une session IANSEO par départ (action « departs ») ;
 *  3. archers : saisie / import IANSEO, puis composition des équipes ;
 *  4. épreuves et poules : proposition modifiable, appliquée sur demande (action « events »).
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/setup.php';
require_once __DIR__ . '/lib/departs.php';
tdm_require(AclCompetition, AclReadOnly);

$canWrite = hasFullACL(AclCompetition, 'cData', AclReadWrite) && !IsBlocked(BIT_BLOCK_TOURDATA);

// ── Actions ───────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
if ($action !== '') {
    tdm_check_token();
    if (!$canWrite) {
        http_response_code(403);
        exit('Droits insuffisants ou données de la compétition verrouillées.');
    }
    $flash = [];
    if ($action === 'step1') {
        $s1 = tdm_setup_step1();
        if ($s1['blocking']) {
            $flash['err'] = implode(' ', $s1['blocking']);
        } else {
            $done = tdm_setup_step1_apply();
            $first = !tdm_is_configured();
            tdm_set_configured();
            $flash['ok'] = 'Étape 1 enregistrée' . ($done ? ' : ' . implode(', ', $done) : '') . '.'
                . ($first ? ' Le menu « Trophée des Mixtes » et la barre TDM sont maintenant disponibles.' : '');
        }
        $flash['at'] = 'step1';

    } elseif ($action === 'events') {
        $state = tdm_setup_events();
        $ok = $warn = $err = [];
        foreach (TDM_CATEGORIES as $code => $c) {
            $row = (array) ($_POST['ev'][$code] ?? []);
            if (empty($row['on'])) continue;
            $groups = intval($row['groups'] ?? 0);
            $size = intval($row['size'] ?? 0);
            $check = tdm_pool_check(0, $groups, $size);
            if (!$check['ok']) {
                $err[] = "$code : " . implode(', ', $check['errors']) . '.';
                continue;
            }
            // Horaire des poules : facultatif ; date du départ de la catégorie par défaut.
            $pDate = (string) ($row['pdate'] ?? '');
            $pTime = substr((string) ($row['ptime'] ?? ''), 0, 5);
            $pDur = intval($row['pdur'] ?? 0);
            $t = $t ?? tdm_tournament();
            $schedErr = tdm_pool_schedule_check($pDate, $pTime, $pDur, $t->ToWhenFrom, $t->ToWhenTo, tdm_pool_rounds($size));
            if ($schedErr) {
                $err[] = "$code : " . implode(', ', $schedErr) . '.';
                continue;
            }
            $s = $state[$code];
            $changes = $s->Groups !== $groups || $s->Size !== $size;
            if ($changes && ($s->Validated || $s->Scored) && empty($row['force'])) {
                $warn[] = "$code : poules déjà remplies — cochez « Je confirme » pour les recréer (matchs effacés).";
                continue;
            }
            $reset = ($s->Validated || $s->Scored) && !empty($row['force']);
            $done = tdm_setup_apply_event($code, $groups, $size, max(0, min(999, intval($row['start'] ?? 0))), $reset);
            // Réécrit seulement si l'horaire change : un horaire retouché dans IANSEO (pause…) est gardé.
            $cur = tdm_setup_pool_schedule($code, $size);
            $empty = $pTime === '';
            if ($empty ? $cur['time'] !== '' : [$cur['date'], $cur['time'], $cur['dur']] !== [$pDate, $pTime, $pDur]) {
                tdm_setup_set_pool_schedule($code, $size, $pDate, $pTime, $pDur);
                $done[] = $empty ? 'horaire des poules effacé'
                    : "horaire des poules $pTime à " . tdm_pool_end($pTime, $pDur, tdm_pool_rounds($size)) . " ($pDur min par match)";
            }
            $ok[] = "$code : " . ($done ? implode(', ', $done) : 'déjà en place');
        }
        if ($ok) $flash['ok'] = 'Étape 4 — ' . implode(' ; ', $ok) . '.';
        if ($warn) $flash['warn'] = implode(' ', $warn);
        if ($err) $flash['err'] = 'Non appliqué : ' . implode(' ', $err);
        if (!$ok && !$warn && !$err) $flash['warn'] = 'Aucune catégorie cochée.';
        $flash['at'] = 'step4';

    } elseif ($action === 'departs') {
        $map = [];
        foreach (TDM_CATEGORIES as $code => $c) {
            $n = intval($_POST['dep'][$code] ?? 0);
            if ($n >= 1 && $n <= TDM_MAX_DEPARTS) $map[$code] = $n;
        }
        $sessions = [];
        foreach (array_unique($map) as $n) {
            $p = (array) ($_POST['ses'][$n] ?? []);
            $sessions[$n] = ['name' => trim(mb_substr((string) ($p['name'] ?? ''), 0, 50)),
                             'date' => (string) ($p['date'] ?? ''), 'time' => substr((string) ($p['time'] ?? ''), 0, 5),
                             'targets' => intval($p['targets'] ?? 0),
                             'warm' => substr((string) ($p['warm'] ?? ''), 0, 5), 'warmDur' => intval($p['warmDur'] ?? 0),
                             'dur' => intval($p['dur'] ?? 0)];
        }
        $t = tdm_tournament();
        $counts = tdm_compose(tdm_setup_compose_input(tdm_setup_archers()))['counts'];
        $needed = array_keys(array_filter($counts));
        $c = tdm_check_departs($map, $needed, $sessions, $t->ToWhenFrom, $t->ToWhenTo, tdm_depart_needs($map, $counts));
        // Bloquant : date ou heure d'un départ ; une catégorie sans départ ne bloque pas (configuration partielle).
        $blocking = array_values(array_filter($c['errors'], fn($e) => !str_ends_with($e, 'aucun départ')));
        if ($blocking) {
            $flash['err'] = 'Non enregistré : ' . implode(' ; ', $blocking) . '.';
            $_SESSION['TDM_DEPARTS_POST'] = $_POST;
        } else {
            $done = tdm_save_departs($map, $sessions);
            // Mode de saisie de chaque départ ; prévenir si des scores existent déjà quand il change.
            $oldModes = tdm_qualif_modes();
            $modes = [];
            foreach (array_keys($sessions) as $n) {
                $modes[$n] = (($_POST['ses'][$n]['mode'] ?? '') === 'isk') ? 'isk' : 'paper';
                if ($modes[$n] !== ($oldModes[$n] ?? 'paper') && tdm_depart_has_scores($n)) {
                    $c['warnings'][] = "départ $n : saisie passée en « " . TDM_QUALIF_MODES[$modes[$n]] . ' » alors que des scores sont déjà saisis'
                        . ($modes[$n] === 'isk' ? ' (les scores saisis sur feuille seront remplacés par les envois des téléphones)' : '');
                }
            }
            tdm_set_qualif_modes($modes);
            tdm_isk_sync_locks();
            $flash['ok'] = 'Étape 2 — départs enregistrés' . ($done ? ' : ' . implode(', ', $done) : '') . '.';
            $other = array_merge(array_diff($c['errors'], $blocking), $c['warnings']);
            if ($other) $flash['warn'] = implode(' ; ', $other) . '.';
        }
        $flash['at'] = 'step2';

    } elseif ($action === 'apply_departs') {
        $n = tdm_apply_departs();
        $flash['ok'] = $n ? "$n archer(s) placé(s) dans la session de leur départ." : 'Tous les archers sont déjà dans leur départ.';
        $flash['at'] = 'step3';
    }
    tdm_flash($flash);
    header('Location: ' . $TDM_ROOT . 'preconfig.php#' . ($flash['at'] ?? ''));
    exit;
}
$flash = tdm_flash();
// Archers saisis ou importés dans IANSEO, cochés « équipe mixte » sans numéro : non placés.
if (hasFullACL(AclParticipants, '', AclReadWrite) && !IsBlocked(BIT_BLOCK_PARTICIPANT) && ($rel = tdm_setup_release_unnumbered())) {
    $flash = (array) $flash;
    $flash['warn'] = trim(($flash['warn'] ?? '') . ' ' . $rel . ' archer(s) coché(s) « Épreuve Équipe Mixte » sans numéro d\'équipe (saisie ou import IANSEO) : remis dans « Non placés », ou dans leur équipe IANSEO s\'ils en ont déjà une.');
}

// ── État ──────────────────────────────────────────────────────────────────────
$s1 = tdm_setup_step1();
$archers = tdm_setup_archers();
$compo = tdm_compose(tdm_setup_compose_input($archers));
$engaged = count(array_filter($archers, fn($a) => (int) $a->EnTeamMixEvent === 1 && (int) $a->EnStatus <= 1));
$teamsTotal = array_sum($compo['counts']);
$s2ok = $teamsTotal > 0 && !$compo['issues'];
$events = tdm_setup_events();
$s3ok = !in_array(false, array_map(fn($s) => $s->Ok, $events), true);
$tour = tdm_tournament();
$depMap = tdm_departs_map();
$qSessions = tdm_qualif_sessions();
$depPost = $_SESSION['TDM_DEPARTS_POST'] ?? null;
unset($_SESSION['TDM_DEPARTS_POST']);
$needed = array_keys(array_filter($compo['counts']));
$depNeeds = tdm_depart_needs($depMap, $compo['counts']);
$depCheck = tdm_check_departs($depMap, $needed, $qSessions, $tour->ToWhenFrom, $tour->ToWhenTo, $depNeeds);
$depMismatch = $depMap ? tdm_departs_mismatch() : 0;
$depOk = $depMap && !$depCheck['errors'] && !$depMismatch && !array_filter(array_unique($depMap), fn($n) => isset($qSessions[$n])
    && ($qSessions[$n]['ends'] !== TDM_QUALIF_ENDS_PER_ARCHER || $qSessions[$n]['arrows'] !== TDM_QUALIF_ARROWS_PER_END));

$PAGE_TITLE = 'TDM - Configuration';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
echo tdm_styles();

/** Ligne de contrôle ✅ / ⚠️. */
function tdm_check_line($ok, $text)
{
    return '<li class="' . ($ok ? 'yes' : 'no') . '">' . ($ok ? '✅' : '⚠️') . ' ' . tdm_h($text) . '</li>';
}
?>
<style>
#tdm .checks { list-style:none; margin:0 0 12px; padding:0; }
#tdm .checks li { padding:3px 0; }
#tdm .checks li.no { color:var(--orange); }
#tdm .card.off { opacity:.55; }
#tdm .card.off .why { display:block; }
#tdm .why { display:none; font-weight:600; color:var(--orange); margin-bottom:10px; }
#tdm .links { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
#tdm .counts { display:flex; gap:10px; flex-wrap:wrap; margin:6px 0 12px; }
#tdm .counts div { border:1px solid var(--bord); border-radius:6px; padding:6px 12px; text-align:center; min-width:90px; }
#tdm .counts b { display:block; font-size:20px; color:var(--bleu); }
#tdm .preview { font-size:12px; }
#tdm .preview .w { color:var(--orange); display:block; }
#tdm .preview .e { color:var(--rouge); display:block; }
#tdm small.w { color:var(--orange); }
#tdm .state { font-size:12px; }
#tdm .state .bad { color:var(--orange); }
#tdm .force { display:block; margin-top:4px; color:var(--rouge); font-size:12px; }
#tdm .force[hidden] { display:none; }
</style>
<div id="tdm">
    <?= tdm_header('preconfig', 'Prépare la compétition pour le TDM, une fois, dans l\'ordre des étapes.', [
        '<b>Division NE, classes et distances</b> : son enregistrement active le menu et la barre TDM.',
        '<b>Départs et horaires</b> : date, heure et nombre de cibles de chaque catégorie.',
        '<b>Archers et composition des équipes</b> : inscription dans IANSEO, puis les équipes.',
        '<b>Épreuves d\'équipe et poules</b> : nombre et taille des poules de chaque catégorie.',
    ], '<b>À faire avant le début de la compétition : la Configuration et la composition des Équipes.</b> Compétition créée en « Salle 18 m » FFTA. Chaque étape peut être relancée : ce qui est déjà en place n\'est pas recréé.') ?>

    <?php if ($flash): ?>
        <?php if (!empty($flash['ok'])): ?><div class="msg ok"><?= tdm_h($flash['ok']) ?></div><?php endif; ?>
        <?php if (!empty($flash['warn'])): ?><div class="msg warn"><?= tdm_h($flash['warn']) ?></div><?php endif; ?>
        <?php if (!empty($flash['err'])): ?><div class="msg err"><?= tdm_h($flash['err']) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if (!$canWrite): ?>
        <div class="msg warn">Lecture seule : il faut le droit d'écriture sur les données de la compétition
            (et qu'elles ne soient pas verrouillées) pour appliquer les étapes.</div>
    <?php endif; ?>

    <!-- Étape 1 -->
    <div class="card" id="step1">
        <h3>1. Division NE, classes et distances
            <span class="tag <?= $s1['ok'] && tdm_is_configured() ? 'ok' : 'warn' ?>"><?= $s1['ok'] && tdm_is_configured() ? 'fait' : 'à faire' ?></span></h3>
        <div>
            <?php foreach ($s1['blocking'] as $b): ?><div class="msg err"><?= tdm_h($b) ?></div><?php endforeach; ?>
            <ul class="checks">
                <?php foreach ($s1['checks'] as [$ok, $text]) echo tdm_check_line($ok, $text); ?>
            </ul>
            <p class="help">Les néo-licenciés tirent en arc classique dans une division à part (NE), acceptée par
                les classes U13 à S3, avec les distances des seniors classiques. La compétition passe à <b>une seule
                distance</b> (le « Salle 18 m » en prévoit deux) : chaque départ, le matin comme l'après-midi, est une
                session IANSEO avec seulement la mini-qualif (comme Compétition › Paramètres avancés › Nombre de distances).</p>
            <?php if ($canWrite && !$s1['blocking']): ?>
                <form method="post" action="<?= tdm_h($TDM_ROOT . 'preconfig.php') ?>">
                    <?= tdm_token_field() ?><input type="hidden" name="action" value="step1">
                    <button type="submit" class="primary"><?= $s1['ok'] ? 'Enregistrer l\'étape 1' : 'Créer la division NE, les classes et les distances' ?></button>
                    <?php if (!tdm_is_configured()): ?><span class="help" style="margin-left:8px">L'enregistrement de l'étape 1 active le menu
                        « Trophée des Mixtes » et la barre TDM sur toutes les pages.</span><?php endif; ?>
                </form>
            <?php endif; ?>
            <div class="links">
                <a class="secondary" href="<?= tdm_h($CFG->ROOT_DIR . 'Tournament/ManDivClass.php') ?>">Divisions et classes (IANSEO)</a>
                <a class="secondary" href="<?= tdm_h($CFG->ROOT_DIR . 'Tournament/ManDistances.php') ?>">Distances (IANSEO)</a>
            </div>
        </div>
    </div>

    <!-- Étape 2 -->
    <div class="card<?= $s1['ok'] ? '' : ' off' ?>" id="step2">
        <h3>2. Départs et horaires
            <span class="tag <?= $depOk ? 'ok' : 'warn' ?>"><?= $depOk ? 'fait' : 'à faire' ?></span></h3>
        <div>
            <span class="why">Terminez d'abord l'étape 1.</span>
            <p class="help">Chaque catégorie tire dans un départ (par exemple l'arc classique le matin, les autres catégories
                l'après-midi). Un départ est une <b>session de qualification IANSEO</b> : le module la crée si besoin, lui donne
                sa date, son heure (programme IANSEO) et son nombre de cibles, et y place les archers de la catégorie.
                L'<b>entraînement</b> (heure et durée, facultatif) est l'échauffement de la session dans le programme IANSEO :
                il s'affiche sur le plan du pas de tir, comme la <b>durée du tir</b> (facultative : durée de la qualification
                dans le programme IANSEO, fin de la session). <b>Saisie des scores</b> : feuille papier (score d'équipe saisi dans la
                page Qualifications) ou Scorekeeper (un téléphone par cible, feuilles de marque natives d'IANSEO avec QR code). Chaque départ
                réutilise le pas de tir à partir de la cible 1 ; la colonne « Qualif » indique les cibles nécessaires (chaque catégorie sur
                ses cibles, deux équipes par cible). La saisie des qualifs s'ouvre départ par départ, depuis la page Qualifications.
                Compétition : <b><?= tdm_h(tdm_date_fr($tour->ToWhenFrom)) ?><?= $tour->ToWhenTo !== $tour->ToWhenFrom ? ' au ' . tdm_h(tdm_date_fr($tour->ToWhenTo)) : '' ?></b>.</p>
            <?php if ($depMap): ?>
            <ul class="checks">
                <?php foreach ($depCheck['errors'] as $e) echo tdm_check_line(false, $e); ?>
                <?php foreach ($depCheck['warnings'] as $w) echo tdm_check_line(false, $w); ?>
                <?= tdm_check_line(!$depMismatch, $depMismatch ? "$depMismatch archer(s) pas dans la session de leur départ (ajoutés ou modifiés depuis) — cliquez sur Enregistrer"
                    : 'Archers placés dans la session de leur départ') ?>
                <?php if (!$depCheck['errors'] && !$depCheck['warnings']): ?><?= tdm_check_line(true, 'Dates et heures cohérentes avec la compétition') ?><?php endif; ?>
                <?php $badFmt = array_filter(array_unique($depMap), fn($n) => isset($qSessions[$n])
                    && ($qSessions[$n]['ends'] !== TDM_QUALIF_ENDS_PER_ARCHER || $qSessions[$n]['arrows'] !== TDM_QUALIF_ARROWS_PER_END)); ?>
                <?= tdm_check_line(!$badFmt, $badFmt ? 'Format de saisie par archer à régler (départ ' . implode(', ', $badFmt) . ') — cliquez sur Enregistrer'
                    : 'Saisie par archer : ' . TDM_QUALIF_ENDS_PER_ARCHER . ' volées de ' . TDM_QUALIF_ARROWS_PER_END . ' flèches (Scorekeeper, saisie native)') ?>
                <?php $iskDeps = array_keys(array_filter(tdm_qualif_modes(), fn($m, $n) => $m === 'isk' && in_array($n, $depMap, true), ARRAY_FILTER_USE_BOTH));
                if ($iskDeps): ?>
                    <?php $iskServer = (string) getModuleParameter('ISK-NG', 'ServerUrl', '');
                    $iskPb = tdm_isk_problems(tdm_scorekeeper_enabled(), 1, 0, 0, $iskServer); ?>
                    <?= tdm_check_line(!$iskPb, !$iskPb
                        ? 'Scorekeeper configuré dans IANSEO, serveur ' . $iskServer . ' (départ ' . implode(', ', $iskDeps) . ' en saisie Scorekeeper)'
                        : 'Départ ' . implode(', ', $iskDeps) . ' en saisie Scorekeeper : ' . implode(' ; ', $iskPb)) ?>
                <?php endif; ?>
            </ul>
            <?php endif; ?>
            <form method="post" action="<?= tdm_h($TDM_ROOT . 'preconfig.php') ?>" id="f4">
                <?= tdm_token_field() ?><input type="hidden" name="action" value="departs">
                <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
                <table class="list" style="width:auto">
                    <tr><th>Catégorie</th><th class="num">Équipes</th><th>Départ</th></tr>
                    <?php foreach (TDM_CATEGORIES as $code => $c):
                        $cur = (int) ($depPost['dep'][$code] ?? ($depMap[$code] ?? 1)); ?>
                    <tr><td class="team"><?= tdm_h($code) ?> <small>— <?= tdm_h($c['name']) ?></small></td>
                        <td class="num"><?= $compo['counts'][$code] ?></td>
                        <td><select name="dep[<?= $code ?>]" class="dep" data-teams="<?= (int) $compo['counts'][$code] ?>">
                            <?php for ($n = 1; $n <= TDM_MAX_DEPARTS; $n++): ?>
                                <option value="<?= $n ?>"<?= $cur === $n ? ' selected' : '' ?>>Départ <?= $n ?></option>
                            <?php endfor; ?>
                        </select></td></tr>
                    <?php endforeach; ?>
                </table>
                <table class="list" style="width:auto">
                    <tr><th>Départ</th><th>Nom</th><th>Date</th>
                        <th title="Échauffement avant le tir (programme horaire IANSEO), facultatif">Entraînement</th><th>Durée</th>
                        <th>Début du tir</th>
                        <th title="Durée de la phase de qualification (programme horaire IANSEO), facultatif">Durée du tir</th>
                        <th>Cibles</th><th>Qualif</th>
                        <th title="Feuille papier : score d'équipe saisi dans la page Qualifications. Scorekeeper : un téléphone par cible (feuilles de marque natives d'IANSEO avec QR code)">Saisie des scores</th></tr>
                    <?php for ($n = 1; $n <= TDM_MAX_DEPARTS; $n++):
                        $s = $qSessions[$n] ?? ['name' => '', 'date' => '', 'time' => '', 'warm' => '', 'warmDur' => 0, 'dur' => 0];
                        $p = $depPost['ses'][$n] ?? null; ?>
                    <tr data-dep="<?= $n ?>">
                        <td class="team">Départ <?= $n ?></td>
                        <td><input type="text" name="ses[<?= $n ?>][name]" maxlength="50" style="width:120px"
                                   value="<?= tdm_h($p['name'] ?? ($s['name'] ?: TDM_DEPART_NAMES[$n])) ?>"></td>
                        <td><input type="date" name="ses[<?= $n ?>][date]" min="<?= tdm_h($tour->ToWhenFrom) ?>" max="<?= tdm_h($tour->ToWhenTo) ?>"
                                   value="<?= tdm_h($p['date'] ?? ($s['date'] ?: $tour->ToWhenFrom)) ?>"></td>
                        <td><input type="time" name="ses[<?= $n ?>][warm]" value="<?= tdm_h($p['warm'] ?? $s['warm']) ?>"
                                   title="Heure de début de l'entraînement (échauffement de la session IANSEO) ; vide = pas d'entraînement"></td>
                        <td><input type="number" class="small" name="ses[<?= $n ?>][warmDur]" min="0" max="<?= TDM_WARM_MAX ?>"
                                   title="Durée de l'entraînement en minutes ; saisie seule, l'heure d'entraînement est proposée juste avant le tir"
                                   value="<?= (int) ($p['warmDur'] ?? $s['warmDur']) ?: '' ?>"> <small>min</small></td>
                        <td><input type="time" name="ses[<?= $n ?>][time]" value="<?= tdm_h($p['time'] ?? $s['time']) ?>"></td>
                        <td style="white-space:nowrap"><input type="number" class="small" name="ses[<?= $n ?>][dur]" min="0" max="<?= TDM_QUALIF_DUR_MAX ?>"
                                   title="Durée de la qualification en minutes (programme horaire et fin de session IANSEO) ; vide = non précisée"
                                   value="<?= (int) ($p['dur'] ?? $s['dur']) ?: '' ?>"> <small>min</small></td>
                        <td><input type="number" class="small" name="ses[<?= $n ?>][targets]" min="1" max="999"
                                   title="Nombre de cibles du pas de tir pour ce départ (session IANSEO)"
                                   value="<?= (int) ($p['targets'] ?? ($s['targets'] ?? 0 ?: (reset($qSessions)['targets'] ?? 24))) ?>"></td>
                        <td class="need" style="font-size:12px"></td>
                        <td><?php $md = (string) ($p['mode'] ?? tdm_qualif_mode($n)); ?>
                            <select name="ses[<?= $n ?>][mode]">
                                <?php foreach (TDM_QUALIF_MODES as $k => $label): ?>
                                    <option value="<?= $k ?>"<?= $md === $k ? ' selected' : '' ?>><?= tdm_h($label) ?></option>
                                <?php endforeach; ?>
                            </select></td>
                    </tr>
                    <?php endfor; ?>
                </table>
                </div>
                <?php if ($canWrite && $s1['ok']): ?>
                <div class="savebar"><button type="submit" class="primary">Enregistrer les départs</button>
                    <span class="help" style="margin:0">Seuls les départs utilisés sont créés ou mis à jour dans IANSEO.</span></div>
                <?php endif; ?>
            </form>
            <div class="links">
                <a class="secondary" href="<?= tdm_h($CFG->ROOT_DIR . 'Tournament/ManSessions.php') ?>">Sessions (IANSEO)</a>
            </div>
        </div>
    </div>
    <!-- Étape 3 -->
    <div class="card<?= $s1['ok'] ? '' : ' off' ?>" id="step3">
        <h3>3. Archers et composition des équipes
            <span class="tag <?= $s2ok ? 'ok' : 'warn' ?>"><?= $s2ok ? 'fait' : ($teamsTotal ? 'à corriger' : 'à faire') ?></span></h3>
        <div>
            <span class="why">Terminez d'abord l'étape 1 : sans division NE, les néo-licenciés ne peuvent pas être inscrits.</span>
            <p class="help">Saisissez ou importez les archers dans IANSEO, puis composez les équipes : division
                (passer les néo-licenciés en NE), « Épreuve Équipe Mixte », numéro d'équipe quand un club en a plusieurs.</p>
            <div class="msg warn" style="margin:8px 0">
                <b>Inscription des archers :</b>
                <ul style="margin:4px 0 0 18px;padding:0">
                    <li>chaque archer dans l'une des <b>4 divisions du TDM : NE, CL, BB ou CO</b> ;</li>
                    <li>la <b>classe</b> (catégorie d'âge) n'a pas d'importance pour le classement, mais elle doit être
                        <b>renseignée</b> : une équipe peut réunir un U13 et un S3 ;</li>
                    <li>le <b>blason</b> est primordial : 60 cm pour NE et BB, 40 cm pour CL, trispot 40 cm pour CO.
                        Vérifiez-le dans la liste des participants (sans blason, un archer n'a pas de cible ni de saisie
                        Scorekeeper).</li>
                </ul>
            </div>
            <div class="counts">
                <div><b><?= count($archers) ?></b>archers</div>
                <div><b><?= $engaged ?></b>en équipe mixte</div>
                <?php foreach (TDM_CATEGORIES as $code => $c): ?>
                    <div><b><?= $compo['counts'][$code] ?></b><?= tdm_h($code) ?></div>
                <?php endforeach; ?>
            </div>
            <ul class="checks">
                <?= tdm_check_line($teamsTotal > 0, $teamsTotal ? "$teamsTotal équipe(s) complète(s)" : 'Aucune équipe complète') ?>
                <?php if ($depMap): ?><?= tdm_check_line(!$depMismatch, $depMismatch ? "$depMismatch archer(s) pas encore dans la session de leur départ (étape 2)" : 'Archers dans la session de leur départ') ?><?php endif; ?>
                <?= tdm_check_line(!$compo['issues'], $compo['issues']
                    ? count($compo['issues']) . ' anomalie(s) de composition — voir la page Composition'
                    : 'Aucune anomalie de composition') ?>
            </ul>
            <?php if ($s1['ok']): ?>
            <div class="links">
                <a class="secondary" href="<?= tdm_h($CFG->ROOT_DIR . 'Partecipants/index.php') ?>">Liste des participants (IANSEO)</a>
                <a class="secondary" href="<?= tdm_h($TDM_ROOT . 'equipes.php') ?>"><b>Composer les équipes</b></a>
            </div>
            <?php if ($depMismatch && $canWrite): ?>
            <form method="post" action="<?= tdm_h($TDM_ROOT . 'preconfig.php') ?>" style="margin-top:10px">
                <?= tdm_token_field() ?><input type="hidden" name="action" value="apply_departs">
                <button type="submit" class="primary">Placer les archers dans la session de leur départ</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Étape 4 -->
    <div class="card<?= $s1['ok'] ? '' : ' off' ?>" id="step4">
        <h3>4. Épreuves d'équipe et poules (Round Robin)
            <span class="tag <?= $s3ok ? 'ok' : 'warn' ?>"><?= $s3ok ? 'fait' : 'à faire' ?></span></h3>
        <div>
            <span class="why">Terminez d'abord l'étape 1.</span>
            <?php if ($teamsTotal && $compo['issues']): ?>
                <div class="msg warn">La composition des équipes a encore des anomalies (étape 3) : le nombre d'équipes ci-dessous peut changer.</div>
            <?php endif; ?>
            <p class="help">Vous choisissez le nombre de poules et leur taille pour chaque catégorie : les valeurs
                proposées ne sont qu'un point de départ. Rien n'est enregistré avant « Appliquer ». Les matchs suivent
                le règlement : 4 volées de 4 flèches, barrage à 2 flèches, victoire = 2 points, départage par les
                points de set. Les poules sont remplies plus tard, à la validation qualif → poules, en serpentin selon la qualif
                (voir la <a href="<?= tdm_h($TDM_ROOT . 'repartition.php?from=preconfig') ?>">répartition des poules</a>). Cibles : chaque match
                garde sa cible pendant toute la poule (une équipe en A, l'autre en B) et les poules se suivent.
                <b>Horaire</b> (facultatif) : heure du 1er tour et durée d'un match ; toutes les poules de la catégorie tirent
                en même temps et les tours s'enchaînent sans pause. Il est écrit dans l'horaire des matchs d'IANSEO
                (Matchs de Poules → Affectation des cibles), où vous pouvez l'ajuster match par match (une pause par exemple).</p>
            <form method="post" action="<?= tdm_h($TDM_ROOT . 'preconfig.php') ?>" id="f3">
                <?= tdm_token_field() ?><input type="hidden" name="action" value="events">
                <table class="list">
                    <tr><th></th><th>Catégorie</th><th class="num">Équipes</th><th>Dans IANSEO</th>
                        <th>Poules</th><th>Taille</th><th>1re cible</th><th>Aperçu</th>
                        <th title="Heure du tour 1 ; les tours suivants s'enchaînent, une durée de match plus tard (horaire des matchs IANSEO)">Début des poules</th>
                        <th>Durée d'un match<br><small style="font-weight:normal">un tour dure entre 10 et 20 min</small></th></tr>
                    <?php $nextTarget = []; // par départ : chaque départ réutilise le pas de tir à partir de la cible 1
                    foreach (TDM_CATEGORIES as $code => $c):
                        $dep = $depMap[$code] ?? 0;
                        $s = $events[$code];
                        $n = $compo['counts'][$code];
                        [$g0, $z0] = $s->Groups ? [$s->Groups, $s->Size] : tdm_pool_suggest($n);
                        // 1re cible : celle en place, sinon à la suite de la catégorie précédente.
                        $t0 = $s->FirstTarget ?: ($nextTarget[$dep] ?? 1);
                        if ($s->Exists || $n > 0) $nextTarget[$dep] = tdm_target_range($t0, $g0, $z0)[1] + 1;
                        // Cochée seulement s'il y a quelque chose à faire : catégorie à créer (avec des équipes)
                        // ou créée mais incomplète. Une catégorie en place n'est mise à jour que si on la coche.
                        $on = $s->Exists ? !$s->Ok : $n > 0;
                        $filled = $s->Validated || $s->Scored; ?>
                    <tr data-teams="<?= $n ?>" data-groups="<?= $s->Groups ?>" data-size="<?= $s->Size ?>" data-filled="<?= $filled ? 1 : 0 ?>">
                        <td><input type="checkbox" name="ev[<?= $code ?>][on]" value="1" <?= $on ? 'checked' : '' ?>
                                   title="Créer / mettre à jour cette catégorie"></td>
                        <td class="team"><?= tdm_h($code) ?> <small>— <?= tdm_h($c['name']) ?></small></td>
                        <td class="num"><?= $n ?></td>
                        <td class="state">
                            <?php if (!$s->Exists): ?><span class="bad">pas encore créée</span>
                            <?php else: ?>
                                <?= $s->Groups ? "{$s->Groups} × {$s->Size}" : '<span class="bad">sans poules</span>' ?>
                                <?= $s->Validated ? ' · qualif validée' : '' ?>
                                <?= $s->Scored ? " · {$s->Scored} match(s) saisi(s)" : '' ?>
                                <?php foreach ($s->Problems as $p): ?><br><span class="bad"><?= tdm_h($p) ?></span><?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td><input type="number" class="small" min="1" max="20" name="ev[<?= $code ?>][groups]" value="<?= $g0 ?>"></td>
                        <td><input type="number" class="small" min="<?= TDM_POOL_MIN ?>" max="<?= TDM_POOL_MAX ?>" name="ev[<?= $code ?>][size]" value="<?= $z0 ?>"></td>
                        <td><input type="number" class="small" min="1" max="999" name="ev[<?= $code ?>][start]" value="<?= $t0 ?>"
                                   title="Chaque match de poule garde sa cible (équipes A et B), les poules se suivent"></td>
                        <td class="preview">
                            <span class="p"></span>
                            <?php if ($filled): ?>
                                <label class="force" hidden><input type="checkbox" name="ev[<?= $code ?>][force]" value="1">
                                    Je confirme : vider les poules et effacer les matchs (validation qualif → poules à refaire)</label>
                            <?php endif; ?>
                        </td>
                        <?php $sch = $s->Schedule;
                            $pd = $sch['date'] ?: (($qSessions[$dep]['date'] ?? '') ?: $tour->ToWhenFrom); ?>
                        <td style="white-space:nowrap">
                            <?php if ($tour->ToWhenTo !== $tour->ToWhenFrom): ?>
                                <input type="date" name="ev[<?= $code ?>][pdate]" min="<?= tdm_h($tour->ToWhenFrom) ?>" max="<?= tdm_h($tour->ToWhenTo) ?>" value="<?= tdm_h($pd) ?>">
                            <?php else: ?><input type="hidden" name="ev[<?= $code ?>][pdate]" value="<?= tdm_h($pd) ?>"><?php endif; ?>
                            <input type="time" name="ev[<?= $code ?>][ptime]" value="<?= tdm_h($sch['time']) ?>"
                                   title="Heure du tour 1 ; vide = pas d'horaire. Les tours suivants s'enchaînent sans pause.">
                            <?php if ($sch['custom']): ?><br><small class="w" title="Les tours ne s'enchaînent pas à la suite : horaire modifié dans IANSEO (Affectation des cibles). Il est gardé tant que vous ne changez pas l'heure ou la durée ici.">horaire personnalisé dans IANSEO</small><?php endif; ?>
                        </td>
                        <td style="white-space:nowrap"><input type="number" class="small" min="1" max="<?= TDM_MATCH_MAX ?>" name="ev[<?= $code ?>][pdur]"
                                   value="<?= $sch['dur'] ?: TDM_MATCH_DEFAULT ?>" title="Durée d'un match en minutes (un tour dure entre 10 et 20 min)"> <small>min</small></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php if ($canWrite && $s1['ok']): ?>
                <div class="savebar">
                    <button type="submit" class="primary">Appliquer</button>
                    <span class="help">Seules les catégories cochées sont créées ou mises à jour (modifier une ligne la coche).</span>
                </div>
                <?php endif; ?>
            </form>
            <?php if (array_filter($events, fn($s) => $s->Exists)): ?>
            <p class="help" style="margin-top:12px">Pour contrôler dans IANSEO :</p>
            <div class="links">
                <a class="secondary" href="<?= tdm_h($CFG->ROOT_DIR . 'Final/Team/ListEvents.php') ?>">Épreuves par équipe</a>
                <a class="secondary" href="<?= tdm_h($CFG->ROOT_DIR . 'Modules/RoundRobin/Setup.php') ?>">Configuration Round Robin</a>
                <a class="secondary" href="<?= tdm_h($CFG->ROOT_DIR . 'Modules/RoundRobin/Targets.php') ?>">Cibles des poules</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?= tdm_footer() ?>
</div>
<script>
// Étape 2 : seuls les départs choisis par au moins une catégorie sont affichés.
(function () {
    var f = document.getElementById('f4'); if (!f) return;
    function upd() {
        var used = {}; f.querySelectorAll('select.dep').forEach(function (s) { used[s.value] = 1; });
        // Cibles nécessaires à la qualif : chaque catégorie sur ses cibles, deux équipes par cible.
        var need = {};
        f.querySelectorAll('select.dep').forEach(function (s) { need[s.value] = (need[s.value] || 0) + Math.ceil(+s.dataset.teams / 2); });
        f.querySelectorAll('tr[data-dep]').forEach(function (tr) {
            tr.style.display = used[tr.dataset.dep] ? '' : 'none';
            tr.querySelectorAll('input').forEach(function (i) { i.disabled = !used[tr.dataset.dep]; });
            var n = need[tr.dataset.dep] || 0, have = +tr.querySelector('input[name$="[targets]"]').value || 0;
            tr.querySelector('.need').innerHTML = n ? (n > have ? '<b style="color:var(--rouge)">' + n + ' cibles nécessaires</b>' : n + ' cible(s) nécessaire(s)') : '—';
        });
    }
    // Entraînement : durée saisie sans heure → heure proposée juste avant le tir (suivie tant qu'elle n'est pas modifiée).
    f.querySelectorAll('tr[data-dep]').forEach(function (tr) {
        var warm = tr.querySelector('input[name$="[warm]"]'), dur = tr.querySelector('input[name$="[warmDur]"]'),
            time = tr.querySelector('input[name$="[time]"]');
        if (!warm.value) warm.dataset.auto = '1';
        warm.addEventListener('input', function () { warm.dataset.auto = warm.value ? '' : '1'; });
        function propose() {
            var d = +dur.value || 0, m = /^(\d\d):(\d\d)/.exec(time.value);
            if (!warm.dataset.auto || !d || !m) return;
            var t = +m[1] * 60 + +m[2] - d;
            if (t >= 0) warm.value = String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0');
        }
        dur.addEventListener('input', propose); time.addEventListener('input', propose);
    });
    f.addEventListener('change', upd); f.addEventListener('input', upd); upd();
})();
</script>
<script>
// Aperçu de l'étape 4, recalculé à la frappe (même règles que tdm_pool_check()).
(function () {
    var MIN = <?= TDM_POOL_MIN ?>, MAX = <?= TDM_POOL_MAX ?>;
    document.querySelectorAll('#f3 tr[data-teams]').forEach(function (tr) {
        var g = tr.querySelector('input[name$="[groups]"]'), z = tr.querySelector('input[name$="[size]"]'), t = tr.querySelector('input[name$="[start]"]');
        var out = tr.querySelector('.p'), force = tr.querySelector('.force'), on = tr.querySelector('input[name$="[on]"]');
        var pt = tr.querySelector('input[name$="[ptime]"]'), pd = tr.querySelector('input[name$="[pdur]"]'),
            pdt = tr.querySelector('input[type="date"][name$="[pdate]"]');
        function upd() {
            var n = +tr.dataset.teams, gv = parseInt(g.value, 10) || 0, zv = parseInt(z.value, 10) || 0, h = '';
            if (gv < 1 || zv < MIN || zv > MAX) {
                h = '<span class="e">' + (gv < 1 ? 'au moins une poule' : 'taille entre ' + MIN + ' et ' + MAX) + '</span>';
            } else {
                var places = gv * zv;
                h = places + ' places · ' + (Math.ceil(zv / 2) * 2 - 1) + ' tours';
                var tv = parseInt(t.value, 10) || 0;
                if (tv > 0) h += ' · cibles ' + tv + ' à ' + (tv + gv * Math.ceil(zv / 2) - 1);
                if (n > places) h += '<span class="w">' + (n - places) + " équipe(s) sans place</span>";
                else if (n > 0 && places - n >= zv) h += '<span class="w">au moins une poule vide</span>';
                else if (places > n) h += '<span class="w">' + (places - n) + ' bye(s) 5-0</span>';
                if (zv % 2) h += '<span class="w">taille impaire : une équipe au repos par tour</span>';
                // Horaire : les tours s'enchaînent sans pause.
                var m = /^(\d\d):(\d\d)/.exec(pt.value), d = parseInt(pd.value, 10) || 0;
                if (m && d > 0) {
                    var end = +m[1] * 60 + +m[2] + (Math.ceil(zv / 2) * 2 - 1) * d;
                    h += '<span style="display:block">poules ' + pt.value + ' → ' + (end >= 1440 ? '<span class="e">après minuit</span>'
                        : String(Math.floor(end / 60)).padStart(2, '0') + ':' + String(end % 60).padStart(2, '0')) + '</span>';
                } else if (pt.value) {
                    h += '<span class="e">durée d\'un match manquante</span>';
                }
            }
            out.innerHTML = h;
            if (force) force.hidden = !on.checked;
        }
        [g, z, t, pt, pd, pdt].forEach(function (i) { if (i) i.addEventListener('input', function () { on.checked = true; upd(); }); });
        on.addEventListener('change', upd);
        upd();
    });
})();
</script>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
