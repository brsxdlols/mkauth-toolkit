(function () {
  'use strict';
  var id = 'mka-mapa-clientes-menu';
  function clean(value) { return (value || '').replace(/\s+/g, ' ').trim().toUpperCase(); }
  function installShortcut() {
    if (document.getElementById(id)) return true;
    var nodes = document.querySelectorAll('a,button,span');
    for (var i = 0; i < nodes.length; i++) {
      if (clean(nodes[i].textContent) !== 'CLIENTES') continue;
      var owner = nodes[i].closest('.navbar-item.has-dropdown,li.dropdown,li') || nodes[i].parentElement;
      if (!owner) continue;
      var menu = owner.querySelector('.navbar-dropdown,.dropdown-menu,ul');
      if (!menu) continue;
      var href = '/admin/addons/mapa-clientes/maps.hhvm';
      if (menu.matches('ul')) {
        var li = document.createElement('li');
        var link = document.createElement('a');
        link.id = id; link.href = href; link.textContent = 'Mapa de clientes';
        li.appendChild(link); menu.appendChild(li);
      } else {
        var item = document.createElement('a');
        item.id = id; item.href = href; item.className = 'navbar-item'; item.textContent = 'Mapa de clientes';
        menu.appendChild(item);
      }
      return true;
    }
    return false;
  }
  if (!installShortcut()) {
    document.addEventListener('DOMContentLoaded', installShortcut);
    var attempts = 0, timer = setInterval(function () { if (installShortcut() || ++attempts > 20) clearInterval(timer); }, 500);
  }
})();
