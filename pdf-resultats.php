<?php
/**
 * pdf-resultats.php — TDM : résultats (PDF au format IANSEO, en-tête et pied).
 *
 * Une catégorie par page (paysage) : place finale (ex aequo du règlement),
 * équipe, n° d'agrément du club, archers et licences, qualif (score, rang),
 * poule (numéro, rang), BSO, points. Mention « provisoire » tant que les
 * poules ou le BSO ne sont pas terminés.
 *
 *   pdf-resultats.php?ev=CL   une catégorie (par défaut : toutes)
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/results.php';
require_once HTDOCS . '/Common/pdf/ResultPDF.inc.php';
tdm_require(AclQualification, AclReadOnly);
tdm_schema();

$events = tdm_events();
$only = (string) ($_GET['ev'] ?? '');
if (!isset($events[$only])) $only = '';

$pdf = new ResultPDF('Résultats', false);
$W = $pdf->GetPageWidth() - 2 * $pdf->getSideMargin();
$limit = $pdf->GetPageHeight() - 22;

// Colonnes (mm), le reste de la largeur pour l'équipe et les archers.
$cols = [
    ['Place', 16, 'C'], ['Équipe', 0, 'L'], ['N° club', 18, 'C'],
    ['Archer femme', 0, 'L'], ['Licence', 18, 'C'], ['Archer homme', 0, 'L'], ['Licence', 18, 'C'],
    ['Qualif', 14, 'C'], ['Rang qualif', 15, 'C'], ['Poule', 12, 'C'], ['Rang poule', 15, 'C'], ['BSO', 14, 'C'], ['Points', 15, 'C'],
];
$fixed = array_sum(array_column($cols, 1));
$free = ($W - $fixed) / 3;
foreach ($cols as &$c) if (!$c[1]) $c[1] = $free;
unset($c);

$header = function () use ($pdf, $cols) {
    $pdf->SetFillColor(0, 43, 146);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf->FontStd, 'B', 8);
    foreach ($cols as [$label, $w]) $pdf->Cell($w, 6, $label, 1, 0, 'C', 1);
    $pdf->Ln();
    $pdf->SetDefaultColor();
};

$first = true;
foreach ($events as $code => $ev) {
    if ($only !== '' && $only !== $code) continue;
    if (!$first) $pdf->AddPage();
    $first = false;
    $res = tdm_category_results($ev);

    $pdf->SetFillColor(0, 43, 146);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell($W, 9, $ev->Name, 1, 1, 'C', 1);
    $pdf->SetDefaultColor();
    $pdf->SetFont($pdf->FontStd, $res->Final ? 'B' : 'BI', 9);
    if (!$res->Final) $pdf->SetTextColor(180, 0, 0);
    $pdf->Cell($W, 6, tdm_results_status($res) . '  ·  ' . count($res->Rows) . ' équipe(s)', 0, 1, 'C');
    $pdf->SetDefaultColor();
    $pdf->Ln(1);
    if (!$res->Rows) {
        $pdf->SetFont($pdf->FontStd, '', 10);
        $pdf->Cell($W, 8, 'Aucune équipe dans cette catégorie.', 0, 1, 'C');
        continue;
    }
    $header();
    foreach ($res->Rows as $r) {
        if ($pdf->GetY() + 7 > $limit) { $pdf->AddPage(); $header(); }
        $podium = $r->Place !== null && $r->Place <= 3;
        $pdf->SetFillColor(255, 248, 220);
        $values = [
            tdm_place_label($r), $r->Name, $r->ClubCode, $r->Woman, $r->WomanCode, $r->Man, $r->ManCode,
            $r->Qualif ?? '', $r->QualifRank ?: '', $r->Pool ?: '', $r->PoolRank ?: '',
            $r->Bso ? ($r->BsoPlace ? (int) $r->BsoPlace : 'oui') : '', $r->Place !== null ? $r->Points : '',
        ];
        foreach ($cols as $i => [$label, $w, $align]) {
            $bold = in_array($i, [0, 1, 12], true);
            $pdf->SetFont($pdf->FontStd, $bold ? 'B' : '', $i === 0 || $i === 12 ? 10 : 8);
            $pdf->Cell($w, 6.5, (string) $values[$i], 1, 0, $align, $podium);
        }
        $pdf->Ln();
    }
    $pdf->Ln(2);
    $pdf->SetFont($pdf->FontStd, 'I', 7);
    $pdf->MultiCell($W, 3.5, "« = » : ex aequo du règlement (tous les 2es de poule, puis tous les 3es…). Points : 10 / 9 / 8 / 7 pour les quatre "
        . "premiers du Big Shoot Off, 6 pour ses autres équipes, puis 5 / 4 / 3 pour les 2es / 3es / 4es de poule, 0 au-delà.", 0, 'L');
}
if ($first) {
    $pdf->SetFont($pdf->FontStd, '', 11);
    $pdf->Cell($W, 10, 'Aucune épreuve TDM.', 0, 1, 'C');
}
$pdf->Output('Résultats TDM' . ($only ? " $only" : '') . '.pdf', 'I');
