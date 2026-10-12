<?php
// ── Menu ianseo — module TDM (Trophée Départemental des Mixtes) ──────────────
// Inclus sur toutes les pages par Common/Menu.php. Entrées visibles seulement si
// une compétition est ouverte, selon les droits.
//
// Tant que l'étape 1 de la configuration n'est pas enregistrée (paramètre de
// module TDM « Configured », voir lib/setup.php), le sous-menu n'offre que
// « Configuration ». Ensuite : toutes les entrées, et une barre TDM statique
// injectée sous le menu ianseo sur toutes les pages (même principe que le
// module TNM), la page en cours en surbrillance.
if (!empty($on) && !empty($_SESSION['TourId'])) {
    $tdmQual  = subFeatureAcl($acl, AclQualification, '') >= AclReadOnly;
    $tdmRobin = subFeatureAcl($acl, AclRobin, '') >= AclReadWrite;
    $tdmRobinRead = subFeatureAcl($acl, AclRobin, '') >= AclReadOnly;
    $tdmSetup = subFeatureAcl($acl, AclCompetition, 'cData') >= AclReadOnly;
    $tdmPart = subFeatureAcl($acl, AclParticipants, '') >= AclReadOnly;
    $tdmOn = (int) getModuleParameter('TDM', 'Configured', 0) === 1;
    $tdmRoot = $CFG->ROOT_DIR . 'Modules/Custom/TDM/';

    // Entrées : [libellé du menu, libellé de la barre, adresse, droit].
    $tdmItems = [
        ['Configuration', 'Configuration', $tdmRoot . 'preconfig.php', $tdmSetup],
        ['Composition des équipes', 'Équipes', $tdmRoot . 'equipes.php', $tdmPart],
        ['Qualifications', 'Qualifications', $tdmRoot . 'qualif.php', $tdmQual],
        ['Répartition des poules', 'Répartition', $tdmRoot . 'repartition.php', $tdmQual],
        ['Validation qualif → poules', 'Validation', $CFG->ROOT_DIR . 'Modules/RoundRobin/AbsRobin.php?team=1', $tdmRobin],
        ['Poules', 'Poules', $tdmRoot . 'pools.php', $tdmRobinRead],
        ['Big Shoot Off', 'Big Shoot Off', $tdmRoot . 'bso.php', $tdmRobinRead],
        ['Saisie BSO (téléphone)', 'Saisie BSO', $tdmRoot . 'bso-saisie.php', $tdmRobinRead],
        ['Écran BSO (public)', 'Écran BSO', $tdmRoot . 'bso-ecran.php', $tdmRobinRead],
        ['Résultats', 'Résultats', $tdmRoot . 'resultats.php', $tdmQual],
    ];

    if ($tdmQual || $tdmRobinRead || $tdmSetup) {
        // Même clé partout ('TDM') : le premier élément est le titre du sous-menu.
        if (!isset($ret['MODS']['TDM'])) $ret['MODS']['TDM'][] = 'Trophée des Mixtes';
        foreach ($tdmItems as $i => [$label, , $url, $allowed]) {
            if ($allowed && ($tdmOn || $i === 0)) $ret['MODS']['TDM'][] = $label . '|' . $url;
        }
        // Administrateur seulement. Avec un module de comptes (AUTH), AclRoot ne
        // suffit pas : il faut aussi la vue administrateur serveur (AUTH_ROOT).
        if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
            && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
            $ret['MODS']['TDM'][] = MENU_DIVIDER;
            $ret['MODS']['TDM'][] = 'Mise à jour du module|' . $tdmRoot . 'admin/update.php';
        }
    }

    // ── Barre TDM statique (une seule fois par page) ─────────────────────────
    if ($tdmOn && empty($GLOBALS['_tdm_bar_done'])) {
        $GLOBALS['_tdm_bar_done'] = true;
        $tdmHtml = '<div id="tdm-bar">';
        foreach ($tdmItems as [, $short, $url, $allowed]) {
            if ($allowed) $tdmHtml .= '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($short) . '</a>';
        }
        $tdmHtml .= '</div>';
        echo '<style>
#tdm-bar{clear:both;background:#002B92;padding:2px 8px;display:flex;gap:2px;flex-wrap:wrap;align-items:center}
#tdm-bar::before{content:"TDM";color:#F90A72;font-size:16px;font-weight:bold;padding:0 10px 0 4px;letter-spacing:3px;flex-shrink:0}
#tdm-bar a{color:#fff;font-size:12px;padding:3px 10px;border-radius:3px;text-decoration:none;white-space:nowrap}
#tdm-bar a:hover{background:rgba(255,255,255,.15)}
#tdm-bar a.tdm-cur{background:#F90A72;color:#fff;font-weight:bold}
@media print{#tdm-bar{display:none}}
</style><script>(function(){var h=' . json_encode($tdmHtml) . ';
document.addEventListener("DOMContentLoaded",function(){
  if(document.getElementById("tdm-bar"))return;
  var nav=document.getElementById("navigation");
  if(!nav)return;
  var d=document.createElement("div");
  d.innerHTML=h;
  var bar=d.firstChild;
  /* Sous la barre TNM si elle est là, sinon sous le menu ianseo. */
  var after=document.getElementById("tnm-nav")||nav;
  after.insertAdjacentElement("afterend",bar);
  var p=location.pathname,q=location.search;
  bar.querySelectorAll("a").forEach(function(a){
    if(a.pathname===p&&(a.search===""||q.indexOf(a.search.slice(1))>=0))a.classList.add("tdm-cur");
  });
});
})();</script>';
        unset($tdmHtml);
    }
    unset($tdmQual, $tdmRobin, $tdmRobinRead, $tdmSetup, $tdmPart, $tdmOn, $tdmRoot, $tdmItems);
}
