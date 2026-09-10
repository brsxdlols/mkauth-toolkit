(function () {
  'use strict';
  if (window.mkaMapShortcutsInstalled) return;
  window.mkaMapShortcutsInstalled = true;
  function install() {
    var links = document.querySelectorAll('a'), anchor = null;
    for (var i = 0; i < links.length; i++) {
      if ((links[i].textContent || '').replace(/\s+/g, ' ').trim().toLowerCase() === 'mapa global') { anchor = links[i]; break; }
    }
    var parent = anchor ? anchor.parentNode : document.getElementById('menu_clientes');
    if (!parent) return;
    var entries = [
      ['mka-mapa-clientes-menu', '/admin/addons/mapa-clientes/', 'Mapa de clientes', 'bi bi-map'],
      ['mka-trafego-cliente-menu', '/admin/addons/mapa-clientes/?monitor=1', 'Tr\u00e1fego de clientes', 'bi bi-graph-up']
    ];
    entries.forEach(function (entry) {
      var item = document.getElementById(entry[0]);
      if (!item) {
        item = document.createElement('a'); item.id = entry[0];
        item.href = entry[1]; item.className = anchor ? anchor.className : 'navbar-item';
        var icon = document.createElement('i'); icon.className = entry[3];
        item.appendChild(icon); item.appendChild(document.createTextNode('\u00a0 ' + entry[2]));
        if (anchor) parent.insertBefore(item, anchor.nextSibling); else parent.appendChild(item);
      }
      item.style.fontWeight = '700';
      anchor = item;
    });
  }
  var queued = false;
  function schedule() {
    if (queued) return;
    queued = true;
    setTimeout(function () { queued = false; install(); }, 100);
  }
  install();
  document.addEventListener('DOMContentLoaded', schedule);
  var root = document.querySelector('.navbar') || document.body;
  if (root) new MutationObserver(schedule).observe(root, {childList:true, subtree:true});
})();
