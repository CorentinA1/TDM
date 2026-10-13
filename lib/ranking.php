<?php
/**
 * lib/ranking.php — places et points du classement final d'une catégorie.
 *
 * Fonctions pures, sans base ni ianseo : tout ce qu'elles savent leur est
 * passé en paramètre. Règlement TDM 2026, section 5.
 *
 * - Le Big Shoot Off (BSO) réunit les 1ers de poule ; avec une seule poule,
 *   les deux premières équipes de la poule.
 * - Les équipes du BSO prennent les places 1, 2, 3… données par le BSO.
 * - Les autres sont classées par rang de poule, toutes ex aequo à rang égal :
 *   tous les 2es de poule à la place qui suit les équipes du BSO, puis tous
 *   les 3es, etc. (6 poules : 2es 7e, 3es 13e, 4es 19e).
 * - Barème de points : 10, 9, 8, 7 pour les quatre premiers du BSO, 6 pour
 *   les autres équipes du BSO, puis 5, 4, 3 pour les 2es, 3es, 4es de poule,
 *   0 au-delà. Avec une seule poule, on ne décale pas : le 3e de poule reste
 *   « 3e de poule », 4 points (décision du 12 octobre 2026).
 * - Poule unique sans BSO (le règlement laisse le choix à l'organisateur,
 *   décision du 13 octobre 2026) : classement de la poule ; 1er = 1re place,
 *   10 points ; 2e = 2e place, 9 points ; puis 3e de poule 4 points, 4e 3 points.
 */

/** Points du 1er et du 2e d'une poule unique tirée sans BSO. */
const TDM_POINTS_NO_BSO = [1 => 10, 2 => 9];

/** Points des quatre premières places du BSO. */
const TDM_POINTS_BSO_PODIUM = [1 => 10, 2 => 9, 3 => 8, 4 => 7];
/** Points d'une équipe du BSO classée au-delà de la 4e place. */
const TDM_POINTS_BSO_OTHER = 6;
/** Points d'une équipe hors BSO, selon son rang de poule. */
const TDM_POINTS_POOL_RANK = [2 => 5, 3 => 4, 4 => 3];

/**
 * Nombre d'équipes qui tirent le BSO.
 *
 * @param int $pools Nombre de poules de la catégorie.
 * @return int Une par poule, ou 2 avec une poule unique.
 */
function tdm_bso_size($pools)
{
    return $pools > 1 ? (int) $pools : 2;
}

/**
 * L'équipe va-t-elle au BSO ?
 *
 * @param int $pools Nombre de poules de la catégorie.
 * @param int $poolRank Rang dans sa poule (0 = pas encore classée).
 */
function tdm_in_bso($pools, $poolRank)
{
    if ($poolRank < 1) return false;
    return $pools > 1 ? $poolRank === 1 : $poolRank <= 2;
}

/**
 * Points du barème pour une équipe.
 *
 * @param bool $inBso L'équipe a tiré le BSO.
 * @param int|null $place Place finale (pour une équipe du BSO).
 * @param int $poolRank Rang de poule (pour une équipe hors BSO).
 */
function tdm_points($inBso, $place, $poolRank)
{
    if ($inBso) {
        if ($place === null) return 0;
        return TDM_POINTS_BSO_PODIUM[$place] ?? TDM_POINTS_BSO_OTHER;
    }
    return TDM_POINTS_POOL_RANK[$poolRank] ?? 0;
}

/**
 * Classement final d'une catégorie.
 *
 * @param int $pools Nombre de poules de la catégorie.
 * @param array<string, int> $poolRanks Clé d'équipe => rang dans sa poule (0 = non classée).
 * @param array<string, int|null> $bsoPlaces Clé d'équipe => place issue du BSO
 *        (1, 2, 3… sans ex aequo), null ou absente tant que le BSO n'a pas classé l'équipe.
 * @param bool $withBso false : poule unique tirée sans BSO (ignoré avec plusieurs poules).
 * @return array<string, array{place: int|null, points: int, bso: bool}>
 *         Dans l'ordre du classement ; place null = pas encore classée.
 */
function tdm_final_ranking($pools, array $poolRanks, array $bsoPlaces, $withBso = true)
{
    $pools = max(1, (int) $pools);
    $bsoSize = tdm_bso_size($pools);

    // Poule unique sans BSO : la place est le rang de poule.
    if ($pools === 1 && !$withBso) {
        $out = [];
        foreach ($poolRanks as $key => $rank) {
            $rank = (int) $rank;
            $out[(string) $key] = $rank >= 1
                ? ['place' => $rank, 'points' => TDM_POINTS_NO_BSO[$rank] ?? tdm_points(false, null, $rank), 'bso' => false]
                : ['place' => null, 'points' => 0, 'bso' => false];
        }
        uasort($out, fn($a, $b) => ($a['place'] ?? PHP_INT_MAX) <=> ($b['place'] ?? PHP_INT_MAX));
        return $out;
    }

    $out = [];
    $byPoolRank = []; // rang de poule => clés des équipes hors BSO
    $unranked = [];

    foreach ($poolRanks as $key => $rank) {
        $rank = (int) $rank;
        $key = (string) $key;
        if (tdm_in_bso($pools, $rank)) {
            $place = isset($bsoPlaces[$key]) ? (int) $bsoPlaces[$key] : null;
            if ($place !== null && ($place < 1 || $place > $bsoSize)) $place = null;
            $out[$key] = ['place' => $place, 'points' => tdm_points(true, $place, $rank), 'bso' => true];
        } elseif ($rank >= 1) {
            $byPoolRank[$rank][] = $key;
        } else {
            $unranked[] = $key;
        }
    }

    // Hors BSO : tous les n-ièmes de poule ex aequo, à la suite des équipes déjà classées.
    ksort($byPoolRank);
    $next = $bsoSize + 1;
    foreach ($byPoolRank as $rank => $keys) {
        foreach ($keys as $key) {
            $out[$key] = ['place' => $next, 'points' => tdm_points(false, null, $rank), 'bso' => false];
        }
        $next += count($keys);
    }
    foreach ($unranked as $key) {
        $out[$key] = ['place' => null, 'points' => 0, 'bso' => false];
    }

    // Ordre d'affichage : places croissantes, puis non classées ; ordre d'origine à égalité.
    $order = array_flip(array_keys($poolRanks));
    uksort($out, function ($a, $b) use ($out, $order) {
        $pa = $out[$a]['place'] ?? PHP_INT_MAX;
        $pb = $out[$b]['place'] ?? PHP_INT_MAX;
        return $pa <=> $pb ?: $order[$a] <=> $order[$b];
    });
    return $out;
}
