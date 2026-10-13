<?php
/**
 * resultats.php — TDM : classement final de chaque catégorie.
 *
 * Une carte par catégorie : état (définitif ou provisoire, et pourquoi), place
 * finale avec les ex aequo du règlement, équipe, archers, qualif, poule, BSO,
 * points. Impressions et extractions : PDF (pdf-resultats.php) et fichier pour
 * Excel (export-resultats.php), par catégorie ou toutes catégories.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/results.php';
require_once __DIR__ . '/lib/ffta.php';
tdm_require(AclQualification, AclReadOnly);
tdm_schema();

$events = tdm_events();

$PAGE_TITLE = 'TDM - Résultats';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
echo tdm_styles();
?>
<style>
#tdm table.list td.place { font-weight:800; color:var(--bleu); font-size:15px; white-space:nowrap; }
#tdm table.list td.pts { font-weight:800; font-size:15px; }
#tdm table.list tr.podium td { background:#fffbea; }
#tdm table.list tr.unranked td { color:#9a9ea1; }
#tdm table.list .sub { font-size:11px; color:#7d8183; }
#tdm .bso { font-size:11px; font-weight:700; color:var(--rose); }
#tdm form.ffta { display:flex; flex-wrap:wrap; gap:10px 16px; align-items:flex-end; }
#tdm form.ffta label { display:flex; flex-direction:column; gap:3px; font-size:12px; font-weight:600; }
</style>
<div id="tdm">
    <?= tdm_header('resultats', 'Donne le classement final et les points de chaque catégorie.', [
        'Vérifiez l\'état de chaque catégorie : <b>définitif</b> ou <b>provisoire</b> (et pourquoi).',
        'Imprimez les résultats (PDF) ou faites l\'extraction pour Excel, par catégorie ou toutes catégories.',
    ], 'Le BSO classe ses équipes, puis tous les 2es de poule sont ex aequo, puis tous les 3es, etc. (« = »). '
        . 'Points : 10, 9, 8, 7 pour les quatre premiers du BSO, 6 pour les autres équipes du BSO, puis 5 / 4 / 3 pour les 2es / 3es / 4es de poule, 0 au-delà.') ?>
    <?php if ($events): ?>
    <div class="shortcuts">
        <a href="<?= tdm_h($TDM_ROOT . 'pdf-resultats.php') ?>" target="_blank">🖨 Résultats — toutes les catégories (PDF)</a>
        <a href="<?= tdm_h($TDM_ROOT . 'export-resultats.php') ?>">⬇ Extraction — toutes les catégories (Excel)</a>
    </div>
    <?php // Fichier FFTA : valeurs gardées de la dernière fois, sinon tirées de la compétition.
        $to = tdm_tournament();
        $ffta = getModuleParameter('TDM', 'Ffta', []);
        if (!is_array($ffta) || !$ffta) {
            $cd = preg_match('/^10(\d{2,3})000$/', (string) ($to->ToCommitee ?? ''), $m) ? 'CD' . $m[1] : (string) ($to->ToComDescr ?? '');
            $ffta = ['cd' => $cd, 'committee' => (string) ($to->ToComDescr ?? ''), 'place' => (string) ($to->ToWhere ?? ''),
                     'date' => substr((string) ($to->ToWhenFrom ?? ''), 0, 10)];
        }
        $notFinal = array_filter($events, fn($ev) => !tdm_category_results($ev)->Final); ?>
    <div class="card" id="ffta">
        <h3>Fichier de résultats pour la FFTA <span class="tag">Excel, modèle FFTA</span></h3>
        <div>
            <p class="help">Les deux feuilles du modèle FFTA : le vainqueur de chaque catégorie, puis toutes les équipes.
                À envoyer à mixite@ffta.fr.</p>
            <?php if ($notFinal): ?>
                <div class="msg warn">Résultats encore provisoires : <?= tdm_h(implode(', ', array_map(fn($e) => $e->Name, $notFinal))) ?>.
                    Le vainqueur n'est indiqué que pour les catégories déjà classées.</div>
            <?php endif; ?>
            <form method="post" action="<?= tdm_h($TDM_ROOT . 'export-ffta.php') ?>" class="ffta">
                <?= tdm_token_field() ?>
                <label>N° du comité <input type="text" name="cd" value="<?= tdm_h($ffta['cd'] ?? '') ?>" size="8" placeholder="CD99"></label>
                <label>Nom du comité <input type="text" name="committee" value="<?= tdm_h($ffta['committee'] ?? '') ?>" size="22" placeholder="Département (99)"></label>
                <label>Lieu <input type="text" name="place" value="<?= tdm_h($ffta['place'] ?? '') ?>" size="22"></label>
                <label>Date <input type="date" name="date" value="<?= tdm_h($ffta['date'] ?? '') ?>"></label>
                <button type="submit" class="primary">⬇ Fichier FFTA (Excel)</button>
            </form>
        </div>
    </div>
    <?php else: ?>
        <div class="msg warn">Aucune épreuve TDM : faites d'abord la configuration à l'<a href="<?= tdm_h($TDM_ROOT . 'preconfig.php#step4') ?>">étape 4</a>.</div>
    <?php endif; ?>

    <?php foreach ($events as $code => $ev):
        $res = tdm_category_results($ev); ?>
    <div class="card" id="ev-<?= tdm_h($code) ?>">
        <h3><?= tdm_h($ev->Name) ?>
            <span class="tag"><?= count($res->Rows) ?> équipe(s)</span>
            <span class="tag <?= $res->Final ? 'ok' : 'warn' ?>"><?= tdm_h(tdm_results_status($res)) ?></span></h3>
        <div>
            <div class="shortcuts">
                <a href="<?= tdm_h($TDM_ROOT . 'pdf-resultats.php?ev=' . rawurlencode($code)) ?>" target="_blank">🖨 PDF</a>
                <a href="<?= tdm_h($TDM_ROOT . 'export-resultats.php?ev=' . rawurlencode($code)) ?>">⬇ Excel</a>
            </div>
            <?php if (!$res->Rows): ?>
                <p class="help">Aucune équipe dans cette catégorie.</p>
            <?php else: ?>
            <table class="list">
                <tr><th>Place</th><th>Équipe</th><th>Archers</th><th class="num">Qualif</th><th class="num">Rang qualif</th>
                    <th class="num">Poule</th><th class="num">Rang poule</th><th>BSO</th><th class="num">Points</th></tr>
                <?php foreach ($res->Rows as $r): ?>
                <tr class="<?= $r->Place !== null && $r->Place <= 3 ? 'podium' : '' ?><?= $r->Place === null ? ' unranked' : '' ?>">
                    <td class="place"><?= tdm_h(tdm_place_label($r)) ?: '—' ?></td>
                    <td class="team"><?= tdm_h($r->Name) ?><div class="sub"><?= tdm_h($r->ClubCode) ?></div></td>
                    <td class="archers"><?= tdm_h($r->Woman) ?><br><?= tdm_h($r->Man) ?></td>
                    <td class="num"><?= $r->Qualif ?? '' ?></td>
                    <td class="num"><?= $r->QualifRank ?: '' ?></td>
                    <td class="num"><?= $r->Pool ?: '' ?></td>
                    <td class="num"><?= $r->PoolRank ?: '' ?></td>
                    <td><?= $r->Bso ? '<span class="bso">BSO' . ($r->BsoPlace ? ' · ' . (int) $r->BsoPlace . ($r->BsoPlace == 1 ? 'er' : 'e') : '') . '</span>' : '' ?></td>
                    <td class="num pts"><?= $r->Place !== null ? $r->Points : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
