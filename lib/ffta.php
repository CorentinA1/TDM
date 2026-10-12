<?php
/**
 * lib/ffta.php — fichier de résultats à envoyer à la FFTA (mixite@ffta.fr).
 *
 * Même présentation que le modèle fourni par la FFTA (classeur à 2 feuilles) :
 *  1. « 1- qualifiés Trophée National » : le vainqueur de chaque catégorie ;
 *  2. « 2 - inscriptions » : lieu, date, puis toutes les équipes, « OUI » pour les vainqueurs.
 * En-têtes en ligne 7, données à partir de la ligne 8. Fonctions pures : les
 * données leur sont passées (export-ffta.php les lit dans la compétition).
 */

/** Dates de la saison (règlement 2026, section 6) : à mettre à jour chaque saison. */
const TDM_FFTA_RESULTS_DEADLINE = '28 avril 2026';
const TDM_FFTA_SIGNUP = 'du 28 avril au 11 mai 2026';

/** Libellés des catégories dans le modèle FFTA : [feuille 1 (court), feuille 2 (long)]. */
const TDM_FFTA_CATEGORIES = [
    'NEO' => ['Néo-licenciés arc classique', 'Néo-licenciés arc classique (Première année de licence)'],
    'CL'  => ['Classiques', 'Arc classique (Toutes catégories d’âge arc classique (valide et para) Arc nu possible)'],
    'CO'  => ['Arcs à Poulies', 'Arc à Poulies (Deuxième année et + de licence)'],
    'BB'  => ['Arcs Nus', 'Arc Nu (Toutes catégorie d\'âge)'],
];

/**
 * Feuilles du classeur FFTA.
 *
 * @param array $info ['cd' => « CD99 », 'committee' => « Mon département (99) », 'place' => lieu, 'date' => AAAA-MM-JJ].
 * @param array $cats code de catégorie => équipes, chacune :
 *        ['club' => nom du club, 'sub' => n° d'équipe, 'agrement' => n° d'agrément du club,
 *         'woman', 'womanLic', 'man', 'manLic' => « Nom Prénom » et licence, 'score' => qualif|null, 'place' => place finale|null].
 * @return array Feuilles pour tdm_xlsx_write().
 */
function tdm_ffta_sheets(array $info, array $cats)
{
    $head = fn($cells) => array_map(fn($v) => [$v, TDM_XLSX_HEAD], $cells);
    $row = fn($cells) => array_map(fn($v) => [$v, TDM_XLSX_CELL], $cells);
    $letters = fn($n) => array_map('tdm_xlsx_col', range(1, $n));

    // ── Feuille 1 : qualifiés (vainqueur de chaque catégorie) ─────────────────
    $s1 = [
        2 => ['E' => ['Feuilles 1 et 2 à nous renvoyer (format Excel) complétées par mail à mixite@ffta.fr au plus tard le '
            . TDM_FFTA_RESULTS_DEADLINE . ' (si possible le préfixer avec votre n° de département)', TDM_XLSX_NOTE], '_h' => 45],
        4 => ['A' => ['Nom Comité départemental :', TDM_XLSX_BOLD], 'C' => [(string) ($info['committee'] ?? ''), TDM_XLSX_BOLD],
              'E' => ["Liste des clubs qualifiés pour le Trophée National des Mixtes\nLes clubs devront inscrire leurs équipes via "
                  . "l'espace dirigeant dans la gestion sportive.\nInscriptions ouvertes " . TDM_FFTA_SIGNUP, TDM_XLSX_WRAP], '_h' => 60],
        7 => array_combine($letters(9), $head(['N° Comité Départemental', 'Catégorie', 'Club', 'N° agrément club',
            'Nom-Prénom archer Femmes', 'Licence archer Femme', 'Nom-Prénom archer Hommes', 'Licence archer Homme',
            'Score qualifications'])) + ['_h' => 30],
    ];
    $r = 8;
    foreach (TDM_FFTA_CATEGORIES as $code => [$short]) {
        $teams = $cats[$code] ?? [];
        $perClub = array_count_values(array_map(fn($t) => (string) $t['agrement'] . '|' . $t['club'], $teams));
        foreach ($teams as $t) {
            if ((int) ($t['place'] ?? 0) !== 1) continue;
            // Nom d'équipe : le club, suivi du numéro si le club a plusieurs équipes dans la catégorie.
            $name = $t['club'] . (($perClub[(string) $t['agrement'] . '|' . $t['club']] ?? 1) > 1 ? ' ' . (int) $t['sub'] : '');
            $s1[$r++] = array_combine($letters(9), $row([(string) ($info['cd'] ?? ''), $short, $name, tdm_ffta_number($t['agrement']),
                $t['woman'], $t['womanLic'], $t['man'], $t['manLic'], $t['score'] === null ? '' : (int) $t['score']]));
        }
    }

    // ── Feuille 2 : toutes les équipes ────────────────────────────────────────
    $s2 = [
        2 => ['C' => ['Feuille à nous renvoyer complétée par mail à mixite@ffta.fr (si possible le préfixer avec votre n° de département) '
            . 'au plus tard le ' . TDM_FFTA_RESULTS_DEADLINE, TDM_XLSX_NOTE], '_h' => 30],
        4 => ['A' => ['Lieu :', TDM_XLSX_BOLD], 'B' => [(string) ($info['place'] ?? ''), TDM_XLSX_BOLD],
              'E' => ['Date :', TDM_XLSX_BOLD], 'F' => [(string) ($info['date'] ?? ''), TDM_XLSX_DATE]],
        7 => array_combine($letters(8), $head(['Catégorie', 'Club', 'N° agrément club', 'Nom-Prénom archer Femmes',
            'Licence archer Femme', 'Nom-Prénom archer Hommes', 'Licence archer Homme', 'Victoire trophée départemental '])) + ['_h' => 30],
    ];
    $r = 8;
    foreach (TDM_FFTA_CATEGORIES as $code => [, $long]) {
        $teams = $cats[$code] ?? [];
        usort($teams, fn($a, $b) => strnatcasecmp($a['club'], $b['club']) ?: (int) $a['sub'] <=> (int) $b['sub']);
        foreach ($teams as $t) {
            $s2[$r++] = array_combine($letters(8), $row([$long, $t['club'], tdm_ffta_number($t['agrement']), $t['woman'], $t['womanLic'],
                $t['man'], $t['manLic'], (int) ($t['place'] ?? 0) === 1 ? 'OUI' : '']));
        }
    }

    return [
        ['name' => '1- qualifiés Trophée National', 'rows' => $s1, 'merges' => ['E2:H2', 'E3:H3', 'A4:B4', 'E4:H4'],
         'cols' => [15.9, 27.7, 35, 24.1, 32, 21.3, 28.3, 24.7, 19.7]],
        ['name' => '2 - inscriptions', 'rows' => $s2, 'merges' => ['C2:H2', 'B4:C4'],
         'cols' => [80, 27.9, 19.1, 32, 24.3, 29.1, 26, 18.3]],
    ];
}

/** N° d'agrément : nombre s'il n'est fait que de chiffres (comme dans le modèle), sinon texte. */
function tdm_ffta_number($v)
{
    $v = trim((string) $v);
    return ctype_digit($v) && $v[0] !== '0' && strlen($v) < 16 ? (int) $v : $v;
}
