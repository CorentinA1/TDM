<?php
/**
 * export-ffta.php — TDM : fichier Excel des résultats à envoyer à la FFTA.
 *
 * Reçoit le formulaire de la page Résultats (n° de CD, nom du comité, lieu,
 * date), garde ces valeurs pour la prochaine fois (paramètre de module TDM
 * « Ffta ») et envoie le classeur au format du modèle FFTA (lib/ffta.php).
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/results.php';
require_once __DIR__ . '/lib/xlsx.php';
require_once __DIR__ . '/lib/ffta.php';
tdm_require(AclQualification, AclReadOnly);
tdm_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $TDM_ROOT . 'resultats.php#ffta');
    exit;
}
tdm_check_token();

$info = [];
foreach (['cd' => 20, 'committee' => 80, 'place' => 80, 'date' => 10] as $k => $max) {
    $info[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $info['date'])) $info['date'] = '';
setModuleParameter('TDM', 'Ffta', $info);

// Équipes de chaque catégorie, avec leur place finale.
$cats = [];
foreach (tdm_events() as $code => $ev) {
    foreach (tdm_category_results($ev)->Rows as $r) {
        $cats[$code][] = ['club' => $r->Club ?: $r->Name, 'sub' => $r->Sub, 'agrement' => $r->ClubCode,
            'woman' => $r->WomanNP, 'womanLic' => $r->WomanCode, 'man' => $r->ManNP, 'manLic' => $r->ManCode,
            'score' => $r->Qualif, 'place' => $r->Place];
    }
}

$tmp = tempnam(sys_get_temp_dir(), 'tdm');
if (!tdm_xlsx_write(tdm_ffta_sheets($info, $cats), $tmp)) {
    @unlink($tmp);
    exit('Impossible de créer le fichier Excel.');
}
$name = 'Résultat ' . ($info['cd'] ?: 'TDM') . ' TDM ' . substr($info['date'] ?: date('Y'), 0, 4) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"" . strtr($name, ['é' => 'e', '"' => '']) . "\"; filename*=UTF-8''" . rawurlencode($name));
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
