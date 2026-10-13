<?php
/**
 * pdf-poules.php — TDM : tableaux de poules (PDF au format IANSEO, en-tête et pied).
 *
 * Comme la feuille « Fiche matchs poule de 4 / 6 » du classeur et l'impression
 * « poules & classements » du module TNM : pour chaque poule, le tableau des
 * duels rencontre par rencontre (cible, équipe avec son n° de tirage, volées,
 * barrage, total), puis le classement de la poule (qualif, points et sets de
 * chaque match, total, rang). Les résultats déjà saisis sont reportés ; avant
 * les matchs, le tableau sert de fiche vierge.
 *
 *   pdf-poules.php?ev=CL&group=2   une poule
 *   pdf-poules.php?ev=CL           toutes les poules de la catégorie, une par page
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/pools.php';
require_once HTDOCS . '/Common/pdf/ResultPDF.inc.php';
tdm_require(AclRobin, AclReadOnly);

$events = tdm_events();
$code = (string) ($_GET['ev'] ?? '');
if (!isset($events[$code])) exit('Catégorie inconnue.');
$ev = $events[$code];
$only = max(0, intval($_GET['group'] ?? 0));

$tour = StrSafe_DB($TDM_TOUR);
$evSql = StrSafe_DB($code);
$rs = safe_r_sql("SELECT RrLevEnds, RrLevWinPoints FROM RoundRobinLevel
    WHERE RrLevTournament = $tour AND RrLevTeam = 1 AND RrLevEvent = $evSql AND RrLevLevel = 1");
$level = safe_fetch($rs);
if (!$level) exit('Poules non configurées.');
$ends = (int) $level->RrLevEnds;

$pools = tdm_pools($ev);

// Matchs avec le n° de tirage (place dans la poule) de chaque équipe.
$rs = safe_r_sql("SELECT RrMatchGroup, RrMatchRound, RrMatchMatchNo, RrMatchTarget, RrMatchAthlete, RrMatchSubTeam,
        RrMatchSetScore, RrMatchSetPoints, RrMatchTbDecoded, RrMatchWinLose, RrMatchRoundPoints, RrGridItem, CoName
    FROM RoundRobinMatches
    LEFT JOIN RoundRobinGrids ON RrGridTournament = RrMatchTournament AND RrGridTeam = RrMatchTeam AND RrGridEvent = RrMatchEvent
        AND RrGridLevel = RrMatchLevel AND RrGridGroup = RrMatchGroup AND RrGridRound = RrMatchRound AND RrGridMatchno = RrMatchMatchNo
    LEFT JOIN Countries ON CoId = RrMatchAthlete AND CoTournament = RrMatchTournament
    WHERE RrMatchTournament = $tour AND RrMatchTeam = 1 AND RrMatchEvent = $evSql AND RrMatchLevel = 1"
    . ($only ? " AND RrMatchGroup = $only" : '') . "
    ORDER BY RrMatchGroup, RrMatchRound, RrMatchMatchNo");
$rounds = []; // poule => tour => [ [m1, m2], ... ]
$perTeam = []; // poule => clé équipe => tour => [points, sets]
while ($r = safe_fetch($rs)) {
    $g = (int) $r->RrMatchGroup;
    $rounds[$g][(int) $r->RrMatchRound][intdiv((int) $r->RrMatchMatchNo, 2)][(int) $r->RrMatchMatchNo % 2] = $r;
}
/**
 * Rencontre jouée ? Dès qu'une des deux équipes l'a gagnée. Contre un bye,
 * IANSEO marque le match gagné dès la validation, avec 0 : il ne compte qu'une
 * fois le 5-0 appliqué (page Poules), sinon les cases restent vides.
 */
function tdm_pdf_done(array $pair)
{
    $bye = !(int) ($pair[0]->RrMatchAthlete ?? 0) || !(int) ($pair[1]->RrMatchAthlete ?? 0);
    if ($bye) return (int) ($pair[0]->RrMatchSetScore ?? 0) > 0 || (int) ($pair[1]->RrMatchSetScore ?? 0) > 0;
    return (int) ($pair[0]->RrMatchWinLose ?? 0) || (int) ($pair[1]->RrMatchWinLose ?? 0);
}
// Résultat de chaque équipe par rencontre.
foreach ($rounds as $g => $byRound) {
    foreach ($byRound as $round => $pairs) {
        foreach ($pairs as $pair) {
            $done = tdm_pdf_done($pair);
            foreach ($pair as $m) {
                if (!(int) $m->RrMatchAthlete) continue;
                $perTeam[$g][$m->RrMatchAthlete . '-' . $m->RrMatchSubTeam][$round] = $done
                    ? [(int) $m->RrMatchRoundPoints, (int) $m->RrMatchSetScore] : null;
            }
        }
    }
}

$pdf = new ResultPDF('Tableaux de poules — ' . $ev->Name, true);
$W = $pdf->GetPageWidth() - 2 * $pdf->getSideMargin();
$limit = $pdf->GetPageHeight() - 22; // au-dessus du pied de page IANSEO
$rowH = 6;

/** Bandeau bleu FFTA, texte blanc. */
function tdm_band($pdf, $w, $h, $text, $size, $fill = [0, 43, 146])
{
    $pdf->SetFillColor(...$fill);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf->FontStd, 'B', $size);
    $pdf->Cell($w, $h, $text, 1, 1, 'C', 1);
    $pdf->SetDefaultColor();
}
/** Saut de page si $need mm ne tiennent plus. */
function tdm_room($pdf, $need, $limit)
{
    if ($pdf->GetY() + $need > $limit) $pdf->AddPage();
}

$first = true;
foreach ($pools as $g => $pool) {
    if ($only && $g !== $only) continue;
    if (!$first) $pdf->AddPage();
    $first = false;

    tdm_band($pdf, $W, 9, $ev->Name . '  —  Poule ' . $g, 16);
    $pdf->SetFont($pdf->FontStd, 'I', 8);
    $pdf->Cell($W, 5, 'Matchs en sets : 4 volées de 4 flèches, set gagné = 2 points, nul = 1 ; la 1re équipe à 5 gagne, à 4-4 barrage. '
        . 'Bye = victoire 5-0.', 0, 1, 'C');
    $pdf->Ln(1);

    if (empty($rounds[$g]) || !$pool->Rows) {
        $pdf->SetFont($pdf->FontStd, '', 11);
        $pdf->Cell($W, 10, 'Poule pas encore remplie : faites la validation qualif → poules.', 0, 1, 'C');
        continue;
    }

    // ── Tableau des duels ─────────────────────────────────────────────────────
    $tgtW = 16;
    $endW = 15;
    $tbW = 18;
    $totW = 18;
    $nameW = $W - $tgtW - $ends * $endW - $tbW - $totW;
    foreach ($rounds[$g] as $round => $pairs) {
        tdm_room($pdf, 6 + 6 + count($pairs) * (2 * $rowH + 1.5), $limit);
        $pdf->SetFillColor(225, 228, 235);
        $pdf->SetFont($pdf->FontStd, 'B', 9);
        $pdf->Cell($tgtW, 6, 'Cible', 1, 0, 'C', 1);
        $pdf->Cell($nameW, 6, 'Rencontre ' . $round, 1, 0, 'L', 1);
        for ($e = 1; $e <= $ends; $e++) $pdf->Cell($endW, 6, 'Volée ' . $e, 1, 0, 'C', 1);
        $pdf->Cell($tbW, 6, 'Barrage', 1, 0, 'C', 1);
        $pdf->Cell($totW, 6, 'Total', 1, 1, 'C', 1);
        $pdf->SetDefaultColor();
        foreach ($pairs as $pair) {
            $bye = !(int) ($pair[0]->RrMatchAthlete ?? 0) || !(int) ($pair[1]->RrMatchAthlete ?? 0);
            foreach ([0, 1] as $side) {
                $m = $pair[$side] ?? null;
                if (!$m) continue;
                $isTeam = (int) $m->RrMatchAthlete > 0;
                $name = $isTeam ? tdm_team_name((string) $m->CoName, max(1, (int) $m->RrMatchSubTeam)) : 'bye';
                $done = tdm_pdf_done($pair);
                $pdf->SetFont($pdf->FontStd, 'B', 10);
                $pdf->Cell($tgtW, $rowH, $bye ? '' : ltrim((string) $m->RrMatchTarget, '0'), 1, 0, 'C');
                $pdf->SetFont($pdf->FontStd, $isTeam ? ((int) $m->RrMatchWinLose ? 'B' : '') : 'I', 10);
                $pdf->Cell($nameW, $rowH, ($m->RrGridItem ? '(' . $m->RrGridItem . ')  ' : '') . $name, 1, 0, 'L');
                $pdf->SetFont($pdf->FontStd, '', 10);
                $sets = array_values(array_filter(explode('|', (string) $m->RrMatchSetPoints), 'strlen'));
                $pdf->SetFillColor(238, 238, 238);
                for ($e = 0; $e < $ends; $e++) {
                    $pdf->Cell($endW, $rowH, $bye ? '' : ($sets[$e] ?? ''), 1, 0, 'C', $bye ? 1 : 0);
                }
                $pdf->Cell($tbW, $rowH, $bye ? '' : (string) $m->RrMatchTbDecoded, 1, 0, 'C', $bye ? 1 : 0);
                $pdf->SetFont($pdf->FontStd, 'B', 11);
                $total = $done ? (string) (int) $m->RrMatchSetScore : '';
                $pdf->Cell($totW, $rowH, $isTeam ? $total : '', 1, 1, 'C');
            }
            $pdf->Ln(1.5);
        }
        $pdf->Ln(2);
    }

    // ── Classement de la poule ────────────────────────────────────────────────
    $n = count($rounds[$g]);
    tdm_room($pdf, 16 + count($pool->Rows) * $rowH, $limit);
    $pdf->Ln(2);
    tdm_band($pdf, $W, 7, 'Classement de la poule ' . $g, 11);
    $rkW = 12;
    $qW = 16;
    $mW = 18;
    $tW = 18;
    $nmW = $W - $rkW - $qW - $n * $mW - 2 * $tW;
    $pdf->SetFillColor(225, 228, 235);
    $pdf->SetFont($pdf->FontStd, 'B', 8);
    $pdf->Cell($rkW, 9, 'Rang', 1, 0, 'C', 1);
    $pdf->Cell($nmW, 9, 'Équipe', 1, 0, 'L', 1);
    $pdf->Cell($qW, 9, 'Qualif', 1, 0, 'C', 1);
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    for ($r = 1; $r <= $n; $r++) {
        $pdf->SetXY($x + ($r - 1) * $mW, $y);
        $pdf->Cell($mW, 4.5, 'Rencontre ' . $r, 1, 0, 'C', 1);
        $pdf->SetXY($x + ($r - 1) * $mW, $y + 4.5);
        $pdf->Cell($mW / 2, 4.5, 'Pts', 1, 0, 'C', 1);
        $pdf->Cell($mW / 2, 4.5, 'Sets', 1, 0, 'C', 1);
    }
    $pdf->SetXY($x + $n * $mW, $y);
    $pdf->Cell($tW, 9, 'Points', 1, 0, 'C', 1);
    $pdf->Cell($tW, 9, 'Pts de sets', 1, 1, 'C', 1);
    $pdf->SetDefaultColor();
    foreach ($pool->Rows as $row) {
        $pdf->SetFont($pdf->FontStd, 'B', 11);
        $pdf->Cell($rkW, $rowH, $pool->Played ? ($row->Rank ?: '') : '', 1, 0, 'C');
        $pdf->Cell($nmW, $rowH, '(' . tdm_pool_item($rounds[$g], $row) . ')  ' . $row->Name, 1, 0, 'L');
        $pdf->SetFont($pdf->FontStd, '', 10);
        $pdf->Cell($qW, $rowH, $row->Qualif ?: '', 1, 0, 'C');
        for ($r = 1; $r <= $n; $r++) {
            $res = $perTeam[$g][$row->Key][$r] ?? null;
            $pdf->Cell($mW / 2, $rowH, $res ? $res[0] : '', 1, 0, 'C');
            $pdf->Cell($mW / 2, $rowH, $res ? $res[1] : '', 1, 0, 'C');
        }
        $pdf->SetFont($pdf->FontStd, 'B', 11);
        $pdf->Cell($tW, $rowH, $pool->Played ? $row->Points : '', 1, 0, 'C');
        $pdf->Cell($tW, $rowH, $pool->Played ? $row->SetPoints : '', 1, 1, 'C');
    }
    $pdf->SetFont($pdf->FontStd, 'I', 8);
    $pdf->Cell($W, 5, 'Victoire = ' . (int) $level->RrLevWinPoints . ' points, défaite = 0. Égalité de points : points de set, puis score de qualif, puis barrage.', 0, 1, 'L');
}

/** N° de tirage d'une équipe dans sa poule (place dans la grille). */
function tdm_pool_item(array $rounds, $row)
{
    foreach ($rounds as $pairs) {
        foreach ($pairs as $pair) {
            foreach ($pair as $m) {
                if ((int) $m->RrMatchAthlete === $row->CoId && (int) $m->RrMatchSubTeam === $row->SubTeam) return (int) $m->RrGridItem;
            }
        }
    }
    return '';
}

$pdf->Output('Tableaux de poules ' . $code . ($only ? " poule $only" : '') . '.pdf', 'I');
