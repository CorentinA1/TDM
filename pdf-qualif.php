<?php
/**
 * pdf-qualif.php — TDM : feuilles de marque de la mini-qualification (PDF).
 *
 * Une feuille par équipe (comme le haut de la « Fiche de marque » du classeur) :
 * nom d'équipe avec son numéro, les deux archers, cible si elle est attribuée,
 * 5 volées de 4 flèches (2 par archer), total de volée, cumul, total, nombre
 * de 10 et de 9 (départage), signatures. Deux feuilles par page paysage.
 * Si Scorekeeper (API ISK-NG) est configuré, le QR code de la cible (session du
 * départ, distance 1, cible) est imprimé comme sur les feuilles natives
 * (Api/ISK-NG/DrawQRCode.php).
 *
 *   pdf-qualif.php?ev=CL   une catégorie (par défaut : toutes)
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/targets.php';
require_once __DIR__ . '/lib/departs.php';
require_once HTDOCS . '/Common/pdf/ScorePDF.inc.php';
tdm_require(AclQualification, AclReadOnly);

/** Volées de la mini-qualif : 5 volées de 4 flèches. */
const TDM_QUALIF_ENDS = 5;
const TDM_QUALIF_END_ARROWS = 4;

$events = tdm_events();
$only = (string) ($_GET['ev'] ?? '');
if (!isset($events[$only])) $only = '';

$depMap = tdm_departs_map();
$sessions = tdm_qualif_sessions();
// Feuilles à imprimer : par départ puis par cible (« 3A », « 3B »…), les équipes sans cible à la fin.
$sheets = [];
foreach ($events as $code => $ev) {
    if ($only !== '' && $only !== $code) continue;
    $teams = tdm_event_teams($ev);
    $targets = tdm_team_targets($teams);
    $list = [];
    foreach ($teams as $key => $t) {
        [$tg, $pos] = $targets[$key];
        $ses = tdm_event_session($code);
        $list[] = ['ev' => $ev, 'team' => $t, 'target' => tdm_team_target_label($tg, $pos), 'tg' => $tg, 'ses' => $ses,
                   'depart' => $depMap ? tdm_depart_label($ses, $sessions[$ses] ?? []) : '',
                   'sort' => [$ses, $tg ?: PHP_INT_MAX, $pos, $t->Name]];
    }
    usort($list, fn($a, $b) => $a['sort'] <=> $b['sort']);
    $sheets = array_merge($sheets, $list);
}
// Toutes catégories : ordre des cibles sur l'ensemble du pas de tir.
if ($only === '') usort($sheets, fn($a, $b) => $a['sort'] <=> $b['sort']);

$qr = tdm_scorekeeper_enabled();
if ($qr) require_once HTDOCS . '/Api/ISK-NG/DrawQRCode.php';
$pdf = new ScorePDF(false);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->PrintFooterSerialNumber = true;
$pdf->SetTitle('Feuilles de marque qualification' . ($only ? " $only" : ''));
$margin = $pdf->getSideMargin();
$cardW = ($pdf->GetPageWidth() - 3 * $margin) / 2;

/** Une feuille de qualification à partir de ($x, $y). */
function tdm_draw_qualif($pdf, $x, $y, $w, $s)
{
    $t = $s['team'];
    $boxW = 26;
    $infoW = $w - $boxW;

    // ── En-tête : équipe, archers, catégorie, cible.
    $pdf->SetXY($x, $y);
    $pdf->SetFont($pdf->FontStd, '', 8);
    $pdf->Cell($infoW, 4, 'Équipe', 'TL', 0, 'L');
    $pdf->SetFont($pdf->FontStd, 'B', 8);
    $pdf->Cell($boxW, 4, 'Cible', 1, 1, 'C', 1);
    $pdf->SetX($x);
    $pdf->SetFont($pdf->FontStd, 'B', 18);
    $pdf->Cell($infoW, 9, $t->Name, 'L', 0, 'L');
    $pdf->SetFont($pdf->FontStd, 'B', 22);
    $pdf->Cell($boxW, 17, $s['target'], 1, 1, 'C');
    $pdf->SetXY($x, $y + 13);
    $pdf->SetFont($pdf->FontStd, '', 9);
    $pdf->Cell($infoW, 4, implode('  –  ', array_map(fn($m) => trim($m->EnFirstName . ' ' . $m->EnName), $t->Members)), 'L', 1, 'L');
    $pdf->SetX($x);
    $pdf->Cell($infoW, 5, $s['ev']->Name . ($s['depart'] ? '  ·  ' . $s['depart'] : '') . '  ·  5 volées de 4 flèches (2 par archer)', 'LB', 1, 'L');
    // QR code Scorekeeper de la cible (natif : session, distance 1, cible sans lettre).
    global $qr;
    if ($qr && $s['tg']) {
        DrawQRCode_ISK_NG($pdf, $x + $infoW - 17, $y + 1, $s['ses'], 1, $s['ses'] . str_pad($s['tg'], 3, '0', STR_PAD_LEFT), '', 'Q', false, '', true);
    }

    // ── Volées.
    $y = $pdf->GetY() + 5;
    $numW = 12;
    $totW = ($w - $numW) * 0.2;
    $arrowW = ($w - $numW - 2 * $totW) / TDM_QUALIF_END_ARROWS;
    $rowH = 10;
    $pdf->SetXY($x, $y);
    $pdf->SetFont($pdf->FontStd, 'B', 9);
    $pdf->Cell($numW, 8, 'Volée', 1, 0, 'C', 1);
    for ($j = 1; $j <= TDM_QUALIF_END_ARROWS; $j++) $pdf->Cell($arrowW, 8, 'Flèche ' . $j, 1, 0, 'C', 1);
    $pdf->Cell($totW, 8, 'Total volée', 1, 0, 'C', 1);
    $pdf->Cell($totW, 8, 'Cumul', 1, 1, 'C', 1);
    for ($i = 1; $i <= TDM_QUALIF_ENDS; $i++) {
        $pdf->SetX($x);
        $pdf->SetFont($pdf->FontStd, 'B', 11);
        $pdf->Cell($numW, $rowH, $i, 1, 0, 'C', 1);
        for ($j = 1; $j <= TDM_QUALIF_END_ARROWS; $j++) $pdf->Cell($arrowW, $rowH, '', 1, 0, 'C');
        $pdf->Cell($totW, $rowH, '', 1, 0, 'C');
        $pdf->Cell($totW, $rowH, '', 1, 1, 'C');
    }

    // ── Total, nombre de 10 et de 9.
    $pdf->Ln(3);
    $smallW = $totW * 0.75;
    $pdf->SetX($x + $w - 2 * $smallW - 2 * $totW - 4);
    $pdf->SetFont($pdf->FontStd, 'B', 9);
    $pdf->Cell($smallW, 5, 'Nb de 10', 1, 0, 'C', 1);
    $pdf->Cell($smallW, 5, 'Nb de 9', 1, 0, 'C', 1);
    $pdf->Cell(4, 5, '', 0, 0);
    $pdf->Cell(2 * $totW, 5, 'TOTAL QUALIFICATION', 1, 1, 'C', 1);
    $pdf->SetX($x + $w - 2 * $smallW - 2 * $totW - 4);
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell($smallW, 11, '', 1, 0, 'C');
    $pdf->Cell($smallW, 11, '', 1, 0, 'C');
    $pdf->Cell(4, 11, '', 0, 0);
    $pdf->Cell(2 * $totW, 11, '', 1, 1, 'C');

    // ── Signatures.
    $pdf->SetXY($x, $pdf->GetY() + 6);
    $pdf->SetFont($pdf->FontStd, 'I', 7);
    $pdf->Cell($w / 2 - 3, 8, 'Signature d\'un archer de l\'équipe', 'B', 0, 'L');
    $pdf->Cell(6, 8, '', 0, 0);
    $pdf->Cell($w / 2 - 3, 8, 'Signature du marqueur', 'B', 1, 'L');
    return $pdf->GetY();
}

if (!$sheets) {
    $pdf->AddPage();
    $pdf->SetFont($pdf->FontStd, '', 12);
    $pdf->SetXY($margin, 40);
    $pdf->Cell(0, 8, 'Aucune équipe : composez les équipes d\'abord (Configuration, Composition des équipes).', 0, 1);
}
foreach (array_chunk($sheets, 2) as $pair) {
    $pdf->AddPage();
    foreach ($pair as $i => $s) tdm_draw_qualif($pdf, $margin + $i * ($cardW + $margin), 32, $cardW, $s);
}
$pdf->Output('Feuilles qualification' . ($only ? " $only" : '') . '.pdf', 'I');
