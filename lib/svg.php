<?php
/**
 * lib/svg.php — images SVG des blasons pour les PDF.
 *
 * Les images natives d'IANSEO (Common/Images/Targets/N.svg, utilisées par le
 * « Plan de cible visuel ») colorent leurs zones avec une feuille de style
 * interne (<style> : « circle {…} .a {…} »). Le moteur SVG de TCPDF ignore ces
 * règles : les zones sortent noires. On recopie donc les règles dans l'attribut
 * style de chaque élément, sans toucher aux fichiers d'origine.
 */

/**
 * Recopie la feuille de style interne d'un SVG dans les attributs style. Fonction pure.
 *
 * Gère les règles par élément (« circle ») et par classe (« .a », éventuellement
 * plusieurs classes par élément). Ordre de priorité : élément, puis classes dans
 * l'ordre de l'attribut class, puis le style déjà écrit sur l'élément.
 *
 * @return string SVG sans <style>, ou le SVG d'origine s'il est illisible.
 */
function tdm_svg_inline_css($svg)
{
    $doc = new DOMDocument();
    if (!@$doc->loadXML($svg)) return $svg;
    $rules = ['el' => [], 'class' => []];
    $styles = [];
    foreach ($doc->getElementsByTagName('style') as $st) $styles[] = $st;
    foreach ($styles as $st) {
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $st->textContent, $m, PREG_SET_ORDER);
        foreach ($m as [, $selectors, $body]) {
            $props = tdm_svg_parse_props($body);
            foreach (explode(',', $selectors) as $sel) {
                $sel = trim($sel);
                if ($sel === '') continue;
                if ($sel[0] === '.') $rules['class'][substr($sel, 1)] = array_merge($rules['class'][substr($sel, 1)] ?? [], $props);
                else $rules['el'][$sel] = array_merge($rules['el'][$sel] ?? [], $props);
            }
        }
        $st->parentNode->removeChild($st);
    }
    if (!$rules['el'] && !$rules['class']) return $doc->saveXML($doc->documentElement);
    foreach ($doc->getElementsByTagName('*') as $node) {
        $props = $rules['el'][$node->localName] ?? [];
        foreach (preg_split('/\s+/', trim($node->getAttribute('class'))) as $c) {
            if ($c !== '' && isset($rules['class'][$c])) $props = array_merge($props, $rules['class'][$c]);
        }
        if ($node->hasAttribute('style')) $props = array_merge($props, tdm_svg_parse_props($node->getAttribute('style')));
        if (!$props) continue;
        $node->setAttribute('style', implode(';', array_map(fn($k, $v) => "$k:$v", array_keys($props), $props)));
        $node->removeAttribute('class');
    }
    return $doc->saveXML($doc->documentElement);
}

/** « fill:#fff; stroke:#000 » → ['fill' => '#fff', 'stroke' => '#000']. */
function tdm_svg_parse_props($text)
{
    $out = [];
    foreach (explode(';', $text) as $decl) {
        $p = explode(':', $decl, 2);
        if (count($p) === 2 && trim($p[0]) !== '') $out[strtolower(trim($p[0]))] = trim($p[1]);
    }
    return $out;
}
