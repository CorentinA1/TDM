<?php
/**
 * pdf-repartition.php — TDM : répartition des poules selon la qualif
 * (PDF au format IANSEO, en-tête et pied). Même contenu que repartition.php :
 * le classement de qualif, puis le serpentin pour chaque configuration ; la
 * configuration réglée est encadrée.
 *
 *   pdf-repartition.php?ev=CL   une catégorie (par défaut : toutes, une par page)
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/setup.php';
require_once HTDOCS . '/Common/pdf/ResultPDF.inc.php';
tdm_require(AclQualification, AclReadOnly);

$events = tdm_events();
$only = (string) ($_GET['ev'] ?? '');
if (!isset($events[$only])) $only = '';

$pdf = new ResultPDF('Répartition des poules', true);
$W = $pdf->GetPageWidth() - 2 * $pdf->getSideMargin();
$left = $pdf->getSideMargin();
$limit = $pdf->GetPageHeight() - 22;

$first = true;
foreach ($events as $code => $ev) {
    if ($only !== '' && $only !== $code) continue;
    if (!$first) $pdf->AddPage();
    $first = false;

    $teams = tdm_rep_order(tdm_event_teams($ev));
    $n = count($teams);
    $current = $ev->Pools ? [$ev->Pools, $ev->PoolSize] : null;

    $pdf->SetFillColor(0, 43, 146);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell($W, 8, 'Répartition des poules — ' . $ev->Name . " ($n équipes)", 1, 1, 'C', 1);
    $pdf->SetDefaultColor();
    $pdf->SetFont($pdf->FontStd, 'I', 8);
    $pdf->Cell($W, 5, 'Serpentin selon le classement de qualification (1, 2, 3 puis 6, 5, 4…).'
        . ($current ? " Configuration retenue : {$current[0]} poule(s) de {$current[1]}." : ''), 0, 1, 'C');
    if (!$n) {
        $pdf->SetFont($pdf->FontStd, '', 11);
        $pdf->Cell($W, 10, 'Aucune équipe dans cette catégorie.', 0, 1, 'C');
        continue;
    }

    // Classement de qualif, sur deux colonnes.
    $pdf->Ln(2);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell($W, 6, 'Classement de qualification', 0, 1, 'L');
    $half = (int) ceil($n / 2);
    $colW = $W / 2;
    $y0 = $pdf->GetY();
    foreach ($teams as $i => $t) {
        $col = $i < $half ? 0 : 1;
        $pdf->SetXY($left + $col * $colW, $y0 + ($i % $half) * 5);
        $pdf->SetFont($pdf->FontStd, 'B', 9);
        $pdf->Cell(10, 5, ($i + 1) . '.', 0, 0, 'R');
        $pdf->SetFont($pdf->FontStd, '', 9);
        $pdf->Cell($colW - 30, 5, $t->Name, 0, 0, 'L');
        $pdf->Cell(18, 5, $t->Score !== null ? (string) $t->Score : '—', 0, 0, 'R');
    }
    $pdf->SetY($y0 + $half * 5 + 4);

    // Une grille par configuration.
    foreach (tdm_rep_configs($n, $current) as [$g, $size]) {
        $snake = tdm_snake($g, $size);
        $isCur = $current && $current[0] === $g && $current[1] === $size;
        $byes = $g * $size - $n;
        $h = 6 + 6 + $size * 5.5 + 4;
        if ($pdf->GetY() + $h > $limit) $pdf->AddPage();
        $y = $pdf->GetY();
        $pdf->SetFont($pdf->FontStd, 'B', 10);
        if ($isCur) $pdf->SetTextColor(249, 10, 114);
        $pdf->Cell($W, 6, "$g poule" . ($g > 1 ? 's' : '') . " de $size"
            . ($byes > 0 ? "  ·  $byes bye(s)" : '') . ($byes < 0 ? '  ·  ' . (-$byes) . ' équipe(s) sans place' : '')
            . ($isCur ? '  ·  configuration retenue' : ''), 0, 1, 'L');
        $pdf->SetDefaultColor();
        $cw = $W / $g;
        $font = $g > 5 ? 7 : ($g > 3 ? 8 : 9);
        $pdf->SetFillColor(0, 43, 146);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont($pdf->FontStd, 'B', $font);
        for ($p = 1; $p <= $g; $p++) $pdf->Cell($cw, 6, "Poule $p", 1, $p === $g ? 1 : 0, 'C', 1);
        $pdf->SetDefaultColor();
        for ($item = 1; $item <= $size; $item++) {
            for ($p = 1; $p <= $g; $p++) {
                $r = $snake[$p][$item];
                $t = $teams[$r - 1] ?? null;
                $pdf->SetFont($pdf->FontStd, $t ? '' : 'I', $font);
                $pdf->Cell($cw, 5.5, $r . '. ' . ($t ? $t->Name : 'bye'), 1, $p === $g ? 1 : 0, 'L');
            }
        }
        if ($isCur) {
            $pdf->SetDrawColor(249, 10, 114);
            $pdf->SetLineWidth(0.6);
            $pdf->Rect($left - 1.5, $y - 1, $W + 3, $pdf->GetY() - $y + 2);
            $pdf->SetLineWidth(0.2);
            $pdf->SetDefaultColor();
        }
        $pdf->Ln(4);
    }
}
if ($first) {
    $pdf->SetFont($pdf->FontStd, '', 11);
    $pdf->Cell($W, 10, 'Aucune épreuve TDM : faites d\'abord la configuration.', 0, 1, 'C');
}
$pdf->Output('Répartition des poules' . ($only ? " $only" : '') . '.pdf', 'I');
