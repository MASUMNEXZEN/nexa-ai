(function () {
  'use strict';
  var paths = {
    circle: '<circle cx="12" cy="12" r="9"></circle>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"></path>',
    moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"></path>',
    plus: '<path d="M12 5v14M5 12h14"></path>',
    close: '<path d="M6 6l12 12M18 6 6 18"></path>',
    message: '<path d="M20 11.5a8 8 0 0 1-8 8H7l-4 2 1.4-4.1A8 8 0 1 1 20 11.5Z"></path><path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01"></path>',
    listChecks: '<path d="M9 6h11M9 12h11M9 18h11"></path><path d="m3 6 1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17"></path>',
    bookmark: '<path d="M6 4.5A2.5 2.5 0 0 1 8.5 2h7A2.5 2.5 0 0 1 18 4.5V22l-6-3.5L6 22Z"></path>',
    bookmarkCheck: '<path d="M6 4.5A2.5 2.5 0 0 1 8.5 2h7A2.5 2.5 0 0 1 18 4.5V22l-6-3.5L6 22Z"></path><path d="m9 10 2 2 4-4"></path>',
    search: '<circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path>',
    settings: '<path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"></path><circle cx="12" cy="12" r="4"></circle>',
    send: '<path d="m22 2-7 20-4-9-9-4Z"></path><path d="M22 2 11 13"></path>',
    image: '<rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="m21 15-5-5L5 21"></path>',
    mic: '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"></path><path d="M19 11v1a7 7 0 0 1-14 0v-1M12 19v3M8 22h8"></path>',
    square: '<rect x="5" y="5" width="14" height="14" rx="3"></rect>',
    arrowRight: '<path d="M5 12h14M13 6l6 6-6 6"></path>',
    arrowLeft: '<path d="M19 12H5M11 18l-6-6 6-6"></path>',
    calendar: '<rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path>',
    arrowUp: '<path d="M12 19V5M6 11l6-6 6 6"></path>',
    arrowDown: '<path d="M12 5v14M18 13l-6 6-6-6"></path>',
    chart: '<path d="M4 19V5M4 19h16"></path><path d="m7 15 3-4 3 2 5-7"></path>',
    wallet: '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H19a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6.5A2.5 2.5 0 0 1 4 17.5Z"></path><path d="M4 8h15M16 14h3"></path>',
    clipboard: '<rect x="6" y="4" width="12" height="17" rx="2"></rect><path d="M9 4V3h6v1M9 9h6M9 13h6M9 17h3"></path>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"></path>',
    megaphone: '<path d="m3 11 18-5v12L3 14Z"></path><path d="M11 15v6M7 16l-1 4"></path>',
    wrench: '<path d="M14.7 6.3a5 5 0 0 0-6.4 6.4L3 18l3 3 5.3-5.3a5 5 0 0 0 6.4-6.4L14 12l-2-2Z"></path>',
    brain: '<path d="M9.5 4.2A3.2 3.2 0 0 0 6 7.4a3.1 3.1 0 0 0-1 5.8A3.1 3.1 0 0 0 7.5 19a3.2 3.2 0 0 0 5.1 1.2A3.2 3.2 0 0 0 17.5 19a3.1 3.1 0 0 0 2.5-5.8 3.1 3.1 0 0 0-1-5.8 3.2 3.2 0 0 0-3.5-3.2 3.2 3.2 0 0 0-6 0Z"></path><path d="M12 5v14M8.5 8.5h3M12 12h3.5M8.5 15.5H12"></path>',
    zap: '<path d="m13 2-9 12h7l-1 8 9-12h-7Z"></path>',
    lock: '<rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"></rect><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"></path>',
    volume: '<path d="M4 10v4h4l5 4V6l-5 4Z"></path><path d="M17 9a5 5 0 0 1 0 6M19.5 6.5a9 9 0 0 1 0 11"></path>',
    alert: '<circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path>',
    target: '<circle cx="12" cy="12" r="9"></circle><circle cx="12" cy="12" r="5"></circle><circle cx="12" cy="12" r="1"></circle>',
    trophy: '<path d="M8 4h8v4a4 4 0 0 1-8 0Z"></path><path d="M8 6H4v2a4 4 0 0 0 4 4M16 6h4v2a4 4 0 0 1-4 4M12 12v5M8 21h8M9 17h6"></path>',
    medal: '<circle cx="12" cy="15" r="5"></circle><path d="m9 10 3-7 3 7M7 4l3 3M17 4l-3 3"></path>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"></path>',
    flag: '<path d="M5 21V4"></path><path d="M5 5c4-3 6 3 14 0v10c-8 3-10-3-14 0"></path>',
    check: '<path d="m5 12 4 4L19 6"></path>',
    crown: '<path d="m3 7 4 5 5-8 5 8 4-5-2 12H5Z"></path>',
    bookOpen: '<path d="M3 5a3 3 0 0 1 3-2h5v17H6a3 3 0 0 0-3 2Z"></path><path d="M21 5a3 3 0 0 0-3-2h-5v17h5a3 3 0 0 1 3 2Z"></path>',
    refresh: '<path d="M20 11a8 8 0 1 0 2 5"></path><path d="M20 5v6h-6"></path>',
    user: '<circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 21a7.5 7.5 0 0 1 15 0"></path>'
  };
  function escapeAttribute(value) { return String(value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
  function svg(name, label, className) {
    var content = paths[name] || paths.circle;
    var classes = 'nexa-icon' + (className ? ' ' + className : '');
    var accessible = label ? ' role="img" aria-label="' + escapeAttribute(label) + '"' : ' aria-hidden="true"';
    return '<svg class="' + classes + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"' + accessible + '>' + content + '</svg>';
  }
  function mount(root) {
    (root || document).querySelectorAll('[data-icon]').forEach(function (slot) {
      var name = slot.getAttribute('data-icon');
      if (paths[name]) slot.innerHTML = svg(name, '', slot.getAttribute('data-icon-class') || '');
    });
  }
  window.NexaIcons = Object.freeze({ svg: svg, mount: mount, names: Object.keys(paths) });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { mount(document); });
  else mount(document);
}());