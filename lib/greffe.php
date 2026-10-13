<?php
/**
 * lib/greffe.php — données des listes du greffe (page greffe.php, PDF pdf-greffe.php).
 *
 * Tous les archers de la compétition, avec leur équipe TDM s'ils en ont une
 * (catégorie, nom d'équipe, départ, cible de qualification), et les équipes
 * inscrites. Les archers sans équipe restent dans les listes : le greffe doit
 * les voir.
 */
require_once __DIR__ . '/qualif.php';
require_once __DIR__ . '/targets.php';
require_once __DIR__ . '/departs.php';
require_once __DIR__ . '/setup.php';

/**
 * Données du greffe.
 *
 * @param int $dep Départ à garder (0 = tous).
 * @return array{archers: object[], teams: object[], deps: array<int, string>}
 *         archers : Id, Name (« NOM Prénom »), Code, Sex, Club, ClubCode, Cat (code TDM ou ''), CatName,
 *                   Team (nom d'équipe ou ''), Dep (n° de départ, 0 = aucun), DepLabel, Target (« 3A » ou ''), Status ;
 *         teams : Cat, CatName, Name, Club, ClubCode, Woman, WomanCode, Man, ManCode, Dep, DepLabel, Target, Issues ;
 *         deps : départs utilisés, n° => libellé.
 */
function tdm_greffe_data($dep = 0)
{
    $depMap = tdm_departs_map();
    $sessions = $depMap ? tdm_qualif_sessions() : [];
    $label = fn($n) => $n ? tdm_depart_label($n, $sessions[$n] ?? []) : '';
    $np = fn($m) => trim(mb_strtoupper((string) $m->EnFirstName) . ' ' . $m->EnName);

    // Équipe de chaque archer, et liste des équipes.
    $teamOf = [];
    $teams = [];
    foreach (tdm_events() as $code => $ev) {
        $evTeams = tdm_event_teams($ev);
        $targets = tdm_team_targets($evTeams);
        $n = (int) ($depMap[$code] ?? 0);
        foreach ($evTeams as $key => $t) {
            [$tg, $pos] = $targets[$key] ?? [0, 'A'];
            $target = tdm_team_target_label($tg, $pos);
            $w = $m = null;
            foreach ($t->Members as $mem) {
                $teamOf[(int) $mem->EnId] = ['cat' => $code, 'catName' => $ev->Name, 'team' => $t->Name, 'dep' => $n, 'target' => $target];
                if ((int) $mem->EnSex === 1 && !$w) $w = $mem;
                elseif ((int) $mem->EnSex === 0 && !$m) $m = $mem;
            }
            $teams[] = (object) [
                'Cat' => $code, 'CatName' => $ev->Name, 'Name' => $t->Name, 'Sub' => $t->SubTeam,
                'Club' => (string) ($t->Members[0]->CoName ?? ''), 'ClubCode' => (string) $t->ClubCode,
                'Woman' => $w ? $np($w) : '', 'WomanCode' => $w->EnCode ?? '', 'Man' => $m ? $np($m) : '', 'ManCode' => $m->EnCode ?? '',
                'Dep' => $n, 'DepLabel' => $label($n), 'Target' => $target, 'TargetSort' => [$tg ?: PHP_INT_MAX, $pos],
                'Issues' => $t->Issues,
            ];
        }
    }

    $archers = [];
    foreach (tdm_setup_archers() as $id => $a) {
        $cat = tdm_category_of_div($a->EnDivision);
        $tm = $teamOf[$id] ?? null;
        $catCode = $tm['cat'] ?? ($cat ?? '');
        $n = $tm['dep'] ?? (int) ($catCode !== '' ? ($depMap[$catCode] ?? 0) : 0);
        $archers[] = (object) [
            'Id' => (int) $id, 'Name' => $np($a), 'Code' => (string) $a->EnCode, 'Sex' => (int) $a->EnSex,
            'Club' => (string) $a->CoName, 'ClubCode' => (string) $a->CoCode,
            'Cat' => $catCode, 'CatName' => $tm['catName'] ?? ($cat ? TDM_CATEGORIES[$cat]['name'] : 'Hors TDM (' . $a->EnDivision . ')'),
            'Team' => $tm['team'] ?? '', 'Dep' => $n, 'DepLabel' => $label($n), 'Target' => $tm['target'] ?? '',
            'Status' => (int) $a->EnStatus,
        ];
    }

    if ($dep) {
        $archers = array_values(array_filter($archers, fn($a) => $a->Dep === (int) $dep));
        $teams = array_values(array_filter($teams, fn($t) => $t->Dep === (int) $dep));
    }
    $deps = [];
    foreach (array_unique(array_values($depMap)) as $n) $deps[(int) $n] = $label((int) $n);
    ksort($deps);
    return ['archers' => $archers, 'teams' => $teams, 'deps' => $deps];
}

/** Archers triés par nom (liste alphabétique). Fonction pure. */
function tdm_greffe_sort_alpha(array $archers)
{
    usort($archers, fn($a, $b) => strnatcasecmp($a->Name, $b->Name) ?: strcmp($a->Code, $b->Code));
    return $archers;
}

/** Archers groupés par club, triés par équipe puis femme d'abord (feuille de greffe). Fonction pure. */
function tdm_greffe_by_club(array $archers)
{
    $order = array_flip(array_keys(TDM_CATEGORIES));
    usort($archers, fn($a, $b) => strnatcasecmp($a->Club, $b->Club)
        ?: ($a->Team === '') <=> ($b->Team === '')
        ?: ($order[$a->Cat] ?? 99) <=> ($order[$b->Cat] ?? 99)
        ?: strnatcasecmp($a->Team, $b->Team) ?: $b->Sex <=> $a->Sex ?: strnatcasecmp($a->Name, $b->Name));
    $out = [];
    foreach ($archers as $a) $out[$a->Club ?: '(sans club)'][] = $a;
    return $out;
}

/** Équipes groupées par catégorie (ordre du règlement), triées par club puis numéro. Fonction pure. */
function tdm_greffe_teams_by_cat(array $teams)
{
    $out = [];
    foreach (array_keys(TDM_CATEGORIES) as $c) $out[$c] = [];
    foreach ($teams as $t) $out[$t->Cat][] = $t;
    foreach ($out as &$list) usort($list, fn($a, $b) => strnatcasecmp($a->Club, $b->Club) ?: $a->Sub <=> $b->Sub);
    unset($list);
    return array_filter($out);
}
