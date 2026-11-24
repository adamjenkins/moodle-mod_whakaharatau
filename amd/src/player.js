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
 * Listening practice player: start screen, slides, round banner, end screen and review.
 *
 * The server marks every answer and never sends the correct option of an unfinished slide; this module
 * only shows what the external functions return. Several players can share a course page: every DOM id
 * is scoped by the course module id, and starting a clip in one player stops the clips of all the others
 * (a document-level event).
 *
 * @module     mod_whakaharatau/player
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';

const COMPONENT = 'mod_whakaharatau';

/** @var {string} Document-level event fired when a player starts a clip. */
const PLAY_EVENT = 'mod_whakaharatau:play';

const MODE_TEST = 1;
const REPEAT_ALL = 1;
const REPEAT_MISSED = 2;

/** @var {number} How long the "Round N" banner stays before the round starts (ms). */
const BANNER_DELAY = 1800;

/** @var {string} Icon drawn on the choose button for each option state. */
const STATE_ICONS = {
    none: 'choose',
    chosen: 'choose',
    wrong: 'wrong',
    correct: 'tick',
    revealed: 'tick',
};

/** @var {Set<number>} Course modules whose player is running. */
const started = new Set();

/**
 * Call one external function.
 *
 * @param {string} methodname function name
 * @param {Object} args arguments
 * @returns {Promise}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * One string of this plugin.
 *
 * @param {string} key string key
 * @param {*} [param] the {$a} value
 * @returns {Promise<string>}
 */
const str = (key, param) => getString(key, COMPONENT, param);

/**
 * Format a score: at most two decimals, no trailing zeros.
 *
 * @param {number} value the number
 * @returns {string}
 */
const formatNumber = (value) => String(Math.round(Number(value) * 100) / 100);

/**
 * Show or hide an element with the hidden attribute.
 *
 * @param {HTMLElement|null} element the element
 * @param {boolean} visible whether to show it
 */
const toggle = (element, visible) => {
    if (element) {
        element.hidden = !visible;
    }
};

/**
 * One player on the page.
 */
class Player {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root the player's root element (data-region="mod-whakaharatau-player")
     */
    constructor(root) {
        const data = root.dataset;
        this.root = root;
        this.cmid = Number(data.cmid);
        this.idprefix = root.id;
        this.inline = data.inline === '1';
        this.showprogress = data.showprogress === '1';
        this.autoplay = data.autoplay === '1';
        this.mode = Number(data.mode);
        this.repeatmode = Number(data.repeatmode);
        this.courseurl = data.courseurl;
        this.hasinprogress = data.hasinprogress === '1';
        this.canstartnew = data.canstartnew === '1';
        this.attemptsleft = data.attemptsleft === undefined ? -1 : Number(data.attemptsleft);
        this.lastattemptid = Number(data.lastattemptid) || 0;

        this.card = root.querySelector('[data-region="card"]');
        this.announcer = root.querySelector('[data-region="announce"]');
        this.startHtml = this.card.innerHTML;

        /** @var {Object|null} attempt state (get_state) */
        this.state = null;
        /** @var {Map<number, Object>} slide data (get_slide_data) by slide id */
        this.slideData = new Map();
        /** @var {string} what the card shows: start, slide, banner, end, review */
        this.view = 'start';
        /** @var {Object|null} the slide on screen: slideid, finished, labels, options (Map optionid => state) */
        this.current = null;
        /** @var {Object|null} the last submit_answer result */
        this.result = null;
        /** @var {Object|null} summary of the attempt on the end screen */
        this.summary = null;
        /** @var {Map<number, string>} slide images for the end screen, by slide id */
        this.images = new Map();
        /** @var {Array|null} review of the attempt on the end screen */
        this.review = null;
        /** @var {boolean} the end screen was opened from the start screen ("Last result") */
        this.endFromStart = false;

        this.busy = false;
        this.interacted = false;
        /** @var {Map<string, HTMLAudioElement>} audio elements by URL (current and next slide) */
        this.audioCache = new Map();
        /** @var {Object|null} the clip playing: audio, button */
        this.playing = null;
        this.bannerTimer = null;
        this.bannerPending = null;
    }

    /**
     * Listen for clicks, the first interaction, other players' clips and the page being left.
     */
    init() {
        this.root.addEventListener('click', (e) => this.handleClick(e));
        this.root.addEventListener('keydown', () => {
            this.interacted = true;
        });
        document.addEventListener(PLAY_EVENT, (e) => {
            if (e.detail && e.detail.cmid !== this.cmid) {
                this.stopAudio();
            }
        });
        window.addEventListener('pagehide', () => this.stopAudio());
    }

    /**
     * Dispatch a click on a [data-action] element of this player.
     *
     * @param {Event} e click event
     */
    handleClick(e) {
        const button = e.target.closest('[data-action]');
        if (!button || !this.root.contains(button)) {
            return;
        }
        this.interacted = true;
        if (button.disabled || button.getAttribute('aria-disabled') === 'true') {
            return;
        }
        e.preventDefault();
        switch (button.dataset.action) {
            case 'start':
                this.run('start', () => this.begin(false, 0));
                break;
            case 'continue':
                this.run('start', () => this.begin(false, 0));
                break;
            case 'startagain':
            case 'tryagain':
            case 'goagain':
                this.run('start', () => this.begin(true, 0));
                break;
            case 'practisemissed':
                this.run('start', () => this.begin(true, this.summary ? this.summary.attemptid : 0));
                break;
            case 'lastresult':
                this.run('result', () => this.showResultOf(this.lastattemptid));
                break;
            case 'play':
                this.togglePlay(button);
                break;
            case 'choose':
                this.run('choose', () => this.choose(button.closest('[data-region="option"]')));
                break;
            case 'next':
                this.run('next', () => this.next());
                break;
            case 'exit':
                this.run('exit', () => this.exit());
                break;
            case 'back':
                this.run('back', () => this.leave());
                break;
            case 'review':
                this.run('review', () => this.showReview());
                break;
            case 'backtoresults':
                this.run('end', () => this.showEnd());
                break;
        }
    }

    /**
     * Run one step at a time, with a Pending for Behat and errors shown to the user.
     *
     * @param {string} name step name
     * @param {Function} step async function
     */
    async run(name, step) {
        if (this.busy) {
            return;
        }
        this.busy = true;
        const pending = new Pending(`mod_whakaharatau/player:${name}`);
        try {
            await step();
        } catch (error) {
            Notification.exception(error);
        } finally {
            this.busy = false;
            pending.resolve();
        }
    }

    // Attempts.

    /**
     * Start or resume an attempt, then show its current slide.
     *
     * @param {boolean} forcenew start a new attempt even if one is unfinished
     * @param {number} missedfrom finished Practice session to take the missed slides from, or 0
     */
    async begin(forcenew, missedfrom) {
        const isnew = forcenew || !this.hasinprogress;
        const response = await call('mod_whakaharatau_start_attempt', {
            cmid: this.cmid,
            forcenew: forcenew,
            missedfrom: missedfrom,
        });
        this.slideData.clear();
        this.acceptState(response.state, response.slides);
        this.result = null;
        this.summary = null;
        this.review = null;
        this.endFromStart = false;
        this.hasinprogress = true;
        if (isnew && response.state.mode === MODE_TEST && this.attemptsleft > 0) {
            this.attemptsleft--;
            this.canstartnew = this.attemptsleft !== 0;
        }
        if (response.state.roundcomplete) {
            await this.finishAndShowEnd();
            return;
        }
        await this.showSlide();
    }

    /**
     * Keep the state and slide data a call returned.
     *
     * @param {Object} state get_state()
     * @param {Array} slides get_slide_data() list
     */
    acceptState(state, slides) {
        this.state = state;
        (slides || []).forEach((slide) => this.slideData.set(slide.slideid, slide));
    }

    /**
     * Finish the attempt (idempotent on the server) and show the end screen.
     */
    async finishAndShowEnd() {
        const response = await call('mod_whakaharatau_finish_attempt', {
            attemptid: this.state.attemptid,
            includereview: false,
        });
        this.acceptSummary(response);
        await this.showEnd();
    }

    /**
     * Show the result of an attempt finished earlier ("Last result" on the start screen).
     *
     * @param {number} attemptid attempt id
     */
    async showResultOf(attemptid) {
        if (!attemptid) {
            return;
        }
        const response = await call('mod_whakaharatau_finish_attempt', {attemptid: attemptid, includereview: false});
        this.acceptSummary(response);
        this.endFromStart = true;
        await this.showEnd();
    }

    /**
     * Keep a finish_attempt result and update what the start screen will offer.
     *
     * @param {Object} response finish_attempt result
     */
    acceptSummary(response) {
        const summary = response.summary;
        this.summary = summary;
        this.review = response.review || null;
        this.images = new Map((response.images || []).map((image) => [image.slideid, image.imageurl || '']));
        if (summary.finished) {
            if (this.state && this.state.attemptid === summary.attemptid) {
                this.hasinprogress = false;
                this.state.finished = true;
            }
            this.lastattemptid = Math.max(this.lastattemptid, summary.attemptid);
            if (!this.hasinprogress) {
                this.canstartnew = summary.canretry;
            }
            if (summary.attemptsleft !== null) {
                this.attemptsleft = summary.attemptsleft;
            }
        }
    }

    // Slides.

    /**
     * Labels of the option rows of a slide.
     *
     * @param {number} count number of options
     * @returns {Promise<Array>} list (by option number - 1) of: play, pause, choose, chosen, wrong, correct, revealed
     */
    async rowLabels(count) {
        const keys = ['playoption', 'pauseoption', 'chooseoption', 'optionchosen', 'optionwrong', 'optioncorrect',
            'optionrevealed'];
        const names = ['play', 'pause', 'choose', 'chosen', 'wrong', 'correct', 'revealed'];
        const requests = [];
        for (let number = 1; number <= count; number++) {
            keys.forEach((key) => requests.push({key, component: COMPONENT, param: {number, count}}));
        }
        const strings = await getStrings(requests);
        const labels = [];
        for (let i = 0; i < count; i++) {
            const row = {};
            names.forEach((name, j) => {
                row[name] = strings[i * keys.length + j];
            });
            labels.push(row);
        }
        return labels;
    }

    /**
     * Show the first unfinished slide of the round.
     */
    async showSlide() {
        const state = this.state;
        const slidestate = state.slides[state.position];
        if (!slidestate) {
            await this.finishAndShowEnd();
            return;
        }
        const nextstate = state.slides[state.position + 1] || null;
        const wanted = [slidestate.slideid];
        if (nextstate) {
            wanted.push(nextstate.slideid);
        }
        if (wanted.some((slideid) => !this.slideData.has(slideid))) {
            const response = await call('mod_whakaharatau_get_attempt_state', {
                attemptid: state.attemptid,
                slideids: wanted,
            });
            this.acceptState(response.state, response.slides);
        }
        const data = this.slideData.get(slidestate.slideid);
        if (!data) {
            throw new Error('Slide data missing');
        }

        const count = this.state.slidecount;
        const number = slidestate.number;
        const round = this.state.round;
        const labels = await this.rowLabels(data.options.length);
        const [slidelabel, exitlabel, nextlabel, promptplaylabel, promptpauselabel] = await Promise.all([
            round > 1 ? str('slidelabelround', {round, number, count}) : str('slidelabel', {number, count}),
            str('exit'),
            str('nextslide'),
            str('playprompt'),
            str('pauseprompt'),
        ]);
        const context = {
            idprefix: this.idprefix,
            slideid: data.slideid,
            slidelabel,
            showprogress: this.showprogress,
            number,
            count,
            percent: count > 0 ? Math.round(number / count * 100) : 0,
            imageurl: data.imageurl || '',
            alttext: data.decorative ? '' : data.alttext,
            fadeside: data.fadeside,
            fadebottom: data.fadebottom,
            prompthtml: data.prompthtml || '',
            promptlang: data.promptlang || '',
            promptaudiourl: data.promptaudiourl || '',
            promptplaylabel,
            promptpauselabel,
            exitlabel,
            nextlabel,
            options: data.options.map((option) => ({
                optionid: option.optionid,
                number: option.number,
                audiourl: option.audiourl || '',
                transcript: option.transcript || '',
                playlabel: labels[option.number - 1].play,
                pauselabel: labels[option.number - 1].pause,
                chooselabel: labels[option.number - 1].choose,
            })),
        };
        await this.render('mod_whakaharatau/player_slide', context);
        this.view = 'slide';
        this.result = null;
        this.current = {
            slideid: data.slideid,
            finished: slidestate.finished,
            labels,
            options: new Map(slidestate.options.map((option) => [option.optionid, option.state])),
        };
        this.applyOptionStates();
        this.prefetch(data, nextstate ? this.slideData.get(nextstate.slideid) : null);
        this.focusTitle();
        this.announce(slidelabel);

        if (this.autoplay && this.interacted && data.promptaudiourl) {
            const promptbutton = this.card.querySelector('[data-prompt="1"]');
            if (promptbutton) {
                this.togglePlay(promptbutton);
            }
        }
    }

    /**
     * Draw each option row of the slide on screen in its state: the dart, a locked dart, an X or a tick.
     */
    applyOptionStates() {
        const slide = this.card.querySelector('[data-region="slide"]');
        if (!slide || !this.current) {
            return;
        }
        slide.classList.toggle('is-finished', this.current.finished);
        slide.querySelectorAll('[data-region="option"]').forEach((row) => {
            const state = this.current.options.get(Number(row.dataset.optionid)) || 'none';
            const labels = this.current.labels[Number(row.dataset.number) - 1];
            const button = row.querySelector('[data-action="choose"]');
            const icon = row.querySelector('[data-region="chooseicon"]');
            const label = row.querySelector('[data-region="chooselabel"]');
            row.dataset.state = state;
            icon.className = `mod-whakaharatau-icon mod-whakaharatau-icon-${STATE_ICONS[state] || 'choose'}`;
            label.textContent = state === 'none' ? labels.choose : labels[state];
            if (state !== 'none' || this.current.finished) {
                // Not "disabled": the button keeps the focus after the answer, and its name says the result.
                button.setAttribute('aria-disabled', 'true');
            } else {
                button.removeAttribute('aria-disabled');
            }
        });
    }

    /**
     * Show the transcripts the server now allows for the slide on screen.
     *
     * @param {number} slideid slide id
     * @param {Array|undefined} transcripts [{optionid, transcript}] from submit_answer; default: the slide's data
     */
    updateTranscripts(slideid, transcripts) {
        const data = this.slideData.get(slideid);
        const list = transcripts || (data ? data.options : []);
        list.forEach((option) => {
            const row = this.card.querySelector(`[data-region="option"][data-optionid="${option.optionid}"]`);
            const transcript = row ? row.querySelector('[data-region="transcript"]') : null;
            if (transcript && option.transcript) {
                transcript.textContent = option.transcript;
                transcript.hidden = false;
            }
        });
    }

    /**
     * Choose an option: submit it at once; the server marks it.
     *
     * @param {HTMLElement|null} row the option row
     */
    async choose(row) {
        if (!row || !this.current || this.current.finished || this.view !== 'slide') {
            return;
        }
        const optionid = Number(row.dataset.optionid);
        if ((this.current.options.get(optionid) || 'none') !== 'none') {
            return;
        }
        const response = await call('mod_whakaharatau_submit_answer', {
            attemptid: this.state.attemptid,
            slideid: this.current.slideid,
            optionid: optionid,
        });
        this.result = response;
        if (response.newround) {
            // A new round has new option orders and hides transcripts again.
            this.slideData.clear();
        }
        this.acceptState(response.state, response.slides);

        const {rowstate, revealednumber} = this.markRows(optionid, response);
        this.applyOptionStates();
        this.updateTranscripts(response.slideid, response.transcripts);

        if (response.attemptfinished) {
            this.hasinprogress = false;
            this.summary = response.summary;
        }
        const labels = this.current.labels[Number(row.dataset.number) - 1];
        const messages = [labels[rowstate]].concat(await this.resultMessages(rowstate, revealednumber, response));
        // Each part is a sentence; the row labels have no full stop of their own.
        const message = messages.map((m) => (/[.!?]$/.test(m) ? m : m + '.')).join(' ');
        const status = this.card.querySelector('[data-region="status"]');
        if (status) {
            status.textContent = message;
        }
        this.announce(message);
    }

    /**
     * Record the state of the chosen row, and of the revealed correct row when the slide is finished.
     *
     * @param {Number} optionid the chosen option
     * @param {Object} response the submit_answer response
     * @returns {Object} the chosen row's state and the revealed row's number (0 if none)
     */
    markRows(optionid, response) {
        let rowstate = 'chosen';
        if (response.feedback) {
            rowstate = response.correct ? 'correct' : 'wrong';
        }
        this.current.options.set(optionid, rowstate);
        let revealednumber = 0;
        if (response.slidefinished) {
            this.current.finished = true;
            if (response.correctoptionid && response.correctoptionid !== optionid) {
                this.current.options.set(response.correctoptionid, 'revealed');
                const correctrow = this.card.querySelector(
                    `[data-region="option"][data-optionid="${response.correctoptionid}"]`);
                revealednumber = correctrow ? Number(correctrow.dataset.number) : 0;
            }
        }
        return {rowstate, revealednumber};
    }

    /**
     * The messages that follow an answer, and showing the Next arrow when the slide is finished.
     *
     * @param {String} rowstate the chosen row's state
     * @param {Number} revealednumber the revealed correct row's number, or 0
     * @param {Object} response the submit_answer response
     * @returns {Promise<String[]>} the messages to show and announce
     */
    async resultMessages(rowstate, revealednumber, response) {
        const messages = [];
        if (rowstate === 'wrong' && !response.slidefinished) {
            if (response.triesleft === null) {
                messages.push(await str('tryanother'));
            } else {
                messages.push(await str(response.triesleft === 1 ? 'triesleftone' : 'triesleft', response.triesleft));
            }
        }
        if (revealednumber) {
            messages.push(await str('correctwas', revealednumber));
        }
        if (response.slidefinished) {
            let nextkey = 'nextslide';
            if (response.attemptfinished) {
                nextkey = 'seeresults';
            } else if (response.newround) {
                nextkey = 'nextround';
            }
            const nextlabel = await str(nextkey);
            const nextbutton = this.card.querySelector('[data-action="next"]');
            nextbutton.querySelector('[data-region="nextlabel"]').textContent = nextlabel;
            nextbutton.hidden = false;
            messages.push(await str('nextavailable', nextlabel));
        }
        return messages;
    }

    /**
     * The Next arrow: the next slide, the next round's banner, or the end screen.
     */
    async next() {
        if (this.view === 'banner') {
            this.clearBanner();
            await this.showSlide();
            return;
        }
        if (this.view !== 'slide' || !this.current || !this.current.finished) {
            return;
        }
        const result = this.result;
        if ((result && result.attemptfinished) || this.state.finished || this.state.roundcomplete) {
            await this.finishAndShowEnd();
            return;
        }
        if (result && result.newround) {
            await this.showBanner(result.newround);
            return;
        }
        await this.showSlide();
    }

    /**
     * Show the "Round N" banner, then the round's first slide.
     *
     * @param {number} round the new round's number
     */
    async showBanner(round) {
        const count = this.state.slidecount;
        let detailkey = 'roundagain';
        if (this.state.repeatmode === REPEAT_MISSED) {
            detailkey = count === 1 ? 'roundmissedone' : 'roundmissed';
        }
        const [roundlabel, detail, exitlabel, nextlabel] = await Promise.all([
            str('roundlabel', round),
            str(detailkey, count),
            str('exit'),
            str('startround'),
        ]);
        await this.render('mod_whakaharatau/player_round_banner', {
            idprefix: this.idprefix,
            roundlabel,
            detail,
            exitlabel,
            nextlabel,
        });
        this.view = 'banner';
        this.current = null;
        this.focusTitle();
        this.announce(`${roundlabel}. ${detail}`);

        this.clearBanner();
        this.bannerPending = new Pending('mod_whakaharatau/player:banner');
        this.bannerTimer = window.setTimeout(() => {
            this.bannerTimer = null;
            if (this.view === 'banner') {
                this.run('next', () => this.showSlide());
            }
            this.resolveBannerPending();
        }, BANNER_DELAY);
    }

    /**
     * Stop the banner's timer.
     */
    clearBanner() {
        if (this.bannerTimer) {
            window.clearTimeout(this.bannerTimer);
            this.bannerTimer = null;
        }
        this.resolveBannerPending();
    }

    /**
     * Resolve the banner's Pending, if any.
     */
    resolveBannerPending() {
        if (this.bannerPending) {
            this.bannerPending.resolve();
            this.bannerPending = null;
        }
    }

    // Leaving.

    /**
     * The exit X. In Practice with Repeat All, a session never ends by itself, so the X first finishes it
     * and shows the session summary. Otherwise it leaves: back to the course, or on the course page back
     * to the start screen. Nothing is lost: every answer is already saved.
     */
    async exit() {
        this.stopAudio();
        this.clearBanner();
        const inplay = this.view === 'slide' || this.view === 'banner';
        if (inplay && this.state && !this.state.finished && this.state.mode !== MODE_TEST
                && this.state.repeatmode === REPEAT_ALL) {
            await this.finishAndShowEnd();
            return;
        }
        await this.leave();
    }

    /**
     * Leave the player: on the course page (or after "Last result") back to the start screen, else to the course.
     */
    async leave() {
        this.stopAudio();
        this.clearBanner();
        if (this.inline || this.endFromStart) {
            await this.showStart();
            return;
        }
        window.location.href = this.courseurl;
    }

    /**
     * Show the start screen again, with Continue / Start again / Last result as they now apply.
     */
    async showStart() {
        await Templates.replaceNodeContents(this.card, this.startHtml, '');
        this.view = 'start';
        this.current = null;
        this.endFromStart = false;
        const find = (selector) => this.card.querySelector(selector);
        // Only reachable after playing, so the activity is playable and the user may attempt it.
        toggle(find('[data-action="start"]'), !this.hasinprogress && this.canstartnew);
        toggle(find('[data-action="continue"]'), this.hasinprogress);
        toggle(find('[data-action="startagain"]'), this.hasinprogress && this.canstartnew);
        toggle(find('[data-region="noattemptsleft"]'), !this.hasinprogress && !this.canstartnew);
        toggle(find('[data-action="lastresult"]'), this.lastattemptid > 0);
        const previous = find('[data-region="previous"]');
        if (previous && this.summary && this.summary.finished) {
            if (this.summary.mode === MODE_TEST) {
                previous.textContent = this.summary.percent === null ? '' :
                    await str('startlastscore', Math.round(this.summary.percent));
            } else {
                previous.textContent = await str('startlastsession', {
                    right: this.summary.firsttrycorrect,
                    played: this.summary.slidesplayed,
                });
            }
            previous.hidden = previous.textContent === '';
        }
        this.focusTitle();
    }

    // End screen and review.

    /**
     * Show the end screen for this.summary.
     */
    async showEnd() {
        const summary = this.summary;
        if (!summary) {
            await this.leave();
            return;
        }
        const istest = summary.mode === MODE_TEST;
        const backkey = (this.inline || this.endFromStart) ? 'backtostart' : 'backtocourse';
        const [heading, exitlabel, backlabel] = await Promise.all([
            str(istest ? 'resultheading' : 'summaryheading'),
            str('exit'),
            str(backkey),
        ]);
        const context = {
            idprefix: this.idprefix,
            heading,
            istest,
            exitlabel,
            backlabel,
            thumbs: [],
            stats: [],
            showreview: false,
            showtryagain: false,
            showgoagain: false,
            showmissed: false,
        };
        let announcement = heading;
        if (istest) {
            const score = formatNumber(summary.score);
            const max = formatNumber(summary.maxscore);
            const percent = summary.percent === null ? '' : `${Math.round(summary.percent)}%`;
            context.scoretext = `${score} / ${max}`;
            context.percenttext = percent;
            context.scorelabel = await str('scorelabel', {score, max, percent});
            context.thumbs = await Promise.all(summary.slides.map(async(slide) => ({
                imageurl: this.images.get(slide.slideid) || '',
                correct: slide.correct,
                label: await str(slide.correct ? 'thumbcorrect' : 'thumbwrong', slide.number),
            })));
            context.showreview = summary.canreview;
            context.showtryagain = summary.canretry;
            announcement = `${heading}. ${context.scorelabel}`;
        } else {
            const [played, firsttry, rounds, streak] = await Promise.all([
                str('statslidesplayed'),
                str('statfirsttry'),
                str('statrounds'),
                str('statbeststreak'),
            ]);
            context.stats = [
                {label: played, value: summary.slidesplayed},
                {label: firsttry, value: summary.firsttrycorrect},
                {label: rounds, value: summary.rounds},
                {label: streak, value: summary.beststreak},
            ];
            context.showgoagain = summary.finished && summary.canretry;
            context.showmissed = summary.finished && summary.missedcount > 0;
            announcement = await str('summaryannouncement', {
                right: summary.firsttrycorrect,
                played: summary.slidesplayed,
            });
        }
        await this.render('mod_whakaharatau/end_screen', context);
        this.view = 'end';
        this.current = null;
        this.focusTitle();
        this.announce(announcement);
    }

    /**
     * Show the review of the attempt on the end screen.
     */
    async showReview() {
        if (!this.summary || !this.summary.canreview) {
            return;
        }
        if (!this.review) {
            const response = await call('mod_whakaharatau_finish_attempt', {
                attemptid: this.summary.attemptid,
                includereview: true,
            });
            this.review = response.review || [];
        }
        const istest = this.summary.mode === MODE_TEST;
        const items = await Promise.all(this.review.map(async(item) => {
            const count = item.slide.options.length;
            const labels = await this.rowLabels(count);
            const options = await Promise.all(item.slide.options.map(async(option) => {
                const chosen = item.chosen.includes(option.optionid);
                let state = chosen ? 'wrong' : 'none';
                if (option.optionid === item.correctoptionid) {
                    state = chosen ? 'correct' : 'revealed';
                }
                const markkeys = {correct: 'reviewcorrect', wrong: 'reviewwrong', revealed: 'reviewrevealed'};
                return {
                    optionid: option.optionid,
                    audiourl: option.audiourl || '',
                    playlabel: labels[option.number - 1].play,
                    pauselabel: labels[option.number - 1].pause,
                    state,
                    markicon: state === 'none' ? '' : STATE_ICONS[state],
                    marklabel: state === 'none' ? '' : await str(markkeys[state], option.number),
                    transcript: option.transcript || '',
                };
            }));
            return {
                slideid: item.slideid,
                label: istest ? await str('reviewslide', item.number) :
                    await str('reviewslideround', {number: item.number, round: item.round}),
                imageurl: item.slide.imageurl || '',
                alttext: item.slide.decorative ? '' : item.slide.alttext,
                prompthtml: item.slide.prompthtml || '',
                promptlang: item.slide.promptlang || '',
                options,
            };
        }));
        const [heading, exitlabel] = await Promise.all([str('reviewheading'), str('exit')]);
        await this.render('mod_whakaharatau/player_review', {idprefix: this.idprefix, heading, exitlabel, items});
        this.view = 'review';
        this.focusTitle();
        this.announce(heading);
    }

    // Audio.

    /**
     * The audio element of a URL, created on first use.
     *
     * @param {string} url audio URL
     * @returns {HTMLAudioElement}
     */
    getAudio(url) {
        let audio = this.audioCache.get(url);
        if (!audio) {
            audio = new Audio();
            audio.preload = 'auto';
            audio.src = url;
            audio.addEventListener('ended', () => this.audioStopped(audio));
            audio.addEventListener('pause', () => this.audioStopped(audio));
            audio.addEventListener('error', () => {
                if (this.playing && this.playing.audio === audio) {
                    this.audioStopped(audio);
                    this.announceString('audioerror').catch(Notification.exception);
                }
            });
            this.audioCache.set(url, audio);
        }
        return audio;
    }

    /**
     * Load the media of the slide on screen and the next one; forget the rest.
     *
     * @param {Object} data slide data on screen
     * @param {Object|null} nextdata slide data of the next slide
     */
    prefetch(data, nextdata) {
        const keep = new Set();
        [data, nextdata].forEach((slide) => {
            if (!slide) {
                return;
            }
            slide.options.forEach((option) => {
                if (option.audiourl) {
                    keep.add(option.audiourl);
                }
            });
            if (slide.promptaudiourl) {
                keep.add(slide.promptaudiourl);
            }
        });
        this.audioCache.forEach((audio, url) => {
            if (!keep.has(url) && !(this.playing && this.playing.audio === audio)) {
                audio.removeAttribute('src');
                audio.load();
                this.audioCache.delete(url);
            }
        });
        keep.forEach((url) => this.getAudio(url));
        if (nextdata && nextdata.imageurl) {
            const image = new Image();
            image.src = nextdata.imageurl;
        }
    }

    /**
     * Play or pause a clip. Starting a clip stops every other clip on the page.
     *
     * @param {HTMLElement} button the play button (data-audiourl)
     */
    togglePlay(button) {
        const url = button.dataset.audiourl;
        if (!url) {
            return;
        }
        const audio = this.getAudio(url);
        if (this.playing && this.playing.audio === audio && !audio.paused) {
            audio.pause();
            return;
        }
        this.stopAudio();
        document.dispatchEvent(new CustomEvent(PLAY_EVENT, {detail: {cmid: this.cmid}}));
        this.playing = {audio, button};
        this.setPlaying(button, true);
        if (audio.ended) {
            audio.currentTime = 0;
        }
        const playing = audio.play();
        if (playing) {
            playing.catch(() => this.audioStopped(audio));
        }
    }

    /**
     * Stop the clip playing, if any.
     */
    stopAudio() {
        if (!this.playing) {
            return;
        }
        const {audio, button} = this.playing;
        this.playing = null;
        this.setPlaying(button, false);
        audio.pause();
        try {
            audio.currentTime = 0;
        } catch (error) {
            // Not seekable yet: nothing to rewind.
        }
    }

    /**
     * A clip ended or was paused.
     *
     * @param {HTMLAudioElement} audio the audio element
     */
    audioStopped(audio) {
        if (this.playing && this.playing.audio === audio) {
            this.setPlaying(this.playing.button, false);
            this.playing = null;
        }
    }

    /**
     * Draw a play button as playing (larger, darker, named "Pause") or not.
     *
     * @param {HTMLElement} button the play button
     * @param {boolean} playing whether its clip plays
     */
    setPlaying(button, playing) {
        if (!button) {
            return;
        }
        button.classList.toggle('is-playing', playing);
        toggle(button.querySelector('[data-region="playlabel"]'), !playing);
        toggle(button.querySelector('[data-region="pauselabel"]'), playing);
    }

    // Rendering helpers.

    /**
     * Render a template into the card.
     *
     * @param {string} template template name
     * @param {Object} context template context
     */
    async render(template, context) {
        this.stopAudio();
        const {html, js} = await Templates.renderForPromise(template, context);
        Templates.replaceNodeContents(this.card, html, js);
    }

    /**
     * Move the focus to the heading of the screen in the card.
     */
    focusTitle() {
        const title = document.getElementById(`${this.idprefix}-title`);
        if (title) {
            title.focus();
        }
    }

    /**
     * Say one of the plugin's strings in the live region.
     *
     * @param {string} key string key
     */
    async announceString(key) {
        this.announce(await str(key));
    }

    /**
     * Say something in the player's polite live region.
     *
     * @param {string} message plain text
     */
    announce(message) {
        if (!this.announcer) {
            return;
        }
        this.announcer.textContent = '';
        window.requestAnimationFrame(() => {
            this.announcer.textContent = message;
        });
    }
}

/**
 * Start the player of a course module (once, however often the page asks).
 *
 * @param {number} cmid course module id
 */
export const init = (cmid) => {
    cmid = Number(cmid);
    if (started.has(cmid)) {
        return;
    }
    const root = document.querySelector(`[data-region="mod-whakaharatau-player"][data-cmid="${cmid}"]`);
    if (!root) {
        return;
    }
    started.add(cmid);
    new Player(root).init();
};
