<?php
/**
 * bso-action.php — TDM : point d'accès JSON du Big Shoot Off.
 *
 *   GET  act=events                         épreuves et état de leur BSO
 *   GET  act=state&ev=CL                    état complet (lib/bsodb.php, tdm_bso_state)
 *   POST act=score&ev&round&key&score       score d'une équipe (vide = effacer)
 *   POST act=order&ev&round&order[key]=n    ordre d'un groupe à égalité
 *
 * Lecture : droit Robin en lecture. Écriture : Robin en écriture, poules non
 * verrouillées et jeton de session (tdm_token) dans le POST.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/bsodb.php';
tdm_require(AclRobin, AclReadOnly);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function tdm_json($data)
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$events = tdm_events();
$act = (string) ($_REQUEST['act'] ?? '');

if ($act === 'events') {
    $out = [];
    foreach ($events as $code => $ev) {
        $teams = tdm_bso_teams($ev);
        $state = $teams ? tdm_bso_state($ev) : null;
        $out[] = [
            'event' => $code, 'name' => $ev->Name, 'teams' => count($teams),
            'current' => $state['current'] ?? 0, 'finished' => $state['finished'] ?? false,
        ];
    }
    tdm_json(['error' => 0, 'events' => $out]);
}

$code = (string) ($_REQUEST['ev'] ?? '');
if (!isset($events[$code])) tdm_json(['error' => 1, 'msg' => 'Épreuve inconnue.']);
$ev = $events[$code];

if ($act === 'state') {
    tdm_json(['error' => 0, 'state' => tdm_bso_state($ev)]);
}

if ($act === 'score' || $act === 'order') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST'
        || !hash_equals(tdm_token(), (string) ($_POST['tdm_token'] ?? ''))) {
        tdm_json(['error' => 1, 'msg' => 'Jeton de sécurité invalide : rechargez la page.']);
    }
    if (!hasFullACL(AclRobin, '', AclReadWrite) || IsBlocked(BIT_BLOCK_ROBIN)) {
        tdm_json(['error' => 1, 'msg' => 'Droits insuffisants ou poules verrouillées.']);
    }
    $round = intval($_POST['round'] ?? 0);

    if ($act === 'score') {
        $raw = trim((string) ($_POST['score'] ?? ''));
        if ($raw !== '' && !ctype_digit($raw)) tdm_json(['error' => 1, 'msg' => 'Score : nombre entier attendu.']);
        $err = tdm_bso_save_score($ev, $round, (string) ($_POST['key'] ?? ''), $raw === '' ? null : intval($raw));
    } else {
        $orders = [];
        foreach ((array) ($_POST['order'] ?? []) as $k => $v) {
            $v = trim((string) $v);
            if (!ctype_digit($v)) tdm_json(['error' => 1, 'msg' => 'Ordre : nombre entier attendu.']);
            $orders[(string) $k] = intval($v);
        }
        $err = tdm_bso_save_orders($ev, $round, $orders);
    }
    tdm_json(['error' => $err ? 1 : 0, 'msg' => $err, 'state' => tdm_bso_state($ev)]);
}

tdm_json(['error' => 1, 'msg' => 'Action inconnue.']);
