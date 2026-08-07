# NexA AI frontend

This directory is the browser application. The canonical runtime is:

- `index.html` - chat shell and app markup
- `nexa-app.js` - application behavior
- `nexa-main.css` - application styles
- `sw.js` and `manifest.json` - PWA behavior

Admin pages use `admin.html`, `admin-engine.js`, `admin-tabs.js`, `admin-style.css`, and `admin-tabs.css`. Login and legal pages are standalone route documents.

Use root-relative API calls such as `/api/auth-check.php`; the frontend does not know the repository's backend directory layout. Do not place credentials, databases, logs, or private API responses in this directory.