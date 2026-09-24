// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * The Nucleus bar: open and close its panel, and keep it current.
 *
 * Polls every 15 seconds while the panel is open and every 60 seconds
 * otherwise, and not at all while the tab is hidden. A region that has
 * keyboard focus is never replaced, so nobody loses their place.
 *
 * @module     local_nucleuscommon/statusbar
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const POLL_OPEN_MS = 15000;
const POLL_CLOSED_MS = 60000;
const HEIGHT_PROPERTY = '--local-nucleuscommon-bar-height';

/**
 * Replace a region's markup unless focus is inside it.
 *
 * @param {HTMLElement|null} region
 * @param {string} html
 * @returns {boolean} True when the region now shows the new markup.
 */
const replaceRegion = (region, html) => {
    if (!region) {
        return true;
    }
    if (region.contains(document.activeElement)) {
        return false;
    }
    region.innerHTML = html;
    return true;
};

/**
 * Set up the bar.
 *
 * @param {string} selector CSS selector for the bar's root element.
 */
export const init = (selector) => {
    const root = document.querySelector(selector);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';

    // Last in the page, so it comes after Moodle's own navigation and
    // content in tab order and sits outside the layout's containers.
    document.body.appendChild(root);

    const strip = root.querySelector('.local-nucleuscommon-bar-strip');
    const toggle = root.querySelector('[data-action="toggle"]');
    const panel = root.querySelector('[data-region="panel"]');
    let timer = null;
    let inflight = false;

    const isOpen = () => toggle.getAttribute('aria-expanded') === 'true';

    // Reserve room at the bottom of the page for the strip.
    const setHeight = () => {
        document.body.style.setProperty(HEIGHT_PROPERTY, `${strip.offsetHeight}px`);
    };
    setHeight();
    if (window.ResizeObserver) {
        new window.ResizeObserver(setHeight).observe(strip);
    } else {
        window.addEventListener('resize', setHeight);
    }

    const apply = (data) => {
        if (!data || !data.hash || data.hash === root.dataset.stateHash) {
            return;
        }
        const done = [
            replaceRegion(root.querySelector('[data-region="segments"]'), data.segments || ''),
            replaceRegion(root.querySelector('[data-region="actions"]'), data.actions || ''),
            replaceRegion(panel, data.panel || ''),
        ];
        // If a region was skipped, keep the old hash so the next poll tries again.
        if (done.every(Boolean)) {
            root.dataset.stateHash = data.hash;
        }
    };

    const poll = async() => {
        if (inflight || !root.dataset.statusUrl) {
            return;
        }
        inflight = true;
        try {
            const response = await fetch(root.dataset.statusUrl, {
                credentials: 'same-origin',
                headers: {Accept: 'application/json'},
            });
            if (response.ok) {
                apply(await response.json());
            }
        } catch (error) {
            // Leave the bar as it is; the next poll tries again.
        } finally {
            inflight = false;
        }
    };

    const schedule = () => {
        window.clearTimeout(timer);
        timer = null;
        if (document.hidden) {
            return;
        }
        timer = window.setTimeout(async() => {
            await poll();
            schedule();
        }, isOpen() ? POLL_OPEN_MS : POLL_CLOSED_MS);
    };

    const setOpen = (open) => {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        root.classList.toggle('local-nucleuscommon-bar-open', open);
        panel.hidden = !open;
        if (open) {
            poll();
        }
        schedule();
    };

    toggle.addEventListener('click', () => setOpen(!isOpen()));

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            setOpen(false);
            toggle.focus();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            window.clearTimeout(timer);
            timer = null;
        } else {
            poll();
            schedule();
        }
    });

    schedule();
};
