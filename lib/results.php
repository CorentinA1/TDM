<?php
/**
 * lib/results.php — résultats d'une catégorie : place finale et points.
 *
 * Assemble ce que les autres étapes ont produit, sans rien recalculer du cœur :
 *  - équipes, archers et qualif : tdm_event_teams() (lib/qualif.php) ;
 *  - poule et rang de poule : tdm_pools() (lib/pools.php) ;
 *  - places du BSO : tdm_bso_state() (lib/bsodb.php) ;
 *  - place finale et points du règlement : tdm_final_ranking() (lib/ranking.php).
 * Les colonnes suivent la feuille de résultats du classeur de l'utilisateur
 * (club, n° d'agrément, archers et licences, score et rang de qualif, rang de
 * poule, points), avec en plus la place finale.
 */
require_once __DIR__ . '/qualif.php';
require_once __DIR__ . '/pools.php';
require_once __DIR__ . '/ranking.php';
require_once __DIR__ . '/bsodb.php';

/**
 * Résultats d'une catégorie.
 *
 * @return object Rows (dans l'ordre du classement), Status (Pools, Matches, Played, ByesLeft,
 *         BsoTeams, BsoFinished), Final (true si poules et BSO sont terminés).
 */
function tdm_category_results($ev)
{
    $teams = tdm_event_teams($ev);
    $pools = $ev->Pools ? tdm_pools($ev) : [];

    // Rangs de poule (0 tant que la poule n'est pas classée) et poule de chaque équipe.
    $poolRanks = $poolOf = $poolPts = [];
    $matches = $played = $byesLeft = 0;
    foreach ($pools as $g => $pool) {
        $matches += $pool->Matches;
        $played += $pool->Played;
        $byesLeft += $pool->Byes - $pool->ByesApplied;
        foreach ($pool->Rows as $r) {
            $poolRanks[$r->Key] = $r->Rank;
            $poolOf[$r->Key] = $g;
            $poolPts[$r->Key] = [$r->Points, $r->SetPoints];
        }
    }

    // Places du BSO (seulement celles déjà acquises).
    $bsoPlaces = [];
    $bsoTeams = 0;
    $bsoFinished = false;
    if ($ev->Pools) {
        $state = tdm_bso_state($ev);
        $bsoTeams = count($state['teams']);
        $bsoFinished = (bool) $state['finished'];
        foreach ($state['places'] as $p) $bsoPlaces[$p['key']] = (int) $p['place'];
    }

    $withBso = tdm_bso_enabled($ev);
    $ranking = tdm_final_ranking(max(1, (int) $ev->Pools), $poolRanks, $bsoPlaces, $withBso);

    // Nombre d'équipes à chaque place (ex aequo du règlement).
    $count = array_count_values(array_filter(array_column($ranking, 'place')));

    $rows = [];
    foreach ($ranking as $key => $f) {
        $rows[$key] = tdm_result_row($teams[$key] ?? null, $key, $f, $poolOf[$key] ?? 0, $poolRanks[$key] ?? 0,
            $bsoPlaces[$key] ?? null, $poolPts[$key] ?? [0, 0], $count);
    }
    // Équipes qui n'ont pas joué les poules (sans score de qualif, forfait…) : à la fin.
    foreach ($teams as $key => $t) {
        if (isset($rows[$key])) continue;
        $rows[$key] = tdm_result_row($t, $key, ['place' => null, 'points' => 0, 'bso' => false], 0, 0, null, [0, 0], $count);
    }

    $poolsDone = $ev->Pools && $matches > 0 && $played === $matches && !$byesLeft;
    return (object) [
        'Rows' => array_values($rows),
        'Status' => (object) ['Pools' => (int) $ev->Pools, 'Matches' => $matches, 'Played' => $played, 'ByesLeft' => $byesLeft,
                              'BsoTeams' => $bsoTeams, 'BsoFinished' => $bsoFinished, 'WithBso' => $withBso],
        'Final' => $poolsDone && ($bsoFinished || !$withBso),
    ];
}

/** Une ligne de résultat (équipe, archers femme / homme, qualif, poule, BSO, place, points). */
function tdm_result_row($t, $key, array $f, $pool, $poolRank, $bsoPlace, array $poolPts, array $count)
{
    $woman = $man = null;
    foreach ($t->Members ?? [] as $m) {
        if ((int) $m->EnSex === 1 && !$woman) $woman = $m;
        elseif ((int) $m->EnSex === 0 && !$man) $man = $m;
    }
    $name = fn($m) => $m ? trim($m->EnName . ' ' . $m->EnFirstName) : '';
    return (object) [
        'Key' => $key, 'Name' => $t->Name ?? $key, 'ClubCode' => $t->ClubCode ?? '',
        'Woman' => $name($woman), 'WomanCode' => $woman->EnCode ?? '', 'Man' => $name($man), 'ManCode' => $man->EnCode ?? '',
        'Qualif' => $t->Score ?? null, 'QualifRank' => (int) ($t->Rank ?? 0),
        'Pool' => (int) $pool, 'PoolRank' => (int) $poolRank, 'PoolPoints' => $poolPts[0], 'SetPoints' => $poolPts[1],
        'Bso' => (bool) $f['bso'], 'BsoPlace' => $bsoPlace,
        'Place' => $f['place'], 'Tie' => $f['place'] !== null && ($count[$f['place']] ?? 0) > 1, 'Points' => (int) $f['points'],
    ];
}

/** Place affichée : « 7 » ou « 7 = » (ex aequo du règlement), vide si pas encore classée. */
function tdm_place_label($row)
{
    return $row->Place === null ? '' : $row->Place . ($row->Tie ? ' =' : '');
}

/** Libellé d'état d'une catégorie : « Résultats définitifs », « Provisoire : … ». */
function tdm_results_status($res)
{
    if ($res->Final) return 'Résultats définitifs';
    $s = $res->Status;
    if (!$s->Pools) return 'Poules non configurées';
    $why = [];
    if ($s->Played < $s->Matches) $why[] = ($s->Matches - $s->Played) . ' match(s) de poule à saisir';
    if ($s->ByesLeft) $why[] = $s->ByesLeft . ' bye(s) 5-0 à appliquer';
    if (!$s->WithBso) { /* poule unique sans BSO : classement de la poule */ }
    elseif (!$s->BsoTeams) $why[] = 'BSO non confirmé';
    elseif (!$s->BsoFinished) $why[] = 'BSO en cours';
    return 'Provisoire' . ($why ? ' : ' . implode(', ', $why) : '');
}
