<?php
/**
 * feuilles.php — TDM : feuilles de marque des matchs de poule (PDF).
 *
 * Remplace, pour le TDM, l'impression native (Modules/RoundRobin/PdfScorecards.php)
 * qui n'écrit que le nom du club : ici chaque feuille porte le nom complet de
 * l'équipe avec son numéro (« Loudun 2 »), ses deux archers et l'adversaire,
 * pour distribuer les feuilles sans erreur. Mise en page reprise de la feuille
 * native (deux feuilles par page, l'équipe A à gauche, l'équipe B à droite).
 *
 *   feuilles.php?ev=CL&group=2   une poule, dans l'ordre des rencontres (tour, puis cible)
 *   feuilles.php?ev=CL           toutes les poules de la catégorie, poule par poule
 *
 * Les byes n'ont pas de feuille.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once HTDOCS . '/Common/pdf/ScorePDF.inc.php';
tdm_require(AclRobin, AclReadOnly);

$events = tdm_events();
$code = (string) ($_GET['ev'] ?? '');
if (!isset($events[$code])) exit('Catégorie inconnue.');
$ev = $events[$code];
$group = max(0, intval($_GET['group'] ?? 0));

$tour = StrSafe_DB($TDM_TOUR);
$evSql = StrSafe_DB($code);
$rs = safe_r_sql("SELECT RrLevEnds, RrLevArrows, RrLevSO, RrLevName FROM RoundRobinLevel
    WHERE RrLevTournament = $tour AND RrLevTeam = 1 AND RrLevEvent = $evSql AND RrLevLevel = 1");
$level = safe_fetch($rs);
if (!$level) exit('Poules non configurées.');

// Équipes : nom avec numéro, archers, rang de qualif.
$teams = tdm_event_teams($ev);

// Matchs : deux lignes par rencontre (numéros pair / impair), dans l'ordre poule, tour, cible.
$rs = safe_r_sql("SELECT RrMatchGroup, RrMatchRound, RrMatchMatchNo, RrMatchTarget, RrMatchAthlete, RrMatchSubTeam, CoName
    FROM RoundRobinMatches
    LEFT JOIN Countries ON CoId = RrMatchAthlete AND CoTournament = RrMatchTournament
    WHERE RrMatchTournament = $tour AND RrMatchTeam = 1 AND RrMatchEvent = $evSql AND RrMatchLevel = 1"
    . ($group ? " AND RrMatchGroup = $group" : '') . "
    ORDER BY RrMatchGroup, RrMatchRound, RrMatchMatchNo");
$pairs = [];
while ($r = safe_fetch($rs)) {
    $k = $r->RrMatchGroup . '-' . $r->RrMatchRound . '-' . intdiv((int) $r->RrMatchMatchNo, 2);
    $pairs[$k][(int) $r->RrMatchMatchNo % 2] = $r;
}
$matches = [];
foreach ($pairs as $p) {
    if (!isset($p[0], $p[1]) || !(int) $p[0]->RrMatchAthlete || !(int) $p[1]->RrMatchAthlete) continue; // bye ou poule vide
    $matches[] = $p;
}
usort($matches, fn($a, $b) => [$a[0]->RrMatchGroup, $a[0]->RrMatchRound, (int) $a[0]->RrMatchTarget, $a[0]->RrMatchMatchNo]
                           <=> [$b[0]->RrMatchGroup, $b[0]->RrMatchRound, (int) $b[0]->RrMatchTarget, $b[0]->RrMatchMatchNo]);

/** Nom, archers et rang de qualif d'un participant de match. */
function tdm_sheet_team($m, array $teams)
{
    $key = (int) $m->RrMatchAthlete . '-' . max(1, (int) $m->RrMatchSubTeam);
    $t = $teams[$key] ?? null;
    return [
        'name' => tdm_team_name((string) $m->CoName, max(1, (int) $m->RrMatchSubTeam)),
        'archers' => $t ? implode('  –  ', array_map(fn($a) => trim($a->EnFirstName . ' ' . $a->EnName), $t->Members)) : '',
        'rank' => $t && $t->Rank ? $t->Rank : '',
        'target' => ltrim((string) $m->RrMatchTarget, '0'),
    ];
}

$pdf = new ScorePDF(false);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->PrintFooterSerialNumber = true;
$pdf->SetTitle('Feuilles de marque ' . $code . ($group ? " poule $group" : ''));

$margin = $pdf->getSideMargin();
$cardW = ($pdf->GetPageWidth() - 3 * $margin) / 2;

/** Une feuille (équipe $me contre $opp), à partir de ($x, $y). */
function tdm_draw_sheet($pdf, $x, $y, $w, $me, $opp, $ev, $level, $m)
{
    $ends = (int) $level->RrLevEnds;
    $arrows = (int) $level->RrLevArrows;
    $so = max(1, (int) $level->RrLevSO);
    $cellH = 8;

    // ── En-tête : équipe (gros), archers, adversaire, cible, rang de qualif.
    $boxW = 26;
    $infoW = $w - 2 * $boxW;
    $pdf->SetXY($x, $y);
    $pdf->SetFont($pdf->FontStd, '', 8);
    $pdf->Cell($infoW, 4, 'Équipe', 'TL', 0, 'L');
    $pdf->SetFont($pdf->FontStd, 'B', 8);
    $pdf->Cell($boxW, 4, 'Cible', 1, 0, 'C', 1);
    $pdf->Cell($boxW, 4, 'Rang qualif', 1, 1, 'C', 1);
    $pdf->SetX($x);
    $pdf->SetFont($pdf->FontStd, 'B', 18);
    $pdf->Cell($infoW, 9, $me['name'], 'L', 0, 'L');
    $pdf->SetFont($pdf->FontStd, 'B', 22);
    $pdf->Cell($boxW, 17, $me['target'], 1, 0, 'C');
    $pdf->Cell($boxW, 17, $me['rank'], 1, 1, 'C');
    $pdf->SetXY($x, $y + 13);
    $pdf->SetFont($pdf->FontStd, '', 9);
    $pdf->Cell($infoW, 4, $me['archers'], 'L', 1, 'L');
    $pdf->SetX($x);
    $pdf->SetFont($pdf->FontStd, '', 9);
    $pdf->Cell($infoW, 5, $ev->Name . '  ·  contre ' . $opp['name'] . ($opp['target'] !== '' ? ' (cible ' . $opp['target'] . ')' : ''), 'LB', 1, 'L');

    // ── Bandeau : case « gagnant », poule et rencontre.
    $y = $pdf->GetY() + 4;
    $numW = 9;
    $totW = ($w - $numW) * 0.17;
    $setW = ($w - $numW) * 0.17;
    $arrowW = ($w - $numW - 2 * $totW - $setW) / $arrows;
    $pdf->SetXY($x + $numW, $y);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell($w - $numW, $cellH, 'Poule ' . $m->RrMatchGroup . '  ·  Rencontre ' . $m->RrMatchRound, 1, 0, 'C', 1);
    $pdf->Rect($x + $numW + 2, $y + 2, 4, 4, 'DF', [], [255, 255, 255]);
    $pdf->SetXY($x + $numW + 7, $y);
    $pdf->SetFont($pdf->FontStd, '', 9);
    $pdf->Cell(20, $cellH, 'Gagnant', 0, 1, 'L');

    // ── Volées.
    $pdf->SetX($x + $numW);
    $pdf->SetFont($pdf->FontStd, 'B', 9);
    for ($j = 1; $j <= $arrows; $j++) $pdf->Cell($arrowW, $cellH, $j, 1, 0, 'C', 1);
    $pdf->Cell($totW, $cellH, 'Total volée', 1, 0, 'C', 1);
    $pdf->Cell($setW, $cellH, 'Points de set', 1, 0, 'C', 1);
    $pdf->Cell($totW, $cellH, 'Total sets', 1, 1, 'C', 1);
    $rowH = 9;
    for ($i = 1; $i <= $ends; $i++) {
        $pdf->SetX($x);
        $pdf->SetFont($pdf->FontStd, 'B', 10);
        $pdf->Cell($numW, $rowH, $i, 1, 0, 'C', 1);
        for ($j = 1; $j <= $arrows; $j++) $pdf->Cell($arrowW, $rowH, '', 1, 0, 'C');
        $pdf->Cell($totW, $rowH, '', 1, 0, 'C');
        $pdf->SetFont($pdf->FontStd, 'B', 11);
        foreach (['2', '1', '0'] as $p) $pdf->Cell($setW / 3, $rowH, $p, 1, 0, 'C');
        $pdf->Cell($totW, $rowH, '', 1, 1, 'C');
    }

    // ── Barrage, plus près du centre, total.
    $yb = $pdf->GetY() + 3;
    $pdf->SetXY($x, $yb);
    $pdf->SetFont($pdf->FontStd, 'B', 7);
    $pdf->Cell($numW, 14, 'Barrage', 1, 0, 'C', 1);
    $soW = min($arrowW, ($arrowW * $arrows) / $so);
    for ($j = 0; $j < $so; $j++) {
        $pdf->SetXY($x + $numW + 1 + $j * ($soW + 1), $yb);
        $pdf->Cell($soW, 8, '', 1, 0, 'C');
    }
    $pdf->Rect($x + $numW + 1, $yb + 10, 4, 4);
    $pdf->SetXY($x + $numW + 6, $yb + 10);
    $pdf->SetFont($pdf->FontStd, '', 8);
    $pdf->Cell(40, 4, 'Plus près du centre', 0, 0, 'L');
    $pdf->SetXY($x + $w - $totW - $setW, $yb);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell($setW, $rowH, 'Total', 0, 0, 'R');
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell($totW, $rowH, '', 1, 1, 'C');

    // ── Signature de l'équipe.
    $pdf->SetXY($x, $yb + 20);
    $pdf->SetFont($pdf->FontStd, 'I', 7);
    $pdf->Cell($w, 8, 'Signature d\'un archer de l\'équipe', 'B', 1, 'L');
    return $pdf->GetY();
}

if (!$matches) {
    $pdf->AddPage();
    $pdf->SetFont($pdf->FontStd, '', 12);
    $pdf->SetXY($margin, 40);
    $pdf->Cell(0, 8, 'Aucune rencontre à imprimer : les poules ne sont pas encore remplies (validation qualif → poules).', 0, 1);
}
foreach ($matches as [$m1, $m2]) {
    $a = tdm_sheet_team($m1, $teams);
    $b = tdm_sheet_team($m2, $teams);
    $pdf->AddPage();
    $top = 32;
    $y1 = tdm_draw_sheet($pdf, $margin, $top, $cardW, $a, $b, $ev, $level, $m1);
    $y2 = tdm_draw_sheet($pdf, 2 * $margin + $cardW, $top, $cardW, $b, $a, $ev, $level, $m1);
    // Arbitre, sous les deux feuilles.
    $pdf->SetXY($margin, max($y1, $y2) + 6);
    $pdf->SetFont($pdf->FontStd, 'I', 7);
    $pdf->Cell($pdf->GetPageWidth() - 2 * $margin - 90, 8, 'Signature de l\'arbitre', 'B', 0, 'L');
    $pdf->Cell(90, 8, 'Heure de signature (hh:mm)', 1, 1, 'L');
}
$pdf->Output('Feuilles ' . $code . ($group ? " poule $group" : '') . '.pdf', 'I');
