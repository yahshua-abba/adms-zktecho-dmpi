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
 * @vite emits this bundle as a deferred module, so it executes *after* any
 * inline script is parsed but *before* DOMContentLoaded fires.
 */
window.$ = window.jQuery = $;
