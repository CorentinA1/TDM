<?php
/**
 * pools.php — TDM : suivi des poules, byes 5-0 et départages.
 *
 * Une carte par catégorie, un tableau par poule (rang, points, points de set,
 * score de qualif). Deux corrections du règlement sur IANSEO (lib/pools.php) :
 * - « Appliquer les byes 5-0 » ;
 * - « Départager par la qualif » ; une égalité parfaite (même score de qualif)
 *   se règle par un barrage dont on saisit l'ordre à la main.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/pools.php';
tdm_require(AclRobin, AclReadOnly);

$canWrite = hasFullACL(AclRobin, '', AclReadWrite) && !IsBlocked(BIT_BLOCK_ROBIN);
$events = tdm_events();

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

    if ($action === 'byes') {
        $n = tdm_apply_byes($ev);
        $flash['ok'] = "{$ev->Name} : $n bye(s) mis à 5-0, classement des poules recalculé.";

    } elseif ($action === 'qualif') {
        $solved = $left = 0;
        foreach (tdm_pools($ev) as $g => $pool) {
            $rows = [];
            foreach ($pool->Rows as $r) $rows[$r->Key] = ['rank' => $r->Rank, 'qualif' => $r->Qualif];
            $res = tdm_break_ties_by_qualif($rows);
            $changed = array_filter($res['ranks'], fn($rank, $k) => $rank !== $rows[$k]['rank'], ARRAY_FILTER_USE_BOTH);
            if ($changed) {
                tdm_write_pool_ranks($ev, $g, $changed);
                $solved++;
            }
            $left += count($res['ties']);
        }
        $flash['ok'] = "{$ev->Name} : $solved poule(s) départagée(s) par la qualif.";
        if ($left) $flash['warn'] = "$left égalité(s) parfaite(s) restante(s) (même score de qualif) : "
            . "saisissez l'ordre issu du barrage sous la poule concernée.";

    } elseif ($action === 'manual') {
        $g = intval($_POST['group'] ?? 0);
        $rank = intval($_POST['rank'] ?? 0);
        $keys = array_map('strval', array_keys((array) ($_POST['order'] ?? [])));
        $ranks = tdm_manual_order($rank, $keys, (array) $_POST['order']);
        if ($ranks === null || count($keys) < 2) {
            $flash['err'] = "Ordre invalide : donnez à chaque équipe une position différente (1, 2…).";
        } else {
            tdm_write_pool_ranks($ev, $g, $ranks);
            $flash['ok'] = "{$ev->Name}, poule $g : ordre du barrage enregistré.";
        }
    }
    tdm_flash($flash);
    header('Location: ' . $TDM_ROOT . 'pools.php#ev-' . rawurlencode($code));
    exit;
}
$flash = tdm_flash();

$PAGE_TITLE = 'TDM - Poules';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
echo tdm_styles();
?>
<style>
#tdm .pools { display:flex; flex-wrap:wrap; gap:14px; }
#tdm .pool { flex:1 1 300px; max-width:520px; border:1px solid var(--bord); border-radius:6px; }
#tdm .pool h4 { margin:0; padding:6px 10px; background:var(--bleu-clair); color:var(--bleu-fonce);
                font-size:13px; display:flex; justify-content:space-between; gap:8px; }
#tdm .pool h4 small { font-weight:normal; color:var(--gris); }
#tdm .pool table.list td, #tdm .pool table.list th { padding:3px 8px; }
#tdm .pool tr.tie td { background:var(--warn-fond); }
#tdm .pool tr.first td.team { color:var(--rose); }
#tdm .pool form.manual { padding:8px 10px; border-top:1px dashed var(--bord); background:var(--warn-fond); }
#tdm .pool form.manual .line { display:flex; align-items:center; gap:8px; margin:3px 0; }
#tdm .pool form.manual input { width:40px; text-align:center; }
#tdm .actions { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; align-items:center; }
#tdm .actions form { margin:0; }
</style>
<div id="tdm">
    <?= tdm_header('poules', 'Suit les matchs de poule et leur classement (points de match, puis points de set).', [
        'Imprimez les <b>feuilles de marque</b> et le <b>tableau</b> de chaque poule (pas de feuille pour les byes). Avec Scorekeeper réglé, chaque feuille porte le <b>QR code du match</b>.',
        '<b>Appliquer les byes 5-0</b> : un match contre une place vide compte comme une victoire 5-0. À refaire si la validation est refaite.',
        '<b>Saisir</b> les matchs (saisie IANSEO).',
        'Poule terminée : <b>Départager par la qualif</b> (à égalité, la meilleure qualif passe devant). Une nouvelle saisie efface ce départage.',
    ]) ?>

    <?php if ($flash): ?>
        <?php if (!empty($flash['ok'])): ?><div class="msg ok"><?= tdm_h($flash['ok']) ?></div><?php endif; ?>
        <?php if (!empty($flash['warn'])): ?><div class="msg warn"><?= tdm_h($flash['warn']) ?></div><?php endif; ?>
        <?php if (!empty($flash['err'])): ?><div class="msg err"><?= tdm_h($flash['err']) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if (!$events): ?>
        <div class="msg warn">Aucune épreuve par équipe en Round Robin dans cette compétition.</div>
    <?php endif; ?>

    <?php foreach ($events as $code => $ev):
        $pools = $ev->Pools ? tdm_pools($ev) : [];
        $matches = $played = $byes = $byesOk = $tiesQualif = $tiesManual = 0;
        foreach ($pools as $pool) {
            $matches += $pool->Matches; $played += $pool->Played;
            $byes += $pool->Byes; $byesOk += $pool->ByesApplied;
            $rows = [];
            foreach ($pool->Rows as $r) $rows[$r->Key] = ['rank' => $r->Rank, 'qualif' => $r->Qualif];
            $pool->Check = tdm_break_ties_by_qualif($rows);
            $ranks = array_count_values(array_filter(array_column($rows, 'rank')));
            $pool->Tied = array_filter($ranks, fn($n) => $n > 1);
            // La qualif changerait-elle un rang de cette poule ?
            foreach ($pool->Check['ranks'] as $k => $nr) {
                if ($nr !== $rows[$k]['rank']) { $tiesQualif++; break; }
            }
            $tiesManual += count($pool->Check['ties']);
        }
        $filled = $ev->Validated && array_sum(array_map(fn($p) => count($p->Rows), $pools)) > 0;
    ?>
    <div class="card" id="ev-<?= tdm_h($code) ?>">
        <h3>
            <?= tdm_h($ev->Name) ?>
            <?php if (!$ev->Pools): ?>
                <span class="tag warn">Poules non configurées</span>
            <?php elseif (!$filled): ?>
                <span class="tag warn">Poules pas encore remplies</span>
            <?php else: ?>
                <span class="tag<?= $played === $matches ? ' ok' : '' ?>">Matchs : <?= $played ?> / <?= $matches ?></span>
                <?php if ($byes): ?>
                    <span class="tag<?= $byesOk === $byes ? ' ok' : ' warn' ?>">Byes 5-0 : <?= $byesOk ?> / <?= $byes ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </h3>
        <div>
            <?php if ($ev->Pools): ?>
            <div class="shortcuts">
                <?php if ($filled && hasFullACL(AclRobin, '', AclReadWrite)):
                    // Saisie IANSEO, ouverte directement sur une poule (ou toutes).
                    $ip = $CFG->ROOT_DIR . 'Modules/RoundRobin/InsertPoint.php?team=1&event=' . rawurlencode($code) . '&level=1'; ?>
                    <span class="lbl" style="min-width:0">✎ Saisir les matchs :</span>
                    <?php for ($g = 1; $g <= $ev->Pools; $g++): ?>
                        <a href="<?= tdm_h($ip . '&group=' . $g) ?>">Poule <?= $g ?></a>
                    <?php endfor; ?>
                    <?php if ($ev->Pools > 1): ?><a href="<?= tdm_h($ip) ?>">Toutes</a><?php endif; ?>
                <?php endif; ?>
                <a href="<?= tdm_h($TDM_ROOT . 'repartition.php?ev=' . rawurlencode($code) . '&from=poules') ?>">Répartition des poules</a>
                <a href="<?= tdm_h($TDM_ROOT . 'pdf-pasdetir.php') ?>" target="_blank">🖨 Plan du pas de tir</a>
            </div>
            <?php if ($filled):
                $printouts = [
                    'feuilles.php' => ['🖨 Feuilles de marque', "Dans l'ordre des rencontres (tour, puis cible)"],
                    'pdf-poules.php' => ['🖨 Tableaux de poules', 'Duels rencontre par rencontre et classement de la poule'],
                ];
                foreach ($printouts as $file => [$label, $title]): ?>
            <div class="shortcuts">
                <span class="lbl" title="<?= tdm_h($title) ?>"><?= $label ?> :</span>
                <?php for ($g = 1; $g <= $ev->Pools; $g++): ?>
                    <a href="<?= tdm_h($TDM_ROOT . $file . '?ev=' . rawurlencode($code) . '&group=' . $g) ?>" target="_blank" title="<?= tdm_h($title) ?>">Poule <?= $g ?></a>
                <?php endfor; ?>
                <?php if ($ev->Pools > 1): ?>
                    <a href="<?= tdm_h($TDM_ROOT . $file . '?ev=' . rawurlencode($code)) ?>" target="_blank">Toutes</a>
                <?php endif; ?>
                <?php if ($file === 'feuilles.php' && $byes): ?>
                    <span class="help" style="margin:0">Pas de feuille pour les <?= $byes ?> bye(s) : match gagné 5-0 sans tirer.</span>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
            <?php endif; ?>
            <?php if (!$ev->Pools): ?>
                <p class="help">Réglez les poules dans la <a href="<?= tdm_h($TDM_ROOT . 'preconfig.php#step4') ?>">configuration</a>
                    (étape 4), puis faites la validation qualif → poules.</p>
            <?php elseif (!$filled): ?>
                <p class="help"><?= $ev->Pools ?> poule(s) de <?= $ev->PoolSize ?>. La saisie des matchs (IANSEO) n'ouvre qu'après la
                    validation qualif → poules : saisissez la qualification, puis <a href="<?= tdm_h($TDM_ROOT . 'qualif.php#ev-' . rawurlencode($code)) ?>">✅ Valider vers les poules</a>.</p>
            <?php else: ?>
                <?php if ($canWrite): ?>
                <div class="actions">
                    <?php if ($byes): ?>
                    <form method="post" action="<?= tdm_h($TDM_ROOT . 'pools.php') ?>">
                        <?= tdm_token_field() ?><input type="hidden" name="action" value="byes">
                        <input type="hidden" name="ev" value="<?= tdm_h($code) ?>">
                        <button type="submit" class="primary"<?= $byesOk === $byes ? ' disabled' : '' ?>>Appliquer les byes 5-0</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="<?= tdm_h($TDM_ROOT . 'pools.php') ?>">
                        <?= tdm_token_field() ?><input type="hidden" name="action" value="qualif">
                        <input type="hidden" name="ev" value="<?= tdm_h($code) ?>">
                        <button type="submit" class="primary"<?= $tiesQualif ? '' : ' disabled' ?>>Départager par la qualif</button>
                    </form>
                    <?php if (is_file(__DIR__ . '/dev/pools-button.php')) include __DIR__ . '/dev/pools-button.php'; ?>
                    <?php if ($played < $matches): ?>
                        <span class="help" style="margin:0">Classement provisoire : <?= $matches - $played ?> match(s) à saisir.</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="pools">
                <?php foreach ($pools as $g => $pool): ?>
                    <div class="pool">
                        <h4><span>Poule <?= $g ?><?php if (hasFullACL(AclRobin, '', AclReadWrite)): ?><a href="<?= tdm_h($CFG->ROOT_DIR . 'Modules/RoundRobin/InsertPoint.php?team=1&event=' . rawurlencode($code) . '&level=1&group=' . $g) ?>">saisir</a><?php endif; ?></span>
                            <small><?= $pool->Played ?> / <?= $pool->Matches ?> matchs<?= $pool->Byes ? ' · ' . $pool->ByesApplied . ' / ' . $pool->Byes . ' bye(s) 5-0' : '' ?></small></h4>
                        <table class="list">
                            <tr><th class="num">Rang</th><th>Équipe</th><th class="num">Pts</th>
                                <th class="num">Sets</th><th class="num">Qualif</th></tr>
                            <?php foreach ($pool->Rows as $r):
                                $tie = isset($pool->Tied[$r->Rank]); ?>
                            <tr class="<?= $tie ? 'tie' : '' ?><?= $r->Rank === 1 ? ' first' : '' ?>">
                                <td class="num"><?= $r->Rank ?: '' ?><?= $tie ? ' =' : '' ?></td>
                                <td class="team"><?= tdm_h($r->Name) ?></td>
                                <td class="num"><?= $r->Points ?></td>
                                <td class="num"><?= $r->SetPoints ?></td>
                                <td class="num"><?= $r->Qualif ?: '' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <?php if ($canWrite):
                            foreach ($pool->Check['ties'] as $rank => $keys):
                                // Barrage possible seulement sur une égalité encore présente dans la poule.
                                if (!isset($pool->Tied[$rank])) continue;
                                $names = [];
                                foreach ($pool->Rows as $r) $names[$r->Key] = $r->Name; ?>
                        <form method="post" class="manual" action="<?= tdm_h($TDM_ROOT . 'pools.php') ?>">
                            <?= tdm_token_field() ?><input type="hidden" name="action" value="manual">
                            <input type="hidden" name="ev" value="<?= tdm_h($code) ?>">
                            <input type="hidden" name="group" value="<?= $g ?>">
                            <input type="hidden" name="rank" value="<?= $rank ?>">
                            <b>Égalité parfaite au rang <?= $rank ?></b> (même qualif) : ordre issu du barrage
                            <?php foreach ($keys as $k): ?>
                                <div class="line"><input type="text" inputmode="numeric" maxlength="1"
                                    name="order[<?= tdm_h($k) ?>]"> <?= tdm_h($names[$k] ?? $k) ?></div>
                            <?php endforeach; ?>
                            <button type="submit" class="primary">Enregistrer l'ordre</button>
                        </form>
                        <?php endforeach; endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
