<?php
/**
 * lib/bso.php — moteur du Big Shoot Off. Fonctions pures, sans base.
 *
 * Règlement TDM 2026 : chaque volée = 2 flèches par équipe (une par archer),
 * score de 0 à 20. Volée 1 : toutes les équipes, les 4 meilleures continuent ;
 * volée 2 : 4 → 2 ; volée 3 : finale. Avec moins d'équipes on commence à la
 * volée qui correspond (décision du 12 octobre 2026) :
 *
 *     5 équipes et plus : N → 4 → 2 → 1
 *     4 équipes         : 4 → 2 → 1
 *     3 équipes         : 3 → 2 → 1
 *     2 équipes         : 2 → 1 (finale seule)
 *
 * Les éliminés d'une volée prennent les places qui suivent les qualifiés, dans
 * l'ordre de leur score de volée : volée 1 → 5e, 6e… ; demi-finale → 3e, 4e ;
 * finale → 2e (et 1er pour le vainqueur). Place dans la volée = place finale.
 *
 * Égalité : flèche la plus près du centre, puis barrage. Le moteur ne tranche
 * pas lui-même ; il signale l'égalité et attend un ordre saisi (1, 2…) pour les
 * équipes concernées. Une égalité entre équipes toutes qualifiées n'a pas
 * besoin d'être tranchée : la volée suivante les départagera.
 */

/** Score maximal d'une volée de BSO : 2 flèches à 10. */
const TDM_BSO_MAX_END = 20;

/**
 * Nombre d'équipes de chaque volée.
 *
 * @param int $n Équipes au départ du BSO.
 * @return int[] Volée (1, 2…) => équipes ; vide sous 2 équipes.
 */
function tdm_bso_sizes($n)
{
    $n = (int) $n;
    if ($n < 2) return [];
    $sizes = $n > 4 ? [$n, 4, 2] : ($n > 2 ? [$n, 2] : [2]);
    return array_combine(range(1, count($sizes)), $sizes);
}

/**
 * Résultat d'une volée.
 *
 * @param array<string, int|null> $scores Clé d'équipe => score (null = pas encore saisi).
 * @param array<string, int|null> $orders Clé => ordre saisi pour une égalité (1 = devant).
 * @param int $qualifiers Équipes qualifiées pour la suite (1 en finale).
 * @return array{
 *     complete: bool,               tous les scores sont saisis
 *     order: string[],              équipes de la meilleure à la moins bonne (provisoire si incomplet)
 *     rank: array<string, int|null>, place dans la volée, null si une égalité reste à trancher
 *     status: array<string, string|null>, 'q' qualifiée, 'e' éliminée, null indéterminé
 *     pending: string[][],          groupes d'équipes à égalité à départager
 *     qualified: string[]|null      qualifiées (null tant que ce n'est pas tranché)
 * }
 */
function tdm_bso_round(array $scores, array $orders, $qualifiers)
{
    $keys = array_map('strval', array_keys($scores));
    $q = (int) $qualifiers;
    $complete = !in_array(null, $scores, true) && $keys;

    // Ordre : score décroissant, ordre de départage saisi, puis ordre d'origine.
    $pos = array_flip($keys);
    $sorted = $keys;
    usort($sorted, function ($a, $b) use ($scores, $orders, $pos) {
        $sa = $scores[$a] ?? -1;
        $sb = $scores[$b] ?? -1;
        if ($sa !== $sb) return $sb <=> $sa;
        $oa = $orders[$a] ?? PHP_INT_MAX;
        $ob = $orders[$b] ?? PHP_INT_MAX;
        return $oa <=> $ob ?: $pos[$a] <=> $pos[$b];
    });

    $res = [
        'complete' => (bool) $complete, 'order' => $sorted,
        'rank' => array_fill_keys($keys, null), 'status' => array_fill_keys($keys, null),
        'pending' => [], 'qualified' => null,
    ];
    if (!$complete) return $res;

    // Groupes de même score, dans l'ordre.
    $groups = [];
    foreach ($sorted as $k) $groups[(int) $scores[$k]][] = $k;

    $at = 0; // équipes déjà placées devant
    $cutResolved = true;
    foreach ($groups as $group) {
        $size = count($group);
        $first = $at + 1;
        $last = $at + $size;
        $allQualified = $last <= $q;

        if ($size === 1 || $allQualified) {
            // Pas de départage nécessaire : place partagée si plusieurs qualifiées ex aequo.
            foreach ($group as $k) $res['rank'][$k] = $first;
        } elseif (tdm_bso_order_complete($group, $orders)) {
            foreach ($group as $i => $k) $res['rank'][$k] = $first + $i; // déjà trié par ordre saisi
        } else {
            $res['pending'][] = $group;
            if ($first <= $q && $last > $q) $cutResolved = false;
        }
        foreach ($group as $k) {
            if ($last <= $q) $res['status'][$k] = 'q';
            elseif ($first > $q) $res['status'][$k] = 'e';
            elseif ($res['rank'][$k] !== null) $res['status'][$k] = $res['rank'][$k] <= $q ? 'q' : 'e';
        }
        $at = $last;
    }
    if ($cutResolved) {
        $res['qualified'] = array_values(array_filter($sorted, fn($k) => $res['status'][$k] === 'q'));
    }
    return $res;
}

/** Chaque équipe du groupe a un ordre saisi, et ces ordres forment 1…n. */
function tdm_bso_order_complete(array $group, array $orders)
{
    $seen = [];
    foreach ($group as $k) {
        $o = $orders[$k] ?? null;
        if ($o === null || $o < 1 || $o > count($group) || isset($seen[$o])) return false;
        $seen[$o] = true;
    }
    return true;
}

/**
 * Déroulé complet d'un BSO.
 *
 * @param string[] $teams Équipes au départ, dans l'ordre des cibles.
 * @param array<int, array<string, array{score: int|null, order: int|null}>> $ends
 *        Volée => clé => saisie. Les saisies d'équipes qui ne tirent pas la volée sont ignorées.
 * @return array{
 *     sizes: int[],
 *     rounds: array<int, array>,   volée => tdm_bso_round() + teams (équipes de la volée) + qualifiers
 *     places: array<string, int>,  places finales déjà acquises
 *     current: int,                volée en cours (0 si pas de BSO)
 *     finished: bool
 * }
 */
function tdm_bso_run(array $teams, array $ends)
{
    $teams = array_values(array_map('strval', $teams));
    $sizes = tdm_bso_sizes(count($teams));
    $out = ['sizes' => $sizes, 'rounds' => [], 'places' => [], 'current' => 0, 'finished' => false];

    if (count($teams) === 1) {
        $out['places'][$teams[0]] = 1;
        $out['finished'] = true;
        return $out;
    }

    $players = $teams;
    foreach ($sizes as $r => $size) {
        $q = $sizes[$r + 1] ?? 1;
        $scores = $orders = [];
        foreach ($players as $k) {
            $e = $ends[$r][$k] ?? [];
            $scores[$k] = isset($e['score']) ? (int) $e['score'] : null;
            $orders[$k] = isset($e['order']) ? (int) $e['order'] : null;
        }
        $res = tdm_bso_round($scores, $orders, $q);
        $res['teams'] = $players;
        $res['qualifiers'] = $q;
        $out['rounds'][$r] = $res;
        $out['current'] = $r;

        // Places des éliminés de cette volée (et du vainqueur en finale).
        foreach ($res['rank'] as $k => $rank) {
            if ($rank === null) continue;
            if ($res['status'][$k] === 'e') $out['places'][$k] = $rank;
            if ($q === 1 && $res['status'][$k] === 'q') $out['places'][$k] = 1;
        }

        if ($res['qualified'] === null || !$res['complete']) break;
        if ($q === 1) {
            // Finale jouée : terminé quand toutes les égalités de toutes les volées sont tranchées.
            $out['finished'] = !array_filter($out['rounds'], fn($rd) => $rd['pending']);
            break;
        }
        // Volée suivante : les qualifiés, dans l'ordre des cibles. Une égalité entre
        // éliminés (places 5, 6…) ne bloque pas la suite : elle se tranche en parallèle.
        $players = array_values(array_filter($players, fn($k) => in_array($k, $res['qualified'], true)));
    }
    asort($out['places']);
    return $out;
}
