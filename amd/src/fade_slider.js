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
 * Adds a slider beside a fade width number box and keeps the two in step.
 *
 * @module     mod_whakaharatau/fade_slider
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the slider.
 *
 * @param {string} id id of the number box
 */
export const init = (id) => {
    const box = document.getElementById(id);
    // A frozen (locked) field has no box to edit.
    if (!box || box.type === 'hidden' || !box.dataset.whakaharatauFade) {
        return;
    }
    const max = Number(box.dataset.whakaharatauFade);
    const slider = document.createElement('input');
    slider.type = 'range';
    slider.min = '0';
    slider.max = String(max);
    slider.step = '1';
    slider.className = 'form-range mod_whakaharatau-fadeslider';
    const label = document.querySelector(`label[for="${CSS.escape(id)}"]`);
    slider.setAttribute('aria-label', label ? label.textContent.trim() : '');
    const clamp = (value) => Math.max(0, Math.min(max, Math.round(Number(value) || 0)));
    const fromBox = () => {
        slider.value = String(clamp(box.value));
        slider.setAttribute('aria-valuetext', `${slider.value}%`);
        // Moodle's hideIf disables the box; the slider follows it.
        slider.disabled = box.disabled;
    };
    slider.addEventListener('input', () => {
        box.value = slider.value;
        slider.setAttribute('aria-valuetext', `${slider.value}%`);
        box.dispatchEvent(new Event('change', {bubbles: true}));
    });
    box.addEventListener('input', fromBox);
    new MutationObserver(fromBox).observe(box, {attributes: true, attributeFilter: ['disabled']});
    box.after(slider);
    fromBox();
};
