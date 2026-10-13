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
 *
 * Départ en mode Scorekeeper (lib/departs.php) : chaque archer reçoit ses
 * propres flèches de l'application, la somme reste la même règle. Une équipe
 * reprise à la main (téléphone en panne) est rangée comme en feuille papier,
 * son détail de flèches effacé et sa feuille marquée « validée » (QuConfirm,
 * bit de la distance 1) : Scorekeeper refuse alors tout envoi pour ses archers.
 */

/** Bit de QuConfirm d'une feuille validée, distance 1 (comme Scorekeeper et les codes-barres). */
const TDM_CONFIRM_D1 = 2;

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

/**
 * Volées d'équipe reconstruites à partir des flèches de chaque archer (saisie
 * Scorekeeper) : à chaque volée, les 2 flèches de la femme puis les 2 de
 * l'homme. Fonction pure.
 *
 * @param string[] $strings Détail des flèches des archers (QuD1Arrowstring, lettres IANSEO), femme d'abord.
 * @return string[][] volée (1..5) => flèches (lettre IANSEO, ' ' = pas de flèche) ; [] sans aucune flèche.
 */
function tdm_team_ends(array $strings, $ends = 5, $perArcher = 2)
{
    if (trim(implode('', $strings)) === '') return [];
    $out = [];
    for ($e = 1; $e <= $ends; $e++) {
        $out[$e] = [];
        foreach ($strings as $s) {
            for ($k = 0; $k < $perArcher; $k++) $out[$e][] = substr(str_pad((string) $s, $ends * $perArcher), ($e - 1) * $perArcher + $k, 1);
        }
    }
    return $out;
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
            QuScore, QuGold, QuXnine, QuHits, QuD1Arrowstring
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
        // Flèches tirées (Scorekeeper : avancement ; feuille papier : 10 par archer dès qu'un score est saisi).
        $t->Arrows = $t->Hits;
        unset($t->Hits);
    }
    return $teams;
}

/** Signature d'une égalité parfaite : « score|10|9 ». */
function tdm_tie_sig($t)
{
    return $t->Score . '|' . $t->Tens . '|' . $t->Nines;
}

/**
 * Classement de qualification du règlement : score, puis nombre de 10, puis
 * de 9, puis pile ou face. Fonction pure.
 *
 * @param array $teams clé => équipe (Score, Tens, Nines, Name ; Score null = pas de score).
 * @param array $toss  clé => ['sig' => signature de l'égalité tranchée, 'pos' => place dans l'égalité (1 = vainqueur)].
 *                     Un pile ou face ne vaut que si la signature est toujours la même (scores inchangés).
 * @return array{ranks: array<string, int>, ties: array<string, string[]>, open: array<string, string[]>}
 *         ranks : rang de chaque équipe avec score (égalité non tranchée = même rang) ;
 *         ties : signature => équipes à égalité parfaite (2 et plus), dans l'ordre du classement ;
 *         open : les égalités dont le pile ou face n'est pas fait.
 */
function tdm_qualif_ranking(array $teams, array $toss = [])
{
    $scored = array_filter($teams, fn($t) => $t->Score !== null);
    $groups = [];
    foreach ($scored as $k => $t) $groups[tdm_tie_sig($t)][] = $k;
    $pos = $open = [];
    foreach ($groups as $sig => $keys) {
        if (count($keys) < 2) continue;
        $p = [];
        foreach ($keys as $k) {
            if (isset($toss[$k]['sig']) && (string) $toss[$k]['sig'] === (string) $sig) $p[$k] = (int) $toss[$k]['pos'];
        }
        if (count($p) === count($keys) && count(array_unique($p)) === count($keys) && min($p) >= 1) $pos += $p;
        else $open[$sig] = true;
    }
    uksort($scored, function ($a, $b) use ($scored, $pos) {
        $x = $scored[$a];
        $y = $scored[$b];
        return [$y->Score, $y->Tens, $y->Nines] <=> [$x->Score, $x->Tens, $x->Nines]
            ?: ($pos[$a] ?? 0) <=> ($pos[$b] ?? 0) ?: strnatcasecmp($x->Name, $y->Name);
    });
    $ranks = $ties = [];
    $i = $rank = 0;
    $prev = null;
    foreach ($scored as $k => $t) {
        $i++;
        $sig = tdm_tie_sig($t);
        if (!($sig === $prev && isset($open[$sig]))) $rank = $i;
        $ranks[$k] = $rank;
        $prev = $sig;
        if (count($groups[$sig]) > 1) $ties[$sig][] = $k;
    }
    $openList = [];
    foreach ($ties as $sig => $keys) if (isset($open[$sig])) $openList[$sig] = $keys;
    return ['ranks' => $ranks, 'ties' => $ties, 'open' => $openList];
}

/**
 * Pile ou face saisis : nouvelles places dans chaque égalité. Fonction pure.
 *
 * @param array $ties  Égalités actuelles (tdm_qualif_ranking()['ties']).
 * @param array $win   signature => clé du vainqueur (égalité à deux).
 * @param array $order clé => place 1..n (égalité à trois et plus).
 * @param array $old   Pile ou face déjà enregistrés (gardés pour une égalité sans nouvelle saisie).
 * @return array clé => ['sig', 'pos'], pour les égalités actuelles seulement.
 */
function tdm_toss_from_input(array $ties, array $win, array $order, array $old)
{
    $out = [];
    foreach ($ties as $sig => $keys) {
        $w = (string) ($win[$sig] ?? '');
        if (count($keys) === 2 && in_array($w, $keys, true)) {
            foreach ($keys as $k) $out[$k] = ['sig' => (string) $sig, 'pos' => $k === $w ? 1 : 2];
            continue;
        }
        $p = [];
        foreach ($keys as $k) {
            $v = (int) ($order[$k] ?? 0);
            if ($v >= 1 && $v <= count($keys)) $p[$k] = $v;
        }
        if (count($keys) > 2 && count($p) === count($keys) && count(array_unique($p)) === count($keys)) {
            foreach ($p as $k => $v) $out[$k] = ['sig' => (string) $sig, 'pos' => $v];
            continue;
        }
        foreach ($keys as $k) {
            if (isset($old[$k]['sig']) && (string) $old[$k]['sig'] === (string) $sig) $out[$k] = $old[$k];
        }
    }
    return $out;
}

/** Pile ou face enregistrés d'une épreuve (paramètre de module TDM « CoinToss »). */
function tdm_toss_get($code)
{
    $all = getModuleParameter('TDM', 'CoinToss', []);
    return is_array($all) && isset($all[$code]) && is_array($all[$code]) ? $all[$code] : [];
}

/** Enregistre les pile ou face d'une épreuve. */
function tdm_toss_set($code, array $toss)
{
    $all = getModuleParameter('TDM', 'CoinToss', []);
    if (!is_array($all)) $all = [];
    $all[$code] = $toss;
    setModuleParameter('TDM', 'CoinToss', $all);
}

/**
 * Équipes d'une épreuve avec le rang du règlement. Tant que les poules ne sont
 * pas remplies, le rang vient de tdm_qualif_ranking() (pile ou face compris) ;
 * ensuite, c'est celui qu'IANSEO a enregistré à la validation.
 * Ajoute TieOpen (égalité parfaite sans pile ou face) à chaque équipe.
 *
 * @return array{0: array, 1: array} [équipes, résultat de tdm_qualif_ranking()]
 */
function tdm_event_teams_ranked($event)
{
    $teams = tdm_event_teams($event);
    $rk = tdm_qualif_ranking($teams, tdm_toss_get($event->EvCode));
    $openKeys = $rk['open'] ? array_merge(...array_values($rk['open'])) : [];
    foreach ($teams as $k => $t) {
        if (!$event->Validated) $t->Rank = $rk['ranks'][$k] ?? 0;
        $t->TieOpen = in_array($k, $openKeys, true);
    }
    return [$teams, $rk];
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
 * Le détail des flèches est effacé : une saisie d'équipe ne doit pas être
 * recalculée plus tard à partir de flèches envoyées par Scorekeeper.
 *
 * @param object $team Équipe (tdm_event_teams()).
 * @param array $q Saisie contrôlée (tdm_parse_qualif()) ; score null = efface.
 * @param bool $lock Reprise à la main d'un départ Scorekeeper : feuilles marquées
 *        « validées » (Scorekeeper refuse alors les envois) et volées en attente effacées.
 * @return bool true si quelque chose a changé.
 */
function tdm_store_team_score($team, array $q, $lock = false)
{
    $changed = false;
    $ids = implode(',', array_map(fn($m) => intval($m->EnId), $team->Members));
    if ($lock && $ids) {
        safe_w_sql("UPDATE Qualifications SET QuConfirm = QuConfirm | " . TDM_CONFIRM_D1 . " WHERE QuId IN ($ids)");
        if (safe_w_affected_rows()) $changed = true;
        tdm_isk_drop_pending($team);
    }
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
                QuD1Score = $s, QuD1Gold = $g, QuD1Xnine = $x, QuD1Hits = $h, QuD1Arrowstring = '',
                QuD2Score = 0, QuD2Gold = 0, QuD2Xnine = 0, QuD2Hits = 0, QuD2Arrowstring = '',
                QuScore = $s, QuGold = $g, QuXnine = $x, QuHits = $h
            WHERE QuId = " . intval($m->EnId));
        if (safe_w_affected_rows()) $changed = true;
    }
    return $changed;
}

/**
 * « Rendre à Scorekeeper » une équipe reprise à la main : score effacé, feuilles
 * de nouveau ouvertes aux envois de l'application (bit QuConfirm retiré).
 *
 * @return bool true si quelque chose a changé.
 */
function tdm_release_team($team)
{
    $ids = implode(',', array_map(fn($m) => intval($m->EnId), $team->Members));
    if (!$ids) return false;
    safe_w_sql("UPDATE Qualifications SET QuConfirm = QuConfirm & ~" . TDM_CONFIRM_D1 . ",
            QuD1Score = 0, QuD1Gold = 0, QuD1Xnine = 0, QuD1Hits = 0, QuD1Arrowstring = '',
            QuScore = 0, QuGold = 0, QuXnine = 0, QuHits = 0
        WHERE QuId IN ($ids)");
    return safe_w_affected_rows() > 0;
}

/** Volées Scorekeeper en attente d'import pour les archers d'une équipe : effacées. */
function tdm_isk_drop_pending($team)
{
    global $TDM_TOUR;
    if (!tdm_isk_table()) return;
    $ids = implode(',', array_map(fn($m) => intval($m->EnId), $team->Members));
    safe_w_sql("DELETE IskData FROM IskData INNER JOIN Qualifications ON IskDtTargetNo = CONCAT(QuSession, '.', QuTarget, QuLetter)
        WHERE IskDtTournament = " . StrSafe_DB($TDM_TOUR) . " AND IskDtType = 'Q' AND IskDtMatchNo = 0 AND QuId IN ($ids)");
}

/** La table des volées Scorekeeper existe-t-elle (API ISK-NG installée) ? */
function tdm_isk_table()
{
    static $ok = null;
    if ($ok === null) $ok = (bool) safe_num_rows(safe_r_sql("SHOW TABLES LIKE 'IskData'"));
    return $ok;
}

/** Équipes reprises à la main dans un départ Scorekeeper : clés d'équipe (paramètre TDM « IskManual »). */
function tdm_isk_manual_get($code)
{
    $all = (array) getModuleParameter('TDM', 'IskManual', []);
    return array_values((array) ($all[$code] ?? []));
}

function tdm_isk_manual_set($code, array $keys)
{
    $all = (array) getModuleParameter('TDM', 'IskManual', []);
    $keys = array_values(array_unique($keys));
    if ($keys) $all[$code] = $keys; else unset($all[$code]);
    setModuleParameter('TDM', 'IskManual', $all);
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
