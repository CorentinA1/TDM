<?php
/**
 * equipes-action.php — TDM : composition des équipes, réponses JSON.
 *
 *   GET  act=state                       état complet (tdm_setup_composition_state())
 *   POST act=place  id, cat, sub         archer déposé dans une équipe
 *   POST act=remove id                   archer remis dans les non placés
 *   POST act=auto   club                 « Composer automatiquement » un club
 *
 * Chaque écriture est enregistrée tout de suite et renvoie l'état à jour.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/setup.php';
tdm_require(AclParticipants, AclReadOnly);

header('Content-Type: application/json; charset=utf-8');
function tdm_json($data)
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$act = (string) ($_REQUEST['act'] ?? '');
if ($act === 'state') tdm_json(['state' => tdm_setup_composition_state()]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') tdm_json(['error' => 'Requête invalide.']);
if (!hash_equals(tdm_token(), (string) ($_POST['tdm_token'] ?? ''))) {
    tdm_json(['error' => 'Jeton de sécurité invalide : rechargez la page.']);
}
if (!hasFullACL(AclParticipants, '', AclReadWrite) || IsBlocked(BIT_BLOCK_PARTICIPANT)) {
    tdm_json(['error' => 'Droits insuffisants ou liste des participants verrouillée.']);
}

$archers = tdm_setup_archers();
$input = tdm_setup_place_input($archers);
$id = intval($_POST['id'] ?? 0);
$error = null;
$changes = [];

if ($act === 'place') {
    $r = tdm_place_changes($input, $id, (string) ($_POST['cat'] ?? ''), intval($_POST['sub'] ?? 0), tdm_setup_class_divisions());
    $error = $r['error'];
    $changes = $r['changes'];
} elseif ($act === 'remove') {
    $changes = tdm_remove_changes($input, $id);
    if (!$changes) $error = 'archer inconnu';
} elseif ($act === 'auto') {
    // club = 0 : tous les clubs.
    $club = intval($_POST['club'] ?? 0);
    $clubs = $club ? [$club] : array_unique(array_column($input, 'club'));
    foreach ($clubs as $c) $changes += tdm_auto_changes($input, $c);
} else {
    $error = 'action inconnue';
}

if ($error !== null) {
    $who = isset($archers[$id]) ? trim($archers[$id]->EnFirstName . ' ' . $archers[$id]->EnName) . ' : ' : '';
    tdm_json(['error' => ucfirst($who . $error) . '.', 'state' => tdm_setup_composition_state()]);
}
$n = $changes ? tdm_setup_save_composition($changes, $archers) : 0;
$msg = $act === 'auto' ? ($n ? (count($changes) / 2) . ' équipe(s) formée(s).' : 'Rien à composer automatiquement : '
    . 'il faut au moins une femme et un homme non placés dans une même catégorie.') : '';
tdm_json(['ok' => true, 'msg' => $msg, 'state' => tdm_setup_composition_state()]);
