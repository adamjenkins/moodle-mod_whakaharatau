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
 * Drag-reorder of the Edit slides list (core/sortable_list, which also gives a keyboard "Move" dialogue).
 *
 * @module     mod_whakaharatau/edit_slides
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Pending from 'core/pending';
import SortableList from 'core/sortable_list';
import {getString, getStrings} from 'core/str';
import {add as addToast} from 'core/toast';

const SELECTORS = {
    list: '[data-region="slide-list"]',
    item: '[data-slideid]',
    title: '[data-region="slide-title"]',
    handle: '[data-drag-type="move"]',
};

/**
 * Renumber the visible titles and move-button names after a move.
 *
 * @param {HTMLElement} list the slide list
 * @returns {Promise}
 */
const renumber = (list) => {
    const items = Array.from(list.querySelectorAll(SELECTORS.item));
    const requests = [];
    items.forEach((item, index) => {
        requests.push({key: 'slidenumber', component: 'mod_whakaharatau', param: index + 1});
        requests.push({key: 'moveslide', component: 'mod_whakaharatau', param: index + 1});
    });
    return getStrings(requests).then((strings) => {
        items.forEach((item, index) => {
            const title = item.querySelector(SELECTORS.title);
            if (title) {
                title.textContent = strings[index * 2];
            }
            item.querySelectorAll('.visually-hidden').forEach((hidden) => {
                hidden.textContent = ' ' + strings[index * 2];
            });
            const handle = item.querySelector(SELECTORS.handle);
            if (handle) {
                handle.setAttribute('title', strings[index * 2 + 1]);
                handle.setAttribute('aria-label', strings[index * 2 + 1]);
            }
        });
        return null;
    });
};

/**
 * Initialise the sortable slide list of one activity.
 *
 * @param {Number} cmid course module id
 */
export const init = (cmid) => {
    const root = document.getElementById('mod_whakaharatau-edit-' + cmid);
    const list = root ? root.querySelector(SELECTORS.list) : null;
    if (!list) {
        return;
    }
    const sortable = new SortableList(list);
    sortable.getElementName = (element) => {
        const title = element[0].querySelector(SELECTORS.title);
        return Promise.resolve(title ? title.textContent : '');
    };

    list.addEventListener(SortableList.EVENTS.elementDrop, (event) => {
        const info = event.detail;
        if (!info || !info.positionChanged) {
            return;
        }
        const element = info.element[0];
        const previous = element.previousElementSibling;
        const pending = new Pending('mod_whakaharatau/edit_slides:move');
        const request = Ajax.call([{
            methodname: 'mod_whakaharatau_move_slide',
            args: {
                cmid: cmid,
                slideid: Number(element.dataset.slideid),
                afterslideid: previous ? Number(previous.dataset.slideid) : 0,
            },
        }])[0];
        // A core/ajax promise is a jQuery Deferred with no finally(): wrap it.
        Promise.resolve(request)
            .then(() => renumber(list))
            .then(() => getString('slidemoved', 'mod_whakaharatau'))
            .then((message) => addToast(message))
            .catch(Notification.exception)
            .then(() => pending.resolve())
            .catch(() => pending.resolve());
    });
};
