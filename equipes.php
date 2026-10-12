<?php
/**
 * equipes.php — TDM : composition des équipes par glisser-déposer
 * (configuration, étape 3).
 *
 * Une carte par club : à gauche les archers non placés, rangés par catégorie
 * d'après leur division ; à droite les équipes, une case femme et une case
 * homme. Déposer un archer dans une case l'engage en équipe mixte avec le
 * numéro de l'équipe (un classique déposé en NEO passe en NE, et inversement) ;
 * le remettre dans « non placés » le retire. Chaque geste est enregistré tout
 * de suite (equipes-action.php). Au doigt : toucher l'archer puis la case.
 */
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/setup.php';
tdm_require(AclParticipants, AclReadOnly);

$canWrite = hasFullACL(AclParticipants, '', AclReadWrite) && !IsBlocked(BIT_BLOCK_PARTICIPANT);
$cats = [];
foreach (TDM_CATEGORIES as $code => $c) $cats[$code] = ['div' => $c['div'], 'name' => $c['name']];

$PAGE_TITLE = 'TDM - Composition des équipes';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
echo tdm_styles();
?>
<style>
#tdm { max-width:1300px; }
#tdm .counts { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 12px; }
#tdm .counts div { border:1px solid var(--bord); border-radius:6px; padding:6px 12px; text-align:center; min-width:80px; background:#fff; }
#tdm .counts b { display:block; font-size:20px; color:var(--bleu); }
#tdm .counts b.bad { color:var(--orange); } #tdm .counts b.good { color:var(--vert); }
#tdm .tools { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
#tdm .tools input[type=search] { padding:5px 8px; border:1px solid var(--bord); border-radius:4px; min-width:220px; }
#tdm button.ghost { background:#fff; border:1px solid var(--bleu); color:var(--bleu); border-radius:6px; padding:4px 10px;
                    cursor:pointer; font-weight:600; font-size:12px; }
#tdm button.ghost:hover { background:var(--bleu-clair); }
#tdm .card > h3 button.ghost { margin-left:auto; border-color:#fff; }
#tdm .card > h3 small { font-weight:normal; opacity:.8; }
#tdm .tchip { font-size:11px; padding:2px 8px; border-radius:10px; background:#eafaf0; color:var(--vert); font-weight:600; }
#tdm .tchip.bad { background:#fff8e1; color:var(--orange); }
#tdm .cols { display:grid; grid-template-columns:minmax(260px, 1fr) 2fr; gap:16px; }
@media (max-width:800px) { #tdm .cols { grid-template-columns:1fr; } }
#tdm .zone h4 { margin:0 0 6px; font-size:12px; color:#7d8183; text-transform:uppercase; letter-spacing:.04em; }
#tdm .free { min-height:60px; border:2px dashed var(--bord); border-radius:6px; padding:8px; }
#tdm .free.over { border-color:var(--bleu); background:var(--bleu-clair); }
#tdm .grp { margin-bottom:6px; }
#tdm .grp > span { display:inline-block; min-width:40px; font-size:11px; font-weight:700; color:var(--bleu); }
#tdm .a { display:inline-flex; align-items:center; gap:5px; margin:2px; padding:3px 9px; border-radius:14px;
          background:#fff; border:1px solid var(--bord); cursor:grab; user-select:none; font-size:12px; }
#tdm .a.f { border-left:4px solid var(--rose); } #tdm .a.h { border-left:4px solid var(--bleu); }
#tdm .a small { color:#7d8183; }
#tdm .a.sel { outline:3px solid var(--rose); }
#tdm .a.dragging { opacity:.4; }
#tdm .a.ro { cursor:default; }
#tdm .a.x { border-color:var(--rouge); background:#fdecea; cursor:not-allowed; }
#tdm .a.out { opacity:.5; cursor:not-allowed; }
#tdm .warnline { color:var(--rouge); font-size:11px; font-weight:600; }
#tdm .team { display:flex; align-items:center; gap:8px; padding:5px 0; border-bottom:1px solid #eceeef; flex-wrap:wrap; }
#tdm .team .lbl { min-width:110px; font-weight:700; color:var(--bleu-fonce); }
#tdm .slot { flex:1 1 180px; min-height:30px; border:2px dashed var(--bord); border-radius:16px; padding:1px 4px;
             display:flex; align-items:center; flex-wrap:wrap; }
#tdm .slot .ph { color:#aab; font-size:12px; padding:0 8px; }
#tdm .slot.can { border-color:var(--vert); background:#f3fbf6; }
#tdm .slot.over { border-color:var(--vert); background:#dff5e7; }
#tdm .slot.full { border-style:solid; }
#tdm .team .st { font-size:16px; min-width:20px; }
#tdm .adds { margin-top:8px; display:flex; gap:6px; flex-wrap:wrap; }
#tdm .issues { margin:8px 0 0; color:var(--orange); font-size:12px; padding-left:18px; }
#tdm #toast { position:fixed; left:50%; bottom:20px; transform:translateX(-50%); padding:10px 18px; border-radius:6px;
              color:#fff; font-weight:600; box-shadow:0 2px 8px rgba(0,0,0,.25); display:none; z-index:50; max-width:90vw; }
#tdm #toast.ok { background:var(--vert); } #tdm #toast.err { background:var(--rouge); }
#tdm #saved { font-size:12px; color:#7d8183; }
</style>
<div id="tdm">
    <?= tdm_header('equipes', 'Forme les équipes : une femme et un homme du même club. Tout est enregistré automatiquement.', [
        'Glissez chaque archer dans une équipe (au doigt : touchez l\'archer, puis la case). Les cases possibles s\'éclairent en vert.',
        'Ou <b>Composer automatiquement</b> les clubs qui ont exactement une femme et un homme non placés.',
        'Vérifiez les clubs à compléter, puis revenez à la Configuration (étape 4).',
    ], 'Un archer ne va que dans une équipe de sa division d\'inscription. Seule exception : arc classique ↔ NEO, après confirmation. '
        . 'Le remettre dans « Non placés » le retire de l\'équipe.', [$TDM_ROOT . 'preconfig.php#step4', 'Configuration']) ?>

    <div id="valid"></div>
    <?php if (!$canWrite): ?>
        <div class="msg warn">Lecture seule : il faut le droit d'écriture sur les participants (et que la liste ne soit pas verrouillée).</div>
    <?php endif; ?>

    <div class="counts" id="counts"></div>
    <div class="tools">
        <input type="search" id="q" placeholder="Filtrer : club, nom, licence…">
        <label><input type="checkbox" id="only"> Clubs à compléter seulement</label>
        <?php if ($canWrite): ?>
            <button type="button" class="ghost" id="autoAll" title="Dans chaque club et chaque catégorie où il y a exactement une femme et un homme non placés">Composer automatiquement tous les clubs</button>
        <?php endif; ?>
        <span id="saved"></span>
    </div>
    <div id="clubs"><p class="help">Chargement…</p></div>
    <div id="toast"></div>
</div>
<script>
(function () {
    var URL = <?= json_encode($TDM_ROOT . 'equipes-action.php') ?>;
    var TOKEN = <?= json_encode(tdm_token()) ?>;
    var CATS = <?= json_encode($cats, JSON_UNESCAPED_UNICODE) ?>;
    var SWAP = <?= json_encode(TDM_SWAP_DIVISIONS) ?>;
    var WRITE = <?= $canWrite ? 'true' : 'false' ?>;
    var S = null, busy = false, selected = null, dragId = null, pending = {}; // pending[club][cat] = [numéros d'équipes vides]
    var root = document.getElementById('clubs');

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function toast(msg, ok) {
        var t = document.getElementById('toast'); if (!msg) return;
        t.textContent = msg; t.className = ok ? 'ok' : 'err'; t.style.display = 'block';
        clearTimeout(toast.h); toast.h = setTimeout(function () { t.style.display = 'none'; }, ok ? 2500 : 5000);
    }

    // ── Rendu ────────────────────────────────────────────────────────────────
    function chip(id, kind) {
        var a = S.archers[id], f = a.sex === 1, cls = 'a ' + (f ? 'f' : 'h');
        var drag = WRITE && kind !== 'other' && kind !== 'out';
        if (kind === 'other') cls += ' x'; else if (kind === 'out') cls += ' out'; else if (!WRITE) cls += ' ro';
        return '<span class="' + cls + '" data-id="' + id + '"' + (drag ? ' draggable="true"' : '') + ' title="' + esc(a.code) + '">'
            + (f ? '♀' : '♂') + ' ' + esc(a.name) + ' <small>' + esc(a.cls) + (kind === 'team' ? '' : ' · ' + esc(a.div)) + '</small></span>';
    }
    function slot(club, cat, sub, sex, ids) {
        var h = '<div class="slot' + (ids.length ? ' full' : '') + '" data-club="' + club + '" data-cat="' + cat
            + '" data-sub="' + sub + '" data-sex="' + sex + '">';
        ids.forEach(function (id) { h += chip(id, 'team'); });
        if (!ids.length) h += '<span class="ph">' + (sex ? '♀ femme' : '♂ homme') + '</span>';
        return h + '</div>';
    }
    function card(c) {
        var h = '<div class="card" data-club="' + c.id + '" data-todo="' + (c.issues.length || hasFree(c) ? 1 : 0) + '"><h3>'
            + esc(c.name) + ' <small>' + esc(c.code) + '</small>';
        Object.keys(CATS).forEach(function (cat) {
            (c.teams[cat] || []).forEach(function (t) {
                h += '<span class="tchip' + (t.ok ? '' : ' bad') + '">' + cat + ' ' + t.sub + (t.ok ? ' ✓' : ' ⚠') + '</span>';
            });
        });
        if (WRITE) h += '<button type="button" class="ghost" data-auto="' + c.id + '">Composer automatiquement</button>';
        h += '</h3><div><div class="cols"><div class="zone"><h4>Non placés</h4><div class="free" data-club="' + c.id + '">';
        var any = false;
        Object.keys(CATS).forEach(function (cat) {
            var ids = (c.free[cat] || []);
            if (!ids.length) return;
            any = true;
            h += '<div class="grp"><span>' + cat + '</span>' + ids.map(function (id) { return chip(id, 'free'); }).join('') + '</div>';
        });
        if (!any) h += '<span class="help">Tous les archers sont placés.</span>';
        h += '</div>';
        if (c.other.length) {
            h += '<div class="warnline" style="margin-top:8px">⚠ Division non reconnue pour le TDM (seules CL, CO, BB et NE le sont) :</div>'
               + c.other.map(function (id) { return chip(id, 'other'); }).join('');
        }
        if (c.out.length) {
            h += '<div class="help" style="margin-top:8px">Ne tirent pas (statut) :</div>' + c.out.map(function (id) { return chip(id, 'out'); }).join('');
        }
        h += '</div><div class="zone"><h4>Équipes</h4>';
        Object.keys(CATS).forEach(function (cat) {
            var teams = (c.teams[cat] || []).slice(), subs = teams.map(function (t) { return t.sub; });
            ((pending[c.id] || {})[cat] || []).forEach(function (s) {
                if (subs.indexOf(s) < 0) teams.push({sub: s, women: [], men: [], ok: false, empty: true});
            });
            teams.sort(function (x, y) { return x.sub - y.sub; });
            teams.forEach(function (t) {
                h += '<div class="team"><span class="lbl">' + cat + ' · équipe ' + t.sub + '</span>'
                   + slot(c.id, cat, t.sub, 1, t.women) + slot(c.id, cat, t.sub, 0, t.men)
                   + '<span class="st">' + (t.ok ? '✅' : (t.empty ? '' : '⚠️')) + '</span></div>';
            });
        });
        if (WRITE) {
            h += '<div class="adds">';
            Object.keys(CATS).forEach(function (cat) {
                h += '<button type="button" class="ghost" data-add="' + cat + '" data-club="' + c.id + '" title="' + esc(CATS[cat].name) + '">+ ' + cat + '</button>';
            });
            h += '</div>';
        }
        if (c.issues.length) h += '<ul class="issues">' + c.issues.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
        return h + '</div></div></div></div>';
    }
    function hasFree(c) { return Object.keys(c.free).some(function (k) { return c.free[k].length; }) || c.other.length; }
    function render() {
        var h = '';
        Object.keys(CATS).forEach(function (cat) { h += '<div><b>' + S.counts[cat] + '</b>' + cat + '</div>'; });
        h += '<div><b class="' + (S.issues ? 'bad' : 'good') + '">' + S.issues + '</b>anomalie(s)</div>';
        document.getElementById('counts').innerHTML = h;
        document.getElementById('valid').innerHTML = S.validated.length
            ? '<div class="msg warn"><b>Poules déjà validées</b> pour ' + esc(S.validated.join(', '))
              + ' : changer une équipe maintenant modifie les équipes IANSEO, il faudrait refaire la validation qualif → poules (ce qui efface les matchs).</div>' : '';
        root.innerHTML = S.clubs.length ? S.clubs.map(card).join('')
            : '<div class="msg warn">Aucun archer dans la compétition : saisissez-les ou importez-les d\'abord.</div>';
        filter();
    }
    function filter() {
        var s = document.getElementById('q').value.trim().toLowerCase(), only = document.getElementById('only').checked;
        root.querySelectorAll('.card').forEach(function (c) {
            c.style.display = (!only || c.dataset.todo === '1') && (!s || c.textContent.toLowerCase().indexOf(s) >= 0) ? '' : 'none';
        });
    }

    // ── Échanges avec le serveur ─────────────────────────────────────────────
    function load() {
        fetch(URL + '?act=state', {credentials: 'same-origin'}).then(function (r) { return r.json(); })
            .then(function (d) { if (d.state) { S = d.state; render(); } else toast(d.error || 'Erreur de chargement'); })
            .catch(function () { toast('Erreur de chargement'); });
    }
    function post(data) {
        if (busy) return;
        busy = true;
        document.getElementById('saved').textContent = 'Enregistrement…';
        var body = new URLSearchParams(data); body.append('tdm_token', TOKEN);
        fetch(URL, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) { return r.json(); })
            .then(function (d) {
                busy = false;
                if (d.state) { S = d.state; render(); }
                if (d.error) { toast(d.error, false); document.getElementById('saved').textContent = ''; }
                else { toast(d.msg, true); document.getElementById('saved').textContent = 'Enregistré ✓ ' + new Date().toLocaleTimeString(); }
            })
            .catch(function () { busy = false; toast('Erreur réseau : rien n\'a été enregistré.', false); load(); });
    }

    // ── Gestes ───────────────────────────────────────────────────────────────
    function clubOf(id) {
        for (var i = 0; i < S.clubs.length; i++) {
            var c = S.clubs[i], all = [];
            Object.keys(c.free).forEach(function (k) { all = all.concat(c.free[k]); });
            Object.keys(c.teams).forEach(function (k) { c.teams[k].forEach(function (t) { all = all.concat(t.women, t.men); }); });
            if (all.indexOf(+id) >= 0) return c.id;
        }
        return null;
    }
    function inTeam(id) { var a = document.querySelector('.slot .a[data-id="' + id + '"]'); return !!a; }
    // Raison pour laquelle l'archer ne peut pas aller dans cette case (vide = possible).
    function refuse(id, s) {
        var a = S.archers[id], div = CATS[s.dataset.cat].div;
        if (String(clubOf(id)) !== s.dataset.club) return 'Un archer ne peut aller que dans une équipe de son club.';
        if (a.sex !== +s.dataset.sex) return a.sex ? 'Case réservée à un homme.' : 'Case réservée à une femme.';
        if (a.div !== div && !(SWAP.indexOf(a.div) >= 0 && SWAP.indexOf(div) >= 0))
            return a.name + ' est en division ' + a.div + ' : pas d\'équipe ' + s.dataset.cat + '.';
        return '';
    }
    function place(id, s) {
        var why = refuse(id, s);
        if (why) { toast(why, false); return; }
        var cur = document.querySelector('.slot .a[data-id="' + id + '"]');
        if (cur && cur.parentNode === s) return;
        // Changement de division (classique ↔ néo-licencié) : on demande confirmation.
        var a = S.archers[id], div = CATS[s.dataset.cat].div;
        if (a.div !== div && !confirm(a.name + ' est inscrit(e) en division ' + a.div + '.\n\n'
            + 'Le / la placer dans une équipe ' + s.dataset.cat + ' change sa division en ' + div
            + (div === 'NE' ? ' (néo-licencié).' : '.') + '\n\nConfirmer ?')) return;
        post({act: 'place', id: id, cat: s.dataset.cat, sub: s.dataset.sub});
    }
    function unplace(id) { if (inTeam(id)) post({act: 'remove', id: id}); }
    function mark(id) {
        root.querySelectorAll('.slot').forEach(function (s) { s.classList.toggle('can', id !== null && !refuse(id, s)); });
    }
    function select(el) {
        root.querySelectorAll('.a.sel').forEach(function (x) { x.classList.remove('sel'); });
        selected = el ? el.dataset.id : null;
        if (el) el.classList.add('sel');
        mark(selected);
    }

    root.addEventListener('dragstart', function (e) {
        var a = e.target.closest('.a[draggable]'); if (!a) return;
        e.dataTransfer.setData('text/plain', a.dataset.id); e.dataTransfer.effectAllowed = 'move';
        dragId = a.dataset.id;
        a.classList.add('dragging'); select(null); mark(a.dataset.id);
    });
    root.addEventListener('dragend', function (e) {
        var a = e.target.closest('.a'); if (a) a.classList.remove('dragging'); mark(null); dragId = null;
        root.querySelectorAll('.over').forEach(function (x) { x.classList.remove('over'); });
    });
    root.addEventListener('dragover', function (e) {
        var z = e.target.closest('.slot, .free'); if (!z || !WRITE) return;
        // Case interdite (autre division, autre club, autre sexe) : pas de dépôt possible (curseur « interdit »).
        if (dragId && z.classList.contains('slot') && refuse(dragId, z)) return;
        if (dragId && z.classList.contains('free') && z.dataset.club !== String(clubOf(dragId))) return;
        e.preventDefault(); z.classList.add('over');
    });
    root.addEventListener('dragleave', function (e) {
        var z = e.target.closest('.slot, .free'); if (z && !z.contains(e.relatedTarget)) z.classList.remove('over');
    });
    root.addEventListener('drop', function (e) {
        var z = e.target.closest('.slot, .free'); if (!z) return;
        e.preventDefault(); z.classList.remove('over');
        var id = e.dataTransfer.getData('text/plain'); if (!id) return;
        if (z.classList.contains('slot')) place(id, z); else unplace(id);
    });
    // Toucher / cliquer : archer puis case.
    root.addEventListener('click', function (e) {
        var add = e.target.closest('[data-add]');
        if (add) {
            var club = add.dataset.club, cat = add.dataset.add, c = S.clubs.filter(function (x) { return String(x.id) === club; })[0];
            var used = (c.teams[cat] || []).map(function (t) { return t.sub; }).concat((pending[club] || {})[cat] || []);
            var n = 1; while (used.indexOf(n) >= 0) n++;
            pending[club] = pending[club] || {}; (pending[club][cat] = pending[club][cat] || []).push(n);
            render(); return;
        }
        var auto = e.target.closest('[data-auto]');
        if (auto) { post({act: 'auto', club: auto.dataset.auto}); return; }
        if (!WRITE) return;
        var a = e.target.closest('.a[draggable]');
        if (a && selected && selected !== a.dataset.id) {
            // Archer déjà sélectionné : toucher un archer d'une case ou des non placés vaut dépôt.
            var zs = a.closest('.slot'), zf = a.closest('.free'), sid = selected;
            if (zs) { select(null); place(sid, zs); e.stopPropagation(); return; }
            if (zf && inTeam(sid)) { select(null); unplace(sid); e.stopPropagation(); return; }
        }
        if (a) {
            if (selected === a.dataset.id) select(null); else select(a);
            e.stopPropagation(); return;
        }
        if (!selected) return;
        var s = e.target.closest('.slot'), f = e.target.closest('.free'), id = selected;
        if (s) { select(null); place(id, s); }
        else if (f) { select(null); unplace(id); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') select(null); });
    var all = document.getElementById('autoAll');
    if (all) all.addEventListener('click', function () { post({act: 'auto', club: 0}); });
    document.getElementById('q').addEventListener('input', filter);
    document.getElementById('only').addEventListener('change', filter);
    load();
})();
</script>
<?php
include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
