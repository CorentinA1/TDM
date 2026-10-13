<?php
/**
 * bso-ecran.php — TDM : écran public / commentateur du Big Shoot Off.
 *
 * Page autonome, en lecture seule, à afficher sur un grand écran : volée en
 * cours (cibles, scores, qualifiées / éliminées), volées précédentes et
 * places acquises. Mise à jour toutes les 3 s.
 *
 *   bso-ecran.php?ev=CL          une catégorie
 *   bso-ecran.php?rotate=20      fait tourner les catégories toutes les 20 s
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/bsodb.php';
tdm_require(AclRobin, AclReadOnly);
tdm_schema();

$events = [];
foreach (tdm_events() as $code => $ev) {
    if (tdm_bso_teams($ev)) $events[] = ['event' => $code, 'name' => $ev->Name];
}
$first = (string) ($_GET['ev'] ?? '');
if (!in_array($first, array_column($events, 'event'), true)) $first = $events[0]['event'] ?? '';
$rotate = max(0, min(600, intval($_GET['rotate'] ?? 0)));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Big Shoot Off — <?= tdm_h(tdm_tournament()->ToName ?? '') ?></title>
<style>
* { box-sizing:border-box; }
html, body { margin:0; height:100%; }
body { font-family:system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#0b1a44; color:#fff; }
header { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:1.2vh 2vw;
         background:linear-gradient(90deg, #002b92, #f90a72); }
header h1 { margin:0; font-size:3.4vh; letter-spacing:.05em; }
header .comp { font-size:1.8vh; opacity:.85; }
header .ev { font-size:3vh; font-weight:800; text-align:right; }
nav { display:flex; gap:.6vw; padding:.6vh 2vw; background:rgba(255,255,255,.06); }
nav button { background:transparent; color:#c9d3f0; border:1px solid rgba(255,255,255,.25); border-radius:2vh;
             padding:.4vh 1vw; font-size:1.6vh; cursor:pointer; }
nav button.on { background:#fff; color:#002b92; font-weight:700; }
body.clean nav { display:none; }
main { display:grid; grid-template-columns:2fr 1fr; gap:2vw; padding:2vh 2vw; height:calc(100% - 13vh); }
h2 { margin:0 0 1.4vh; font-size:2.6vh; color:#f9a8cc; text-transform:uppercase; letter-spacing:.08em; }
.row { display:flex; align-items:center; gap:1.4vw; padding:1.1vh 1.2vw; margin-bottom:1vh; border-radius:1vh;
       background:rgba(255,255,255,.08); border-left:.8vh solid rgba(255,255,255,.2); transition:background .4s; }
.row.q { border-left-color:#3ddc84; background:rgba(61,220,132,.13); }
.row.e { opacity:.55; }
.row.p { border-left-color:#ffc23d; background:rgba(255,194,61,.14); }
.tgt { font-size:2.2vh; color:#9fb0dc; min-width:5vw; }
.name { flex:1; font-size:3.4vh; font-weight:700; }
.st { font-size:2vh; font-weight:700; min-width:10vw; text-align:right; }
.row.q .st { color:#3ddc84; } .row.p .st { color:#ffc23d; } .row.e .st { color:#c0c7d8; }
.sc { font-size:5vh; font-weight:900; min-width:7vw; text-align:right; font-variant-numeric:tabular-nums; }
.side .row .name { font-size:2.4vh; } .side .row .sc { font-size:2.8vh; } .side .tgt { min-width:3vw; }
.place { font-size:3vh; font-weight:900; color:#f9a8cc; min-width:4vw; }
.small { font-size:1.8vh; color:#9fb0dc; margin:-.6vh 0 1.4vh; }
.prev { margin-top:2vh; }
.empty { font-size:3vh; color:#9fb0dc; text-align:center; padding:20vh 0; grid-column:1 / -1; }
</style>
</head>
<body class="<?= isset($_GET['clean']) ? 'clean' : '' ?>">
<header>
    <div><h1>BIG SHOOT OFF</h1><div class="comp"><?= tdm_h(tdm_tournament()->ToName ?? '') ?></div></div>
    <div class="ev" id="evname"></div>
</header>
<nav id="evbar">
    <?php foreach ($events as $e): ?>
        <button type="button" data-ev="<?= tdm_h($e['event']) ?>"><?= tdm_h($e['name']) ?></button>
    <?php endforeach; ?>
</nav>
<main id="main"><?php if (!$events): ?><div class="empty">Aucun Big Shoot Off pour le moment.</div><?php endif; ?></main>
<script>
(function () {
    var URL = <?= json_encode($TDM_ROOT . 'bso-action.php') ?>;
    var EVENTS = <?= json_encode(array_column($events, 'event')) ?>;
    var ROTATE = <?= $rotate ?>;
    var ev = <?= json_encode($first) ?>;
    var stamp = '';
    if (!ev) return;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function title(rd) { return rd.qualifiers === 1 ? 'Finale' : 'Volée ' + rd.round; }
    function st(row, rd, pend) {
        if (pend) return 'Égalité';
        if (row.status === 'q') return rd.qualifiers === 1 ? 'Vainqueur' : 'Qualifiée';
        if (row.status === 'e') return row.rank + 'e';
        return '';
    }
    function rows(rd, big) {
        var pend = {};
        rd.pending.forEach(function (g) { g.forEach(function (k) { pend[k] = true; }); });
        var list = rd.complete ? rd.rows : rd.byTarget, h = '';
        list.forEach(function (r) {
            h += '<div class="row ' + (pend[r.key] ? 'p' : (r.status || '')) + '">'
               + '<div class="tgt">' + (big ? 'Cible ' : '') + esc(r.target) + '</div>'
               + '<div class="name">' + esc(r.name) + '</div>'
               + '<div class="st">' + st(r, rd, pend[r.key]) + '</div>'
               + '<div class="sc">' + (r.score == null ? '–' : r.score) + '</div></div>';
        });
        return h;
    }
    function render(s) {
        document.getElementById('evname').textContent = s.name;
        document.querySelectorAll('#evbar button').forEach(function (b) { b.classList.toggle('on', b.dataset.ev === ev); });
        var cur = s.rounds.filter(function (r) { return r.round === s.current; })[0];
        var h = '<section>';
        if (!cur) h += '<div class="empty">En attente des équipes.</div>';
        else {
            h += '<h2>' + (s.finished ? 'Résultat — ' : '') + title(cur) + '</h2><div class="small">' + esc(cur.label) + '</div>' + rows(cur, true);
            s.rounds.slice().reverse().forEach(function (rd) {
                if (rd.round === s.current) return;
                h += '<div class="prev"><h2>' + title(rd) + '</h2>' + rows(rd, false) + '</div>';
            });
        }
        h += '</section><section class="side"><h2>Classement</h2>';
        if (!s.places.length) h += '<div class="small">Les places apparaissent au fil des volées.</div>';
        s.places.forEach(function (p) {
            h += '<div class="row"><div class="place">' + p.place + '</div><div class="name">' + esc(p.name) + '</div></div>';
        });
        h += '</section>';
        document.getElementById('main').innerHTML = h;
    }
    function load() {
        fetch(URL + '?act=state&ev=' + encodeURIComponent(ev), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) return;
                if (d.state.stamp + d.state.event === stamp) return;
                stamp = d.state.stamp + d.state.event;
                render(d.state);
            }).catch(function () {});
    }
    document.getElementById('evbar').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        ev = b.dataset.ev; stamp = ''; load();
    });
    load();
    setInterval(load, 3000);
    if (ROTATE && EVENTS.length > 1) {
        setInterval(function () {
            ev = EVENTS[(EVENTS.indexOf(ev) + 1) % EVENTS.length]; stamp = ''; load();
        }, ROTATE * 1000);
    }
})();
</script>
</body>
</html>
