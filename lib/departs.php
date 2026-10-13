<?php
/**
 * lib/departs.php — départs (horaires) des catégories.
 *
 * Un départ est une session de qualification IANSEO (table Session, type Q) :
 * nom, date et heure de début (SesDtStart, et DistanceInformation DiDay / DiStart
 * de la 1re distance, que lit le programme horaire natif), entraînement avant le
 * tir (échauffement natif de la 1re distance : DiWarmStart / DiWarmDuration,
 * mêmes champs que le programme horaire d'IANSEO). Les sessions sont
 * créées et modifiées par les fonctions natives insertSession() / updateSession()
 * (Tournament/Fun_ManSessions.inc.php), qui tiennent aussi à jour le nombre de
 * sessions, les distances et les cibles disponibles.
 *
 * Réglage : configuration, étape 2 (avant les archers et les épreuves).
 *
 * Le module retient :
 *  - la catégorie → départ (paramètre de module TDM « Departs ») ; les archers
 *    de la catégorie sont placés dans la session de leur départ (QuSession) ;
 *  - le départ dont la saisie des qualifs est ouverte (paramètre « OpenDepart »),
 *    choisi à la main par l'organisateur.
 * Chaque départ réutilise le pas de tir à partir de la cible 1.
 */

require_once __DIR__ . '/setup.php';

/** Nombre maximum de départs proposés. */
const TDM_MAX_DEPARTS = 4;
/** Mini-qualif saisie par archer (Scorekeeper, saisie native) : 5 volées de 2 flèches sur la distance 1. */
const TDM_QUALIF_ENDS_PER_ARCHER = 5;
const TDM_QUALIF_ARROWS_PER_END = 2;
/** Durée maximale de l'entraînement (minutes). */
const TDM_WARM_MAX = 180;
/** Durée maximale de la phase de qualification d'un départ (minutes). */
const TDM_QUALIF_DUR_MAX = 600;
/** Noms proposés par défaut. */
const TDM_DEPART_NAMES = [1 => 'Matin', 2 => 'Après-midi', 3 => 'Départ 3', 4 => 'Départ 4'];

// ── Fonctions pures ───────────────────────────────────────────────────────────

/**
 * Contrôle des départs. Fonction pure.
 *
 * @param array<string, int> $map Catégorie => départ (0 = aucun).
 * @param string[] $needed Catégories qui ont des équipes.
 * @param array<int, array{name: string, date: string, time: string, dur?: int, warm?: string, warmDur?: int}> $sessions Départs
 *        (date AAAA-MM-JJ, heure HH:MM ; durée du tir facultative, en minutes ; entraînement facultatif : heure HH:MM
 *        et durée en minutes).
 * @param string $from Premier jour de la compétition (AAAA-MM-JJ).
 * @param string $to Dernier jour.
 * @param array<int, int> $needs Cibles nécessaires à la qualif de chaque départ (tdm_depart_needs()).
 * @return array{errors: string[], warnings: string[]}
 */
function tdm_check_departs(array $map, array $needed, array $sessions, $from, $to, array $needs = [])
{
    $errors = $warnings = [];
    foreach ($needed as $cat) {
        if (empty($map[$cat])) $errors[] = "$cat : aucun départ";
    }
    $used = array_unique(array_filter(array_values($map)));
    sort($used);
    $prev = null;
    foreach ($used as $n) {
        $s = $sessions[$n] ?? null;
        if (!$s) { $errors[] = "départ $n : session inexistante"; continue; }
        $label = "départ $n" . ($s['name'] !== '' ? " ({$s['name']})" : '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s['date']) || $s['date'] === '0000-00-00') {
            $errors[] = "$label : date manquante";
        } elseif ($s['date'] < $from || $s['date'] > $to) {
            $errors[] = "$label : le " . tdm_date_fr($s['date']) . ' est en dehors des dates de la compétition ('
                . tdm_date_fr($from) . ($to !== $from ? ' au ' . tdm_date_fr($to) : '') . ')';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s['time']) || $s['time'] === '00:00') {
            $errors[] = "$label : heure de début manquante";
        }
        // Entraînement : facultatif ; s'il est indiqué, heure et durée, avant le début du tir.
        $warm = (string) ($s['warm'] ?? '');
        $dur = (int) ($s['warmDur'] ?? 0);
        if ($warm !== '' || $dur) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $warm)) {
                $errors[] = "$label : heure de l'entraînement manquante";
            } elseif ($dur < 1 || $dur > TDM_WARM_MAX) {
                $errors[] = "$label : durée de l'entraînement entre 1 et " . TDM_WARM_MAX . ' minutes';
            } elseif (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s['time'])) {
                if ($warm >= $s['time']) {
                    $errors[] = "$label : l'entraînement ($warm) doit commencer avant le tir ({$s['time']})";
                } elseif (tdm_minutes($warm) + $dur > tdm_minutes($s['time'])) {
                    $warnings[] = "$label : l'entraînement ($warm, $dur min) finit après le début du tir ({$s['time']})";
                }
            }
        }
        // Durée du tir (qualification) : facultative.
        $qDur = (int) ($s['dur'] ?? 0);
        if ($qDur < 0 || $qDur > TDM_QUALIF_DUR_MAX) {
            $errors[] = "$label : durée du tir entre 1 et " . TDM_QUALIF_DUR_MAX . ' minutes';
        }
        if (isset($s['targets'])) {
            if ((int) $s['targets'] < 1 || (int) $s['targets'] > 999) {
                $errors[] = "$label : nombre de cibles entre 1 et 999";
            } elseif (!empty($needs[$n]) && $needs[$n] > (int) $s['targets']) {
                $warnings[] = "$label : la qualification demande {$needs[$n]} cibles pour {$s['targets']} sur le pas de tir";
            }
        }
        if ($prev && $s['date'] . $s['time'] <= $prev[1]) {
            $warnings[] = "$label commence avant ou en même temps que le départ {$prev[0]}";
        } elseif ($prev && $prev[2] !== null && $s['date'] === $prev[3]) {
            // Le tir du départ précédent (avec sa durée) déborde sur ce départ (entraînement compris).
            $begin = $warm !== '' && $warm < $s['time'] ? $warm : $s['time'];
            if ($prev[2] > tdm_minutes($begin)) {
                $warnings[] = "$label : le tir du départ {$prev[0]} finit à " . sprintf('%02d:%02d', intdiv($prev[2], 60) % 24, $prev[2] % 60)
                    . ", après le début de ce départ ($begin)";
            }
        }
        $prev = [$n, $s['date'] . $s['time'], $qDur ? tdm_minutes($s['time']) + $qDur : null, $s['date']];
    }
    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * Cibles nécessaires à la qualification de chaque départ. Fonction pure.
 * Chaque catégorie sur ses propres cibles, deux équipes par cible.
 *
 * @param array<string, int> $map Catégorie => départ.
 * @param array<string, int> $counts Catégorie => nombre d'équipes.
 * @return array<int, int> départ => cibles
 */
function tdm_depart_needs(array $map, array $counts)
{
    $out = [];
    foreach ($map as $cat => $n) $out[$n] = ($out[$n] ?? 0) + (int) ceil(($counts[$cat] ?? 0) / 2);
    ksort($out);
    return $out;
}

/** Horaire d'un départ : « Entraînement 08:30 (30 min) · Tir 09:00 à 10:30 » ; vide sans heure. */
function tdm_depart_schedule($s)
{
    $parts = [];
    if (!empty($s['warm'])) $parts[] = 'Entraînement ' . $s['warm'] . (!empty($s['warmDur']) ? " ({$s['warmDur']} min)" : '');
    if (!empty($s['time']) && $s['time'] !== '00:00') {
        $end = '';
        if (!empty($s['dur'])) {
            $m = tdm_minutes($s['time']) + (int) $s['dur'];
            $end = sprintf(' à %02d:%02d', intdiv($m, 60) % 24, $m % 60);
        }
        $parts[] = ($parts || $end ? 'Tir ' : 'Début ') . $s['time'] . $end;
    }
    return implode(' · ', $parts);
}

/** Date en français : 18/10/2026. */
function tdm_date_fr($date)
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $date, $m) ? "$m[3]/$m[2]/$m[1]" : (string) $date;
}

/** Libellé d'un départ : « Départ 1 · Matin · 09:00 ». */
function tdm_depart_label($n, $s)
{
    $parts = ["Départ $n"];
    if (!empty($s['name'])) $parts[] = $s['name'];
    if (!empty($s['time']) && $s['time'] !== '00:00') $parts[] = $s['time'];
    return implode(' · ', $parts);
}

// ── Base de données ───────────────────────────────────────────────────────────

/** Catégorie => départ, d'après la configuration enregistrée. */
function tdm_departs_map()
{
    $map = getModuleParameter('TDM', 'Departs', []);
    $out = [];
    foreach ((array) $map as $cat => $n) {
        if ((int) $n >= 1 && (int) $n <= TDM_MAX_DEPARTS) $out[$cat] = (int) $n;
    }
    return $out;
}

/** Les départs sont-ils configurés ? */
function tdm_departs_enabled()
{
    return (bool) tdm_departs_map();
}

/**
 * Sessions de qualification IANSEO.
 *
 * @return array<int, array{name: string, date: string, time: string, targets: int, perTarget: int, first: int}>
 */
function tdm_qualif_sessions()
{
    global $TDM_TOUR;
    $tour = StrSafe_DB($TDM_TOUR);
    $out = [];
    $rs = safe_r_sql("SELECT SesOrder, SesName, SesTar4Session, SesAth4Target, SesFirstTarget, SesDtStart,
            DiDay, DiStart, DiDuration, DiWarmStart, DiWarmDuration
        FROM Session
        LEFT JOIN DistanceInformation ON DiTournament = SesTournament AND DiSession = SesOrder AND DiType = 'Q' AND DiDistance = 1
        WHERE SesTournament = $tour AND SesType = 'Q' ORDER BY SesOrder");
    while ($r = safe_fetch($rs)) {
        // Date et heure : celles du programme (DistanceInformation), sinon celles de la session.
        $date = ($r->DiDay && $r->DiDay !== '0000-00-00') ? $r->DiDay : substr((string) $r->SesDtStart, 0, 10);
        $time = ($r->DiStart && $r->DiStart !== '00:00:00') ? substr($r->DiStart, 0, 5) : substr((string) $r->SesDtStart, 11, 5);
        $fmt = safe_fetch(safe_r_sql("SELECT DiEnds, DiArrows FROM DistanceInformation WHERE DiTournament = $tour AND DiType = 'Q'
            AND DiSession = " . intval($r->SesOrder) . " AND DiDistance = 1"));
        $out[(int) $r->SesOrder] = [
            'name' => (string) $r->SesName, 'date' => $date === '0000-00-00' ? '' : $date,
            'time' => $time === '00:00' ? '' : $time, 'targets' => (int) $r->SesTar4Session,
            'perTarget' => (int) $r->SesAth4Target, 'first' => (int) $r->SesFirstTarget ?: 1,
            'ends' => (int) ($fmt->DiEnds ?? 0), 'arrows' => (int) ($fmt->DiArrows ?? 0),
            // Entraînement : échauffement natif (sans heure, pas de durée).
            'warm' => ($r->DiWarmStart && $r->DiWarmStart !== '00:00:00') ? substr($r->DiWarmStart, 0, 5) : '',
            'warmDur' => ($r->DiWarmStart && $r->DiWarmStart !== '00:00:00') ? (int) $r->DiWarmDuration : 0,
            // Durée du tir : durée native de la 1re distance (programme horaire IANSEO).
            'dur' => (int) $r->DiDuration,
        ];
    }
    return $out;
}

/** Départ d'une catégorie (sans configuration : la première session). */
function tdm_event_session($code)
{
    $map = tdm_departs_map();
    if (isset($map[$code])) return $map[$code];
    $s = tdm_qualif_sessions();
    return $s ? (int) array_key_first($s) : 1;
}

/**
 * Scorekeeper (API IANSEO ISK-NG) configuré pour la compétition ? Même règle
 * que les feuilles de marque natives : API présente, mode réglé et pas « live ».
 */
function tdm_scorekeeper_enabled()
{
    if (!function_exists('AvailableApis') || !in_array('ISK-NG', AvailableApis(), true)) return false;
    $mode = (string) getModuleParameter('ISK-NG', 'Mode', '');
    return $mode !== '' && strpos($mode, 'live') === false;
}

/** Départ dont la saisie des qualifs est ouverte (0 = aucun). */
function tdm_open_depart()
{
    return (int) getModuleParameter('TDM', 'OpenDepart', 0);
}

function tdm_set_open_depart($n)
{
    setModuleParameter('TDM', 'OpenDepart', (int) $n);
}

/**
 * Enregistre les départs : sessions IANSEO (création / mise à jour), horaire
 * du programme, catégorie → départ, puis place les archers.
 *
 * @param array<string, int> $map Catégorie => départ.
 * @param array<int, array{name: string, date: string, time: string, targets?: int, warm?: string, warmDur?: int}> $sessions Départs utilisés.
 * @return string[] Ce qui a été fait.
 */
function tdm_save_departs(array $map, array $sessions)
{
    global $TDM_TOUR;
    require_once HTDOCS . '/Tournament/Fun_ManSessions.inc.php';
    $tour = StrSafe_DB($TDM_TOUR);
    $done = [];
    $existing = tdm_qualif_sessions();
    $model = $existing[array_key_first($existing) ?? 1] ?? ['targets' => 24, 'perTarget' => 4, 'first' => 1];

    foreach ($sessions as $n => $s) {
        $dt = $s['date'] . ' ' . $s['time'] . ':00';
        if (!isset($existing[$n])) {
            // Sessions manquantes créées dans l'ordre (IANSEO numérote les sessions à la suite).
            for ($k = 1; $k <= $n; $k++) {
                if (isset($existing[$k])) continue;
                $name = $k === $n ? $s['name'] : (TDM_DEPART_NAMES[$k] ?? "Départ $k");
                $targets = $k === $n && !empty($s['targets']) ? (int) $s['targets'] : $model['targets'];
                insertSession($TDM_TOUR, $k, 'Q', $name, null, $targets, max(4, $model['perTarget']), $model['first'], 0,
                    $k === $n ? $dt : 0);
                $existing[$k] = ['name' => $name] + $model;
                $done[] = "session $k créée";
            }
        }
        $rs = safe_r_sql("SELECT * FROM Session WHERE SesTournament = $tour AND SesType = 'Q' AND SesOrder = " . intval($n));
        if ($r = safe_fetch($rs)) {
            // Nombre de cibles du départ ; 4 archers par cible (deux équipes).
            updateSession($TDM_TOUR, $n, 'Q', $s['name'], $r->SesLocation, !empty($s['targets']) ? (int) $s['targets'] : $r->SesTar4Session,
                max(4, (int) $r->SesAth4Target), $r->SesFirstTarget,
                $r->SesFollow, $dt, !empty($s['dur']) ? date('Y-m-d H:i:s', strtotime($dt) + 60 * (int) $s['dur']) : $r->SesDtEnd, $r->SesOdfCode, $r->SesOdfPeriod, $r->SesOdfVenue, $r->SesOdfLocation, $r->SesEvents);
        }
        // Programme horaire natif : début de la 1re distance de la session, entraînement
        // (échauffement natif), et format de la mini-qualif par archer (5 volées de 2 flèches)
        // que lisent Scorekeeper et la saisie native.
        $warm = !empty($s['warm']) ? $s['warm'] . ':00' : '00:00:00';
        $warmDur = !empty($s['warm']) ? (int) ($s['warmDur'] ?? 0) : 0;
        safe_w_sql("UPDATE DistanceInformation SET DiDay = " . StrSafe_DB($s['date']) . ", DiStart = " . StrSafe_DB($s['time'] . ':00') . ",
                DiWarmStart = " . StrSafe_DB($warm) . ", DiWarmDuration = $warmDur, DiDuration = " . max(0, (int) ($s['dur'] ?? 0)) . ",
                DiEnds = " . TDM_QUALIF_ENDS_PER_ARCHER . ", DiArrows = " . TDM_QUALIF_ARROWS_PER_END . "
            WHERE DiTournament = $tour AND DiType = 'Q' AND DiSession = " . intval($n) . " AND DiDistance = 1");
    }
    setModuleParameter('TDM', 'Departs', $map);
    $moved = tdm_apply_departs();
    if ($moved) $done[] = "$moved archer(s) placé(s) dans la session de leur départ";
    return $done;
}

/**
 * Place les archers de chaque catégorie dans la session de son départ. Un
 * archer qui change de session perd sa cible (les cibles appartiennent à la session).
 *
 * @return int Nombre d'archers déplacés.
 */
function tdm_apply_departs($onlyEnId = 0)
{
    global $TDM_TOUR;
    $n = 0;
    foreach (tdm_departs_map() as $cat => $ses) {
        if (!isset(TDM_CATEGORIES[$cat])) continue;
        $ses = intval($ses);
        // Les colonnes de cible d'abord : MySQL évalue SET de gauche à droite.
        safe_w_sql("UPDATE Qualifications INNER JOIN Entries ON EnId = QuId
            SET QuTarget = IF(QuSession <> $ses, 0, QuTarget), QuLetter = IF(QuSession <> $ses, '', QuLetter),
                QuTargetNo = IF(QuSession <> $ses, '', QuTargetNo), QuSession = $ses, QuTimestamp = QuTimestamp
            WHERE EnTournament = " . StrSafe_DB($TDM_TOUR) . " AND EnAthlete = 1 AND QuSession <> $ses
              AND EnDivision = " . StrSafe_DB(TDM_CATEGORIES[$cat]['div']) . ($onlyEnId ? ' AND EnId = ' . intval($onlyEnId) : ''));
        $n += safe_w_affected_rows();
    }
    return $n;
}

/** Archers d'une catégorie TDM qui ne sont pas dans la session de leur départ. */
function tdm_departs_mismatch()
{
    global $TDM_TOUR;
    $n = 0;
    foreach (tdm_departs_map() as $cat => $ses) {
        if (!isset(TDM_CATEGORIES[$cat])) continue;
        $rs = safe_r_sql("SELECT COUNT(*) AS N FROM Entries INNER JOIN Qualifications ON QuId = EnId
            WHERE EnTournament = " . StrSafe_DB($TDM_TOUR) . " AND EnAthlete = 1 AND EnStatus <= 1
              AND EnDivision = " . StrSafe_DB(TDM_CATEGORIES[$cat]['div']) . " AND QuSession <> " . intval($ses));
        $n += (int) safe_fetch($rs)->N;
    }
    return $n;
}
