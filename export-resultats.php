<?php
/**
 * export-resultats.php — TDM : extraction des résultats pour Excel (CSV).
 *
 * Une ligne par équipe, toutes catégories (ou une seule avec ?ev=CL), colonnes
 * de la feuille de résultats du classeur de l'utilisateur, plus la place
 * finale, la poule, le BSO et l'état (définitif / provisoire). Séparateur « ; »
 * et UTF-8 avec BOM : le fichier s'ouvre directement dans Excel.
 * (PhpSpreadsheet, fourni avec IANSEO, demande PHP 8.3 : d'où le CSV.)
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/results.php';
tdm_require(AclQualification, AclReadOnly);
tdm_schema();

$events = tdm_events();
$only = (string) ($_GET['ev'] ?? '');
if (!isset($events[$only])) $only = '';

$t = tdm_tournament();
$name = 'Resultats TDM ' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($_SESSION['TourCode'] ?? '')) . ($only ? " $only" : '') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
$csv = fn(array $row) => fputcsv($out, $row, ';', '"', '');
$csv(['Compétition', $t->ToName ?? '', 'Lieu', $t->ToWhere ?? '', 'Date', tdm_date_fr_csv($t->ToWhenFrom ?? '')]);
$csv([]);
$csv(['Catégorie', 'Place finale', 'Ex aequo', 'Équipe', 'N° agrément club', 'Nom-Prénom archer Femme', 'Licence archer Femme',
      'Nom-Prénom archer Homme', 'Licence archer Homme', 'Score Qualifications', 'Classement Qualifications', 'Poule',
      'Classement Poule', 'Place BSO', 'Points', 'État']);
foreach ($events as $code => $ev) {
    if ($only !== '' && $only !== $code) continue;
    $res = tdm_category_results($ev);
    $state = tdm_results_status($res);
    foreach ($res->Rows as $r) {
        $csv([$ev->Name, $r->Place ?? '', $r->Tie ? 'oui' : '', $r->Name, $r->ClubCode, $r->Woman, $r->WomanCode, $r->Man, $r->ManCode,
              $r->Qualif ?? '', $r->QualifRank ?: '', $r->Pool ?: '', $r->PoolRank ?: '', $r->BsoPlace ?? '',
              $r->Place !== null ? $r->Points : '', $state]);
    }
}
fclose($out);

/** Date « 18/10/2026 ». */
function tdm_date_fr_csv($d)
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $d, $m) ? "$m[3]/$m[2]/$m[1]" : (string) $d;
}
