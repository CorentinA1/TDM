<?php
/**
 * pdf-qrcodes.php — TDM : planche des QR codes Scorekeeper d'une catégorie (PDF).
 *
 * Saisie de la qualif avec Scorekeeper : un téléphone par cible. Chaque case
 * donne la cible, ses deux équipes (A et B) et le QR code natif d'IANSEO de la
 * cible (Api/ISK-NG/DrawQRCode.php : adresse du serveur, code de la compétition,
 * session du départ, distance 1, cible), agrandi pour un scan facile. La feuille
 * de marque d'équipe (pdf-qualif.php) reste devant les archers.
 *
 *   pdf-qrcodes.php?ev=CL   une catégorie (par défaut : toutes les catégories en Scorekeeper)
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/targets.php';
require_once __DIR__ . '/lib/departs.php';
require_once HTDOCS . '/Common/pdf/ResultPDF.inc.php';
tdm_require(AclQualification, AclReadOnly);

$events = tdm_events();
$only = (string) ($_GET['ev'] ?? '');
$depMap = tdm_departs_map();
$modes = tdm_qualif_modes();
$sessions = tdm_qualif_sessions();

// Cases : une par cible, par catégorie (dans l'ordre des cibles).
$cells = [];
foreach ($events as $code => $ev) {
    if ($only !== '' ? $only !== $code : ($modes[$depMap[$code] ?? 0] ?? 'paper') !== 'isk') continue;
    $ses = tdm_event_session($code);
    $teams = tdm_event_teams($ev);
    $byTarget = [];
    foreach (tdm_team_targets($teams) as $key => [$tg, $pos]) {
        if ($tg) $byTarget[$tg][$pos] = $teams[$key]->Name;
    }
    ksort($byTarget);
    foreach ($byTarget as $tg => $pos) {
        $cells[] = ['ev' => $ev, 'ses' => $ses, 'tg' => $tg, 'A' => $pos['A'] ?? '', 'B' => $pos['B'] ?? '',
                    'depart' => $depMap ? tdm_depart_label($ses, $sessions[$ses] ?? []) : ''];
    }
}

$title = 'QR codes Scorekeeper' . ($only !== '' && isset($events[$only]) ? ' — ' . $events[$only]->Name : '');
$pdf = new ResultPDF($title, true);
$margin = $pdf->getSideMargin();
$W = $pdf->GetPageWidth() - 2 * $margin;
$top = 38;
$limit = $pdf->GetPageHeight() - 22;
$cols = 3;
$cw = $W / $cols;
$ch = 72;
$scale = 1.5; // QR natif de 25 mm → 37,5 mm

$server = (string) getModuleParameter('ISK-NG', 'ServerUrl', '');
$problem = !tdm_scorekeeper_enabled() ? 'Scorekeeper n\'est pas configuré dans IANSEO : pas de QR code.'
    : (!tdm_isk_server_ok($server) ? 'Adresse du serveur Scorekeeper à vérifier (Compétition › Informations principales) : '
        . ($server === '' ? 'non renseignée' : $server) . '.' : '');

if ($problem || !$cells) {
    $pdf->SetFont($pdf->FontStd, 'B', 11);
    $pdf->SetTextColor(200, 0, 0);
    $pdf->MultiCell($W, 8, $problem ?: 'Aucune cible attribuée : faites d\'abord l\'attribution des cibles.', 0, 'C');
    $pdf->SetDefaultColor();
}
if (!$problem && $cells) {
    $i = 0;
    $y = $top;
    foreach ($cells as $c) {
        $col = $i % $cols;
        if ($col === 0 && $i > 0) $y += $ch;
        if ($col === 0 && $y + $ch > $limit) {
            $pdf->AddPage();
            $y = $top;
        }
        $x = $margin + $col * $cw;
        $pdf->SetDrawColor(160);
        $pdf->Rect($x + 1, $y + 1, $cw - 2, $ch - 2);
        $pdf->SetDrawColor(0);
        $pdf->SetXY($x + 1, $y + 2);
        $pdf->SetFont($pdf->FontStd, 'B', 18);
        $pdf->Cell($cw - 2, 8, 'Cible ' . $c['tg'], 0, 1, 'C');
        $pdf->SetX($x + 1);
        $pdf->SetFont($pdf->FontStd, '', 8);
        $pdf->Cell($cw - 2, 4, $c['ev']->Name . ($c['depart'] ? ' · ' . $c['depart'] : ''), 0, 1, 'C');
        // QR de la cible, identique au natif (tdm_isk_qr()), agrandi.
        $qw = 25 * $scale;
        $qx = $x + ($cw - $qw) / 2;
        $qy = $y + 15;
        tdm_isk_qr($pdf, $qx, $qy, $c['ses'], $c['tg'], $scale);
        $pdf->SetXY($x + 3, $qy + $qw + 2);
        $pdf->SetFont($pdf->FontStd, '', 8);
        $pdf->Cell($cw - 6, 4, 'A (A-B) : ' . ($c['A'] ?: '—'), 0, 1, 'L');
        $pdf->SetX($x + 3);
        $pdf->Cell($cw - 6, 4, 'B (C-D) : ' . ($c['B'] ?: '—'), 0, 1, 'L');
        $i++;
    }
}
$pdf->Output('QR codes Scorekeeper' . ($only !== '' ? " $only" : '') . '.pdf', 'I');
