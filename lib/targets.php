<?php
/**
 * lib/targets.php — cibles de la mini-qualification, par équipe.
 *
 * Deux équipes par cible : l'équipe en position A et l'équipe en position B
 * (« 3A », « 3B », comme le classeur). IANSEO range la cible par archer
 * (Qualifications : QuSession, QuTarget, QuLetter, QuTargetNo) avec 4 archers
 * par cible : l'équipe A occupe les lettres A et B, l'équipe B les lettres C
 * et D, la femme d'abord. Les pages natives (impressions, feuilles de marque
 * individuelles, plan de cibles) voient donc des archers correctement placés.
 */

/** Lettres IANSEO des deux archers d'une équipe selon sa position sur la cible. */
const TDM_TEAM_LETTERS = ['A' => ['A', 'B'], 'B' => ['C', 'D']];

// ── Fonctions pures ───────────────────────────────────────────────────────────

/**
 * Lit une cible d'équipe saisie (« 3A », « 12 b », « 7 »). Fonction pure.
 *
 * @return array{0: int, 1: string}|null [cible, position A/B] ; position A par défaut ; null si illisible.
 */
function tdm_parse_team_target($text)
{
    $text = strtoupper(preg_replace('/\s+/', '', (string) $text));
    if (!preg_match('/^0*(\d{1,3})([AB]?)$/', $text, $m) || (int) $m[1] < 1) return null;
    return [(int) $m[1], $m[2] ?: 'A'];
}

/** Cible d'équipe affichée : « 3A ». */
function tdm_team_target_label($target, $pos)
{
    return $target ? $target . $pos : '';
}

/** Position d'équipe (A/B) d'après la lettre IANSEO d'un de ses archers. */
function tdm_pos_of_letter($letter)
{
    return in_array(strtoupper((string) $letter), ['C', 'D'], true) ? 'B' : 'A';
}

/**
 * Attribution automatique. Fonction pure (le tirage dépend de $seed).
 *
 * Catégorie par catégorie, dans l'ordre donné, chacune sur ses propres cibles
 * (blasons différents) : les équipes sont tirées au sort puis placées deux par
 * cible, en évitant deux équipes du même club sur une cible quand c'est possible.
 *
 * @param array $teams Liste de [key, cat, club].
 * @param string[] $cats Ordre des catégories.
 * @param int $first Première cible.
 * @return array<string, array{0: int, 1: string}> clé d'équipe => [cible, position].
 */
function tdm_auto_targets(array $teams, array $cats, $first, $seed)
{
    mt_srand((int) $seed);
    $out = [];
    $target = (int) $first;
    foreach ($cats as $cat) {
        $list = array_values(array_filter($teams, fn($t) => $t[1] === $cat));
        if (!$list) continue;
        // Tirage au sort (Fisher-Yates avec le générateur initialisé).
        for ($i = count($list) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        }
        while ($list) {
            $a = array_shift($list);
            $out[$a[0]] = [$target, 'A'];
            if ($list) {
                $pick = 0;
                foreach ($list as $i => $b) {
                    if ($b[2] !== $a[2]) { $pick = $i; break; }
                }
                $b = $list[$pick];
                array_splice($list, $pick, 1);
                $out[$b[0]] = [$target, 'B'];
            }
            $target++;
        }
    }
    mt_srand();
    return $out;
}

/**
 * Complète les cibles déjà commencées. Fonction pure.
 *
 * Une cible où une catégorie n'a qu'une équipe (l'autre position libre, sans
 * autre catégorie) reçoit une équipe sans cible de la même catégorie, d'un
 * autre club si possible. Les équipes restantes sont rendues pour un tirage.
 *
 * @param array $placed Équipes placées : liste de [cat, club, target, pos].
 * @param array $todo Équipes sans cible : liste de [key, cat, club] (déjà tirées au sort).
 * @return array{0: array<string, array{0: int, 1: string}>, 1: array} [clé => [cible, position], équipes restantes]
 */
function tdm_fill_free_slots(array $placed, array $todo)
{
    $byTarget = [];
    foreach ($placed as [$cat, $club, $target, $pos]) $byTarget[$target][$pos] = [$cat, $club];
    ksort($byTarget);
    $out = [];
    foreach ($byTarget as $target => $slots) {
        if (count($slots) !== 1) continue;
        $pos = isset($slots['A']) ? 'A' : 'B';
        [$cat, $club] = $slots[$pos];
        $free = $pos === 'A' ? 'B' : 'A';
        $pick = null;
        foreach ($todo as $i => $t) {
            if ($t[1] !== $cat) continue;
            if ($pick === null) $pick = $i;
            if ($t[2] !== $club) { $pick = $i; break; }
        }
        if ($pick === null) continue;
        $out[$todo[$pick][0]] = [$target, $free];
        array_splice($todo, $pick, 1);
    }
    return [$out, $todo];
}

/**
 * Contrôle des cibles. Fonction pure.
 *
 * Erreurs (bloquent l'enregistrement manuel) : place prise deux fois, cible hors
 * session. Avertissements (non bloquants) : deux catégories sur une cible, deux
 * équipes du même club dans la même catégorie sur une cible. Chaque départ
 * (session) a son propre pas de tir : les cibles ne se comparent qu'à
 * l'intérieur d'un même départ.
 *
 * @param array $teams clé => [cat, club, name, target, pos, session] (target 0 = sans cible ; session 1 par défaut).
 * @param int|array<int, int> $maxTarget Nombre de cibles (par session si tableau ; 0 = pas de contrôle).
 * @return array{errors: string[], warnings: string[]}
 */
function tdm_check_targets(array $teams, $maxTarget = 0)
{
    $errors = $warnings = [];
    $slots = $byTarget = [];
    $sessions = [];
    foreach ($teams as $t) if ($t[3]) $sessions[(int) ($t[5] ?? 1)] = true;
    $several = count($sessions) > 1;
    $where = fn($ses) => $several ? " (départ $ses)" : '';
    foreach ($teams as $key => $t) {
        [$cat, $club, $name, $target, $pos] = $t;
        $ses = (int) ($t[5] ?? 1);
        if (!$target) continue;
        $slots[$ses][$target . $pos][] = $name;
        $byTarget[$ses][$target][] = [$cat, $club, $name];
        $max = is_array($maxTarget) ? (int) ($maxTarget[$ses] ?? 0) : (int) $maxTarget;
        if ($max && $target > $max) $errors[] = "$name : cible $target au-delà des $max cibles de la session" . $where($ses);
    }
    ksort($slots);
    foreach ($slots as $ses => $list) {
        ksort($list, SORT_NATURAL);
        foreach ($list as $slot => $names) {
            if (count($names) > 1) $errors[] = "cible $slot" . $where($ses) . ' donnée à ' . implode(' et ', $names);
        }
    }
    ksort($byTarget);
    foreach ($byTarget as $ses => $targets) {
        ksort($targets);
        foreach ($targets as $target => $list) {
            if (count(array_unique(array_column($list, 0))) > 1) {
                $warnings[] = "cible $target" . $where($ses) . ' : catégories différentes (' . implode(', ', array_unique(array_column($list, 0))) . ') — blasons à vérifier';
            }
            if (count($list) === 2 && $list[0][1] === $list[1][1] && $list[0][0] === $list[1][0]) {
                $warnings[] = "cible $target" . $where($ses) . ' : deux équipes du même club dans la même catégorie (' . $list[0][2] . ', ' . $list[1][2] . ') — à éviter, non bloquant';
            }
        }
    }
    return ['errors' => $errors, 'warnings' => $warnings];
}

// ── Base de données ───────────────────────────────────────────────────────────

/** Première session de qualification : numéro et nombre de cibles. */
function tdm_qualif_session()
{
    global $TDM_TOUR;
    $rs = safe_r_sql("SELECT SesOrder, SesTar4Session, SesFirstTarget, SesAth4Target FROM Session
        WHERE SesTournament = " . StrSafe_DB($TDM_TOUR) . " AND SesType = 'Q' ORDER BY SesOrder LIMIT 1");
    $s = safe_fetch($rs);
    return $s ? (object) ['Session' => (int) $s->SesOrder, 'Targets' => (int) $s->SesTar4Session,
                          'First' => (int) $s->SesFirstTarget, 'PerTarget' => (int) $s->SesAth4Target] : null;
}

/**
 * Cible actuelle de chaque équipe d'une catégorie, lue sur ses archers.
 *
 * @param object[] $teams Équipes (tdm_event_teams()).
 * @return array<string, array{0: int, 1: string, 2: bool}> clé => [cible, position, archers cohérents].
 */
function tdm_team_targets(array $teams)
{
    $ids = [];
    foreach ($teams as $t) foreach ($t->Members as $m) $ids[] = (int) $m->EnId;
    $q = [];
    if ($ids) {
        $rs = safe_r_sql("SELECT QuId, QuTarget, QuLetter FROM Qualifications WHERE QuId IN (" . implode(',', $ids) . ")");
        while ($r = safe_fetch($rs)) $q[(int) $r->QuId] = [(int) $r->QuTarget, (string) $r->QuLetter];
    }
    $out = [];
    foreach ($teams as $key => $t) {
        $first = $q[(int) ($t->Members[0]->EnId ?? 0)] ?? [0, ''];
        $target = $first[0];
        $pos = tdm_pos_of_letter($first[1]);
        $ok = true;
        foreach ($t->Members as $i => $m) {
            $mine = $q[(int) $m->EnId] ?? [0, ''];
            $expected = TDM_TEAM_LETTERS[$pos][$i] ?? null;
            if ($mine[0] !== $target || ($target && $mine[1] !== $expected)) $ok = false;
        }
        $out[$key] = [$target, $pos, $ok];
    }
    return $out;
}

/**
 * Place une équipe sur une cible (0 = retire la cible) : ses archers prennent
 * les deux lettres de sa position, la femme d'abord.
 */
function tdm_set_team_target($team, $session, $target, $pos)
{
    foreach ($team->Members as $i => $m) {
        $id = intval($m->EnId);
        if ($target && isset(TDM_TEAM_LETTERS[$pos][$i])) {
            $letter = TDM_TEAM_LETTERS[$pos][$i];
            safe_w_sql("UPDATE Qualifications SET QuSession = " . intval($session) . ", QuTarget = " . intval($target) . ",
                    QuLetter = '$letter', QuTargetNo = CONCAT(" . intval($session) . ", LPAD(" . intval($target) . ", 3, '0'), '$letter'),
                    QuTimestamp = QuTimestamp
                WHERE QuId = $id");
        } else {
            safe_w_sql("UPDATE Qualifications SET QuTarget = 0, QuLetter = '', QuTargetNo = '', QuTimestamp = QuTimestamp WHERE QuId = $id");
        }
    }
}
