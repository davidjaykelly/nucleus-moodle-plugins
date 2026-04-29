<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Library hooks for local_nucleuscommon.
 *
 * Owns the Nucleus status bar — a VS Code-style fixed bar at the
 * bottom of every Moodle page for federation-aware operators
 * (hub_admin, spoke_admin+). Hub and spoke plugins contribute
 * context widgets via their own `local_*_statusbar_widget()`
 * functions; this file renders the shell + aggregates them.
 *
 * @package    local_nucleuscommon
 * @copyright  2026 David Kelly <contact@davidkel.ly>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     David Kelly <contact@davidkel.ly>
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Render the Nucleus status bar into the page. We emit at body-top
 * via the `local_*_before_standard_top_of_body_html` old-style
 * callback — Moodle 5's footer hooks moved to the new
 * `\core\hook\output\*` class system, but the top-of-body callback
 * is still invoked directly. The bar uses `position: fixed; bottom: 0`
 * so DOM position doesn't matter.
 *
 * @return string
 */
function local_nucleuscommon_before_standard_top_of_body_html(): string {
    global $PAGE;

    // Only surface to federation-aware operators. Regular teachers
    // and students get nothing — matches the capability model on
    // the underlying pages.
    $sys = context_system::instance();
    $caninvoke = has_capability('local/nucleushub:publish', $sys)
        || has_capability('local/nucleusspoke:pull', $sys);
    if (!$caninvoke) {
        return '';
    }
    // Don't render on login / install / upgrade pages.
    if (in_array($PAGE->pagelayout, ['login', 'maintenance', 'redirect'], true)) {
        return '';
    }

    $state = local_nucleuscommon_nsb_render_state();
    $css = local_nucleuscommon_nsb_css();
    $js = local_nucleuscommon_nsb_js();
    $segments = $state['segments'];
    $actions = $state['actions'];
    $panel = $state['panel'];
    $hash = sha1($segments . '|' . $actions . '|' . $panel);
    // Banners are page-level attention calls, distinct from the
    // persistent bar at the bottom. They render once at page load
    // (no live polling) above the page content. Empty string when
    // no widget produced one.
    $banners = local_nucleuscommon_nfb_render_banners();
    // Polling re-runs the same render code in an AJAX context — no
    // `$PAGE` course/pagetype unless we hand them across. Bake the
    // current page's identity into the status URL so status.php can
    // rehydrate `$PAGE` and produce identical widget output.
    $statusurl = (new moodle_url('/local/nucleuscommon/status.php', [
        'pagetype' => (string) $PAGE->pagetype,
        'courseid' => (int) ($PAGE->course->id ?? 0),
    ]))->out(false);

    $html = <<<HTML
<div id="nsb" class="nsb" data-expanded="false" data-state-hash="{$hash}" data-status-url="{$statusurl}">
  <div class="nsb-bar">
    <div class="nsb-cluster nsb-cluster-left">{$segments}</div>
    <div class="nsb-cluster nsb-cluster-right">
      <span class="nsb-actions">{$actions}</span>
      <button type="button" class="nsb-expand" aria-label="Toggle panel" aria-expanded="false">
        <i class="fa fa-chevron-up" aria-hidden="true"></i>
      </button>
    </div>
  </div>
  <div class="nsb-panel" hidden>
    <div class="nsb-panel-grid">{$panel}</div>
  </div>
</div>
HTML;

    return $css . $banners . $html . $js;
}

/**
 * Aggregate per-widget banners into the top-of-body banner stack.
 * Each widget's `banner` field, if present, contributes one row.
 * Widgets that don't want a banner return null/empty for the field.
 *
 * Banner shape: ['tone' => 'warn|info|brand', 'icon' => 'fa-pen',
 * 'body' => 'plain text', 'action' => ['label' => '...', 'url' =>
 * '...', 'icon' => 'fa-upload'] (optional)].
 *
 * Returns an HTML fragment ready to drop above the page content.
 *
 * @return string
 */
function local_nucleuscommon_nfb_render_banners(): string {
    global $CFG, $PAGE;

    // Mirror the widget-discovery in nsb_render_state so the same
    // gating (pagetype, capability) applies.
    if (in_array($PAGE->pagelayout, ['login', 'maintenance', 'redirect'], true)) {
        return '';
    }
    $sys = context_system::instance();
    if (!has_capability('local/nucleushub:publish', $sys)
            && !has_capability('local/nucleusspoke:pull', $sys)) {
        return '';
    }
    foreach (['nucleushub', 'nucleusspoke'] as $sibling) {
        $libpath = $CFG->dirroot . '/local/' . $sibling . '/lib.php';
        if (file_exists($libpath)) {
            require_once($libpath);
        }
    }

    $banners = [];
    foreach (['local_nucleushub_statusbar_widget', 'local_nucleusspoke_statusbar_widget'] as $fn) {
        if (!function_exists($fn)) {
            continue;
        }
        $w = $fn();
        if (!$w || empty($w['banner'])) {
            continue;
        }
        $banners[] = $w['banner'];
    }
    if (!$banners) {
        return '';
    }

    $out = '<div class="nfb-stack">';
    foreach ($banners as $b) {
        $tone = (string) ($b['tone'] ?? 'info');
        $icon = (string) ($b['icon'] ?? 'fa-circle-info');
        $body = (string) ($b['body'] ?? '');
        $action = $b['action'] ?? null;

        $actionhtml = '';
        if ($action && !empty($action['url']) && !empty($action['label'])) {
            $actionhtml = html_writer::link(
                $action['url'],
                '<i class="fa ' . s($action['icon'] ?? 'fa-arrow-right') . '" aria-hidden="true"></i>'
                    . '<span>' . s($action['label']) . '</span>',
                ['class' => 'nfb-action']
            );
        }

        $out .= html_writer::tag(
            'div',
            '<i class="fa ' . s($icon) . ' nfb-icon" aria-hidden="true"></i>'
                . '<span class="nfb-body">' . s($body) . '</span>'
                . $actionhtml,
            ['class' => 'nfb nfb-tone-' . s($tone)]
        );
    }
    $out .= '</div>';
    return $out;
}

/**
 * Render the dynamic bits of the status bar — the bits that can
 * change while the user has a Moodle page open and that the
 * polling JS in `nsb_js` re-fetches via `/local/nucleuscommon/status.php`.
 *
 * Returns an associative array `{ segments, actions, panel }` —
 * each value is a complete HTML fragment ready to drop into the
 * matching DOM node. The expand button + outer chrome don't move,
 * so they're not part of the state.
 *
 * Used by the initial server-side render (above) AND the
 * status.php JSON endpoint that the polling JS hits.
 *
 * @return array{segments: string, actions: string, panel: string}
 */
function local_nucleuscommon_nsb_render_state(): array {
    global $CFG, $DB;

    // Federation identity — always surfaced in the bar so operators
    // know which tenant they're poking at.
    $federationid = (string) get_config('local_nucleuscommon', 'federationid');
    $mode = (string) get_config('local_nucleuscommon', 'federationmode') ?: 'content';

    // Sibling plugin libs aren't auto-loaded in AJAX contexts (e.g.
    // /local/nucleuscommon/status.php for the polling endpoint).
    // Require them explicitly so the widget functions exist whether
    // we're called from the body-top hook or from polling.
    foreach (['nucleushub', 'nucleusspoke'] as $sibling) {
        $libpath = $CFG->dirroot . '/local/' . $sibling . '/lib.php';
        if (file_exists($libpath)) {
            require_once($libpath);
        }
    }

    // Collect context widgets from hub + spoke plugins.
    $widgets = [];
    if (function_exists('local_nucleushub_statusbar_widget')) {
        $w = local_nucleushub_statusbar_widget();
        if ($w) $widgets[] = ['source' => 'hub', 'widget' => $w];
    }
    if (function_exists('local_nucleusspoke_statusbar_widget')) {
        $w = local_nucleusspoke_statusbar_widget();
        if ($w) $widgets[] = ['source' => 'spoke', 'widget' => $w];
    }

    // Left cluster: brand + federation + widget segments.
    $segments = '';
    $segments .= local_nucleuscommon_nsb_brand_segment();
    $segments .= local_nucleuscommon_nsb_segment(
        'fa-sitemap',
        $federationid !== '' ? substr($federationid, 0, 18) : 'no-federation',
        'muted',
        get_string('statusbar_federation', 'local_nucleuscommon')
    );
    // Mode badge — distinct icon + tone per mode so operators can
    // tell at a glance whether this Moodle is a Mode A / Mode B /
    // both federation.
    $modeicon = match ($mode) {
        'identity' => 'fa-user-group',
        'both'     => 'fa-layer-group',
        default    => 'fa-book',
    };
    $modetone = match ($mode) {
        'identity' => 'accent',
        'both'     => 'accent',
        default    => 'muted',
    };
    $modetitle = match ($mode) {
        'identity' => 'Mode B — identity federation (hub-owned shadow users)',
        'both'     => 'Mode A + B — content sync AND identity federation',
        default    => 'Mode A — content federation (spoke-owned users)',
    };
    $segments .= local_nucleuscommon_nsb_segment(
        $modeicon,
        $mode,
        $modetone,
        $modetitle
    );
    foreach ($widgets as $entry) {
        foreach ($entry['widget']['segments'] ?? [] as $seg) {
            $segments .= local_nucleuscommon_nsb_segment(
                $seg['icon'] ?? 'fa-circle',
                $seg['label'] ?? '',
                $seg['tone'] ?? '',
                $seg['title'] ?? null
            );
        }
    }

    // Right cluster: primary actions from widgets + portal link.
    // The expand button isn't part of the state — JS keeps it.
    $actions = '';
    foreach ($widgets as $entry) {
        foreach ($entry['widget']['actions'] ?? [] as $a) {
            $actions .= html_writer::link(
                $a['url'],
                '<i class="fa ' . s($a['icon'] ?? 'fa-arrow-up-right-from-square') . '" aria-hidden="true"></i>'
                    . '<span>' . s($a['label']) . '</span>',
                ['class' => 'nsb-action' . (!empty($a['primary']) ? ' nsb-action-primary' : '')]
            );
        }
    }
    // Global navigation — federation pages reachable from any
    // Moodle page, gated by capability + plugin presence. Saves
    // operators digging through Site administration → Plugins →
    // Local plugins to find them. Rendered before the portal link
    // so portal stays the rightmost action.
    // Gate global hub nav by capability + this Moodle not being
    // wired as a spoke. The hub and spoke plugins both install on
    // every Moodle image; `hubwwwroot` set means this Moodle points
    // at *another* hub, which makes it a spoke (not a hub itself).
    $sysctx = context_system::instance();
    $isspokeshape = (string) get_config('local_nucleusspoke', 'hubwwwroot') !== '';
    $ishub = file_exists($CFG->dirroot . '/local/nucleushub/lib.php') && !$isspokeshape;
    if ($ishub && has_capability('local/nucleushub:publish', $sysctx)) {
        $actions .= html_writer::link(
            (new moodle_url('/local/nucleushub/families.php'))->out(false),
            '<i class="fa fa-cubes" aria-hidden="true"></i><span>'
                . get_string('statusbar_nav_families', 'local_nucleuscommon') . '</span>',
            ['class' => 'nsb-action']
        );
        $actions .= html_writer::link(
            (new moodle_url('/local/nucleushub/spokes.php'))->out(false),
            '<i class="fa fa-sitemap" aria-hidden="true"></i><span>'
                . get_string('statusbar_nav_spokes', 'local_nucleuscommon') . '</span>',
            ['class' => 'nsb-action']
        );
    }

    // Gate global spoke nav by capability + this Moodle being wired
    // as a spoke (hubwwwroot set). The spoke plugin is installed on
    // every Moodle image, so capability alone isn't enough — a hub
    // admin would see broken Catalog/Versions links otherwise.
    $isspoke = (string) get_config('local_nucleusspoke', 'hubwwwroot') !== '';
    if ($isspoke
            && file_exists($CFG->dirroot . '/local/nucleusspoke/lib.php')
            && has_capability('local/nucleusspoke:pull', $sysctx)) {
        $actions .= html_writer::link(
            (new moodle_url('/local/nucleusspoke/catalog.php'))->out(false),
            '<i class="fa fa-folder-open" aria-hidden="true"></i><span>'
                . get_string('statusbar_nav_catalog', 'local_nucleuscommon') . '</span>',
            ['class' => 'nsb-action']
        );
        $actions .= html_writer::link(
            (new moodle_url('/local/nucleusspoke/versions.php'))->out(false),
            '<i class="fa fa-list" aria-hidden="true"></i><span>'
                . get_string('statusbar_nav_versions', 'local_nucleuscommon') . '</span>',
            ['class' => 'nsb-action']
        );
    }

    $portalbase = (string) get_config('local_nucleuscommon', 'cpportalurl');
    if ($portalbase !== '') {
        $actions .= html_writer::link(
            rtrim($portalbase, '/'),
            '<i class="fa fa-arrow-up-right-from-square" aria-hidden="true"></i><span>'
                . get_string('statusbar_portal', 'local_nucleuscommon') . '</span>',
            ['class' => 'nsb-action', 'target' => '_blank', 'rel' => 'noopener']
        );
    }

    // Expanded panel. A widget's `panel` can be a string (one card,
    // the original shape) or an array of strings (each is its own
    // card — used by widgets that want to fill horizontal space on
    // wide screens via the responsive grid).
    $panel = '';
    foreach ($widgets as $entry) {
        $entrypanel = $entry['widget']['panel'] ?? null;
        if (!$entrypanel) {
            continue;
        }
        $cards = is_array($entrypanel) ? $entrypanel : [$entrypanel];
        foreach ($cards as $cardhtml) {
            if ($cardhtml === '' || $cardhtml === null) {
                continue;
            }
            $panel .= html_writer::div(
                $cardhtml,
                'nsb-panel-card nsb-panel-' . s($entry['source'])
            );
        }
    }
    if ($panel === '') {
        $panel = html_writer::div(
            html_writer::tag('div', get_string('statusbar_panel_empty', 'local_nucleuscommon'), ['class' => 'nsb-panel-sub']),
            'nsb-panel-card'
        );
    }

    return [
        'segments' => $segments,
        'actions'  => $actions,
        'panel'    => $panel,
    ];
}

/**
 * One compact "segment" in the status bar: icon + label, optional
 * tone (brand / ok / warn / info / muted).
 */
function local_nucleuscommon_nsb_segment(
    string $icon,
    string $label,
    string $tone = '',
    ?string $title = null
): string {
    $toneclass = $tone !== '' ? ' nsb-tone-' . s($tone) : '';
    $attrs = ['class' => 'nsb-seg' . $toneclass];
    if ($title !== null) {
        $attrs['title'] = $title;
    }
    return html_writer::tag(
        'span',
        '<i class="fa ' . s($icon) . '" aria-hidden="true"></i><span>' . s($label) . '</span>',
        $attrs
    );
}

function local_nucleuscommon_nsb_brand_segment(): string {
    return '<span class="nsb-brand" title="Nucleus"><span class="nsb-brand-dot"></span>NUCLEUS</span>';
}

/**
 * Inline CSS for the status bar. Scoped via `.nsb` prefix so it
 * can't leak into Moodle's own styles. Uses CSS variables for
 * theming (dark-first, with an ember accent).
 */
function local_nucleuscommon_nsb_css(): string {
    return <<<'CSS'
<style>
body { padding-bottom: 30px; }
.nsb {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 9998;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 11.5px;
  color: #d0d3d0;
  background: linear-gradient(180deg, #191d1b 0%, #0f1211 100%);
  border-top: 1px solid #2a2f2c;
  user-select: none;
}
.nsb-bar {
  display: flex; align-items: stretch; height: 30px; padding: 0 2px;
}
.nsb-cluster { display: flex; align-items: stretch; gap: 0; }
.nsb-cluster-left { flex: 1; min-width: 0; overflow: hidden; }
.nsb-cluster-right { flex-shrink: 0; }
.nsb-seg,
.nsb-action,
.nsb-brand,
.nsb-expand {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 0 10px; height: 30px;
  color: inherit; text-decoration: none;
  border: 0; background: transparent; cursor: default;
  line-height: 1;
  white-space: nowrap;
}
.nsb-seg i,
.nsb-action i,
.nsb-brand i { font-size: 11px; opacity: 0.9; }
.nsb-brand {
  font-weight: 600; letter-spacing: 0.08em; color: #f0a255;
  padding-left: 12px; padding-right: 12px;
  border-right: 1px solid #2a2f2c;
}
.nsb-brand-dot {
  display: inline-block; width: 7px; height: 7px; border-radius: 50%;
  background: #f0a255; box-shadow: 0 0 6px rgba(240, 162, 85, 0.6);
}
.nsb-tone-brand { color: #f0a255; }
.nsb-tone-ok { color: #7dd3a2; }
.nsb-tone-warn { color: #f4c06a; }
.nsb-tone-info { color: #7fb8e0; }
.nsb-tone-muted { color: #8a8d8a; }
.nsb-tone-accent { color: #b78cf2; }
.nsb-action {
  cursor: pointer;
  border-left: 1px solid #2a2f2c;
}
.nsb-action:hover { background: #23282692; color: #fff; text-decoration: none; }
.nsb-action-primary { color: #f0a255; }
.nsb-action-primary:hover { background: #2a1f15; color: #ffb46b; }
.nsb-expand {
  cursor: pointer; border-left: 1px solid #2a2f2c; padding: 0 14px;
  color: #8a8d8a;
}
.nsb-expand:hover { background: #23282692; color: #fff; }
.nsb[data-expanded="true"] .nsb-expand i { transform: rotate(180deg); }
.nsb-panel {
  max-height: 260px;
  overflow: auto;
  padding: 14px 16px;
  background: #14171692;
  border-top: 1px solid #2a2f2c;
  color: #c8cac9;
}
.nsb-panel-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 12px;
}
.nsb-panel-card {
  border: 1px solid #2a2f2c;
  border-radius: 4px;
  padding: 12px 14px;
  background: #191d1b;
}
.nsb-panel-title {
  margin: 0 0 6px 0;
  font-size: 13px;
  font-weight: 500;
  color: #f0a255;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
}
.nsb-panel-version { color: #8a8d8a; font-weight: 400; margin-left: 6px; }
.nsb-panel-sub { font-size: 11.5px; color: #a1a4a1; }
.nsb-panel-notes {
  margin-top: 8px;
  padding: 8px 10px;
  background: #0f1211;
  border: 1px solid #2a2f2c;
  border-radius: 3px;
  font-size: 11px;
  color: #c8cac9;
  white-space: pre-wrap;
  max-height: 80px;
  overflow: auto;
}
.nsb-panel-callout {
  margin-top: 8px;
  padding: 6px 10px;
  background: rgba(244, 192, 106, 0.1);
  border-left: 2px solid #f4c06a;
  color: #f4c06a;
  font-size: 11px;
}
/* Print: hide the bar. */
@media print { .nsb { display: none; } }

/* ---------- Top-of-body federation banner (NFB) ---------- */
/* In-flow above page content; non-fixed so it scrolls naturally.
   Light theme so it sits comfortably on Moodle's standard pages —
   the dark Nucleus identity lives in the bottom status bar. */
.nfb-stack {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 0 0 12px 0;
}
.nfb {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  font-size: 13px;
  color: #1d2326;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-left: 3px solid #f59e0b;
  border-radius: 4px;
}
.nfb-tone-warn { background: #fffbeb; border-color: #fde68a; border-left-color: #f59e0b; }
.nfb-tone-info { background: #eff6ff; border-color: #bfdbfe; border-left-color: #3b82f6; }
.nfb-tone-brand { background: #fff7ed; border-color: #fed7aa; border-left-color: #d97706; }
.nfb-tone-ok { background: #ecfdf5; border-color: #a7f3d0; border-left-color: #059669; }
.nfb-icon {
  font-size: 14px;
  flex-shrink: 0;
}
.nfb-tone-warn .nfb-icon { color: #b45309; }
.nfb-tone-info .nfb-icon { color: #1d4ed8; }
.nfb-tone-brand .nfb-icon { color: #b45309; }
.nfb-tone-ok .nfb-icon { color: #047857; }
.nfb-body {
  flex: 1;
  min-width: 0;
}
.nfb-action {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  background: #fff;
  color: #b45309;
  text-decoration: none;
  border: 1px solid #fde68a;
  border-radius: 4px;
  font-weight: 500;
  font-size: 12.5px;
  flex-shrink: 0;
}
.nfb-action:hover {
  background: #b45309;
  color: #fff;
  border-color: #b45309;
  text-decoration: none;
}
.nfb-action i { font-size: 11px; }
@media print { .nfb-stack { display: none; } }
</style>
CSS;
}

/**
 * Inline JS for expand/collapse. Tiny — no deps, no Moodle AMD
 * module overhead. Idempotent via a `window` flag so re-emitting
 * the bar (theoretically) doesn't double-install handlers.
 */
function local_nucleuscommon_nsb_js(): string {
    return <<<'JS'
<script>
(function(){
    if (window.__nsbInstalled) return;
    window.__nsbInstalled = true;
    // Poll every 5s. Pause when the tab is hidden so we don't burn
    // requests on backgrounded Moodle pages.
    var POLL_MS = 5000;
    var pollTimer = null;
    var inFlight = false;

    var applyState = function(root, data){
        if (!data || !data.hash) return;
        if (root.dataset.stateHash === data.hash) return;
        var left = root.querySelector('.nsb-cluster-left');
        var actions = root.querySelector('.nsb-actions');
        var grid = root.querySelector('.nsb-panel-grid');
        if (left)    left.innerHTML    = data.segments || '';
        if (actions) actions.innerHTML = data.actions  || '';
        if (grid)    grid.innerHTML    = data.panel    || '';
        root.dataset.stateHash = data.hash;
    };

    var poll = function(root){
        if (inFlight) return;
        var url = root.dataset.statusUrl;
        if (!url) return;
        inFlight = true;
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(data){ if (data) applyState(root, data); })
            .catch(function(){ /* silent — bar just won't refresh this tick */ })
            .finally(function(){ inFlight = false; });
    };

    var startPolling = function(root){
        if (pollTimer) return;
        pollTimer = setInterval(function(){ poll(root); }, POLL_MS);
    };
    var stopPolling = function(){
        if (!pollTimer) return;
        clearInterval(pollTimer);
        pollTimer = null;
    };

    var init = function(){
        var root = document.getElementById('nsb');
        if (!root) return;
        var btn = root.querySelector('.nsb-expand');
        var panel = root.querySelector('.nsb-panel');
        if (!btn || !panel) return;
        btn.addEventListener('click', function(){
            var open = root.dataset.expanded === 'true';
            root.dataset.expanded = open ? 'false' : 'true';
            btn.setAttribute('aria-expanded', String(!open));
            if (open) {
                panel.setAttribute('hidden', '');
            } else {
                panel.removeAttribute('hidden');
                // Refresh immediately on expand so the panel isn't stale.
                poll(root);
            }
        });
        // Pause polling on hidden tabs; refresh on focus.
        document.addEventListener('visibilitychange', function(){
            if (document.hidden) {
                stopPolling();
            } else {
                poll(root);
                startPolling(root);
            }
        });
        if (!document.hidden) startPolling(root);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
JS;
}
