<?php
/**
 * greffe.php — TDM : impressions du greffe (accueil des archers).
 *
 * Listes du module, pensées « équipes mixtes » (pdf-greffe.php) : feuille de
 * greffe par club avec cases à cocher, liste alphabétique des archers, équipes
 * inscrites par catégorie ; toutes ou par départ. Puis les listes de base
 * d'IANSEO (Partecipants/Prn*.php), par archer.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/greffe.php';
tdm_require(AclParticipants, AclReadOnly);

$data = tdm_greffe_data();
$archers = $data['archers'];
$byCat = [];
foreach (tdm_greffe_teams_by_cat($data['teams']) as $c => $l) $byCat[$c] = count($l);
$noTeam = array_filter($archers, fn($a) => $a->Team === '' && $a->Status <= 1);
$outTdm = array_filter($archers, fn($a) => $a->Cat === '');

$PAGE_TITLE = 'TDM - Greffe';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
echo tdm_styles();
$pdf = fn($list, $dep = 0) => $TDM_ROOT . 'pdf-greffe.php?list=' . $list . ($dep ? '&dep=' . $dep : '');
$prn = $CFG->ROOT_DIR . 'Partecipants/';
?>
<style>
#tdm table.lists td:first-child { width:260px; }
#tdm table.lists td .shortcuts { margin:0; }
#tdm .counts { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 6px; }
#tdm .counts span { background:var(--bleu-clair); border-radius:6px; padding:4px 10px; font-size:12px; }
#tdm .counts b { color:var(--bleu-fonce); font-size:14px; }
</style>
<div id="tdm">
    <?= tdm_header('greffe', 'Imprime les listes utiles à l\'accueil des archers et pendant le concours.', [
        '<b>Feuille de greffe</b> : par club, équipes et archers ; cochez « Payé » au règlement de l\'inscription.',
        '<b>Liste des archers</b> (alphabétique) et <b>équipes inscrites</b> (par catégorie), à afficher ou à garder à la table de marque.',
        'Si besoin, les <b>listes de base d\'IANSEO</b>, par archer : par départ et cible, par club, par catégorie.',
    ], 'Toutes les listes existent pour l\'ensemble de la compétition ou pour un seul départ.') ?>

    <div class="card">
        <h3>Inscrits</h3>
        <div>
            <div class="counts">
                <span><b><?= count($archers) ?></b> archer(s)</span>
                <span><b><?= count($data['teams']) ?></b> équipe(s)</span>
                <?php foreach ($byCat as $c => $n): ?><span><?= tdm_h(TDM_CATEGORIES[$c]['name']) ?> : <b><?= $n ?></b></span><?php endforeach; ?>
            </div>
            <?php if ($noTeam): ?>
                <div class="msg warn"><?= count($noTeam) ?> archer(s) sans équipe : ils figurent en fin de club sur la feuille de greffe.
                    <a href="<?= tdm_h($TDM_ROOT . 'equipes.php') ?>">Composer les équipes</a></div>
            <?php endif; ?>
            <?php if ($outTdm): ?>
                <div class="msg warn"><?= count($outTdm) ?> archer(s) inscrit(s) dans une division hors TDM.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>Listes du module (équipes mixtes)</h3>
        <div>
            <table class="list lists">
                <?php foreach ([
                    'greffe' => ['🖨 Feuille de greffe', 'Par club : équipes, archers, licences, départ, cible ; case « Payé ».'],
                    'archers' => ['🖨 Liste des archers', 'Ordre alphabétique : licence, club, catégorie, équipe, cible.'],
                    'equipes' => ['🖨 Équipes inscrites', 'Par catégorie : équipe, n° d\'agrément, archers et licences, départ, cible.'],
                ] as $list => [$label, $help]): ?>
                <tr>
                    <td><b><?= $label ?></b><div class="help" style="margin:2px 0 0"><?= tdm_h($help) ?></div></td>
                    <td><div class="shortcuts">
                        <a href="<?= tdm_h($pdf($list)) ?>" target="_blank">Tous les départs</a>
                        <?php if (count($data['deps']) > 1): foreach ($data['deps'] as $n => $lbl): ?>
                            <a href="<?= tdm_h($pdf($list, $n)) ?>" target="_blank"><?= tdm_h($lbl) ?></a>
                        <?php endforeach; endif; ?>
                    </div></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Listes de base d'IANSEO (par archer)</h3>
        <div>
            <p class="help">Les impressions natives d'IANSEO (menu Impressions › Listes des participants), une ligne par archer.</p>
            <div class="shortcuts">
                <a href="<?= tdm_h($prn . 'PrnSession.php') ?>" target="_blank">🖨 Par départ et cible</a>
                <a href="<?= tdm_h($prn . 'PrnCountry.php') ?>" target="_blank">🖨 Par club</a>
                <a href="<?= tdm_h($prn . 'PrnCountry.php?SinglePage=1') ?>" target="_blank">🖨 Par club, une page par club</a>
                <a href="<?= tdm_h($prn . 'PrnAlphabetical.php') ?>" target="_blank">🖨 Alphabétique</a>
                <a href="<?= tdm_h($prn . 'PrnCategory.php') ?>" target="_blank">🖨 Par catégorie</a>
                <a href="<?= tdm_h($prn . 'PrnErrors.php') ?>" target="_blank">🖨 Contrôle des inscriptions</a>
                <a href="<?= tdm_h($prn . 'PrintOut.php') ?>">Toutes les listes IANSEO…</a>
            </div>
        </div>
    </div>
</div>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
