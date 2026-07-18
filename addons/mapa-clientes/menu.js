(function () {
  'use strict';
  var id = 'mka-mapa-clientes-menu';
  function clean(value) { return (value || '').replace(/\s+/g, ' ').trim().toUpperCase(); }
  function installShortcut() {
    if (document.getElementById(id)) return true;
    var nodes = document.querySelectorAll('a');
    for (var i = 0; i < nodes.length; i++) {
      if (clean(nodes[i].textContent) !== 'MAPA GLOBAL') continue;
      var href = '/admin/addons/mapa-clientes/maps.hhvm';
      var item = document.createElement('a');
      item.id = id;
      item.href = href;
      item.className = nodes[i].className;
      item.innerHTML = '<i class="bi bi-map"></i>&nbsp; Mapa de clientes';
      nodes[i].parentNode.insertBefore(item, nodes[i].nextSibling);
      return true;
    }
    return false;
  }
  if (!installShortcut()) {
    document.addEventListener('DOMContentLoaded', installShortcut);
    var attempts = 0, timer = setInterval(function () { if (installShortcut() || ++attempts > 20) clearInterval(timer); }, 500);
  }
})();
