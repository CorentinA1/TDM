<?php
/**
 * lib/xlsx.php — écriture d'un classeur Excel (.xlsx) simple, sans bibliothèque.
 *
 * PhpSpreadsheet, fourni avec ianseo, demande PHP 8.3 : on écrit donc le format
 * Office Open XML directement (ZipArchive). Juste ce qu'il faut pour des tableaux
 * de résultats : textes, nombres, dates, largeurs de colonnes, cellules fusionnées
 * et quelques styles fixes.
 *
 * Feuille : ['name' => nom, 'cols' => [largeur, …], 'merges' => ['E2:H2', …],
 *            'rows' => [n° de ligne => [lettre de colonne => [valeur, style]]]].
 * Valeur : chaîne, entier ou flottant ; une date « AAAA-MM-JJ » avec le style TDM_XLSX_DATE.
 */

/** Styles disponibles (index dans cellXfs). */
const TDM_XLSX_NORMAL = 0;
const TDM_XLSX_BOLD = 1;     // gras
const TDM_XLSX_HEAD = 2;     // en-tête de tableau : gras, fond gris-bleu, bordures, centré, retour à la ligne
const TDM_XLSX_CELL = 3;     // cellule de tableau : bordures
const TDM_XLSX_NOTE = 4;     // texte d'information : gras, rouge, retour à la ligne
const TDM_XLSX_DATE = 5;     // date jj/mm/aaaa, gras
const TDM_XLSX_WRAP = 6;     // texte avec retour à la ligne, gras

/** Référence de colonne Excel : 1 → A, 27 → AA. */
function tdm_xlsx_col($n)
{
    $s = '';
    for ($n = (int) $n; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
    return $s;
}

/** Échappement XML d'un texte de cellule. */
function tdm_xlsx_esc($v)
{
    return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Numéro de série Excel d'une date « AAAA-MM-JJ » (null si invalide). */
function tdm_xlsx_date_serial($date)
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
    return (int) ((gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
}

/** XML d'une feuille. Fonction pure. */
function tdm_xlsx_sheet_xml(array $sheet)
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    if (!empty($sheet['cols'])) {
        $x .= '<cols>';
        foreach (array_values($sheet['cols']) as $i => $w) {
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
        }
        $x .= '</cols>';
    }
    $x .= '<sheetData>';
    $rows = $sheet['rows'] ?? [];
    ksort($rows);
    foreach ($rows as $r => $cells) {
        $height = $cells['_h'] ?? null;
        unset($cells['_h']);
        uksort($cells, fn($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
        $x .= '<row r="' . (int) $r . '"' . ($height ? ' ht="' . (float) $height . '" customHeight="1"' : '') . '>';
        foreach ($cells as $col => [$v, $s]) {
            $ref = $col . (int) $r;
            $s = (int) $s;
            if ($v === null || $v === '') {
                $x .= '<c r="' . $ref . '" s="' . $s . '"/>';
            } elseif ($s === TDM_XLSX_DATE && ($serial = tdm_xlsx_date_serial($v)) !== null) {
                $x .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $serial . '</v></c>';
            } elseif (is_int($v) || is_float($v)) {
                $x .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . tdm_xlsx_esc($v) . '</t></is></c>';
            }
        }
        $x .= '</row>';
    }
    $x .= '</sheetData>';
    if (!empty($sheet['merges'])) {
        $x .= '<mergeCells count="' . count($sheet['merges']) . '">';
        foreach ($sheet['merges'] as $m) $x .= '<mergeCell ref="' . tdm_xlsx_esc($m) . '"/>';
        $x .= '</mergeCells>';
    }
    return $x . '</worksheet>';
}

/** Feuille de styles (les styles TDM_XLSX_*). */
function tdm_xlsx_styles_xml()
{
    $border = '<left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right>'
        . '<top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFC00000"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E1F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>' . $border . '</border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyAlignment="1"><alignment horizontal="left"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * Écrit le classeur dans un fichier.
 *
 * @param array $sheets Feuilles (voir l'en-tête du fichier).
 * @return bool
 */
function tdm_xlsx_write(array $sheets, $file)
{
    $z = new ZipArchive();
    if ($z->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $n = count($sheets);
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wbSheets = $wbRels = '';
    foreach (array_values($sheets) as $i => $s) {
        $k = $i + 1;
        $types .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        // Nom de feuille : 31 caractères au plus, sans : \ / ? * [ ].
        $name = mb_substr(preg_replace('#[:\\\\/?*\[\]]#u', ' ', (string) $s['name']), 0, 31);
        $wbSheets .= '<sheet name="' . tdm_xlsx_esc($name) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
        $wbRels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
        $z->addFromString('xl/worksheets/sheet' . $k . '.xml', tdm_xlsx_sheet_xml($s));
    }
    $types .= '</Types>';
    $z->addFromString('[Content_Types].xml', $types);
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $wbSheets . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels
        . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $z->addFromString('xl/styles.xml', tdm_xlsx_styles_xml());
    return $z->close();
}
