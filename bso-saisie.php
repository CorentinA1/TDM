<?php
/**
 * bso-saisie.php — TDM : saisie du Big Shoot Off, pensée pour le téléphone.
 *
 * Page autonome (sans le menu ianseo) : choix de la catégorie, volée en
 * cours, une ligne par cible avec son score (0 à 20), enregistré dès la
 * saisie. Une égalité à trancher (flèche la plus près du centre, barrage)
 * s'affiche sous la volée avec un bouton par équipe. Plusieurs personnes
 * peuvent saisir en même temps : la page se met à jour toutes les 3 s.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/bsodb.php';
tdm_require(AclRobin, AclReadOnly);
tdm_schema();

$canWrite = hasFullACL(AclRobin, '', AclReadWrite) && !IsBlocked(BIT_BLOCK_ROBIN);
$events = [];
foreach (tdm_events() as $code => $ev) {
    if (tdm_bso_teams($ev)) $events[] = ['event' => $code, 'name' => $ev->Name];
}
$first = (string) ($_GET['ev'] ?? '');
if (!in_array($first, array_column($events, 'event'), true)) $first = $events[0]['event'] ?? '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Saisie BSO — <?= tdm_h(tdm_tournament()->ToName ?? '') ?></title>
<style>
* { box-sizing:border-box; }
body { margin:0; font-family:system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#eef1f6; color:#22252a; }
header { background:#002b92; color:#fff; padding:10px 12px; position:sticky; top:0; z-index:5; }
header h1 { margin:0; font-size:17px; display:flex; justify-content:space-between; align-items:center; gap:8px; }
header h1 a.back { color:#002b92; background:#fff; font-size:13px; font-weight:700; text-decoration:none;
                    border-radius:16px; padding:5px 12px; white-space:nowrap; }
header .comp { font-size:12px; opacity:.8; margin-top:2px; }
.bar { display:flex; gap:6px; overflow-x:auto; padding:8px 10px; background:#fff; border-bottom:1px solid #d7dbe2; }
.bar button { flex:0 0 auto; border:1px solid #002b92; background:#fff; color:#002b92; border-radius:16px;
              padding:6px 12px; font-size:14px; font-weight:600; }
.bar button.on { background:#002b92; color:#fff; }
.bar.rounds button { border-color:#f90a72; color:#f90a72; }
.bar.rounds button.on { background:#f90a72; color:#fff; }
main { padding:10px; max-width:640px; margin:0 auto; }
.info { font-size:14px; margin:4px 2px 10px; color:#4c4e50; }
.info b { color:#002b92; }
.row { display:flex; align-items:center; gap:10px; background:#fff; border-radius:10px; padding:10px;
       margin-bottom:8px; box-shadow:0 1px 2px rgba(0,0,0,.08); border-left:6px solid #d7dbe2; }
.row.q { border-left-color:#2ea84e; }
.row.e { border-left-color:#b9bec7; opacity:.75; }
.row.p { border-left-color:#f0a500; }
.tgt { min-width:44px; text-align:center; font-weight:800; font-size:20px; color:#002b92; }
.tgt small { display:block; font-size:10px; font-weight:600; color:#7d8183; }
.name { flex:1; font-weight:600; font-size:16px; line-height:1.2; }
.name .st { display:block; font-size:12px; font-weight:600; margin-top:3px; }
.row.q .st { color:#1e7a3a; } .row.e .st { color:#7d8183; } .row.p .st { color:#a06a00; }
.score { width:74px; height:52px; font-size:26px; font-weight:800; text-align:center; border:2px solid #c4c9d2;
         border-radius:8px; }
.score:focus { border-color:#002b92; outline:none; }
.score.saving { border-color:#f0a500; } .score.saved { border-color:#2ea84e; } .score.err { border-color:#d9363e; }
.tie { background:#fff8e1; border:1px solid #e0c46c; border-radius:10px; padding:10px; margin:6px 0 12px; }
.tie b { color:#8a5a00; }
.tie p { margin:0 0 8px; font-size:13px; }
.tie .opts { display:flex; flex-direction:column; gap:6px; }
.tie button { border:none; border-radius:8px; padding:12px; font-size:15px; font-weight:700; background:#002b92; color:#fff; }
.tie select { font-size:16px; padding:6px; }
.tie .line { display:flex; align-items:center; gap:8px; }
h2 { font-size:15px; margin:18px 2px 8px; color:#002b92; }
.places .row { border-left-color:#002b92; }
.places .tgt { color:#f90a72; }
.msg { position:fixed; left:10px; right:10px; bottom:10px; padding:12px; border-radius:8px; color:#fff;
       font-weight:600; display:none; z-index:10; }
.msg.err { background:#b3261e; } .msg.ok { background:#1e7a3a; }
.empty { text-align:center; color:#7d8183; padding:30px 10px; }
.ro { background:#fff3cd; color:#8a5a00; padding:8px 12px; font-size:13px; }
</style>
</head>
<body>
<header>
    <h1><a class="back" id="back" href="<?= tdm_h($TDM_ROOT . 'bso.php') ?>">← Big Shoot Off</a> <span>Saisie BSO</span></h1>
    <div class="comp"><?= tdm_h(tdm_tournament()->ToName ?? '') ?></div>
</header>
<?php if (!$canWrite): ?><div class="ro">Lecture seule : vous n'avez pas le droit de saisir.</div><?php endif; ?>
<?php if (!$events): ?>
    <main><div class="empty">Aucun BSO confirmé.<br>Confirmez d'abord les équipes dans <b>Big Shoot Off</b>.</div></main>
<?php else: ?>
<div class="bar" id="evbar">
    <?php foreach ($events as $e): ?>
        <button type="button" data-ev="<?= tdm_h($e['event']) ?>"><?= tdm_h($e['name']) ?></button>
    <?php endforeach; ?>
</div>
<div class="bar rounds" id="rdbar"></div>
<main id="main"></main>
<?php endif; ?>
<div class="msg" id="msg"></div>
<script>
(function () {
    var URL = <?= json_encode($TDM_ROOT . 'bso-action.php') ?>;
    var TOKEN = <?= json_encode(tdm_token()) ?>;
    var CAN_WRITE = <?= $canWrite ? 'true' : 'false' ?>;
    var ev = <?= json_encode($first) ?>;
    var round = 0;          // volée affichée ; 0 = suivre la volée en cours
    var state = null;
    var busy = 0;           // requêtes d'écriture en cours
    if (!ev) return;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function msg(text, ok) {
        var m = document.getElementById('msg');
        m.textContent = text; m.className = 'msg ' + (ok ? 'ok' : 'err'); m.style.display = 'block';
        clearTimeout(msg.t); msg.t = setTimeout(function () { m.style.display = 'none'; }, ok ? 1500 : 4000);
    }
    function post(data) {
        var body = new URLSearchParams();
        body.append('tdm_token', TOKEN);
        Object.keys(data).forEach(function (k) {
            if (data[k] && typeof data[k] === 'object') {
                Object.keys(data[k]).forEach(function (kk) { body.append(k + '[' + kk + ']', data[k][kk]); });
            } else body.append(k, data[k]);
        });
        busy++;
        return fetch(URL, {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .finally(function () { busy--; });
    }
    // Retour à la page Big Shoot Off, sur la catégorie en cours de saisie.
    function backLink() {
        var a = document.getElementById('back');
        if (a) a.href = <?= json_encode($TDM_ROOT . 'bso.php') ?> + (ev ? '#ev-' + encodeURIComponent(ev) : '');
    }
    backLink();
    function load() {
        return fetch(URL + '?act=state&ev=' + encodeURIComponent(ev), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { msg(d.msg || 'Erreur', false); return; }
                if (state && state.stamp === d.state.stamp && state.event === d.state.event) return;
                state = d.state; render();
            }).catch(function () {});
    }

    function statusText(row, rd, pending) {
        if (pending) return 'Égalité à trancher';
        if (row.status === 'q') return rd.qualifiers === 1 ? 'Vainqueur' : 'Qualifiée';
        if (row.status === 'e') return 'Éliminée — ' + row.rank + 'e';
        return '';
    }

    function render() {
        // Barres catégorie et volées
        document.querySelectorAll('#evbar button').forEach(function (b) { b.classList.toggle('on', b.dataset.ev === ev); });
        var shown = round && state.rounds.some(function (r) { return r.round === round; }) ? round : state.current;
        var rb = '';
        state.rounds.forEach(function (r) {
            rb += '<button type="button" data-r="' + r.round + '" class="' + (round !== -1 && r.round === shown ? 'on' : '') + '">'
                + (r.qualifiers === 1 ? 'Finale' : 'Volée ' + r.round) + '</button>';
        });
        if (state.places.length) rb += '<button type="button" data-r="-1" class="' + (round === -1 ? 'on' : '') + '">Classement</button>';
        document.getElementById('rdbar').innerHTML = rb;

        var h = '';
        if (round === -1) {
            h += '<div class="places">';
            state.places.forEach(function (p) {
                h += '<div class="row"><div class="tgt">' + p.place + '<small>place</small></div><div class="name">' + esc(p.name) + '</div></div>';
            });
            h += state.finished ? '' : '<div class="info">Classement partiel : le BSO n\'est pas terminé.</div>';
            h += '</div>';
        } else {
            var rd = state.rounds.filter(function (r) { return r.round === shown; })[0];
            if (!rd) { document.getElementById('main').innerHTML = '<div class="empty">Pas encore de volée.</div>'; return; }
            var pendingKeys = {};
            rd.pending.forEach(function (g) { g.forEach(function (k) { pendingKeys[k] = true; }); });
            h += '<div class="info"><b>' + (rd.qualifiers === 1 ? 'Finale' : 'Volée ' + rd.round) + '</b> — '
                + esc(rd.label) + ' · 2 flèches, 40 s' + (state.finished ? ' · <b>BSO terminé</b>' : '') + '</div>';
            rd.byTarget.forEach(function (row) {
                var cls = pendingKeys[row.key] ? 'p' : (row.status || '');
                h += '<div class="row ' + cls + '"><div class="tgt">' + esc(row.target) + '<small>cible</small></div>'
                    + '<div class="name">' + esc(row.name) + '<span class="st">' + statusText(row, rd, pendingKeys[row.key]) + '</span></div>'
                    + '<input class="score" type="number" inputmode="numeric" min="0" max="20" data-key="' + esc(row.key) + '"'
                    + ' data-r="' + rd.round + '" value="' + (row.score == null ? '' : row.score) + '"' + (CAN_WRITE ? '' : ' disabled') + '></div>';
            });
            rd.pending.forEach(function (g) {
                var names = {}, score = null;
                rd.rows.forEach(function (row) { if (g.indexOf(row.key) >= 0) { names[row.key] = row.name; score = row.score; } });
                h += '<div class="tie" data-r="' + rd.round + '" data-keys="' + esc(g.join(',')) + '"><p><b>Égalité à ' + score + '</b> : '
                    + 'qui est devant ? Flèche la plus près du centre, sinon barrage.</p>';
                if (!CAN_WRITE) { h += '</div>'; return; }
                h += '<div class="opts">';
                if (g.length === 2) {
                    g.forEach(function (k) { h += '<button type="button" data-first="' + esc(k) + '">' + esc(names[k]) + ' devant</button>'; });
                } else {
                    g.forEach(function (k) {
                        h += '<div class="line"><select data-key="' + esc(k) + '"><option value=""></option>';
                        for (var i = 1; i <= g.length; i++) h += '<option value="' + i + '">' + i + '</option>';
                        h += '</select> ' + esc(names[k]) + '</div>';
                    });
                    h += '<button type="button" data-order="1">Valider l\'ordre</button>';
                }
                h += '</div></div>';
            });
        }
        document.getElementById('main').innerHTML = h;
    }

    // ── Interactions ─────────────────────────────────────────────────────────
    document.getElementById('evbar').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        ev = b.dataset.ev; round = 0; state = null; backLink();
        history.replaceState(null, '', '?ev=' + encodeURIComponent(ev));
        load();
    });
    document.getElementById('rdbar').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        var r = parseInt(b.dataset.r, 10);
        round = (r === state.current) ? 0 : r;
        render();
    });
    document.getElementById('main').addEventListener('change', function (e) {
        var inp = e.target;
        if (!inp.classList.contains('score')) return;
        var v = inp.value.trim();
        if (v !== '' && (!/^\d+$/.test(v) || +v > 20)) { inp.classList.add('err'); msg('Score de 0 à 20.', false); return; }
        inp.classList.remove('err', 'saved'); inp.classList.add('saving');
        post({act: 'score', ev: ev, round: inp.dataset.r, key: inp.dataset.key, score: v}).then(function (d) {
            inp.classList.remove('saving');
            if (d.error) { inp.classList.add('err'); msg(d.msg, false); return; }
            inp.classList.add('saved');
            state = d.state;
            // Ne pas redessiner sous les doigts : seulement si on a quitté la case.
            setTimeout(function () { if (!document.activeElement || !document.activeElement.classList.contains('score')) render(); }, 300);
        }).catch(function () { inp.classList.remove('saving'); inp.classList.add('err'); msg('Réseau indisponible : score non enregistré.', false); });
    });
    // Entrée passe à la cible suivante (le changement part avec le blur).
    document.getElementById('main').addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.classList.contains('score')) return;
        e.preventDefault();
        var all = Array.prototype.slice.call(document.querySelectorAll('.score'));
        var next = all[all.indexOf(e.target) + 1];
        if (next) next.focus(); else e.target.blur();
    });
    document.getElementById('main').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        var box = b.closest('.tie'); if (!box) return;
        var keys = box.dataset.keys.split(','), order = {};
        if (b.dataset.first) {
            keys.forEach(function (k) { order[k] = (k === b.dataset.first) ? 1 : 2; });
        } else {
            box.querySelectorAll('select').forEach(function (s) { order[s.dataset.key] = s.value; });
        }
        post({act: 'order', ev: ev, round: box.dataset.r, order: order}).then(function (d) {
            if (d.error) { msg(d.msg, false); return; }
            msg('Départage enregistré.', true);
            state = d.state; render();
        }).catch(function () { msg('Réseau indisponible.', false); });
    });
    // Fin de saisie dans une case : on peut redessiner.
    document.getElementById('main').addEventListener('focusout', function () {
        setTimeout(function () {
            var a = document.activeElement;
            if (state && !busy && !(a && a.classList.contains('score'))) render();
        }, 400);
    });

    load();
    setInterval(function () {
        var a = document.activeElement;
        if (busy || (a && (a.classList.contains('score') || a.tagName === 'SELECT'))) return;
        load();
    }, 3000);
})();
</script>
</body>
</html>
