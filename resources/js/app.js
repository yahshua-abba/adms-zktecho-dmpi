import './bootstrap';

/**
 * DM Sans, self-hosted (was Nunito). Originally an @import from fonts.bunny.net,
 * which meant the dashboard fell back to a system font (and the icon font
 * disappeared entirely) on a device LAN with no internet access.
 *
 * Latin subset only — the unqualified entrypoints also ship cyrillic, cyrillic-ext
 * and vietnamese, which is ~18 extra font files this UI never renders.
 */
import '@fontsource/dm-sans/latin-400.css';
import '@fontsource/dm-sans/latin-600.css';
import '@fontsource/dm-sans/latin-700.css';

import 'bootstrap-icons/font/bootstrap-icons.css';
import 'datatables.net-bs5/css/dataTables.bootstrap5.css';
import 'tom-select/dist/css/tom-select.bootstrap5.css';

import $ from 'jquery';
import 'datatables.net-bs5';
import TomSelect from 'tom-select';

window.TomSelect = TomSelect;

/**
 * The DataTables initialisers stay inline in the Blade views because they
 * interpolate route URLs and column definitions, so jQuery has to be reachable
 * from a plain <script>. Those inline blocks must wait for DOMContentLoaded:
 * the Vite directive emits this bundle as a deferred module, so it executes
 * *after* any inline script is parsed but *before* DOMContentLoaded fires.
 */
window.$ = window.jQuery = $;

/**
 * Desktop sidebar collapse.
 *
 * The state lives as `.sidebar-collapsed` on <html>; the head already restored it
 * from localStorage before first paint, so all this does is flip it and remember.
 * Below lg the button is hidden and the class has no effect — there the sidebar is
 * Bootstrap's offcanvas drawer.
 */
document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('sidebarCollapseToggle');

    if (! toggle) {
        return;
    }

    const sync = () => {
        const collapsed = document.documentElement.classList.contains('sidebar-collapsed');
        const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';

        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('title', label);
    };

    toggle.addEventListener('click', () => {
        const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');

        try {
            localStorage.setItem('adms.sidebarCollapsed', collapsed ? '1' : '0');
        } catch (e) { /* storage disabled — the toggle still works for this page */ }

        sync();

        // DataTables sizes its columns from the container width and only
        // recalculates on a resize event, which a CSS width change doesn't fire.
        // Wait out the transition, then tell it the width moved.
        window.setTimeout(() => window.dispatchEvent(new Event('resize')), 200);
    });

    sync();
});
