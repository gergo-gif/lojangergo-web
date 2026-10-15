// A u2vibe.com következő estjeinek betöltése (/api/u2vibe-estek.php).
(function () {
  var egy = document.querySelector('[data-u2vibe="kovetkezo"]');
  var lista = document.querySelector('[data-u2vibe="lista"]');
  if (!egy && !lista) return;
  function esc(s) { return String(s || '').replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function hely(e) { return [e.helyszin, e.cim].filter(Boolean).join(', '); }
  function meta(e) { return [e.ido, hely(e), e.ar ? e.ar + ' / fő' : ''].filter(Boolean).join(' · '); }
  fetch('/api/u2vibe-estek.php').then(function (r) { return r.json(); }).then(function (d) {
    var es = (d && d.estek) || [];
    if (!es.length) return;
    if (egy) {
      var e = es[0];
      egy.querySelector('[data-f="datum"]').innerHTML = esc(e.datum) + '<br><span style="font-size: 18px; font-weight: 600">' + esc(e.nap) + '</span>';
      egy.querySelector('[data-f="nev"]').textContent = e.nev;
      egy.querySelector('[data-f="meta"]').textContent = meta(e);
      egy.querySelector('[data-f="link"]').href = e.url;
    }
    if (lista) {
      var tpl = lista.querySelector('[data-f="sor"]');
      var out = '';
      es.slice(0, 4).forEach(function (e) {
        out += '<div class="row" style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px 32px; padding: 24px 0; border-bottom: 1px solid #C9B9A0">'
          + '<div style="flex: 0 0 140px; font-family: \'Big Shoulders Display\', sans-serif; font-weight: 800; font-size: 28px; line-height: 1; text-transform: uppercase">' + esc(e.datum) + '<br><span style="font-size: 18px; font-weight: 600">' + esc(e.nap) + '</span></div>'
          + '<div style="flex: 999 1 320px; min-width: 0"><p style="margin: 0; font-size: 20px; font-weight: 600">' + esc(e.nev) + '</p>'
          + '<p style="margin: 0; font-size: 15px; color: #5A4C42">' + esc(meta(e)) + '</p></div>'
          + '<a href="' + esc(e.url) + '" class="btn" style="flex: 0 0 auto; background: #2A211C; color: #F5EDE0; text-decoration: none; font-weight: 600; padding: 12px 20px" target="_blank" rel="noopener">' + (e.elfogyott ? 'Betelt' : 'Jegyek →') + '</a></div>';
      });
      if (tpl) tpl.remove();
      lista.insertAdjacentHTML('beforeend', out);
    }
  }).catch(function () {});
})();
