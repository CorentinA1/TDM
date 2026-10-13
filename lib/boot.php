<?php
/**
 * lib/boot.php — amorçage commun des pages du module TDM.
 *
 * La page appelante inclut ce fichier puis exige ses droits :
 *
 *     require_once __DIR__ . '/lib/boot.php';
 *     tdm_require(AclQualification, AclReadWrite);
 *
 * Ensuite : configuration ianseo, compétition ouverte, helpers d'affichage.
 */

if (!defined('HTDOCS')) define('HTDOCS', dirname(__DIR__, 4));
require_once HTDOCS . '/config.php';

CheckTourSession(true);

$TDM_TOUR = intval($_SESSION['TourId']);
$TDM_ROOT = $CFG->ROOT_DIR . 'Modules/Custom/TDM/';

/** Exige un droit ianseo sur la compétition ouverte (sinon ianseo redirige). */
function tdm_require($feature, $level)
{
    checkFullACL($feature, '', $level);
}

/** Échappement HTML de toute valeur affichée. */
function tdm_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Jeton anti-CSRF de la session. */
function tdm_token()
{
    if (empty($_SESSION['TDM_TOKEN'])) $_SESSION['TDM_TOKEN'] = bin2hex(random_bytes(16));
    return $_SESSION['TDM_TOKEN'];
}

/** Champ caché portant le jeton, à placer dans chaque formulaire POST. */
function tdm_token_field()
{
    return '<input type="hidden" name="tdm_token" value="' . tdm_h(tdm_token()) . '">';
}

/** Arrête la requête si le jeton POST est absent ou faux. */
function tdm_check_token()
{
    $t = (string) ($_POST['tdm_token'] ?? '');
    if (!hash_equals(tdm_token(), $t)) {
        http_response_code(403);
        exit('Jeton de sécurité invalide : rechargez la page.');
    }
}

/** Compétition ouverte : nom, lieu, dates. */
function tdm_tournament()
{
    global $TDM_TOUR;
    static $t = null;
    if ($t !== null) return $t;
    $rs = safe_r_sql("SELECT ToName, ToCommitee, ToComDescr, ToWhere, ToWhenFrom, ToWhenTo
        FROM Tournament WHERE ToId = " . StrSafe_DB($TDM_TOUR));
    $t = safe_fetch($rs) ?: new stdClass();
    return $t;
}

/**
 * Épreuves TDM de la compétition : épreuves par équipe en Round Robin.
 *
 * @return array<string, object> code => EvCode, Name, EvTeamCreationMode,
 *         Validated (qualif → poules validée), Pools (nombre de poules, 0 si non configuré).
 */
function tdm_events()
{
    global $TDM_TOUR;
    static $events = null;
    if ($events !== null) return $events;
    $events = [];
    $rs = safe_r_sql("SELECT EvCode, EvEventName, EvTeamCreationMode, EvE1ShootOff,
            COALESCE(RrLevGroups, 0) AS Pools, COALESCE(RrLevGroupArchers, 0) AS PoolSize
        FROM Events
        LEFT JOIN RoundRobinLevel ON RrLevTournament = EvTournament AND RrLevTeam = 1
            AND RrLevEvent = EvCode AND RrLevLevel = 1
        WHERE EvTournament = " . StrSafe_DB($TDM_TOUR) . " AND EvTeamEvent = 1 AND EvElimType = 5
            AND EvCodeParent = ''
        ORDER BY EvProgr, EvCode");
    while ($r = safe_fetch($rs)) {
        $r->Name = get_text($r->EvEventName, '', '', true);
        $r->Validated = (int) $r->EvE1ShootOff === 1;
        $r->Pools = (int) $r->Pools;
        $r->PoolSize = (int) $r->PoolSize;
        $events[$r->EvCode] = $r;
    }
    return $events;
}

/**
 * Message flash d'une page à la suivante (après une redirection).
 *
 * @param array|null $set Message à garder ; null pour lire et effacer.
 */
function tdm_flash($set = null)
{
    if ($set !== null) {
        $_SESSION['TDM_FLASH'] = $set;
        return null;
    }
    $f = $_SESSION['TDM_FLASH'] ?? null;
    unset($_SESSION['TDM_FLASH']);
    return $f;
}

/**
 * Déroulé d'une manche : clé de page => [numéro d'étape, titre]. Sert à l'en-tête
 * commun (« Étape 4 sur 9 ») ; même ordre que la barre TDM (menu.php).
 */
const TDM_WORKFLOW = [
    'preconfig'   => [1, 'Configuration'],
    'equipes'     => [2, 'Composition des équipes'],
    'greffe'      => [3, 'Greffe'],
    'qualif'      => [4, 'Qualifications'],
    'cibles'      => [4, 'Cibles de qualification'],
    'repartition' => [5, 'Répartition des poules'],
    'validation'  => [6, 'Validation qualif → poules'],
    'poules'      => [7, 'Poules'],
    'bso'         => [8, 'Big Shoot Off'],
    'resultats'   => [9, 'Résultats'],
];

/**
 * En-tête commun des pages : titre de la page, étape du déroulé, puis un bandeau
 * « à quoi sert cette page » en une phrase et ses étapes, en quelques mots.
 *
 * @param string $current Clé de TDM_WORKFLOW.
 * @param string $intro   Rôle de la page, une phrase (HTML permis).
 * @param array  $steps   Étapes de la page, dans l'ordre (HTML permis).
 * @param string $note    Remarque facultative sous les étapes (HTML permis).
 * @param array|null $back [adresse, libellé] d'un lien de retour, ou null.
 */
function tdm_header($current, $intro, array $steps = [], $note = '', $back = null)
{
    [$num, $title] = TDM_WORKFLOW[$current];
    $total = max(array_column(TDM_WORKFLOW, 0));
    $h = '<div class="tdm-head"><h2>Trophée des Mixtes <span class="page">› ' . tdm_h($title) . '</span></h2>'
        . '<span class="step-tag">Étape ' . $num . ' sur ' . $total . '</span>';
    if ($back) $h .= '<a class="secondary back" href="' . tdm_h($back[0]) . '">← ' . tdm_h($back[1]) . '</a>';
    $h .= '</div>';
    // Avant l'étape 1 enregistrée : seul lien utile, la Configuration.
    if ($current !== 'preconfig' && (int) getModuleParameter('TDM', 'Configured', 0) !== 1) {
        global $TDM_ROOT;
        $h .= '<div class="msg warn">Compétition pas encore configurée pour le TDM : commencez par la <a href="'
            . tdm_h($TDM_ROOT . 'preconfig.php') . '">Configuration</a>.</div>';
    }
    $h .= '<div class="banner noprint"><b>' . $intro . '</b>';
    if ($steps) {
        $h .= '<ol class="steps">';
        foreach ($steps as $s) $h .= '<li>' . $s . '</li>';
        $h .= '</ol>';
    }
    if ($note !== '') $h .= '<div class="note">' . $note . '</div>';
    return $h . '</div>';
}

/** Feuille de style commune des pages du module (conteneur #tdm). */
function tdm_styles()
{
    return '<style>
#tdm { --bleu:#002b92; --bleu-fonce:#001a5c; --bleu-clair:#eef2fc; --rose:#f90a72; --gris:#4c4e50;
       --bord:#d2d4d6; --fond:#f7f7f7; --rouge:#b3261e; --orange:#8a5a00; --vert:#1e5e33;
       max-width:1100px; color:var(--gris); font-size:13px; }
#tdm .tdm-head { display:flex; flex-wrap:wrap; align-items:center; gap:6px 18px; margin:6px 0 14px; }
#tdm .tdm-head h2 { margin:0; color:var(--bleu); font-size:18px; }
#tdm .tdm-head h2 .page { font-weight:600; }
#tdm .tdm-head .step-tag { font-size:11px; font-weight:700; color:#fff; background:var(--rose); padding:2px 10px; border-radius:10px; }
#tdm .tdm-head a.back { margin-left:auto; padding:4px 12px; font-size:12px; }
#tdm .banner ol.steps { margin:6px 0 0 20px; padding:0; }
#tdm .banner ol.steps li { margin:2px 0; }
#tdm .banner .note { margin-top:6px; font-size:12px; color:#5d6163; }
@media print { #tdm .noprint, #tdm .tdm-head .step-tag, #tdm .tdm-head a.back { display:none; } }
#tdm .card { border:1px solid var(--bord); border-radius:6px; background:#fff;
             box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; }
#tdm .card > h3 { margin:0; padding:10px 14px; font-size:14px; color:#fff; background:var(--bleu);
                  border-radius:5px 5px 0 0; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
#tdm .card > h3 .tag { font-size:11px; font-weight:600; padding:2px 8px; border-radius:10px; background:#fff; color:var(--bleu); }
#tdm .card > h3 .tag.ok { background:#eafaf0; color:var(--vert); }
#tdm .card > h3 .tag.warn { background:#fff8e1; color:var(--orange); }
#tdm .card > div { padding:14px; }
#tdm .banner { background:var(--bleu-clair); border-left:4px solid var(--bleu); border-radius:0 6px 6px 0;
               padding:10px 14px; margin-bottom:16px; }
#tdm .banner b { color:var(--bleu-fonce); }
#tdm .msg { border-radius:6px; padding:10px 14px; margin-bottom:12px; }
#tdm .msg ul { margin:4px 0 0 18px; padding:0; }
#tdm .msg.ok   { background:#eafaf0; border:1px solid #9bd3ad; color:var(--vert); }
#tdm .msg.err  { background:#fdecea; border:1px solid #e6a19b; color:var(--rouge); }
#tdm .msg.warn { background:#fff8e1; border:1px solid #e0c46c; color:var(--orange); }
#tdm table.list { border-collapse:collapse; width:100%; }
#tdm table.list th { text-align:left; font-size:12px; color:#7d8183; font-weight:600; padding:4px 8px;
                     border-bottom:2px solid var(--bord); white-space:nowrap; }
#tdm table.list td { padding:4px 8px; border-bottom:1px solid #eceeef; vertical-align:middle; }
#tdm table.list tr.issue td { background:#fdecea; }
#tdm table.list tr.tie td.rank { color:var(--orange); font-weight:bold; }
#tdm table.list .num { text-align:right; }
#tdm table.list .team { font-weight:600; color:var(--bleu-fonce); }
#tdm table.list .archers { font-size:12px; }
#tdm table.list .note { font-size:11px; color:var(--rouge); }
#tdm input[type=text], #tdm input[type=number] { padding:4px 6px; border:1px solid var(--bord); border-radius:4px; font-size:13px; }
#tdm input.score { width:64px; text-align:right; }
#tdm input.small { width:46px; text-align:right; }
#tdm input:invalid { border-color:var(--rouge); }
#tdm button.primary { background:var(--bleu); border:1px solid var(--bleu); color:#fff; font-weight:600;
                      padding:8px 18px; font-size:13px; border-radius:6px; cursor:pointer; }
#tdm button.primary:hover { background:var(--bleu-fonce); }
#tdm button.primary:disabled { opacity:.5; cursor:default; }
#tdm a.secondary { display:inline-block; border:1px solid var(--bleu); color:var(--bleu); font-weight:600;
                   padding:7px 14px; border-radius:6px; text-decoration:none; }
#tdm .savebar { display:flex; align-items:center; gap:12px; margin-top:12px; flex-wrap:wrap; }
#tdm .help { color:#7d8183; font-size:12px; margin:0 0 10px; }
#tdm .dirty { color:var(--orange); display:none; }
#tdm .shortcuts { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:0 0 12px; }
#tdm .shortcuts a { display:inline-flex; align-items:center; gap:5px; border:1px solid var(--bleu); color:var(--bleu); background:#fff;
                    font-weight:600; font-size:12px; padding:4px 12px; border-radius:6px; text-decoration:none; }
#tdm .shortcuts a:hover { background:var(--bleu-clair); }
#tdm .shortcuts .lbl { font-weight:600; color:var(--bleu-fonce); min-width:150px; }
#tdm .pool h4 a { font-weight:600; font-size:11px; color:var(--bleu); margin-left:6px; }
</style>';
}
