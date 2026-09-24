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
 * Show the identifier spokes will see as it's typed.
 *
 * Mirrors local_nucleushub\version\publisher::slugify(); the server
 * applies the same rule when the form is saved.
 *
 * @module     local_nucleushub/slug_preview
 * @copyright  2026 David Kelly <contact@dklabs.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The identifier the server would store for this text.
 *
 * @param {string} value
 * @returns {string}
 */
const slugify = (value) => {
    const slug = (value || '').trim().toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    return (slug || 'course').substring(0, 120);
};

/**
 * Keep the preview in step with the field.
 *
 * @param {string} inputSelector The identifier field.
 * @param {string} previewSelector The element that shows the result.
 */
export const init = (inputSelector, previewSelector) => {
    const input = document.querySelector(inputSelector);
    const preview = document.querySelector(previewSelector);
    if (!input || !preview) {
        return;
    }
    input.addEventListener('input', () => {
        preview.textContent = slugify(input.value);
    });
};
