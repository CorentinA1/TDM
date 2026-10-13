<?php
/**
 * pdf-pasdetir.php — TDM : plan du pas de tir (PDF au format IANSEO).
 *
 * Dans l'esprit du « Field of Play » natif (Final/FopSetup.php, Fun_Scheduler::FOP) :
 * numéros de cibles en colonnes, une ligne par phase (qualification, poules,
 * Big Shoot Off), un bloc de couleur par catégorie avec son blason. Le natif
 * s'appuie sur le programme horaire (scheduler), que le TDM n'utilise pas :
 * ici les blocs viennent des cibles réellement attribuées (cibles.php, étape 4
 * de la configuration, page Big Shoot Off).
 *
 * Les blasons sont dessinés avec les images du « Plan de cible visuel » natif
 * (module DragDropTarget) : Common/Images/Targets/N.svg, N = type de cible IANSEO
 * (TargetFaces.TfT1, Events.EvFinalTargetType ; 1 = blason complet, 2 = trispot
 * classique, 4 = trispot poulies…).
 *
 * Disposition des blasons sur une cible (règlement 2026, section 3) : équipe 1
 * (position A) à gauche, équipe 2 (B) à droite ; NEO et arc nu : un blason 60 cm
 * par équipe ; arc classique : un blason 40 cm par archer, l'un au-dessus de
 * l'autre ; poulies : un trispot vertical par archer, deux côte à côte, le spot
 * du bas barré (seuls les 4 spots du haut servent).
 *
 * Page 1 : le plan. Page suivante : l'affectation des cibles de qualification
 * (équipes A et B de chaque cible, archers, blason). Avec des départs
 * (configuration, étape 2), un plan par départ : chacun réutilise le pas de tir.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/qualif.php';
require_once __DIR__ . '/lib/targets.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/svg.php';
require_once HTDOCS . '/Common/pdf/ResultPDF.inc.php';
tdm_require(AclQualification, AclReadOnly);
tdm_schema();

$events = tdm_events();
$tour = StrSafe_DB($TDM_TOUR);

/** Couleur de chaque catégorie (pastels lisibles en noir et blanc). */
const TDM_CAT_COLORS = ['NEO' => [190, 235, 190], 'CL' => [185, 205, 245], 'CO' => [255, 210, 160], 'BB' => [245, 190, 220]];
function tdm_cat_color($code)
{
    return TDM_CAT_COLORS[$code] ?? [220, 220, 220];
}

/** Blason d'une épreuve d'équipe (type IANSEO 4 = trispot, sinon blason complet). */
function tdm_event_face($ev)
{
    $rs = safe_r_sql("SELECT EvFinalTargetType, EvTargetSize FROM Events WHERE EvTournament = " . StrSafe_DB($GLOBALS['TDM_TOUR'])
        . " AND EvTeamEvent = 1 AND EvCode = " . StrSafe_DB($ev->EvCode));
    $r = safe_fetch($rs);
    if (!$r) return '';
    return ((int) $r->EvFinalTargetType === 4 ? 'Trispot ' : 'Blason ') . (int) $r->EvTargetSize . ' cm';
}

/** Type de cible IANSEO d'une épreuve d'équipe (EvFinalTargetType). */
function tdm_event_face_type($ev)
{
    $rs = safe_r_sql("SELECT EvFinalTargetType FROM Events WHERE EvTournament = " . StrSafe_DB($GLOBALS['TDM_TOUR'])
        . " AND EvTeamEvent = 1 AND EvCode = " . StrSafe_DB($ev->EvCode));
    $r = safe_fetch($rs);
    return $r ? (int) $r->EvFinalTargetType : 0;
}

/**
 * Dessine l'image native d'un type de cible, centrée dans la case ($x, $y, $w, $h),
 * proportions du viewBox respectées. Sans image pour ce type : rien.
 *
 * @return array{0: float, 1: float, 2: float, 3: float}|null rectangle dessiné
 */
function tdm_face_image($pdf, $type, $x, $y, $w, $h)
{
    global $CFG;
    static $sizes = [], $svg = [];
    $file = $CFG->DOCUMENT_PATH . 'Common/Images/Targets/' . (int) $type . '.svg';
    if (!$type || !is_file($file) || $w <= 0 || $h <= 0) return null;
    if (!isset($sizes[$type])) {
        $raw = (string) file_get_contents($file);
        $sizes[$type] = preg_match('/viewBox="[\d.\-]+ [\d.\-]+ ([\d.]+) ([\d.]+)"/', $raw, $m) ? [(float) $m[1], (float) $m[2]] : [1, 1];
        // TCPDF ignore la feuille de style interne de ces images : couleurs recopiées sur chaque zone.
        $svg[$type] = '@' . tdm_svg_inline_css($raw);
    }
    [$vw, $vh] = $sizes[$type];
    $scale = min($w / $vw, $h / $vh);
    $iw = $vw * $scale;
    $ih = $vh * $scale;
    $pdf->ImageSVG($svg[$type], $x + ($w - $iw) / 2, $y + ($h - $ih) / 2, $iw, $ih);
    return [$x + ($w - $iw) / 2, $y + ($h - $ih) / 2, $iw, $ih];
}

/** Disposition des blasons d'une équipe sur la cible, par catégorie (règlement, section 3). */
const TDM_BUTT_LAYOUT = ['NEO' => 'single', 'BB' => 'single', 'CL' => 'stack', 'CO' => 'pair'];

/**
 * Dessine une cible (carré $x, $y, $s) comme le règlement : équipe A à gauche,
 * équipe B à droite, blasons selon la catégorie. Une position sans équipe est
 * dessinée pâle.
 *
 * @param array{A: bool, B: bool} $occupied
 */
function tdm_draw_butt($pdf, $x, $y, $s, $layout, $type, array $occupied)
{
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(160, 160, 160);
    $pdf->Rect($x, $y, $s, $s, 'DF');
    $pdf->SetDefaultColor();
    $half = $s / 2;
    $pad = $s * 0.04;
    foreach (['A' => 0, 'B' => 1] as $pos => $i) {
        $hx = $x + $i * $half;
        if (empty($occupied[$pos])) $pdf->SetAlpha(0.22);
        switch ($layout) {
            case 'stack':   // un blason par archer, l'un au-dessus de l'autre
                tdm_face_image($pdf, $type, $hx + $pad, $y + $pad, $half - 2 * $pad, $half - $pad);
                tdm_face_image($pdf, $type, $hx + $pad, $y + $half, $half - 2 * $pad, $half - $pad);
                break;
            case 'pair':    // un trispot par archer, côte à côte, spot du bas barré
                foreach ([0, 1] as $j) {
                    $r = tdm_face_image($pdf, $type, $hx + $pad + $j * ($half - 2 * $pad) / 2, $y + $pad, ($half - 2 * $pad) / 2, $s - 2 * $pad);
                    if ($r) {
                        [$ix, $iy, $iw, $ih] = $r;
                        $top = $iy + $ih * 110 / 160;   // 3e spot : de 110 à 160 sur 160
                        $pdf->SetLineWidth(0.15);
                        $pdf->Line($ix, $top, $ix + $iw, $iy + $ih);
                        $pdf->Line($ix + $iw, $top, $ix, $iy + $ih);
                        $pdf->SetLineWidth(0.2);
                    }
                }
                break;
            default:        // un blason par équipe
                tdm_face_image($pdf, $type, $hx + $pad, $y + $pad, $half - 2 * $pad, $s - 2 * $pad);
        }
        if (empty($occupied[$pos])) $pdf->SetAlpha(1);
    }
    // Séparation des deux équipes.
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line($x + $half, $y + $pad, $x + $half, $y + $s - $pad);
    $pdf->SetDefaultColor();
}

require_once __DIR__ . '/lib/departs.php';

// Départs : un plan par départ (chaque départ réutilise le pas de tir) ; sans départs, un seul plan.
$depMap = tdm_departs_map();
$sessions = tdm_qualif_sessions();
$groups = [];
foreach ($events as $code => $ev) $groups[$depMap ? tdm_event_session($code) : 0][] = $code;
ksort($groups);

$faces = [];       // EnId => nom du blason
$faceType = [];    // EnId => type de cible IANSEO (image)
$rs = safe_r_sql("SELECT EnId, TfName, TfT1 FROM Entries LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
    WHERE EnTournament = $tour");
while ($r = safe_fetch($rs)) {
    $faces[(int) $r->EnId] = (string) $r->TfName;
    $faceType[(int) $r->EnId] = (int) $r->TfT1;
}

$pdf = new ResultPDF('Plan du pas de tir', false);
$left = $pdf->getSideMargin();
$W = $pdf->GetPageWidth() - 2 * $left;
$firstGroup = true;

foreach ($groups as $ses => $codes) {
    $grpEvents = array_intersect_key($events, array_flip($codes));
    $depTitle = $ses ? tdm_depart_label($ses, $sessions[$ses] ?? []) : '';
    // ── Données : qualification ───────────────────────────────────────────────────
    $qualif = [];      // cible => ['A' => [ev, team], 'B' => ...]
    foreach ($grpEvents as $code => $ev) {
        $teams = tdm_event_teams($ev);
        foreach (tdm_team_targets($teams) as $key => [$target, $pos]) {
            if ($target) $qualif[$target][$pos] = [$ev, $teams[$key]];
        }
    }
    ksort($qualif);

    // ── Données : poules et BSO ───────────────────────────────────────────────────
    $pools = [];       // [ev, from, to, label]
    $rs = safe_r_sql("SELECT RrMatchEvent, RrMatchGroup, MIN(CAST(RrMatchTarget AS UNSIGNED)) AS T1, MAX(CAST(RrMatchTarget AS UNSIGNED)) AS T2
        FROM RoundRobinMatches WHERE RrMatchTournament = $tour AND RrMatchTeam = 1 AND RrMatchLevel = 1 AND RrMatchTarget <> ''
        GROUP BY RrMatchEvent, RrMatchGroup ORDER BY T1");
    while ($r = safe_fetch($rs)) {
        if (isset($grpEvents[$r->RrMatchEvent]) && (int) $r->T1) $pools[] = [$grpEvents[$r->RrMatchEvent], (int) $r->T1, (int) $r->T2, 'Poule ' . $r->RrMatchGroup,
            tdm_event_face_type($grpEvents[$r->RrMatchEvent])];
    }
    $bso = [];
    $rs = safe_r_sql("SELECT BtEvent, MIN(BtTarget) AS T1, MAX(BtTarget) AS T2 FROM TdmBsoTeams WHERE BtTournament = $tour AND BtTarget > 0
        GROUP BY BtEvent ORDER BY T1");
    while ($r = safe_fetch($rs)) {
        if (isset($grpEvents[$r->BtEvent])) $bso[] = [$grpEvents[$r->BtEvent], (int) $r->T1, (int) $r->T2, 'BSO', tdm_event_face_type($grpEvents[$r->BtEvent])];
    }

    // Ordre des catégories (NEO, CL, CO, BB), puis des cibles.
    $order = array_flip(array_keys($events));
    $byCat = fn($a, $b) => [$order[$a[0]->EvCode], $a[1]] <=> [$order[$b[0]->EvCode], $b[1]];
    usort($pools, $byCat);
    usort($bso, $byCat);

    // Qualif en blocs : cibles consécutives d'une même catégorie.
    $qBlocks = [];
    foreach ($qualif as $target => $slots) {
        $cats = array_unique(array_map(fn($s) => $s[0]->EvCode, $slots));
        $code = count($cats) === 1 ? reset($cats) : '';
        $occ = ['A' => isset($slots['A']), 'B' => isset($slots['B'])];
        // Blason de la cible : celui des archers de la première équipe (qualif = blason individuel).
        $first = reset($slots)[1]->Members[0] ?? null;
        $type = $first ? ($faceType[(int) $first->EnId] ?? 0) : 0;
        // Archers sans blason IANSEO : celui de l'épreuve.
        if (!$type && $code) $type = tdm_event_face_type($grpEvents[$code]);
        $last = end($qBlocks);
        if ($last && $last[2] === $target - 1 && $last[0] && $code && $last[0]->EvCode === $code && $last[4] === $type) {
            $qBlocks[key($qBlocks)][2] = $target;
            $qBlocks[key($qBlocks)][5][$target] = $occ;
        } else {
            $qBlocks[] = [$code ? $grpEvents[$code] : null, $target, $target, $code ? '' : 'mixte', $type, [$target => $occ]];
        }
    }

    $maxTarget = max(1, $qualif ? max(array_keys($qualif)) : 0, $pools ? max(array_column($pools, 2)) : 0, $bso ? max(array_column($bso, 2)) : 0);
    // Tout le pas de tir : les cibles de la session du départ (jusqu'à 40 pour rester lisible).
    $maxTarget = max($maxTarget, min(40, (int) ($sessions[$ses]['targets'] ?? ($sessions ? reset($sessions)['targets'] : 0))));
    $firstTarget = 1;

    // ── Plan ──────────────────────────────────────────────────────────────────────
    if (!$firstGroup) $pdf->AddPage();
    $firstGroup = false;
    $labelW = 34;
    $n = $maxTarget - $firstTarget + 1;
    $cw = ($W - $labelW) / $n;
    // Hauteur d'une ligne : libellé (9,5 mm) + une cible carrée de la largeur d'une colonne.
    $rowH = max(18, min($cw - 1.2, 30) + 11);

    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell($W, 8, 'Plan du pas de tir' . ($depTitle ? ' — ' . $depTitle : ''), 0, 1, 'C');
    // Horaire du départ : entraînement (échauffement de la session IANSEO) et début du tir.
    $sched = tdm_depart_schedule($sessions[$ses ?: (array_key_first($sessions) ?? 0)] ?? []);
    if ($sched) {
        $pdf->SetFont($pdf->FontStd, '', 10);
        $pdf->Cell($W, 6, $sched, 0, 1, 'C');
    }
    $pdf->Ln(2);

    // En-tête : numéros de cibles.
    $y = $pdf->GetY();
    $pdf->SetFillColor(0, 43, 146);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont($pdf->FontStd, 'B', $n > 30 ? 6 : 8);
    $pdf->Cell($labelW, 7, 'Cible', 1, 0, 'C', 1);
    for ($t = $firstTarget; $t <= $maxTarget; $t++) $pdf->Cell($cw, 7, $t, 1, 0, 'C', 1);
    $pdf->Ln();
    $pdf->SetDefaultColor();

    $phases = [
        'Qualification' => $qBlocks,
        'Poules' => $pools,
        'Big Shoot Off' => $bso,
    ];
    foreach ($phases as $label => $blocks) {
        // Blocs qui se chevauchent (catégories tirées l'une après l'autre sur les mêmes cibles) :
        // chacun sur sa propre ligne dans la phase.
        $lanes = [];
        foreach ($blocks as $i => $bl) {
            $lane = 0;
            while (isset($lanes[$lane]) && array_filter($lanes[$lane], fn($o) => $o[1] <= $bl[2] && $bl[1] <= $o[2])) $lane++;
            $lanes[$lane][] = $bl;
        }
        $laneH = $rowH;
        $h = max(1, count($lanes)) * $laneH;
        if ($pdf->GetY() + $h > $pdf->GetPageHeight() - 40) $pdf->AddPage();
        $y = $pdf->GetY();
        $pdf->SetFillColor(225, 228, 235);
        $pdf->SetFont($pdf->FontStd, 'B', 9);
        $pdf->MultiCell($labelW, $h, $label, 1, 'C', 1, 0, $left, $y, true, 0, false, true, $h, 'M');
        // Grille vide.
        for ($t = $firstTarget; $t <= $maxTarget; $t++) {
            $pdf->Rect($left + $labelW + ($t - $firstTarget) * $cw, $y, $cw, $h);
        }
        foreach ($lanes as $l => $laneBlocks) {
            $ly = $y + $l * $laneH;
            foreach ($laneBlocks as $block) {
                [$ev, $from, $to, $sub, $type] = $block;
                $occupancy = $block[5] ?? null;   // qualif : positions occupées ; poules, BSO : les deux
                $x = $left + $labelW + ($from - $firstTarget) * $cw;
                $w = ($to - $from + 1) * $cw;
                $pdf->SetFillColor(...($ev ? tdm_cat_color($ev->EvCode) : [230, 230, 230]));
                $pdf->Rect($x + 0.4, $ly + 0.4, $w - 0.8, $laneH - 0.8, 'DF');
                $text = ($ev ? $ev->EvCode : '') . ($sub ? ($ev ? ' · ' : '') . $sub : '');
                $pdf->SetFont($pdf->FontStd, 'B', $w < 14 ? 6 : 8);
                $pdf->SetXY($x, $ly + 1.2);
                $pdf->Cell($w, 4.5, $text, 0, 2, 'C');
                $pdf->SetFont($pdf->FontStd, '', $w < 14 ? 5 : 7);
                $pdf->Cell($w, 3.5, $from === $to ? "cible $from" : "cibles $from-$to", 0, 2, 'C');
                // Chaque cible disposée comme le règlement (images natives du plan de cible visuel).
                $s = min($cw - 1.2, $laneH - 11);  // cible carrée
                $layout = TDM_BUTT_LAYOUT[$ev->EvCode ?? ''] ?? 'single';
                for ($t = $from; $t <= $to; $t++) {
                    $occ = $occupancy !== null ? ($occupancy[$t] ?? ['A' => false, 'B' => false]) : ['A' => true, 'B' => true];
                    tdm_draw_butt($pdf, $left + $labelW + ($t - $firstTarget) * $cw + ($cw - $s) / 2, $ly + 9.5, $s, $layout, $type, $occ);
                }
            }
        }
        if (!$blocks) {
            $pdf->SetFont($pdf->FontStd, 'I', 8);
            $pdf->SetXY($left + $labelW, $y + $h / 2 - 2);
            $pdf->Cell($W - $labelW, 4, $label === 'Qualification' ? 'Cibles non attribuées (Qualifications › Attribution des cibles)'
                : ($label === 'Poules' ? 'Cibles non attribuées (Configuration, étape 4)' : 'BSO non configuré'), 0, 0, 'C');
        }
        $pdf->SetXY($left, $y + $h);
    }
    // Légende.
    $pdf->Ln(5);
    $pdf->SetFont($pdf->FontStd, 'B', 9);
    $pdf->Cell(20, 5, 'Légende :', 0, 0, 'L');
    foreach ($grpEvents as $code => $ev) {
        $pdf->SetFillColor(...tdm_cat_color($code));
        $pdf->Rect($pdf->GetX(), $pdf->GetY() + 0.8, 4, 3.4, 'DF');
        tdm_face_image($pdf, tdm_event_face_type($ev), $pdf->GetX() + 5, $pdf->GetY() - 0.5, 4, 6);
        $pdf->SetX($pdf->GetX() + 10);
        $pdf->SetFont($pdf->FontStd, '', 9);
        $pdf->Cell(57, 5, $ev->Name . ' — ' . tdm_event_face($ev), 0, 0, 'L');
    }
    $pdf->Ln(8);
    $pdf->SetFont($pdf->FontStd, 'I', 8);
    $pdf->Cell($W, 4, 'Deux équipes par cible : équipe A à gauche, équipe B à droite (blasons disposés comme le règlement, section 3 ; place vide en pâle). '
            . 'Poules : chaque match garde sa cible pendant toute la poule.', 0, 1, 'L');

    // ── Page 2 : affectation des cibles de qualification ─────────────────────────
    if ($qualif) {
        $pdf->AddPage();
        $pdf->SetFont($pdf->FontStd, 'B', 14);
        $pdf->Cell($W, 8, 'Qualification — affectation des cibles' . ($depTitle ? ' — ' . $depTitle : ''), 0, 1, 'C');
        $pdf->Ln(1);
        $cols = ['Cible' => 16, 'Catégorie' => 34, 'Équipe A' => 0, 'Équipe B' => 0, 'Blason' => 46];
        $free = ($W - 16 - 34 - 46) / 2;
        $cols['Équipe A'] = $cols['Équipe B'] = $free;
        $header = function () use ($pdf, $cols) {
            $pdf->SetFillColor(0, 43, 146);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont($pdf->FontStd, 'B', 9);
            foreach ($cols as $c => $w) $pdf->Cell($w, 6, $c, 1, 0, 'C', 1);
            $pdf->Ln();
            $pdf->SetDefaultColor();
        };
        $header();
        $limit = $pdf->GetPageHeight() - 22;
        foreach ($qualif as $target => $slots) {
            if ($pdf->GetY() + 10 > $limit) { $pdf->AddPage(); $header(); }
            $cats = array_unique(array_map(fn($s) => $s[0]->Name, $slots));
            $code = reset($slots)[0]->EvCode;
            $face = $types = [];
            foreach ($slots as $s) foreach ($s[1]->Members as $m) {
                if (!empty($faces[(int) $m->EnId])) $face[] = $faces[(int) $m->EnId];
                if (!empty($faceType[(int) $m->EnId])) $types[] = $faceType[(int) $m->EnId];
            }
            $pdf->SetFillColor(...tdm_cat_color($code));
            $pdf->SetFont($pdf->FontStd, 'B', 12);
            $pdf->Cell($cols['Cible'], 10, $target, 1, 0, 'C', 1);
            $pdf->SetFont($pdf->FontStd, '', 8);
            $pdf->Cell($cols['Catégorie'], 10, implode(' / ', $cats), 1, 0, 'C');
            foreach (['A', 'B'] as $p) {
                $x = $pdf->GetX();
                $y = $pdf->GetY();
                $pdf->Rect($x, $y, $free, 10);
                if (isset($slots[$p])) {
                    $t = $slots[$p][1];
                    $pdf->SetFont($pdf->FontStd, 'B', 9);
                    $pdf->SetXY($x + 1, $y + 0.8);
                    $pdf->Cell($free - 2, 4.5, "$target$p  ·  " . $t->Name, 0, 2, 'L');
                    $pdf->SetFont($pdf->FontStd, '', 7);
                    $pdf->Cell($free - 2, 4, implode(' – ', array_map(fn($m) => trim($m->EnFirstName . ' ' . $m->EnName), $t->Members)), 0, 0, 'L');
                }
                $pdf->SetXY($x + $free, $y);
            }
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->Rect($x, $y, $cols['Blason'], 10);
            if ($types) tdm_face_image($pdf, reset($types), $x + 1, $y + 1, 8, 8);
            $pdf->SetFont($pdf->FontStd, '', 8);
            $pdf->SetXY($x + 10, $y);
            $pdf->Cell($cols['Blason'] - 10, 10, implode(' / ', array_unique($face)), 0, 1, 'L');
        }
    }
}

$pdf->Output('Plan du pas de tir.pdf', 'I');
