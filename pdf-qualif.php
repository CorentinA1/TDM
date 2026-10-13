<?php
/**
 * pdf-qualif.php — TDM : feuilles de marque de la mini-qualification (PDF).
 *
 * Une feuille par équipe (comme le haut de la « Fiche de marque » du classeur) :
 * nom d'équipe avec son numéro, les deux archers, cible si elle est attribuée,
 * 5 volées de 4 flèches (2 par archer), total de volée, cumul, total, nombre
 * de 10 et de 9 (départage), signatures. Deux feuilles par page paysage.
 * Une page par cible (équipe A à gauche, B à droite). Départ saisi avec Scorekeeper :
 * un QR code de la cible par page, en bas au milieu (le même que sur la planche
 * pdf-qrcodes.php) ; la feuille reste devant les archers et fait foi.
 *
 *   pdf-qualif.php?ev=CL       une catégorie (par défaut : toutes)
 *   pdf-qualif.php?filled=1    feuilles complétées : flèches reçues de Scorekeeper
 *                              (à chaque volée, 2 flèches de la femme puis 2 de
 *                              l'homme), totaux, 10 et 9 ; équipe saisie à la main :
 *                              seulement les totaux
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/targets.php';
require_once __DIR__ . '/lib/departs.php';
require_once HTDOCS . '/Common/pdf/ScorePDF.inc.php';
require_once HTDOCS . '/Common/Lib/ArrTargets.inc.php';
tdm_require(AclQualification, AclReadOnly);
$filled = !empty($_GET['filled']);

/** Volées de la mini-qualif : 5 volées de 4 flèches. */
const TDM_QUALIF_ENDS = 5;
const TDM_QUALIF_END_ARROWS = 4;

$events = tdm_events();
$only = (string) ($_GET['ev'] ?? '');
if (!isset($events[$only])) $only = '';

$depMap = tdm_departs_map();
$sessions = tdm_qualif_sessions();
// QR code de la cible sur les feuilles des départs saisis avec Scorekeeper.
$iskOn = tdm_scorekeeper_enabled();
$modes = tdm_qualif_modes();
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
                   'qr' => $iskOn && $tg && isset($depMap[$code]) && ($modes[$ses] ?? 'paper') === 'isk',
                   'sort' => [$ses, $tg ?: PHP_INT_MAX, $pos, $t->Name]];
    }
    usort($list, fn($a, $b) => $a['sort'] <=> $b['sort']);
    $sheets = array_merge($sheets, $list);
}
// Toutes catégories : ordre des cibles sur l'ensemble du pas de tir.
if ($only === '') usort($sheets, fn($a, $b) => $a['sort'] <=> $b['sort']);

$pdf = new ScorePDF(false);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->PrintFooterSerialNumber = true;
$pdf->SetTitle('Feuilles de marque qualification' . ($filled ? ' complétées' : '') . ($only ? " $only" : ''));
$margin = $pdf->getSideMargin();
$cardW = ($pdf->GetPageWidth() - 3 * $margin) / 2;

/** Une feuille de qualification à partir de ($x, $y). */
function tdm_draw_qualif($pdf, $x, $y, $w, $s)
{
    global $filled;
    $t = $s['team'];
    // Feuille complétée : volées reconstruites depuis les flèches des archers (femme d'abord).
    $ends = $filled ? tdm_team_ends(array_map(fn($m) => (string) $m->QuD1Arrowstring, $t->Members), TDM_QUALIF_ENDS) : [];
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

    // ── Volées.
    $y = $pdf->GetY() + 5;
    $numW = 12;
    $totW = ($w - $numW) * 0.2;
    $arrowW = ($w - $numW - 2 * $totW) / TDM_QUALIF_END_ARROWS;
    $rowH = 10;
    $pdf->SetXY($x, $y);
    $pdf->SetFont($pdf->FontStd, 'B', 9);
    $pdf->Cell($numW, 8, 'Volée', 1, 0, 'C', 1);
    for ($j = 1; $j <= TDM_QUALIF_END_ARROWS; $j++) {
        $pdf->Cell($arrowW, 8, $ends ? ($j <= 2 ? 'Femme' : 'Homme') : 'Flèche ' . $j, 1, 0, 'C', 1);
    }
    $pdf->Cell($totW, 8, 'Total volée', 1, 0, 'C', 1);
    $pdf->Cell($totW, 8, 'Cumul', 1, 1, 'C', 1);
    $cumul = 0;
    for ($i = 1; $i <= TDM_QUALIF_ENDS; $i++) {
        $pdf->SetX($x);
        $pdf->SetFont($pdf->FontStd, 'B', 11);
        $pdf->Cell($numW, $rowH, $i, 1, 0, 'C', 1);
        $arrows = $ends[$i] ?? [];
        $shot = trim(implode('', $arrows)) !== '';
        $pdf->SetFont($pdf->FontStd, '', 12);
        for ($j = 1; $j <= TDM_QUALIF_END_ARROWS; $j++) {
            $a = $arrows[$j - 1] ?? ' ';
            $pdf->Cell($arrowW, $rowH, trim($a) !== '' ? DecodeFromLetter($a) : '', 1, 0, 'C');
        }
        $endTot = $shot ? ValutaArrowString(implode('', $arrows)) : 0;
        $cumul += $endTot;
        $pdf->SetFont($pdf->FontStd, 'B', 12);
        $pdf->Cell($totW, $rowH, $shot ? $endTot : '', 1, 0, 'C');
        $pdf->Cell($totW, $rowH, $shot ? $cumul : '', 1, 1, 'C');
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
    // Feuille complétée : totaux IANSEO de l'équipe (somme des deux archers), aussi pour une équipe saisie à la main.
    $done = $filled && $t->Score !== null;
    $pdf->Cell($smallW, 11, $done ? $t->Tens : '', 1, 0, 'C');
    $pdf->Cell($smallW, 11, $done ? $t->Nines : '', 1, 0, 'C');
    $pdf->Cell(4, 11, '', 0, 0);
    $pdf->Cell(2 * $totW, 11, $done ? $t->Score : '', 1, 1, 'C');
    if ($filled && $done && !$ends) {
        $pdf->SetX($x);
        $pdf->SetFont($pdf->FontStd, 'I', 7);
        $pdf->Cell($w, 4, 'Score saisi à la main (feuille d\'équipe) : détail des flèches non disponible.', 0, 1, 'L');
    }

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
// Une page par cible : équipe A à gauche, B à droite ; les équipes sans cible deux par deux.
// Un seul QR Scorekeeper par page (sur la feuille de gauche) : les deux équipes partagent le téléphone de la cible.
$pages = [];
foreach ($sheets as $s) {
    $last = count($pages) - 1;
    $k = $s['tg'] ? $s['ses'] . '|' . $s['tg'] : '';
    if ($last >= 0 && count($pages[$last]['sheets']) < 2 && $pages[$last]['key'] === $k) {
        $s['qr'] = false;
        $pages[$last]['sheets'][] = $s;
    } else {
        $pages[] = ['key' => $k, 'sheets' => [$s]];
    }
}
foreach ($pages as $page) {
    $pdf->AddPage();
    $bottom = 0;
    foreach ($page['sheets'] as $i => $s) $bottom = max($bottom, tdm_draw_qualif($pdf, $margin + $i * ($cardW + $margin), 32, $cardW, $s));
    // Départ saisi avec Scorekeeper : un QR de la cible, en bas au milieu de la page (taille native,
    // loin des cases, pour un scan facile) ; les deux équipes partagent le téléphone de la cible.
    $s = $page['sheets'][0];
    if (!empty($s['qr'])) {
        $qw = 30;
        $qx = ($pdf->GetPageWidth() - $qw) / 2;
        $qy = min($bottom + 6, $pdf->GetPageHeight() - 22 - $qw - 5);
        tdm_isk_qr($pdf, $qx, $qy, $s['ses'], $s['tg'], $qw / 25);
        $pdf->SetXY($qx - 30, $qy + $qw + 1);
        $pdf->SetFont($pdf->FontStd, '', 8);
        $pdf->Cell($qw + 60, 4, 'Scorekeeper : cible ' . $s['tg'] . ' (un téléphone pour la cible)', 0, 1, 'C');
    }
}
$pdf->Output('Feuilles qualification' . ($only ? " $only" : '') . '.pdf', 'I');
