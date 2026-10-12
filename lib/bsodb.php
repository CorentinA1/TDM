<?php
/**
 * lib/bsodb.php — BSO : lecture et écriture en base (tables TdmBso*).
 *
 * Le calcul (qui passe, qui est éliminé, places) reste dans lib/bso.php ; ici
 * on ne fait que lire les saisies et les lui passer. Rien n'est stocké en
 * dehors des saisies : corriger un score recalcule tout le déroulé.
 */
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/bso.php';
require_once __DIR__ . '/ranking.php';
require_once __DIR__ . '/pools.php';
require_once __DIR__ . '/qualif.php';

/**
 * Équipes que le règlement envoie au BSO, d'après le classement des poules.
 *
 * @param object $event Épreuve (tdm_events()).
 * @return array{keys: string[], names: array<string, string>, ready: bool, why: string}
 *         keys : 1ers de poule dans l'ordre des poules (poule unique : 1er puis 2e) ;
 *         ready : poules terminées et départagées aux rangs utiles ; why : sinon, pourquoi.
 */
function tdm_bso_proposed($event)
{
    $pools = tdm_pools($event);
    $keys = $names = [];
    $why = [];
    $wanted = $event->Pools > 1 ? [1] : [1, 2];
    foreach ($pools as $g => $pool) {
        if ($pool->Played < $pool->Matches) $why[] = "poule $g : " . ($pool->Matches - $pool->Played) . " match(s) à saisir";
        if ($pool->Byes > $pool->ByesApplied) $why[] = "poule $g : byes 5-0 pas appliqués";
        foreach ($wanted as $rank) {
            $found = array_values(array_filter($pool->Rows, fn($r) => $r->Rank === $rank));
            if (count($found) > 1) $why[] = "poule $g : égalité au rang $rank";
            if (count($found) === 1) {
                $keys[] = $found[0]->Key;
                $names[$found[0]->Key] = $found[0]->Name;
            }
        }
        foreach ($pool->Rows as $r) $names[$r->Key] = $r->Name;
    }
    return ['keys' => $keys, 'names' => $names, 'ready' => !$why && $keys, 'why' => implode(' ; ', $why)];
}

/**
 * Équipes confirmées pour le BSO, dans l'ordre des cibles.
 *
 * @return array<string, object> clé => Key, CoId, SubTeam, Pos, Target, Name.
 */
function tdm_bso_teams($event)
{
    global $TDM_TOUR;
    tdm_schema();
    $out = [];
    $rs = safe_r_sql("SELECT BtCoId, BtSubTeam, BtPos, BtTarget, CoName FROM TdmBsoTeams
        LEFT JOIN Countries ON CoId = BtCoId AND CoTournament = BtTournament
        WHERE BtTournament = " . StrSafe_DB($TDM_TOUR) . " AND BtEvent = " . StrSafe_DB($event->EvCode) . "
        ORDER BY BtPos");
    while ($r = safe_fetch($rs)) {
        $key = $r->BtCoId . '-' . $r->BtSubTeam;
        $out[$key] = (object) [
            'Key' => $key, 'CoId' => (int) $r->BtCoId, 'SubTeam' => (int) $r->BtSubTeam,
            'Pos' => (int) $r->BtPos, 'Target' => $r->BtTarget === null ? null : (int) $r->BtTarget,
            'Name' => tdm_team_name((string) $r->CoName, (int) $r->BtSubTeam),
        ];
    }
    return $out;
}

/**
 * Confirme les équipes du BSO : remplace la liste et efface les volées saisies.
 *
 * @param string[] $keys Clés « CoId-SubTeam » dans l'ordre des cibles.
 * @param int $startTarget Première cible ; les suivantes s'enchaînent.
 */
function tdm_bso_confirm($event, array $keys, $startTarget)
{
    global $TDM_TOUR;
    tdm_schema();
    $tour = StrSafe_DB($TDM_TOUR);
    $ev = StrSafe_DB($event->EvCode);
    safe_w_sql("DELETE FROM TdmBsoEnds WHERE BeTournament = $tour AND BeEvent = $ev");
    safe_w_sql("DELETE FROM TdmBsoTeams WHERE BtTournament = $tour AND BtEvent = $ev");
    $start = max(1, (int) $startTarget);
    foreach (array_values($keys) as $i => $key) {
        [$coId, $sub] = array_map('intval', explode('-', $key));
        safe_w_sql("INSERT INTO TdmBsoTeams (BtTournament, BtEvent, BtCoId, BtSubTeam, BtPos, BtTarget)
            VALUES ($tour, $ev, $coId, $sub, " . ($i + 1) . ", " . ($start + $i) . ")");
    }
}

/** Efface les volées saisies (garde les équipes confirmées). */
function tdm_bso_clear($event)
{
    global $TDM_TOUR;
    tdm_schema();
    safe_w_sql("DELETE FROM TdmBsoEnds WHERE BeTournament = " . StrSafe_DB($TDM_TOUR)
        . " AND BeEvent = " . StrSafe_DB($event->EvCode));
}

/** Supprime le BSO de l'épreuve (équipes et volées). */
function tdm_bso_delete($event)
{
    global $TDM_TOUR;
    tdm_schema();
    $tour = StrSafe_DB($TDM_TOUR);
    $ev = StrSafe_DB($event->EvCode);
    safe_w_sql("DELETE FROM TdmBsoEnds WHERE BeTournament = $tour AND BeEvent = $ev");
    safe_w_sql("DELETE FROM TdmBsoTeams WHERE BtTournament = $tour AND BtEvent = $ev");
}

/**
 * Saisies des volées.
 *
 * @return array{ends: array<int, array<string, array{score: int|null, order: int|null}>>, stamp: string}
 *         stamp : change dès qu'une saisie change (pour le rafraîchissement des écrans).
 */
function tdm_bso_ends($event)
{
    global $TDM_TOUR;
    tdm_schema();
    $ends = [];
    $stamp = [];
    $rs = safe_r_sql("SELECT BeRound, BeCoId, BeSubTeam, BeScore, BeOrder, BeUpdated FROM TdmBsoEnds
        WHERE BeTournament = " . StrSafe_DB($TDM_TOUR) . " AND BeEvent = " . StrSafe_DB($event->EvCode));
    while ($r = safe_fetch($rs)) {
        $ends[(int) $r->BeRound][$r->BeCoId . '-' . $r->BeSubTeam] = [
            'score' => $r->BeScore === null ? null : (int) $r->BeScore,
            'order' => $r->BeOrder === null ? null : (int) $r->BeOrder,
        ];
        $stamp[] = $r->BeRound . $r->BeCoId . $r->BeSubTeam . '|' . $r->BeScore . '|' . $r->BeOrder;
    }
    sort($stamp);
    return ['ends' => $ends, 'stamp' => md5(implode(',', $stamp))];
}

/**
 * État complet du BSO d'une épreuve : équipes, déroulé calculé, places.
 *
 * @return array Prêt pour json_encode (voir bso-action.php, act=state).
 */
function tdm_bso_state($event)
{
    $teams = tdm_bso_teams($event);
    $data = tdm_bso_ends($event);
    $run = tdm_bso_run(array_keys($teams), $data['ends']);

    $rounds = [];
    foreach ($run['rounds'] as $r => $rd) {
        $rows = [];
        foreach ($rd['order'] as $k) {
            $e = $data['ends'][$r][$k] ?? [];
            $rows[] = [
                'key' => $k, 'name' => $teams[$k]->Name ?? $k, 'target' => $teams[$k]->Target ?? null,
                'score' => $e['score'] ?? null, 'order' => $e['order'] ?? null,
                'rank' => $rd['rank'][$k], 'status' => $rd['status'][$k],
            ];
        }
        // Ligne dans l'ordre des cibles pour la saisie, dans l'ordre du résultat pour l'affichage.
        $byTarget = $rows;
        usort($byTarget, fn($a, $b) => ($a['target'] ?? 0) <=> ($b['target'] ?? 0));
        $rounds[] = [
            'round' => $r, 'size' => count($rd['teams']), 'qualifiers' => $rd['qualifiers'],
            'complete' => $rd['complete'], 'pending' => $rd['pending'],
            'rows' => $rows, 'byTarget' => $byTarget,
            'label' => tdm_bso_round_label($rd['qualifiers'], count($rd['teams'])),
        ];
    }
    $places = [];
    foreach ($run['places'] as $k => $p) {
        $places[] = ['key' => $k, 'name' => $teams[$k]->Name ?? $k, 'place' => $p];
    }
    return [
        'event' => $event->EvCode, 'name' => $event->Name,
        'teams' => array_values(array_map(fn($t) => ['key' => $t->Key, 'name' => $t->Name, 'target' => $t->Target], $teams)),
        'rounds' => $rounds, 'places' => $places, 'current' => $run['current'],
        'finished' => $run['finished'], 'stamp' => $data['stamp'] . count($teams),
    ];
}

/** Nom d'une volée : « Finale », « Demi-finale (4 → 2) », « Volée 1 (7 → 4) ». */
function tdm_bso_round_label($qualifiers, $size)
{
    if ($qualifiers === 1) return 'Finale';
    return "$size équipes → $qualifiers qualifiées";
}

/** Utilisateur courant pour la trace des saisies (comptes AUTH), sinon vide. */
function tdm_current_user()
{
    return mb_substr((string) ($_SESSION['AUTH_User'] ?? ''), 0, 64);
}

/**
 * Enregistre le score d'une équipe pour une volée.
 *
 * @param int|null $score 0 à 20, ou null pour effacer.
 * @return string Message d'erreur, vide si c'est enregistré.
 */
function tdm_bso_save_score($event, $round, $key, $score)
{
    global $TDM_TOUR;
    $ends = tdm_bso_ends($event)['ends'];
    $state = tdm_bso_run(array_keys(tdm_bso_teams($event)), $ends);
    $rd = $state['rounds'][$round] ?? null;
    if (!$rd) return "Cette volée n'est pas encore ouverte.";
    if (!in_array($key, $rd['teams'], true)) return "Cette équipe ne tire pas cette volée.";
    if ($score !== null && ($score < 0 || $score > TDM_BSO_MAX_END)) return 'Score de 0 à ' . TDM_BSO_MAX_END . '.';

    $old = $ends[$round][$key]['score'] ?? null;
    if ($old === $score && isset($ends[$round][$key])) return '';

    [$coId, $sub] = array_map('intval', explode('-', $key));
    $tour = StrSafe_DB($TDM_TOUR);
    $ev = StrSafe_DB($event->EvCode);
    $s = $score === null ? 'NULL' : (int) $score;
    safe_w_sql("INSERT INTO TdmBsoEnds (BeTournament, BeEvent, BeRound, BeCoId, BeSubTeam, BeScore, BeBy)
        VALUES ($tour, $ev, " . intval($round) . ", $coId, $sub, $s, " . StrSafe_DB(tdm_current_user()) . ")
        ON DUPLICATE KEY UPDATE BeScore = VALUES(BeScore), BeOrder = NULL, BeBy = VALUES(BeBy)");

    // Les égalités autour de l'ancien et du nouveau score ont pu changer : leurs départages sont à refaire.
    $affected = array_filter([$old, $score], fn($v) => $v !== null);
    if ($affected) {
        safe_w_sql("UPDATE TdmBsoEnds SET BeOrder = NULL
            WHERE BeTournament = $tour AND BeEvent = $ev AND BeRound = " . intval($round) . "
              AND BeScore IN (" . implode(',', array_map('intval', $affected)) . ")");
    }
    return '';
}

/**
 * Enregistre l'ordre d'un groupe d'équipes à égalité (plus près du centre, barrage).
 *
 * @param array<string, int> $orders Clé => 1, 2… (1 = devant).
 * @return string Message d'erreur, vide si c'est enregistré.
 */
function tdm_bso_save_orders($event, $round, array $orders)
{
    global $TDM_TOUR;
    $ends = tdm_bso_ends($event)['ends'];
    $state = tdm_bso_run(array_keys(tdm_bso_teams($event)), $ends);
    $rd = $state['rounds'][$round] ?? null;
    if (!$rd) return "Cette volée n'est pas encore ouverte.";

    // Égalité à trancher, ou retouche d'un départage déjà fait : dans les deux
    // cas, des équipes de la volée qui ont le même score.
    $keys = array_map('strval', array_keys($orders));
    $scores = [];
    foreach ($keys as $k) {
        if (!in_array($k, $rd['teams'], true)) return "Cette équipe ne tire pas cette volée.";
        $scores[] = $ends[$round][$k]['score'] ?? null;
    }
    if (count($keys) < 2 || in_array(null, $scores, true) || count(array_unique($scores)) !== 1) {
        return "Ces équipes ne sont pas à égalité.";
    }
    if (!tdm_bso_order_complete($keys, $orders)) return 'Donnez à chaque équipe une position différente (1, 2…).';

    foreach ($orders as $key => $o) {
        [$coId, $sub] = array_map('intval', explode('-', (string) $key));
        safe_w_sql("UPDATE TdmBsoEnds SET BeOrder = " . intval($o) . ", BeBy = " . StrSafe_DB(tdm_current_user()) . "
            WHERE BeTournament = " . StrSafe_DB($TDM_TOUR) . " AND BeEvent = " . StrSafe_DB($event->EvCode) . "
              AND BeRound = " . intval($round) . " AND BeCoId = $coId AND BeSubTeam = $sub");
    }
    return '';
}
