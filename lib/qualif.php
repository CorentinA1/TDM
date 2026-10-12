<?php
/**
 * lib/qualif.php — équipes déclarées et score de qualification par équipe.
 *
 * Une équipe TDM = les archers d'un même club, inscrits dans la division de
 * l'épreuve, avec « équipe mixte » coché (EnTeamMixEvent) et le même numéro
 * d'équipe (EnSubTeam). C'est exactement ce que MakeTeamsAbs (cœur) assemble.
 *
 * Le score d'équipe ne peut pas être écrit dans Teams : IANSEO reconstruit
 * cette table depuis Qualifications à chaque modification d'archer ou de
 * règle. On le range donc sur les archers : le score, les 10 et les 9 sur la
 * femme (ou le premier archer), seulement les 10 flèches sur l'homme. La somme
 * que fait IANSEO redonne le score de l'équipe.
 */

/** Flèches tirées par chaque archer en mini-qualification (5 volées × 2). */
const TDM_QUALIF_ARROWS_PER_ARCHER = 10;
/** Flèches de l'équipe : 5 volées de 4 flèches. */
const TDM_QUALIF_ARROWS = 20;

/**
 * Contrôle une saisie de qualification d'équipe. Fonction pure.
 *
 * @param string $score Score saisi ; vide = pas de score (équipe retirée du classement).
 * @param string $tens Nombre de 10 ; vide = 0.
 * @param string $nines Nombre de 9 ; vide = 0.
 * @return array{ok: bool, error: string, score: int|null, tens: int, nines: int}
 */
function tdm_parse_qualif($score, $tens, $nines)
{
    $score = trim((string) $score);
    $tens = trim((string) $tens);
    $nines = trim((string) $nines);
    $res = ['ok' => true, 'error' => '', 'score' => null, 'tens' => 0, 'nines' => 0];

    if ($score === '') {
        if ($tens !== '' && $tens !== '0' || $nines !== '' && $nines !== '0') {
            return ['ok' => false, 'error' => 'des 10 ou des 9 sans score'] + $res;
        }
        return $res;
    }
    foreach (['score' => $score, 'tens' => $tens, 'nines' => $nines] as $k => $v) {
        if ($v !== '' && !ctype_digit($v)) return ['ok' => false, 'error' => 'nombre entier attendu'] + $res;
    }
    $s = intval($score);
    $t = $tens === '' ? 0 : intval($tens);
    $n = $nines === '' ? 0 : intval($nines);
    $max = TDM_QUALIF_ARROWS * 10;
    if ($s > $max) return ['ok' => false, 'error' => "score supérieur à $max"] + $res;
    if ($t + $n > TDM_QUALIF_ARROWS) return ['ok' => false, 'error' => 'plus de ' . TDM_QUALIF_ARROWS . ' flèches à 10 ou 9'] + $res;
    if (10 * $t + 9 * $n > $s) return ['ok' => false, 'error' => 'les 10 et les 9 dépassent le score'] + $res;
    return ['ok' => true, 'error' => '', 'score' => $s, 'tens' => $t, 'nines' => $n];
}

/** Nom affiché d'une équipe : « Club Alpha 2 » (numéro omis s'il vaut 0). */
function tdm_team_name($club, $subTeam)
{
    return trim($club . ($subTeam > 0 ? ' ' . $subTeam : ''));
}

/**
 * Équipes déclarées d'une épreuve, avec leur score et leur rang de qualif.
 *
 * @param object $event Épreuve (tdm_events()).
 * @return array<string, object> clé « CoId-SubTeam » => équipe :
 *         CoId, SubTeam, Name, ClubCode, Members (archers, femme d'abord),
 *         Score, Tens, Nines (null si pas de score), Rank (0 = pas d'équipe IANSEO),
 *         Issues (anomalies de composition, en clair).
 */
function tdm_event_teams($event)
{
    global $TDM_TOUR;
    $ev = StrSafe_DB($event->EvCode);
    $country = tdm_country_sql((int) $event->EvTeamCreationMode);

    // Un archer peut répondre à deux règles (femmes, hommes) : DISTINCT sur EnId.
    $rs = safe_r_sql("SELECT DISTINCT EnId, EnSex, EnCode, EnName, EnFirstName, EnSubTeam, EnDivision, EnClass,
            $country AS CoId, CoCode, CoName,
            QuScore, QuGold, QuXnine, QuHits
        FROM Entries
        INNER JOIN EventClass ON EcTournament = EnTournament AND EcCode = $ev AND EcTeamEvent > 0
            AND EcDivision = EnDivision AND EcClass = EnClass AND (EcSubClass = '' OR EcSubClass = EnSubClass)
        INNER JOIN Qualifications ON QuId = EnId
        INNER JOIN Countries ON CoId = $country AND CoTournament = EnTournament
        WHERE EnTournament = " . StrSafe_DB($TDM_TOUR) . " AND EnAthlete = 1 AND EnTeamMixEvent = 1 AND EnStatus <= 1
        ORDER BY CoName, GREATEST(EnSubTeam, 1), EnSex DESC, EnName, EnFirstName");

    $teams = [];
    while ($r = safe_fetch($rs)) {
        // Numéro vide = équipe 1, comme MakeTeamsAbs pour une épreuve multi-équipes.
        $sub = max(1, (int) $r->EnSubTeam);
        $key = $r->CoId . '-' . $sub;
        if (!isset($teams[$key])) {
            $teams[$key] = (object) [
                'Key' => $key, 'CoId' => (int) $r->CoId, 'SubTeam' => $sub,
                'Name' => tdm_team_name($r->CoName, $sub), 'ClubCode' => $r->CoCode,
                'Members' => [], 'Score' => 0, 'Tens' => 0, 'Nines' => 0, 'Hits' => 0,
                'Rank' => 0, 'Issues' => [],
            ];
        }
        $t = $teams[$key];
        $t->Members[] = $r;
        $t->Score += (int) $r->QuScore;
        $t->Tens += (int) $r->QuGold;
        $t->Nines += (int) $r->QuXnine;
        $t->Hits += (int) $r->QuHits;
    }

    // Rang IANSEO (Teams, phase finale) et contrôles de composition.
    $ranks = [];
    $rs = safe_r_sql("SELECT TeCoId, TeSubTeam, TeRank FROM Teams
        WHERE TeTournament = " . StrSafe_DB($TDM_TOUR) . " AND TeFinEvent = 1 AND TeEvent = $ev");
    while ($r = safe_fetch($rs)) $ranks[$r->TeCoId . '-' . $r->TeSubTeam] = (int) $r->TeRank;

    $perClub = [];
    foreach ($teams as $t) $perClub[$t->CoId] = ($perClub[$t->CoId] ?? 0) + 1;

    foreach ($teams as $key => $t) {
        if ($t->Hits === 0) {
            $t->Score = $t->Tens = $t->Nines = null;
        }
        $t->Rank = $ranks[$key] ?? 0;
        $women = count(array_filter($t->Members, fn($m) => (int) $m->EnSex === 1));
        $men = count($t->Members) - $women;
        if ($women !== 1 || $men !== 1) {
            $t->Issues[] = "composition : $women femme(s), $men homme(s) au lieu d'une femme et d'un homme";
        }
        if (count($t->Members) > 2 && array_filter($t->Members, fn($m) => (int) $m->EnSubTeam === 0)) {
            $t->Issues[] = "numéro d'équipe manquant : IANSEO formera les équipes lui-même";
        }
        unset($t->Hits);
    }
    return $teams;
}

/** Expression SQL du club d'un archer selon le mode de création des équipes. */
function tdm_country_sql($mode)
{
    switch ($mode) {
        case 1: return 'EnCountry';
        case 2: return 'EnCountry2';
        case 3: return 'EnCountry3';
        default: return 'IF(EnCountry2 = 0, EnCountry, EnCountry2)';
    }
}

/**
 * Range le score d'une équipe sur ses archers (voir l'en-tête du fichier).
 *
 * @param object $team Équipe (tdm_event_teams()).
 * @param array $q Saisie contrôlée (tdm_parse_qualif()) ; score null = efface.
 * @return bool true si quelque chose a changé.
 */
function tdm_store_team_score($team, array $q)
{
    $changed = false;
    foreach ($team->Members as $i => $m) {
        $carrier = ($i === 0); // femme d'abord (tri EnSex DESC)
        if ($q['score'] === null) {
            $s = $g = $x = $h = 0;
        } else {
            $s = $carrier ? $q['score'] : 0;
            $g = $carrier ? $q['tens'] : 0;
            $x = $carrier ? $q['nines'] : 0;
            $h = TDM_QUALIF_ARROWS_PER_ARCHER;
        }
        // Tout sur la 1re distance ; la 2e (salle 2×18 m) est remise à zéro.
        safe_w_sql("UPDATE Qualifications SET
                QuD1Score = $s, QuD1Gold = $g, QuD1Xnine = $x, QuD1Hits = $h,
                QuD2Score = 0, QuD2Gold = 0, QuD2Xnine = 0, QuD2Hits = 0,
                QuScore = $s, QuGold = $g, QuXnine = $x, QuHits = $h
            WHERE QuId = " . intval($m->EnId));
        if (safe_w_affected_rows()) $changed = true;
    }
    return $changed;
}

/**
 * Reconstruit les équipes modifiées et le classement de qualif (cœur IANSEO).
 *
 * Comme la saisie native d'un score (Qualification/UpdateQuals.php) : un appel
 * par club, division et classe touchés. Un recalcul complet, MakeTeamsAbs sans
 * filtre, remettrait à « non validée » la qualif de TOUTES les catégories, alors
 * que refaire la validation efface les matchs de poule déjà saisis.
 *
 * @param object[] $teams Équipes dont le score a changé (tdm_event_teams()).
 */
function tdm_rebuild_teams(array $teams)
{
    require_once HTDOCS . '/Qualification/Fun_Qualification.local.inc.php';
    $done = [];
    $ok = true;
    foreach ($teams as $t) {
        foreach ($t->Members as $m) {
            $k = $t->CoId . '|' . $m->EnDivision . '|' . $m->EnClass;
            if (isset($done[$k])) continue;
            $done[$k] = true;
            if (MakeTeamsAbs($t->CoId, $m->EnDivision, $m->EnClass) != 0) $ok = false;
        }
    }
    return $ok;
}
