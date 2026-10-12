<?php
/**
 * pdf-greffe.php — TDM : listes du greffe (PDF, en-tête et pied IANSEO).
 *
 *   pdf-greffe.php?list=greffe    feuille de greffe : par club, équipes (cellule fusionnée)
 *                                 et archers, départ, cible, case « Payé »
 *   pdf-greffe.php?list=archers   liste alphabétique des archers
 *   pdf-greffe.php?list=equipes   équipes inscrites, par catégorie
 *   &dep=N                        un seul départ (par défaut : tous)
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/greffe.php';
require_once HTDOCS . '/Common/pdf/ResultPDF.inc.php';
tdm_require(AclParticipants, AclReadOnly);

$list = (string) ($_GET['list'] ?? 'greffe');
if (!in_array($list, ['greffe', 'archers', 'equipes'], true)) $list = 'greffe';
$data = tdm_greffe_data(intval($_GET['dep'] ?? 0));
$dep = intval($_GET['dep'] ?? 0);
$depLabel = $dep ? ($data['deps'][$dep] ?? '') : '';

$titles = ['greffe' => 'Feuille de greffe', 'archers' => 'Liste des archers', 'equipes' => 'Équipes inscrites'];
$pdf = new ResultPDF($titles[$list] . ($depLabel ? " — $depLabel" : ''), $list === 'archers');
$W = $pdf->GetPageWidth() - 2 * $pdf->getSideMargin();
$limit = $pdf->GetPageHeight() - 22; // au-dessus du pied de page IANSEO
$rowH = 6;

/** Bandeau bleu FFTA, texte blanc. */
function tdm_g_band($pdf, $w, $text, $size = 12)
{
    $pdf->SetFillColor(0, 43, 146);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf->FontStd, 'B', $size);
    $pdf->Cell($w, 7, $text, 1, 1, 'L', 1);
    $pdf->SetDefaultColor();
}

/** Ligne d'en-tête de tableau. $cols = [[libellé, largeur, alignement], …]. */
function tdm_g_head($pdf, array $cols)
{
    $pdf->SetFillColor(225, 228, 235);
    $pdf->SetFont($pdf->FontStd, 'B', 8);
    foreach ($cols as $i => [$label, $w]) $pdf->Cell($w, 6, $label, 1, $i === count($cols) - 1 ? 1 : 0, 'C', 1);
    $pdf->SetDefaultColor();
}

/** Ligne de tableau ; saut de page avec en-tête répété si besoin. */
function tdm_g_row($pdf, array $cols, array $values, $h, $limit, $bold = [])
{
    if ($pdf->GetY() + $h > $limit) {
        $pdf->AddPage();
        tdm_g_head($pdf, $cols);
    }
    foreach ($cols as $i => [, $w, $align]) {
        $pdf->SetFont($pdf->FontStd, in_array($i, $bold, true) ? 'B' : '', 9);
        $pdf->Cell($w, $h, (string) ($values[$i] ?? ''), 1, $i === count($cols) - 1 ? 1 : 0, $align);
    }
}

$archers = $data['archers'];
$sub = $pdf->FontStd;

if (!$archers && !$data['teams']) {
    $pdf->SetFont($pdf->FontStd, '', 11);
    $pdf->Cell($W, 10, 'Aucun archer' . ($depLabel ? " pour le $depLabel" : '') . '.', 0, 1, 'C');

} elseif ($list === 'greffe') {
    // ── Feuille de greffe : un bloc par club ──────────────────────────────────
    // Équipe et catégorie : une cellule fusionnée sur les lignes des archers de l'équipe.
    $cols = [['Équipe', 40, 'L'], ['Catégorie', 55, 'L'], ['Archer', 72, 'L'], ['Licence', 24, 'C'],
             ['Départ', 0, 'C'], ['Cible', 18, 'C'], ['Payé', 18, 'C']];
    $cols[4][1] = $W - 40 - 55 - 72 - 24 - 18 - 18;
    $pdf->SetFont($pdf->FontStd, 'I', 8);
    $pdf->Cell($W, 5, 'Cochez « Payé » au règlement de l\'inscription. Les archers sans équipe sont en fin de club.', 0, 1, 'L');
    foreach (tdm_greffe_by_club($archers) as $club => $list2) {
        if ($pdf->GetY() + 7 + 6 + 2 * $rowH > $limit) $pdf->AddPage();
        $code = $list2[0]->ClubCode;
        tdm_g_band($pdf, $W, $club . ($code ? "  ($code)" : '') . '  —  ' . count($list2) . ' archer(s)', 11);
        tdm_g_head($pdf, $cols);
        // Groupes : une équipe (même nom dans deux catégories possible), ou un archer seul sans équipe.
        $groups = [];
        foreach ($list2 as $i => $a) {
            $k = $a->Team !== '' ? $a->Cat . '|' . $a->Team : 'seul' . $i;
            $groups[$k][] = $a;
        }
        foreach ($groups as $members) {
            $h = count($members) * $rowH;
            if ($pdf->GetY() + $h > $limit) {
                $pdf->AddPage();
                tdm_g_head($pdf, $cols);
            }
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $first = $members[0];
            $pdf->SetFont($pdf->FontStd, 'B', 9);
            $pdf->MultiCell($cols[0][1], $h, $first->Team !== '' ? $first->Team : 'sans équipe', 1, 'L', false, 0, $x, $y, true, 0, false, true, $h, 'M');
            $pdf->SetFont($pdf->FontStd, '', 9);
            $pdf->MultiCell($cols[1][1], $h, $first->CatName, 1, 'L', false, 0, $x + $cols[0][1], $y, true, 0, false, true, $h, 'M');
            $cx = $x + $cols[0][1] + $cols[1][1];
            foreach ($members as $i => $m) {
                $pdf->SetXY($cx, $y + $i * $rowH);
                $vals = [$m->Name . ($m->Status > 1 ? ' (ne tire pas)' : ''), $m->Code, $m->DepLabel, $m->Target, ''];
                foreach (array_slice($cols, 2) as $j => [, $w, $align]) $pdf->Cell($w, $rowH, $vals[$j], 1, 0, $align);
            }
            $pdf->SetXY($x, $y + $h);
        }
        $pdf->Ln(3);
    }

} elseif ($list === 'archers') {
    // ── Liste alphabétique ────────────────────────────────────────────────────
    $cols = [['Archer', 50, 'L'], ['Licence', 20, 'C'], ['Club', 44, 'L'], ['Catégorie', 0, 'L'], ['Équipe', 28, 'L'], ['Cible', 14, 'C']];
    $cols[3][1] = $W - 50 - 20 - 44 - 28 - 14;
    tdm_g_band($pdf, $W, count($archers) . ' archer(s)' . ($depLabel ? " — $depLabel" : ''), 11);
    tdm_g_head($pdf, $cols);
    foreach (tdm_greffe_sort_alpha($archers) as $a) {
        tdm_g_row($pdf, $cols, [$a->Name, $a->Code, $a->Club, $a->CatName, $a->Team ?: '—', $a->Target], $rowH, $limit, [0]);
    }

} else {
    // ── Équipes inscrites par catégorie ───────────────────────────────────────
    $cols = [['Équipe', 40, 'L'], ['Agrément', 20, 'C'], ['Archer femme', 52, 'L'], ['Licence', 22, 'C'],
             ['Archer homme', 52, 'L'], ['Licence', 22, 'C'], ['Départ', 0, 'C'], ['Cible', 16, 'C']];
    $cols[6][1] = $W - 40 - 20 - 52 - 22 - 52 - 22 - 16;
    foreach (tdm_greffe_teams_by_cat($data['teams']) as $cat => $teams) {
        if ($pdf->GetY() + 7 + 6 + 3 * $rowH > $limit) $pdf->AddPage();
        tdm_g_band($pdf, $W, $teams[0]->CatName . '  —  ' . count($teams) . ' équipe(s)', 11);
        tdm_g_head($pdf, $cols);
        foreach ($teams as $t) {
            tdm_g_row($pdf, $cols, [$t->Name, $t->ClubCode, $t->Woman, $t->WomanCode, $t->Man, $t->ManCode, $t->DepLabel, $t->Target],
                $rowH, $limit, [0]);
        }
        $pdf->Ln(4);
    }
}
$pdf->Output($titles[$list] . ($dep ? " départ $dep" : '') . '.pdf', 'I');
