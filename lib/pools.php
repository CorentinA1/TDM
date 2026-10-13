<?php
/**
 * lib/pools.php — poules (Round Robin, niveau 1) : byes et départages.
 *
 * Deux écarts entre IANSEO et le règlement TDM, corrigés ici :
 *
 * 1. Bye. IANSEO ne donne les points de victoire d'un bye que si l'opérateur
 *    coche « bye » dans la saisie, et jamais de points de set. Le règlement
 *    veut une victoire 5-0 : 2 points et 5 points de set.
 * 2. Départage. Le classement de poule IANSEO ne trie que sur les points et
 *    deux critères (TB1, TB2) dont aucun n'est la qualification. Le règlement
 *    dit : points de set, puis score de qualif, puis barrage. Avec une seule
 *    phase de poules, la page « Validation tour » ne sait pas non plus
 *    départager une poule (elle cherche un tour suivant). Le module écrit donc
 *    le rang de poule lui-même, comme le fait la page native.
 *
 * Fonctions pures en tête (testées sans base), accès base ensuite.
 */

/** Points de set d'un bye : victoire 5-0. */
const TDM_BYE_SET_POINTS = 5;

/**
 * Départage d'une poule par le score de qualification. Fonction pure.
 *
 * @param array<string, array{rank: int, qualif: int}> $rows
 *        Clé d'équipe => rang de poule actuel (égalités = même rang) et score de qualif.
 * @return array{ranks: array<string, int>, ties: array<int, string[]>}
 *         ranks : nouveau rang de chaque équipe (inchangé hors égalité) ;
 *         ties : rang => clés encore à égalité (même score de qualif : barrage).
 */
function tdm_break_ties_by_qualif(array $rows)
{
    $byRank = [];
    foreach ($rows as $key => $r) {
        if ((int) $r['rank'] > 0) $byRank[(int) $r['rank']][] = (string) $key;
    }
    $ranks = [];
    foreach ($rows as $key => $r) $ranks[(string) $key] = (int) $r['rank'];
    $ties = [];

    foreach ($byRank as $rank => $keys) {
        if (count($keys) < 2) continue;
        usort($keys, fn($a, $b) => $rows[$b]['qualif'] <=> $rows[$a]['qualif']);
        // Rangs consécutifs à partir du rang partagé ; même score de qualif = encore ex aequo.
        $pos = $rank;
        $prevScore = null;
        $prevRank = $rank;
        foreach ($keys as $i => $key) {
            $score = (int) $rows[$key]['qualif'];
            $new = ($i > 0 && $score === $prevScore) ? $prevRank : $pos;
            $ranks[$key] = $new;
            $prevScore = $score;
            $prevRank = $new;
            $pos++;
        }
    }
    $shared = [];
    foreach ($ranks as $key => $rank) {
        if ($rank > 0) $shared[$rank][] = $key;
    }
    foreach ($shared as $rank => $keys) {
        if (count($keys) > 1) $ties[$rank] = $keys;
    }
    ksort($ties);
    return ['ranks' => $ranks, 'ties' => $ties];
}

/**
 * Contrôle un ordre saisi à la main pour des équipes à égalité (après barrage).
 * Fonction pure.
 *
 * @param int $rank Rang partagé par les équipes.
 * @param string[] $keys Équipes à égalité.
 * @param array<string, string> $posted Clé => ordre saisi (1, 2, 3…).
 * @return array<string, int>|null Clé => rang final, ou null si la saisie
 *         n'est pas un ordre complet (chaque position une seule fois).
 */
function tdm_manual_order($rank, array $keys, array $posted)
{
    $out = [];
    $seen = [];
    foreach ($keys as $key) {
        $v = trim((string) ($posted[$key] ?? ''));
        if (!ctype_digit($v)) return null;
        $o = (int) $v;
        if ($o < 1 || $o > count($keys) || isset($seen[$o])) return null;
        $seen[$o] = true;
        $out[$key] = $rank + $o - 1;
    }
    return $out;
}

// ── Accès base ────────────────────────────────────────────────────────────────

/**
 * Situation des poules d'une épreuve.
 *
 * @param object $event Épreuve (tdm_events()).
 * @return array<int, object> numéro de poule => Group, Rows (équipes triées par rang :
 *         Key, CoId, SubTeam, Name, Points, SetPoints, Rank, RankBefSO, Seed, Qualif),
 *         Matches, Played, Byes, ByesApplied.
 */
function tdm_pools($event)
{
    global $TDM_TOUR;
    $tour = StrSafe_DB($TDM_TOUR);
    $ev = StrSafe_DB($event->EvCode);

    $pools = [];
    for ($g = 1; $g <= $event->Pools; $g++) {
        $pools[$g] = (object) ['Group' => $g, 'Rows' => [], 'Matches' => 0, 'Played' => 0, 'Byes' => 0, 'ByesApplied' => 0];
    }

    $rs = safe_r_sql("SELECT RrPartGroup, RrPartParticipant, RrPartSubTeam, RrPartPoints, RrPartTieBreaker,
            RrPartGroupRank, RrPartGroupRankBefSO, RrPartSourceRank, CoName, COALESCE(TeScore, 0) AS TeScore
        FROM RoundRobinParticipants
        INNER JOIN Countries ON CoId = RrPartParticipant AND CoTournament = RrPartTournament
        LEFT JOIN Teams ON TeTournament = RrPartTournament AND TeEvent = RrPartEvent AND TeFinEvent = 1
            AND TeCoId = RrPartParticipant AND TeSubTeam = RrPartSubTeam
        WHERE RrPartTournament = $tour AND RrPartTeam = 1 AND RrPartEvent = $ev AND RrPartLevel = 1
            AND RrPartParticipant > 0
        ORDER BY RrPartGroup, IF(RrPartGroupRank = 0, 9999, RrPartGroupRank), RrPartSourceRank");
    while ($r = safe_fetch($rs)) {
        $g = (int) $r->RrPartGroup;
        if (!isset($pools[$g])) continue;
        $pools[$g]->Rows[] = (object) [
            'Key' => $r->RrPartParticipant . '-' . $r->RrPartSubTeam,
            'CoId' => (int) $r->RrPartParticipant, 'SubTeam' => (int) $r->RrPartSubTeam,
            'Name' => tdm_team_name($r->CoName, (int) $r->RrPartSubTeam),
            'Points' => (int) $r->RrPartPoints, 'SetPoints' => (int) $r->RrPartTieBreaker,
            'Rank' => (int) $r->RrPartGroupRank, 'RankBefSO' => (int) $r->RrPartGroupRankBefSO,
            'Seed' => (int) $r->RrPartSourceRank, 'Qualif' => (int) $r->TeScore,
        ];
    }

    // Matchs : une paire (n° pair, n° impair) par rencontre.
    $rs = safe_r_sql("SELECT a.RrMatchGroup AS G,
            (a.RrMatchAthlete > 0 AND b.RrMatchAthlete > 0) AS IsReal,
            (a.RrMatchWinLose + b.RrMatchWinLose > 0) AS Done,
            (a.RrMatchAthlete > 0) + (b.RrMatchAthlete > 0) AS Present,
            IF(a.RrMatchAthlete > 0, a.RrMatchRoundPoints, b.RrMatchRoundPoints) AS ByePoints,
            IF(a.RrMatchAthlete > 0, a.RrMatchSetScore, b.RrMatchSetScore) AS ByeSets,
            l.RrLevWinPoints AS WinPoints
        FROM RoundRobinMatches a
        INNER JOIN RoundRobinMatches b ON b.RrMatchTournament = a.RrMatchTournament AND b.RrMatchTeam = a.RrMatchTeam
            AND b.RrMatchEvent = a.RrMatchEvent AND b.RrMatchLevel = a.RrMatchLevel AND b.RrMatchGroup = a.RrMatchGroup
            AND b.RrMatchRound = a.RrMatchRound AND b.RrMatchMatchNo = a.RrMatchMatchNo + 1
        INNER JOIN RoundRobinLevel l ON l.RrLevTournament = a.RrMatchTournament AND l.RrLevTeam = a.RrMatchTeam
            AND l.RrLevEvent = a.RrMatchEvent AND l.RrLevLevel = a.RrMatchLevel
        WHERE a.RrMatchTournament = $tour AND a.RrMatchTeam = 1 AND a.RrMatchEvent = $ev AND a.RrMatchLevel = 1
            AND a.RrMatchMatchNo % 2 = 0");
    while ($r = safe_fetch($rs)) {
        $g = (int) $r->G;
        if (!isset($pools[$g])) continue;
        if ($r->IsReal) {
            $pools[$g]->Matches++;
            if ($r->Done) $pools[$g]->Played++;
        } elseif ((int) $r->Present === 1) {
            $pools[$g]->Byes++;
            if ((int) $r->ByePoints === (int) $r->WinPoints && (int) $r->ByeSets === TDM_BYE_SET_POINTS) {
                $pools[$g]->ByesApplied++;
            }
        }
    }
    return $pools;
}

/**
 * Pose « victoire 5-0 » sur chaque match de bye de l'épreuve, puis recalcule
 * le classement de chaque poule (cœur IANSEO).
 *
 * Les points de set comptent dans le départage selon le critère choisi pour
 * la poule : total des points de set (2) ou différentiel (5) prennent 5 ;
 * les autres critères ne sont pas touchés.
 *
 * @return int Nombre de matchs de bye modifiés.
 */
function tdm_apply_byes($event)
{
    global $TDM_TOUR;
    $tour = StrSafe_DB($TDM_TOUR);
    $ev = StrSafe_DB($event->EvCode);
    $five = TDM_BYE_SET_POINTS;
    $tb = "CASE %s WHEN 2 THEN $five WHEN 5 THEN $five ELSE 0 END";

    safe_w_sql("UPDATE RoundRobinMatches m
        INNER JOIN RoundRobinMatches o ON o.RrMatchTournament = m.RrMatchTournament AND o.RrMatchTeam = m.RrMatchTeam
            AND o.RrMatchEvent = m.RrMatchEvent AND o.RrMatchLevel = m.RrMatchLevel AND o.RrMatchGroup = m.RrMatchGroup
            AND o.RrMatchRound = m.RrMatchRound
            AND o.RrMatchMatchNo = IF(m.RrMatchMatchNo % 2 = 0, m.RrMatchMatchNo + 1, m.RrMatchMatchNo - 1)
        INNER JOIN RoundRobinLevel l ON l.RrLevTournament = m.RrMatchTournament AND l.RrLevTeam = m.RrMatchTeam
            AND l.RrLevEvent = m.RrMatchEvent AND l.RrLevLevel = m.RrMatchLevel
        SET m.RrMatchTie = 2, m.RrMatchWinLose = 1, m.RrMatchRoundPoints = l.RrLevWinPoints,
            m.RrMatchSetScore = $five,
            m.RrMatchTieBreaker = " . sprintf($tb, 'l.RrLevTieBreakSystem') . ",
            m.RrMatchTieBreaker2 = " . sprintf($tb, 'l.RrLevTieBreakSystem2') . "
        WHERE m.RrMatchTournament = $tour AND m.RrMatchTeam = 1 AND m.RrMatchEvent = $ev AND m.RrMatchLevel = 1
            AND m.RrMatchAthlete > 0 AND o.RrMatchAthlete = 0");
    $n = safe_w_affected_rows();
    tdm_recalc_pools($event);
    return $n;
}

/** Recalcule le classement de chaque poule de l'épreuve (cœur IANSEO). */
function tdm_recalc_pools($event)
{
    require_once HTDOCS . '/Modules/RoundRobin/Lib.php';
    for ($g = 1; $g <= $event->Pools; $g++) {
        calculateGroupRank((object) ['Team' => 1, 'Event' => $event->EvCode, 'Level' => 1, 'Group' => $g]);
    }
}

/**
 * Écrit les rangs de poule départagés (bouton « Départager par la qualif »
 * ou ordre saisi après barrage). Le rang avant départage (RankBefSO) reste
 * celui d'IANSEO ; une nouvelle saisie de match le recalcule et efface ce
 * départage, comme dans IANSEO.
 *
 * @param array<string, int> $ranks Clé « CoId-SubTeam » => rang de poule.
 */
function tdm_write_pool_ranks($event, $group, array $ranks)
{
    global $TDM_TOUR;
    foreach ($ranks as $key => $rank) {
        [$coId, $sub] = array_map('intval', explode('-', $key));
        safe_w_sql("UPDATE RoundRobinParticipants SET RrPartGroupRank = " . intval($rank) . ",
                RrPartGroupTiesForSO = 0, RrPartGroupTiesForCT = 0
            WHERE RrPartTournament = " . StrSafe_DB($TDM_TOUR) . " AND RrPartTeam = 1
                AND RrPartEvent = " . StrSafe_DB($event->EvCode) . " AND RrPartLevel = 1
                AND RrPartGroup = " . intval($group) . " AND RrPartParticipant = $coId AND RrPartSubTeam = $sub");
    }
}
