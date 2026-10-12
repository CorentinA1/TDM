<?php
/**
 * lib/setup.php — configuration d'une compétition TDM (étapes 1, 3 et 4 ; l'étape 2 est dans lib/departs.php).
 *
 *  1. Division NE (néo-licenciés), classes qui l'acceptent, distances.
 *  2. Archers : saisis ou importés dans IANSEO, équipes composées avec
 *     equipes.php (division, « Épreuve Équipe Mixte », numéro d'équipe).
 *  3. Épreuves d'équipe mixte NEO, CL, CO, BB en Round Robin à un niveau,
 *     avec le nombre de poules et leur taille choisis par l'organisateur.
 *
 * Le modèle est la compétition de test configurée à la main par l'utilisateur
 * (12 octobre 2026). Les écritures reprennent celles des pages IANSEO
 * (Tournament/AddDiv.php, Final/Team/AddEvent.php, AddEventRule.php,
 * SetMixedTeam.php, SetMultiTeam.php, ListEvents-Eliminations-data.php,
 * Modules/RoundRobin/Setup-data.php « setDetails »).
 */

/**
 * Les 4 catégories : code d'épreuve => division, nom, blason (type, taille).
 * L'ordre est celui des épreuves dans IANSEO.
 */
const TDM_CATEGORIES = [
    'NEO' => ['div' => 'NE', 'name' => 'Néo-licenciés arc classique', 'target' => 1, 'size' => 60, 'from' => 'U11'],
    'CL'  => ['div' => 'CL', 'name' => 'Arc classique',               'target' => 1, 'size' => 40, 'from' => 'U11'],
    'CO'  => ['div' => 'CO', 'name' => 'Arc à poulies',               'target' => 4, 'size' => 40, 'from' => 'U15'],
    'BB'  => ['div' => 'BB', 'name' => 'Arc nu',                      'target' => 1, 'size' => 60, 'from' => 'U11'],
];
/** Catégories d'âge des règles d'épreuve, de la plus jeune à la plus âgée (+ F / H). */
const TDM_AGE_CLASSES = ['U11', 'U13', 'U15', 'U18', 'U21', 'S1', 'S2', 'S3'];

/** Division des néo-licenciés, créée par l'étape 1. */
const TDM_NE_DIVISION = 'NE';
const TDM_NE_DESCRIPTION = 'Néo-licenciés Arc Classique';
/** Blason des néo-licenciés : 60 cm, comme en arc nu (règlement). */
const TDM_NE_FACE_NAME = 'Blason Unique 60cm Néo-licenciés';
/** Classes qui acceptent la division NE (comme sur la compétition modèle). */
const TDM_NE_CLASSES = ['U13F', 'U13H', 'U15F', 'U15H', 'U18F', 'U18H', 'U21F', 'U21H',
                        'S1F', 'S1H', 'S2F', 'S2H', 'S3F', 'S3H'];
/** Divisions que la compétition doit déjà avoir (création « salle 18 m » FFTA). */
const TDM_BASE_DIVISIONS = ['CL', 'CO', 'BB'];

/** Matchs de poule (règlement) : 4 volées de 4 flèches, barrage 2 flèches, victoire 2 points. */
const TDM_RR_SETTINGS = [
    'RrLevMatchMode' => 1, 'RrLevBestRankMode' => 0, 'RrLevArrows' => 4, 'RrLevEnds' => 4, 'RrLevSO' => 2,
    'RrLevTieAllowed' => 0, 'RrLevWinPoints' => 2, 'RrLevTiePoints' => 0,
    'RrLevTieBreakSystem' => 2, 'RrLevTieBreakSystem2' => 0,
];
/** Taille de poule acceptée. */
const TDM_POOL_MIN = 2;
const TDM_POOL_MAX = 16;

// ── Fonctions pures ───────────────────────────────────────────────────────────

/** Catégorie TDM d'une division, ou null. */
function tdm_category_of_div($div)
{
    foreach (TDM_CATEGORIES as $code => $c) {
        if ($c['div'] === $div) return $code;
    }
    return null;
}

/**
 * Équipes que formera IANSEO à partir des archers. Fonction pure.
 *
 * Même règle que MakeTeamsAbs : même club, même division, « Épreuve Équipe
 * Mixte » cochée, même numéro d'équipe ; un numéro vide vaut 1.
 *
 * @param array $archers Liste de ['id', 'club', 'clubName', 'sex' (1 = femme), 'div', 'mix' (0/1), 'sub'].
 * @return array{teams: array, counts: array, issues: array}
 *         teams : catégorie => clé « club-numéro » => [club, sub, name, women[], men[], ok] ;
 *         counts : catégorie => nombre d'équipes complètes ;
 *         issues : liste de [cat|null, club, message].
 */
function tdm_compose(array $archers)
{
    $teams = $counts = [];
    $issues = [];
    foreach (TDM_CATEGORIES as $code => $c) {
        $teams[$code] = [];
        $counts[$code] = 0;
    }
    foreach ($archers as $a) {
        if (empty($a['mix'])) continue;
        $cat = tdm_category_of_div($a['div']);
        if ($cat === null) {
            $issues[] = [null, $a['club'], "« Épreuve Équipe Mixte » cochée mais division {$a['div']} hors TDM"];
            continue;
        }
        $sub = max(1, (int) $a['sub']);
        $key = $a['club'] . '-' . $sub;
        if (!isset($teams[$cat][$key])) {
            $teams[$cat][$key] = ['club' => $a['club'], 'sub' => $sub, 'clubName' => $a['clubName'],
                                  'women' => [], 'men' => [], 'ok' => false];
        }
        $teams[$cat][$key][((int) $a['sex'] === 1) ? 'women' : 'men'][] = $a['id'];
    }
    foreach ($teams as $cat => &$list) {
        ksort($list, SORT_NATURAL);
        foreach ($list as &$t) {
            $w = count($t['women']);
            $m = count($t['men']);
            $t['ok'] = ($w === 1 && $m === 1);
            if ($t['ok']) {
                $counts[$cat]++;
            } elseif ($w + $m === 1) {
                $issues[] = [$cat, $t['club'], "équipe {$t['sub']} incomplète : il manque " . ($w ? 'un homme' : 'une femme')];
            } else {
                $issues[] = [$cat, $t['club'], "équipe {$t['sub']} : $w femme(s) et $m homme(s) au lieu d'une femme et d'un homme"];
            }
        }
        unset($t);
    }
    unset($list);
    return ['teams' => $teams, 'counts' => $counts, 'issues' => $issues];
}

/**
 * Contrôle un choix de poules. Fonction pure. Rien n'est imposé : seules les
 * valeurs impossibles sont refusées, le reste donne des avertissements.
 *
 * @return array{ok: bool, byes: int, errors: string[], warnings: string[]}
 */
function tdm_pool_check($teams, $groups, $size)
{
    $errors = $warnings = [];
    if ($groups < 1) $errors[] = 'au moins une poule';
    if ($size < TDM_POOL_MIN || $size > TDM_POOL_MAX) $errors[] = 'taille de poule entre ' . TDM_POOL_MIN . ' et ' . TDM_POOL_MAX;
    if ($errors) return ['ok' => false, 'byes' => 0, 'errors' => $errors, 'warnings' => []];

    $places = $groups * $size;
    $byes = max(0, $places - $teams);
    if ($teams > $places) {
        $warnings[] = ($teams - $places) . " équipe(s) n'auront pas de place en poule ($places places pour $teams équipes)";
    } elseif ($teams > 0 && $places - $teams >= $size) {
        $warnings[] = 'au moins une poule restera vide';
    } elseif ($byes > 0) {
        $warnings[] = "$byes place(s) vide(s) : byes comptés 5-0";
    }
    if ($size % 2) $warnings[] = 'poule de taille impaire : une équipe au repos à chaque tour';
    return ['ok' => true, 'byes' => $byes, 'errors' => [], 'warnings' => $warnings];
}

/**
 * Valeur de départ du formulaire de l'étape 4 (modifiable). Fonction pure.
 * Poules de 6 au plus, ramenées à 4 quand c'est assez.
 *
 * @return array{0: int, 1: int} [poules, taille]
 */
function tdm_pool_suggest($teams)
{
    if ($teams <= 2) return [1, 2];
    $groups = (int) ceil($teams / 6);
    $size = (int) ceil($teams / $groups) <= 4 ? 4 : 6;
    return [$groups, $size];
}

/** Divisions interchangeables par glisser-déposer : un classique devient néo-licencié et inversement. */
const TDM_SWAP_DIVISIONS = ['CL', 'NE'];

/**
 * Archer déposé dans une équipe : modifications à enregistrer. Fonction pure.
 *
 * L'archer prend la division de la catégorie (CL ↔ NE seulement), est engagé
 * en équipe mixte avec le numéro de l'équipe. S'il y a déjà dans cette équipe
 * un archer du même sexe, celui-ci retourne dans les non placés.
 *
 * @param array $archers EnId => [club, sex (1 = femme), div, cls, mix, sub, status].
 * @param array $classDivs Classe => divisions acceptées (null = toutes).
 * @return array{error: string|null, changes: array<int, array{div: string, mix: int, sub: int}>}
 */
function tdm_place_changes(array $archers, $id, $cat, $sub, array $classDivs)
{
    $fail = fn($msg) => ['error' => $msg, 'changes' => []];
    if (!isset($archers[$id])) return $fail('archer inconnu');
    if (!isset(TDM_CATEGORIES[$cat])) return $fail('catégorie inconnue');
    $sub = (int) $sub;
    if ($sub < 1 || $sub > 99) return $fail("numéro d'équipe invalide");
    $a = $archers[$id];
    if ((int) $a['status'] > 1) return $fail('cet archer ne tire pas (statut)');

    $div = TDM_CATEGORIES[$cat]['div'];
    if ($a['div'] !== $div) {
        if (!in_array($a['div'], TDM_SWAP_DIVISIONS, true) || !in_array($div, TDM_SWAP_DIVISIONS, true)) {
            return $fail("archer en division {$a['div']} : il ne peut pas tirer en $cat");
        }
        $allowed = $classDivs[$a['cls']] ?? null;
        if ($allowed !== null && !in_array($div, $allowed, true)) {
            return $fail("la classe {$a['cls']} n'accepte pas la division $div");
        }
    }

    $changes = [];
    foreach ($archers as $oid => $o) {
        if ($oid == $id || $o['club'] !== $a['club'] || (int) $o['sex'] !== (int) $a['sex']) continue;
        if (empty($o['mix']) || (int) $o['status'] > 1 || $o['div'] !== $div || max(1, (int) $o['sub']) !== $sub) continue;
        $changes[$oid] = ['div' => $o['div'], 'mix' => 0, 'sub' => 0];
    }
    $changes[$id] = ['div' => $div, 'mix' => 1, 'sub' => $sub];
    return ['error' => null, 'changes' => $changes];
}

/** Archer retiré de son équipe : il garde sa division. Fonction pure. */
function tdm_remove_changes(array $archers, $id)
{
    if (!isset($archers[$id])) return [];
    return [$id => ['div' => $archers[$id]['div'], 'mix' => 0, 'sub' => 0]];
}

/**
 * « Composer automatiquement » un club. Fonction pure.
 *
 * Dans chaque catégorie où le club a exactement une femme et un homme non
 * placés, forme une équipe avec le premier numéro libre.
 *
 * @return array<int, array{div: string, mix: int, sub: int}>
 */
function tdm_auto_changes(array $archers, $club)
{
    $changes = [];
    foreach (TDM_CATEGORIES as $cat => $c) {
        $free = [1 => [], 0 => []];
        $used = [];
        foreach ($archers as $id => $a) {
            if ($a['club'] !== $club || $a['div'] !== $c['div'] || (int) $a['status'] > 1) continue;
            if (empty($a['mix'])) $free[(int) $a['sex'] === 1 ? 1 : 0][] = $id;
            else $used[max(1, (int) $a['sub'])] = true;
        }
        if (count($free[1]) !== 1 || count($free[0]) !== 1) continue;
        $sub = 1;
        while (isset($used[$sub])) $sub++;
        foreach ([$free[1][0], $free[0][0]] as $id) $changes[$id] = ['div' => $c['div'], 'mix' => 1, 'sub' => $sub];
    }
    return $changes;
}

/**
 * Répartition en serpentin selon le rang de qualif (comme IANSEO « autoSeed »
 * et le classeur Excel) : 1, 2, 3 puis 6, 5, 4… Fonction pure.
 *
 * @return array<int, array<int, int>> poule => [place dans la poule => rang de qualif]
 */
function tdm_snake($groups, $size)
{
    $out = [];
    $rank = 1;
    for ($item = 1; $item <= $size; $item++) {
        $order = range(1, $groups);
        if ($item % 2 === 0) $order = array_reverse($order);
        foreach ($order as $g) $out[$g][$item] = $rank++;
    }
    ksort($out);
    return $out;
}

/**
 * Cibles occupées par une catégorie : chaque match de poule garde sa cible
 * (deux équipes, A et B), les poules se suivent. Fonction pure.
 *
 * @return array{0: int, 1: int} [première cible, dernière cible]
 */
function tdm_target_range($start, $groups, $size)
{
    $perPool = (int) ceil($size / 2);
    return [(int) $start, (int) $start + $groups * $perPool - 1];
}

/**
 * Répartition des poules : configurations à montrer pour $n équipes. Fonction pure.
 * Poules de 4 (1 à 7) et de 6 (1 à 5) qui accueillent tout le monde sans poule
 * vide, plus la configuration réglée ($current = [poules, taille]) si elle n'y est pas.
 *
 * @return array<string, array{0: int, 1: int}> « 4×6 » => [poules, taille], par nombre de places.
 */
function tdm_rep_configs($n, $current)
{
    $out = [];
    foreach ([4 => 7, 6 => 5] as $size => $max) {
        for ($g = 1; $g <= $max; $g++) {
            $places = $g * $size;
            if ($places >= $n && $places - $n < $size) $out["{$g}×{$size}"] = [$g, $size];
        }
    }
    if ($current && !isset($out[$current[0] . '×' . $current[1]])) $out[$current[0] . '×' . $current[1]] = $current;
    uasort($out, fn($a, $b) => $a[0] * $a[1] <=> $b[0] * $b[1] ?: $a[1] <=> $b[1]);
    return $out;
}
/**
 * Équipes d'une catégorie dans l'ordre de la qualif (rang IANSEO, puis score, puis nom).
 * Fonction pure sur la sortie de tdm_event_teams().
 */
function tdm_rep_order(array $teams)
{
    $teams = array_values($teams);
    usort($teams, function ($a, $b) {
        return ($a->Rank ?: PHP_INT_MAX) <=> ($b->Rank ?: PHP_INT_MAX)
            ?: ($b->Score ?? -1) <=> ($a->Score ?? -1) ?: strnatcasecmp($a->Name, $b->Name);
    });
    return $teams;
}

// ── Compétition configurée ────────────────────────────────────────────────────

/**
 * La configuration TDM de la compétition ouverte a-t-elle été enregistrée
 * (étape 1) ? Tant que non, le menu n'offre que « Configuration » et la barre
 * TDM n'est pas affichée (menu.php). Paramètre de module TDM « Configured ».
 */
function tdm_is_configured()
{
    return (int) getModuleParameter('TDM', 'Configured', 0) === 1;
}

function tdm_set_configured($on = true)
{
    setModuleParameter('TDM', 'Configured', $on ? 1 : 0);
}

// ── Étape 1 : division, classes, distances ───────────────────────────────────

/**
 * État de l'étape 1.
 *
 * @return array{ok: bool, blocking: string[], checks: array<int, array{0: bool, 1: string}>}
 *         blocking : ce que le bouton ne sait pas corriger (compétition d'un autre type).
 */
function tdm_setup_step1()
{
    global $TDM_TOUR;
    $tour = StrSafe_DB($TDM_TOUR);
    $divs = [];
    $rs = safe_r_sql("SELECT DivId FROM Divisions WHERE DivTournament = $tour");
    while ($r = safe_fetch($rs)) $divs[$r->DivId] = true;

    $blocking = $checks = [];
    $missing = array_diff(TDM_BASE_DIVISIONS, array_keys($divs));
    if ($missing) {
        $blocking[] = 'Division(s) ' . implode(', ', $missing) . ' absente(s) : créez la compétition en « Salle 18 m » FFTA.';
    }
    $classes = [];
    $rs = safe_r_sql("SELECT ClId, ClDivisionsAllowed FROM Classes WHERE ClTournament = $tour");
    while ($r = safe_fetch($rs)) $classes[$r->ClId] = $r->ClDivisionsAllowed;
    $absent = array_diff(TDM_NE_CLASSES, array_keys($classes));
    if (count($absent) === count(TDM_NE_CLASSES)) {
        $blocking[] = 'Aucune classe U13 à S3 : créez la compétition en « Salle 18 m » FFTA.';
    }

    $checks[] = [isset($divs[TDM_NE_DIVISION]), 'Division ' . TDM_NE_DIVISION . ' (' . TDM_NE_DESCRIPTION . ')'];

    $without = [];
    foreach (TDM_NE_CLASSES as $cl) {
        if (!isset($classes[$cl])) continue;
        $allowed = $classes[$cl];
        if ($allowed !== '' && !in_array(TDM_NE_DIVISION, explode(',', $allowed), true)) $without[] = $cl;
    }
    $checks[] = [!$without, 'Division NE acceptée par les classes U13 à S3'
        . ($without ? ' — manque : ' . implode(', ', $without) : '')];

    $rs = safe_r_sql("SELECT TdDist1 FROM TournamentDistances
        WHERE TdTournament = $tour AND TdClasses = 'NE%'");
    $d = safe_fetch($rs);
    $checks[] = [$d && (int) $d->TdDist1 > 0, 'Distances de la division NE'
        . ($d && (int) $d->TdDist1 === 0 ? ' — ligne présente mais sans distance' : '')];

    $face = tdm_setup_ne_face();
    $checks[] = [(bool) $face, 'Blason des néo-licenciés'
        . ($face ? " : {$face->TfName}" : ' (60 cm, une flèche par zone)')];

    $ok = !$blocking && !in_array(false, array_column($checks, 0), true);
    return ['ok' => $ok, 'blocking' => $blocking, 'checks' => $checks];
}

/**
 * Applique l'étape 1. Sans effet sur ce qui est déjà en place.
 *
 * @return string[] Ce qui a été fait.
 */
function tdm_setup_step1_apply()
{
    global $TDM_TOUR;
    $tour = StrSafe_DB($TDM_TOUR);
    $done = [];

    $rs = safe_r_sql("SELECT DivId FROM Divisions WHERE DivTournament = $tour AND DivId = " . StrSafe_DB(TDM_NE_DIVISION));
    if (!safe_fetch($rs)) {
        $rs = safe_r_sql("SELECT COALESCE(MAX(DivViewOrder), 0) + 1 AS N FROM Divisions WHERE DivTournament = $tour");
        $order = min(255, (int) safe_fetch($rs)->N);
        safe_w_sql("INSERT INTO Divisions (DivId, DivTournament, DivDescription, DivIsPara, DivAthlete, DivViewOrder)
            VALUES (" . StrSafe_DB(TDM_NE_DIVISION) . ", $tour, " . StrSafe_DB(TDM_NE_DESCRIPTION) . ", 0, 1, $order)");
        $done[] = 'division NE créée';
    }

    $n = 0;
    $rs = safe_r_sql("SELECT ClId, ClDivisionsAllowed FROM Classes WHERE ClTournament = $tour
        AND ClId IN (" . implode(',', array_map('StrSafe_DB', TDM_NE_CLASSES)) . ")");
    while ($r = safe_fetch($rs)) {
        if ($r->ClDivisionsAllowed === '') continue; // vide = toutes les divisions
        $list = explode(',', $r->ClDivisionsAllowed);
        if (in_array(TDM_NE_DIVISION, $list, true)) continue;
        $list[] = TDM_NE_DIVISION;
        safe_w_sql("UPDATE Classes SET ClDivisionsAllowed = " . StrSafe_DB(implode(',', $list)) . "
            WHERE ClTournament = $tour AND ClId = " . StrSafe_DB($r->ClId));
        $n++;
    }
    if ($n) $done[] = "division NE ajoutée à $n classe(s)";

    // Distances NE = celles des seniors classiques (salle : 18 m).
    $rs = safe_r_sql("SELECT TdDist1 FROM TournamentDistances WHERE TdTournament = $tour AND TdClasses = 'NE%'");
    $d = safe_fetch($rs);
    if (!$d || (int) $d->TdDist1 === 0) {
        safe_w_sql("DELETE FROM TournamentDistances WHERE TdTournament = $tour AND TdClasses = 'NE%'");
        $rs = safe_r_sql("SELECT TdClasses FROM TournamentDistances WHERE TdTournament = $tour
            AND TdClasses IN ('CLS%', 'CL%') AND TdDist1 > 0 ORDER BY TdClasses DESC LIMIT 1");
        if ($src = safe_fetch($rs)) {
            safe_w_sql("INSERT INTO TournamentDistances (TdClasses, TdType, TdTournament, Td1, Td2, Td3, Td4, Td5, Td6, Td7, Td8,
                    TdTourRules, TdDist1, TdDist2, TdDist3, TdDist4, TdDist5, TdDist6, TdDist7, TdDist8)
                SELECT 'NE%', TdType, TdTournament, Td1, Td2, Td3, Td4, Td5, Td6, Td7, Td8,
                    TdTourRules, TdDist1, TdDist2, TdDist3, TdDist4, TdDist5, TdDist6, TdDist7, TdDist8
                FROM TournamentDistances WHERE TdTournament = $tour AND TdClasses = " . StrSafe_DB($src->TdClasses));
        } else {
            safe_w_sql("INSERT INTO TournamentDistances (TdClasses, TdType, TdTournament, Td1, Td2, TdTourRules, TdDist1, TdDist2)
                SELECT 'NE%', ToType, ToId, '18m-1', '18m-2', '', 18, 18 FROM Tournament WHERE ToId = $tour");
        }
        $done[] = 'distances NE créées';
    }

    // Blason NE : copie du blason unique 60 cm de la compétition (règlement : 60 cm).
    if (!tdm_setup_ne_face()) {
        $rs = safe_r_sql("SELECT COALESCE(MAX(TfId), 0) + 1 AS N FROM TargetFaces WHERE TfTournament = $tour");
        $id = (int) safe_fetch($rs)->N;
        $rs = safe_r_sql("SELECT TfId FROM TargetFaces WHERE TfTournament = $tour AND TfT1 = 1 AND TfW1 = 60
            ORDER BY TfDefault DESC, TfId LIMIT 1");
        $src = safe_fetch($rs);
        $fixed = ['TfId' => $id, 'TfName' => StrSafe_DB(TDM_NE_FACE_NAME), 'TfClasses' => "'NE%'", 'TfRegExp' => "''", 'TfDefault' => "'1'"];
        if ($src) {
            $cols = [];
            $rs = safe_r_sql('SHOW COLUMNS FROM TargetFaces');
            while ($c = safe_fetch($rs)) $cols[] = $c->Field;
            $sel = array_map(fn($c) => $fixed[$c] ?? $c, $cols);
            safe_w_sql('INSERT INTO TargetFaces (' . implode(', ', $cols) . ') SELECT ' . implode(', ', $sel)
                . " FROM TargetFaces WHERE TfTournament = $tour AND TfId = {$src->TfId}");
        } else {
            $set = [];
            for ($i = 1; $i <= 8; $i++) $set[] = "TfT$i = 1, TfW$i = 60";
            safe_w_sql("INSERT INTO TargetFaces SET TfTournament = $tour, TfId = $id, TfName = {$fixed['TfName']},
                TfClasses = 'NE%', TfRegExp = '', TfDefault = '1', " . implode(', ', $set));
        }
        $done[] = 'blason NE créé (60 cm)';
    }
    // Archers déjà en NE : blason recalculé.
    tdm_setup_fix_faces("EnDivision = " . StrSafe_DB(TDM_NE_DIVISION));
    return $done;
}

/** Blason qui s'applique à la division NE (même règle qu'IANSEO : LIKE ou expression régulière), ou null. */
function tdm_setup_ne_face()
{
    global $TDM_TOUR;
    $key = StrSafe_DB(TDM_NE_DIVISION . 'S1F');
    $rs = safe_r_sql("SELECT TfId, TfName FROM TargetFaces WHERE TfTournament = " . StrSafe_DB($TDM_TOUR) . "
        AND ($key LIKE TfClasses OR (TfRegExp <> '' AND $key REGEXP TfRegExp)) ORDER BY TfDefault DESC, TfId LIMIT 1");
    return safe_fetch($rs) ?: null;
}

/**
 * Recalcule le blason des archers choisis, comme IANSEO à l'inscription :
 * premier blason dont TfClasses (LIKE) ou TfRegExp correspond à division + classe,
 * blasons par défaut d'abord. Sans blason correspondant, l'archer garde le sien.
 */
function tdm_setup_fix_faces($where)
{
    global $TDM_TOUR;
    safe_w_sql("UPDATE Entries SET EnTargetFace = COALESCE((SELECT TfId FROM TargetFaces
            WHERE TfTournament = EnTournament AND (CONCAT(EnDivision, EnClass) LIKE TfClasses
                OR (TfRegExp <> '' AND CONCAT(EnDivision, EnClass) REGEXP TfRegExp))
            ORDER BY TfDefault DESC, TfId LIMIT 1), EnTargetFace)
        WHERE EnTournament = " . StrSafe_DB($TDM_TOUR) . " AND ($where)");
}

// ── Étape 3 : archers ─────────────────────────────────────────────────────────

/**
 * Archers de la compétition, pour la composition des équipes.
 *
 * @return array<int, object> EnId => EnId, EnCode, EnName, EnFirstName, EnSex, EnDivision, EnClass,
 *         EnTeamMixEvent, EnSubTeam, EnStatus, CoId, CoCode, CoName.
 */
function tdm_setup_archers()
{
    global $TDM_TOUR;
    $out = [];
    $rs = safe_r_sql("SELECT EnId, EnCode, EnName, EnFirstName, EnSex, EnDivision, EnClass, EnTeamMixEvent,
            EnSubTeam, EnStatus, CoId, CoCode, CoName
        FROM Entries
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        WHERE EnTournament = " . StrSafe_DB($TDM_TOUR) . " AND EnAthlete = 1
        ORDER BY CoName, CoCode, EnSex DESC, EnName, EnFirstName");
    while ($r = safe_fetch($rs)) $out[(int) $r->EnId] = $r;
    return $out;
}

/** Archers au format de tdm_compose() (ceux qui tirent : statut ≤ 1). */
function tdm_setup_compose_input(array $archers)
{
    $in = [];
    foreach ($archers as $a) {
        if ((int) $a->EnStatus > 1) continue;
        $in[] = ['id' => (int) $a->EnId, 'club' => (int) $a->CoId, 'clubName' => (string) $a->CoName,
                 'sex' => (int) $a->EnSex, 'div' => $a->EnDivision, 'mix' => (int) $a->EnTeamMixEvent,
                 'sub' => (int) $a->EnSubTeam];
    }
    return $in;
}

/** Archers au format de tdm_place_changes() / tdm_auto_changes(). */
function tdm_setup_place_input(array $archers)
{
    $in = [];
    foreach ($archers as $id => $a) {
        $in[$id] = ['club' => (int) $a->CoId, 'sex' => (int) $a->EnSex, 'div' => $a->EnDivision, 'cls' => $a->EnClass,
                    'mix' => (int) $a->EnTeamMixEvent, 'sub' => (int) $a->EnSubTeam, 'status' => (int) $a->EnStatus];
    }
    return $in;
}

/**
 * État complet de la page Composition : archers, et par club les équipes,
 * les non placés par catégorie, les archers hors TDM et les anomalies.
 */
function tdm_setup_composition_state()
{
    $archers = tdm_setup_archers();
    $compo = tdm_compose(tdm_setup_compose_input($archers));
    $clubs = $list = [];
    foreach ($archers as $id => $a) {
        $list[$id] = ['name' => trim($a->EnFirstName . ' ' . $a->EnName), 'code' => $a->EnCode, 'sex' => (int) $a->EnSex,
                      'cls' => $a->EnClass, 'div' => $a->EnDivision];
        $k = (int) $a->CoId;
        if (!isset($clubs[$k])) {
            $clubs[$k] = ['id' => $k, 'name' => $a->CoName ?: '(sans club)', 'code' => (string) $a->CoCode,
                          'teams' => [], 'free' => [], 'other' => [], 'out' => [], 'issues' => []];
        }
        $cat = tdm_category_of_div($a->EnDivision);
        if ((int) $a->EnStatus > 1) $clubs[$k]['out'][] = $id;
        elseif ($cat === null) $clubs[$k]['other'][] = $id;
        elseif (!(int) $a->EnTeamMixEvent) $clubs[$k]['free'][$cat][] = $id;
    }
    foreach ($compo['teams'] as $cat => $teams) {
        foreach ($teams as $t) {
            $clubs[$t['club']]['teams'][$cat][] = ['sub' => $t['sub'], 'women' => $t['women'], 'men' => $t['men'], 'ok' => $t['ok']];
        }
    }
    foreach ($compo['issues'] as [$cat, $club, $msg]) {
        if (isset($clubs[$club])) $clubs[$club]['issues'][] = ($cat ? "$cat " : '') . $msg;
    }
    return [
        'archers' => $list, 'clubs' => array_values($clubs), 'counts' => $compo['counts'],
        'issues' => count($compo['issues']),
        'validated' => array_keys(array_filter(tdm_events(), fn($e) => $e->Validated)),
    ];
}

/** Classes qui acceptent une division (ClDivisionsAllowed vide = toutes). */
function tdm_setup_class_divisions()
{
    global $TDM_TOUR;
    $out = [];
    $rs = safe_r_sql("SELECT ClId, ClDivisionsAllowed FROM Classes WHERE ClTournament = " . StrSafe_DB($TDM_TOUR));
    while ($r = safe_fetch($rs)) $out[$r->ClId] = $r->ClDivisionsAllowed === '' ? null : explode(',', $r->ClDivisionsAllowed);
    return $out;
}

/**
 * Enregistre la composition : division, « Épreuve Équipe Mixte », numéro.
 *
 * @param array<int, array{div: string, mix: int, sub: int}> $changes EnId => nouvelles valeurs.
 * @return int Nombre d'archers modifiés.
 */
function tdm_setup_save_composition(array $changes, array $archers)
{
    global $TDM_TOUR;
    require_once HTDOCS . '/Qualification/Fun_Qualification.local.inc.php';
    $n = 0;
    $rebuild = [];
    foreach ($changes as $id => $c) {
        if (!isset($archers[$id])) continue;
        $a = $archers[$id];
        safe_w_sql("UPDATE Entries SET EnDivision = " . StrSafe_DB($c['div']) . ",
                EnTeamMixEvent = " . intval($c['mix']) . ", EnSubTeam = " . intval($c['sub']) . "
            WHERE EnTournament = " . StrSafe_DB($TDM_TOUR) . " AND EnId = " . intval($id));
        if (safe_w_affected_rows()) {
            $n++;
            $rebuild[$a->CoId . '|' . $a->EnDivision . '|' . $a->EnClass] = [$a->CoId, $a->EnDivision, $a->EnClass];
            $rebuild[$a->CoId . '|' . $c['div'] . '|' . $a->EnClass] = [$a->CoId, $c['div'], $a->EnClass];
            if ($c['div'] !== $a->EnDivision) tdm_setup_fix_faces('EnId = ' . intval($id));
            // Départs : l'archer rejoint la session du départ de sa catégorie.
            require_once __DIR__ . '/departs.php';
            tdm_apply_departs(intval($id));
        }
    }
    // Équipes IANSEO des clubs touchés seulement (un recalcul complet dévaliderait
    // la qualif de toutes les catégories, voir tdm_rebuild_teams()).
    if ($n && tdm_events()) {
        foreach ($rebuild as [$co, $div, $cls]) MakeTeamsAbs($co, $div, $cls);
    }
    return $n;
}

// ── Étape 4 : épreuves et Round Robin ─────────────────────────────────────────

/**
 * État des 4 épreuves.
 *
 * @return array<string, object> code => Exists, RoundRobin, Mixed, Rules (règles femmes / hommes),
 *         Groups, Size, Settings (réglages des matchs conformes), Validated, Scored (matchs saisis),
 *         Ok, Problems[].
 */
function tdm_setup_events()
{
    global $TDM_TOUR;
    $tour = StrSafe_DB($TDM_TOUR);
    $out = [];
    foreach (TDM_CATEGORIES as $code => $c) {
        $ev = StrSafe_DB($code);
        $rs = safe_r_sql("SELECT EvElimType, EvElim1, EvMixedTeam, EvMultiTeam, EvE1ShootOff FROM Events
            WHERE EvTournament = $tour AND EvTeamEvent = 1 AND EvCode = $ev");
        $e = safe_fetch($rs);
        $s = (object) ['Exists' => (bool) $e, 'RoundRobin' => false, 'Mixed' => false, 'Rules' => [0, 0],
                       'Groups' => 0, 'Size' => 0, 'Settings' => false, 'Validated' => false, 'Scored' => 0,
                       'Seeded' => false, 'FirstTarget' => 0, 'Targets' => false,
                       'Ok' => false, 'Problems' => []];
        if ($e) {
            $s->RoundRobin = (int) $e->EvElimType === 5 && (int) $e->EvElim1 === 1;
            $s->Mixed = (int) $e->EvMixedTeam === 1 && (int) $e->EvMultiTeam === 1;
            $s->Validated = (int) $e->EvE1ShootOff === 1;
            $rs = safe_r_sql("SELECT EcTeamEvent, COUNT(*) AS N FROM EventClass
                WHERE EcTournament = $tour AND EcCode = $ev AND EcTeamEvent > 0 GROUP BY EcTeamEvent");
            $groups = [];
            while ($r = safe_fetch($rs)) $groups[(int) $r->EcTeamEvent] = (int) $r->N;
            $s->Rules = [$groups[1] ?? 0, $groups[2] ?? 0];
            $rs = safe_r_sql("SELECT * FROM RoundRobinLevel
                WHERE RrLevTournament = $tour AND RrLevTeam = 1 AND RrLevEvent = $ev AND RrLevLevel = 1");
            if ($l = safe_fetch($rs)) {
                $s->Groups = (int) $l->RrLevGroups;
                $s->Size = (int) $l->RrLevGroupArchers;
                $s->Settings = true;
                foreach (TDM_RR_SETTINGS as $f => $v) {
                    if ((int) $l->$f !== $v) $s->Settings = false;
                }
            }
            $rs = safe_r_sql("SELECT COUNT(*) AS N FROM RoundRobinMatches
                WHERE RrMatchTournament = $tour AND RrMatchTeam = 1 AND RrMatchEvent = $ev AND RrMatchLevel = 1
                  AND (RrMatchScore > 0 OR RrMatchSetScore > 0)");
            $s->Scored = (int) safe_fetch($rs)->N;
            // Ordre de remplissage (serpentin) et cibles des matchs.
            $rs = safe_r_sql("SELECT SUM(RrPartSourceRank > 0) AS Seeded, COUNT(*) AS N FROM RoundRobinParticipants
                WHERE RrPartTournament = $tour AND RrPartTeam = 1 AND RrPartEvent = $ev AND RrPartLevel = 1");
            $p = safe_fetch($rs);
            $s->Seeded = (int) $p->N > 0 && (int) $p->Seeded === (int) $p->N;
            $rs = safe_r_sql("SELECT MIN(CAST(RrMatchTarget AS UNSIGNED)) AS First, SUM(RrMatchTarget = '') AS Missing
                FROM RoundRobinMatches WHERE RrMatchTournament = $tour AND RrMatchTeam = 1 AND RrMatchEvent = $ev AND RrMatchLevel = 1");
            $m = safe_fetch($rs);
            $s->Targets = $m && $m->First !== null && (int) $m->Missing === 0;
            $s->FirstTarget = $s->Targets ? (int) $m->First : 0;
        }
        if (!$s->Exists) $s->Problems[] = 'épreuve à créer';
        else {
            if (!$s->RoundRobin) $s->Problems[] = 'pas en Round Robin à un niveau';
            if (!$s->Mixed) $s->Problems[] = 'pas en équipe mixte multi-équipes';
            if (!$s->Rules[0] || !$s->Rules[1]) $s->Problems[] = 'règles femme + homme incomplètes';
            if (!$s->Groups) $s->Problems[] = 'poules non configurées';
            elseif (!$s->Seeded) $s->Problems[] = 'ordre de remplissage des poules (serpentin) absent';
            if ($s->Groups && !$s->Targets) $s->Problems[] = 'cibles des matchs non attribuées';
            elseif (!$s->Settings) $s->Problems[] = 'réglages des matchs différents du règlement (4 volées de 4 flèches, victoire 2 points)';
        }
        $s->Ok = !$s->Problems;
        $out[$code] = $s;
    }
    return $out;
}

/**
 * Crée ou complète une épreuve TDM, puis règle ses poules.
 *
 * @return string[] Ce qui a été fait.
 */
function tdm_setup_apply_event($code, $groups, $size, $start = 0)
{
    global $TDM_TOUR;
    require_once HTDOCS . '/Common/Fun_Various.inc.php';
    require_once HTDOCS . '/Modules/RoundRobin/Lib.php';
    $c = TDM_CATEGORIES[$code];
    $tour = StrSafe_DB($TDM_TOUR);
    $ev = StrSafe_DB($code);
    $where = "EvTournament = $tour AND EvTeamEvent = 1 AND EvCode = $ev";
    $done = [];

    $rs = safe_r_sql("SELECT EvElimType, EvElim1, EvMixedTeam, EvMultiTeam FROM Events WHERE $where");
    $e = safe_fetch($rs);
    if (!$e) {
        // Final/Team/AddEvent.php : pas de phase finale, matchs en sets.
        $progr = array_search($code, array_keys(TDM_CATEGORIES), true) + 1;
        safe_w_sql("INSERT INTO Events (EvGoldsChars, EvGolds, EvXNineChars, EvXNine, EvCode, EvIsPara, EvTeamEvent,
                EvTournament, EvEventName, EvProgr, EvShootOff, EvFinalFirstPhase, EvNumQualified,
                EvFinalTargetType, EvTargetSize, EvDistance, EvMatchMode)
            SELECT ToGoldsChars, ToGolds, ToXNineChars, ToXNine, $ev, 0, 1,
                ToId, " . StrSafe_DB($c['name']) . ", $progr, 0, 0, 0,
                {$c['target']}, {$c['size']}, '18m', 1
            FROM Tournament WHERE ToId = $tour");
        safe_w_sql("UPDATE Events SET EvElimEnds = 4, EvElimArrows = 6, EvElimSO = 3,
            EvFinEnds = 4, EvFinArrows = 6, EvFinSO = 3 WHERE $where");
        $done[] = 'épreuve créée';
        $e = (object) ['EvElimType' => 0, 'EvElim1' => 0, 'EvMixedTeam' => 0, 'EvMultiTeam' => 0];
    }
    if ((int) $e->EvElimType !== 5 || (int) $e->EvElim1 !== 1) {
        // ListEvents-Eliminations-data.php : type 5 (Round Robin), un seul niveau.
        safe_w_sql("UPDATE Events SET EvElimType = 5, EvElim1 = 1, EvE1Arrows = 0, EvE1Ends = 0, EvE1SO = 0,
                EvElim2 = 0, EvE2Arrows = 0, EvE2Ends = 0, EvE2SO = 0, EvFinalAthTarget = 0, EvMatchArrowsNo = 0,
                EvElimEnds = 0, EvElimArrows = 0, EvElimSO = 0
            WHERE $where");
        foreach (['RoundRobinGrids' => 'RrGrid', 'RoundRobinGroup' => 'RrGr', 'RoundRobinLevel' => 'RrLev',
                  'RoundRobinMatches' => 'RrMatch', 'RoundRobinParticipants' => 'RrPart'] as $table => $p) {
            safe_w_sql("DELETE FROM $table WHERE {$p}Tournament = $tour AND {$p}Team = 1 AND {$p}Event = $ev AND {$p}Level > 1");
        }
        $_SESSION['HasRobin'] = 1;
        $done[] = 'Round Robin à un niveau';
    }
    if ((int) $e->EvMixedTeam !== 1 || (int) $e->EvMultiTeam !== 1) {
        safe_w_sql("UPDATE Events SET EvMixedTeam = 1, EvMultiTeam = 1, EvMultiTeamNo = 0 WHERE $where");
        $done[] = 'équipe mixte, plusieurs équipes par club';
    }

    // Règles : groupe 1 = une femme, groupe 2 = un homme (classes existantes seulement).
    $rs = safe_r_sql("SELECT COUNT(*) AS N FROM EventClass WHERE EcTournament = $tour AND EcCode = $ev AND EcTeamEvent > 0");
    if (!(int) safe_fetch($rs)->N) {
        $ages = array_slice(TDM_AGE_CLASSES, array_search($c['from'], TDM_AGE_CLASSES, true));
        foreach ([1 => 'F', 2 => 'H'] as $group => $sx) {
            $list = implode(',', array_map(fn($a) => StrSafe_DB($a . $sx), $ages));
            safe_w_sql("INSERT IGNORE INTO EventClass (EcCode, EcTeamEvent, EcTournament, EcClass, EcDivision, EcSubClass, EcNumber, EcExtraAddons)
                SELECT $ev, $group, ClTournament, ClId, " . StrSafe_DB($c['div']) . ", '', 1, 0
                FROM Classes WHERE ClTournament = $tour AND ClId IN ($list)");
        }
        calcMaxTeamPerson([$code]);
        $done[] = 'règles une femme + un homme';
    }

    // Poules : comme Modules/RoundRobin/Setup-data.php (setDetails).
    $set = "RrLevTournament = $tour, RrLevLevel = 1, RrLevEvent = $ev, RrLevTeam = 1, "
         . "RrLevGroups = " . intval($groups) . ", RrLevGroupArchers = " . intval($size);
    safe_w_sql("INSERT INTO RoundRobinLevel SET $set ON DUPLICATE KEY UPDATE $set");
    if (safe_w_affected_rows()) {
        createRound(1, $code, 1);
        safe_w_sql("DELETE FROM RoundRobinParticipants WHERE RrPartTournament = $tour AND RrPartTeam = 1
            AND RrPartEvent = $ev AND RrPartLevel = 0 AND RrPartGroup = 0");
        // Les poules sont vides : la qualif doit être validée à nouveau.
        safe_w_sql("UPDATE Events SET EvE1ShootOff = 0 WHERE $where");
        $done[] = "$groups poule(s) de $size";
    }
    $key = "RrLevTournament = $tour AND RrLevTeam = 1 AND RrLevEvent = $ev AND RrLevLevel = 1";
    $sql = [];
    foreach (TDM_RR_SETTINGS as $f => $v) $sql[] = "$f = $v";
    safe_w_sql("UPDATE RoundRobinLevel SET " . implode(', ', $sql) . " WHERE $key");
    if (safe_w_affected_rows()) {
        calculateFinalRank(1, $code, 1);
        $done[] = 'réglages des matchs';
    }
    safe_w_sql("UPDATE RoundRobinLevel SET RrLevName = 'Poules' WHERE $key");
    // Serpentin selon la qualif (Modules/RoundRobin/Grouping-data.php, autoSeed type 1).
    $rs = safe_r_sql("SELECT COUNT(*) AS N FROM RoundRobinParticipants WHERE RrPartTournament = $tour AND RrPartTeam = 1
        AND RrPartEvent = $ev AND RrPartLevel = 1 AND RrPartSourceRank = 0");
    if ((int) safe_fetch($rs)->N) {
        foreach (tdm_snake($groups, $size) as $g => $items) {
            foreach ($items as $item => $rank) {
                safe_w_sql("UPDATE RoundRobinParticipants SET RrPartSourceLevel = 0, RrPartSourceGroup = 0, RrPartSourceRank = $rank
                    WHERE RrPartTournament = $tour AND RrPartTeam = 1 AND RrPartEvent = $ev AND RrPartLevel = 1
                      AND RrPartGroup = $g AND RrPartDestItem = $item");
            }
        }
        $done[] = 'remplissage en serpentin';
    }
    // Cibles : chaque match garde sa cible, deux équipes (A / B), les poules se suivent
    // (SetRoundRobinTarget, remplissage « + » de Modules/RoundRobin/Targets.php).
    $start = (int) $start;
    if ($start > 0) {
        $rs = safe_r_sql("SELECT MIN(CAST(RrMatchTarget AS UNSIGNED)) AS First, SUM(RrMatchTarget = '') AS Missing
            FROM RoundRobinMatches WHERE RrMatchTournament = $tour AND RrMatchTeam = 1 AND RrMatchEvent = $ev AND RrMatchLevel = 1");
        $m = safe_fetch($rs);
        if ((int) $m->Missing || (int) $m->First !== $start) {
            safe_w_sql("UPDATE RoundRobinGroup SET RrGrTargetArchers = 1, RrGrArcherWaves = 0
                WHERE RrGrTournament = $tour AND RrGrTeam = 1 AND RrGrEvent = $ev AND RrGrLevel = 1");
            $perPool = (int) ceil($size / 2);
            for ($g = 1; $g <= $groups; $g++) {
                SetRoundRobinTarget($code, 1, 1, $g, 1, 0, ($start + ($g - 1) * $perPool) . '+');
                // Taille impaire : IANSEO s'arrête au tour « taille - 1 », il en existe un de plus.
                if ($size % 2) SetRoundRobinTarget($code, 1, 1, $g, $size, 0, ($start + ($g - 1) * $perPool) . '+');
            }
            [$a, $b] = tdm_target_range($start, $groups, $size);
            $done[] = "cibles $a à $b";
        }
    }
    set_qual_session_flags();
    return $done;
}
