<?php
/**
 * repartition.php — TDM : répartition des poules selon la qualif.
 *
 * Même principe que la feuille « Poules (aide) » du classeur de gestion : les
 * équipes sont classées par la qualif, puis placées en serpentin (1, 2, 3
 * puis 6, 5, 4…) pour chaque configuration possible (poules de 4 ou de 6),
 * afin de choisir la configuration. Celle réglée dans la configuration
 * est mise en avant. C'est ce serpentin qu'applique la validation
 * qualif → poules (sauf égalités de qualif départagées entre-temps).
 *
 *   repartition.php?ev=CL   une catégorie (par défaut : toutes)
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/setup.php';
tdm_require(AclQualification, AclReadOnly);

$events = tdm_events();
$only = (string) ($_GET['ev'] ?? '');
if (!isset($events[$only])) $only = '';

$PAGE_TITLE = 'TDM - Répartition des poules';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
echo tdm_styles();
?>
<style>
#tdm .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; }
#tdm .tabs a { padding:4px 12px; border-radius:14px; border:1px solid var(--bord); text-decoration:none; color:var(--gris); }
#tdm .tabs a.on { background:var(--bleu); color:#fff; border-color:var(--bleu); }
#tdm .rank { columns:2 260px; font-size:12px; margin:0 0 12px; padding:0; list-style:none; }
#tdm .rank li { padding:1px 0; break-inside:avoid; }
#tdm .rank b { display:inline-block; min-width:26px; color:var(--bleu); }
#tdm .cfgs { display:flex; flex-wrap:wrap; gap:14px; }
#tdm .cfg { border:1px solid var(--bord); border-radius:6px; padding:8px; flex:0 1 auto; }
#tdm .cfg.cur { border:2px solid var(--rose); box-shadow:0 0 0 3px rgba(249,10,114,.12); }
#tdm .cfg h4 { margin:0 0 6px; font-size:13px; color:var(--bleu-fonce); }
#tdm .cfg h4 small { font-weight:normal; color:#7d8183; }
#tdm .cfg h4 .cur { color:var(--rose); }
#tdm .cfg table { border-collapse:collapse; font-size:12px; }
#tdm .cfg th { background:var(--bleu); color:#fff; padding:3px 8px; font-weight:600; }
#tdm .cfg td { border:1px solid #e3e5e7; padding:2px 6px; white-space:nowrap; }
#tdm .cfg td b { color:var(--bleu); margin-right:4px; }
#tdm .cfg td.bye { color:#aab; font-style:italic; }
@media print {
    #tdm .tabs, #tdm .noprint, #tdm .tdm-head nav { display:none !important; }
    #tdm .card { box-shadow:none; break-inside:avoid-page; }
    #tdm .card + .card { break-before:page; }
}
</style>
<div id="tdm">
    <?php $back = ['poules' => ['pools.php', 'Poules'], 'qualif' => ['qualif.php', 'Qualifications'], 'preconfig' => ['preconfig.php#step4', 'Configuration']][$_GET['from'] ?? ''] ?? null; ?>
    <?= tdm_header('repartition', 'Montre comment le classement de qualification remplit les poules, en serpentin (1, 2, 3 puis 6, 5, 4…).', [
        'Vérifiez la configuration retenue, <b>encadrée en rose</b>, et comparez avec les autres.',
        'Pour la changer : étape 4 de la <a href="' . tdm_h($TDM_ROOT . 'preconfig.php#step4') . '">Configuration</a>, avant la validation.',
        'Imprimez si besoin, puis faites la <b>Validation qualif → poules</b>.',
    ], 'Même principe que la feuille « Poules (aide) » du classeur Excel.', $back ? [$TDM_ROOT . $back[0], $back[1]] : null) ?>
    <div class="shortcuts noprint">
        <a target="_blank" href="<?= tdm_h($TDM_ROOT . 'pdf-repartition.php' . ($only !== '' ? '?ev=' . rawurlencode($only) : '')) ?>">🖨 PDF<?= $only !== '' ? '' : ' — toutes les catégories' ?></a>
    </div>

    <?php if (count($events) > 1): ?>
    <nav class="tabs">
        <a href="<?= tdm_h($TDM_ROOT . 'repartition.php') ?>" class="<?= $only === '' ? 'on' : '' ?>">Toutes</a>
        <?php foreach ($events as $code => $ev): ?>
            <a href="<?= tdm_h($TDM_ROOT . 'repartition.php?ev=' . rawurlencode($code)) ?>" class="<?= $only === $code ? 'on' : '' ?>"><?= tdm_h($ev->Name) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php if (!$events): ?><div class="msg warn">Aucune épreuve TDM : faites d'abord la configuration à l'<a href="<?= tdm_h($TDM_ROOT . 'preconfig.php#step4') ?>">étape 4</a>.</div><?php endif; ?>

    <?php foreach ($events as $code => $ev):
        if ($only !== '' && $only !== $code) continue;
        $teams = tdm_rep_order(tdm_event_teams_ranked($ev)[0]);
        $n = count($teams);
        $scored = count(array_filter($teams, fn($t) => $t->Score !== null));
        $ranks = array_count_values(array_filter(array_map(fn($t) => $t->Rank, $teams)));
        $ties = array_sum(array_filter($ranks, fn($k) => $k > 1));
        $current = $ev->Pools ? [$ev->Pools, $ev->PoolSize] : null;
        $configs = tdm_rep_configs($n, $current); ?>
    <div class="card">
        <h3><?= tdm_h($ev->Name) ?> <span class="tag"><?= $n ?> équipe(s)</span>
            <?php if ($current): ?><span class="tag">configuré : <?= $current[0] ?> × <?= $current[1] ?></span><?php endif; ?>
            <?php if ($ev->Validated): ?><span class="tag ok">poules déjà remplies</span><?php endif; ?></h3>
        <div>
            <?php if (!$n): ?>
                <p class="help">Aucune équipe dans cette catégorie.</p>
            <?php else: ?>
                <?php if ($scored < $n): ?>
                    <div class="msg warn noprint"><?= $n - $scored ?> équipe(s) sans score de qualif : elles sont placées en fin de classement.</div>
                <?php endif; ?>
                <?php if ($ties): ?>
                    <div class="msg warn noprint"><?= $ties ?> équipes à égalité de qualif : l'ordre entre elles sera fixé à la validation (tirage au sort).</div>
                <?php endif; ?>
                <?php if ($ev->Validated): ?>
                    <div class="msg ok noprint">Les poules sont déjà remplies : leur composition réelle est dans la page <a href="<?= tdm_h($TDM_ROOT . 'pools.php#ev-' . rawurlencode($code)) ?>">Poules</a>.</div>
                <?php endif; ?>
                <ul class="rank">
                    <?php foreach ($teams as $i => $t): ?>
                        <li><b><?= $i + 1 ?>.</b> <?= tdm_h($t->Name) ?> <?= $t->Score !== null ? '— ' . $t->Score : '' ?></li>
                    <?php endforeach; ?>
                </ul>
                <div class="cfgs">
                <?php foreach ($configs as [$g, $size]):
                    $snake = tdm_snake($g, $size);
                    $isCur = $current && $current[0] === $g && $current[1] === $size;
                    $byes = $g * $size - $n; ?>
                    <div class="cfg<?= $isCur ? ' cur' : '' ?>">
                        <h4><?= $g ?> poule<?= $g > 1 ? 's' : '' ?> de <?= $size ?>
                            <small><?= $byes > 0 ? "· $byes bye(s)" : '' ?><?= $byes < 0 ? ' · ' . (-$byes) . ' équipe(s) sans place' : '' ?></small>
                            <?php if ($isCur): ?><span class="cur">· configuration retenue</span><?php endif; ?></h4>
                        <table>
                            <tr><?php for ($p = 1; $p <= $g; $p++): ?><th>Poule <?= $p ?></th><?php endfor; ?></tr>
                            <?php for ($item = 1; $item <= $size; $item++): ?>
                            <tr>
                                <?php for ($p = 1; $p <= $g; $p++):
                                    $r = $snake[$p][$item];
                                    $t = $teams[$r - 1] ?? null; ?>
                                    <td class="<?= $t ? '' : 'bye' ?>"><b><?= $r ?></b><?= $t ? tdm_h($t->Name) : 'bye' ?></td>
                                <?php endfor; ?>
                            </tr>
                            <?php endfor; ?>
                        </table>
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
