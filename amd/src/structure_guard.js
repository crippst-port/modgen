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
 * Hold back each section change on a Flexible sections course until the last one is done.
 *
 * Flexible sections renumbers every section on a delete, duplicate, move, merge or add, and two
 * that overlap leave the course out of order. This covers the page while a change is saved, so
 * the next cannot start until it is done, and before each change asks the server whether
 * another (from another tab, another person or a Module Assistant build) is under way, waiting
 * for it to finish.
 *
 * Changes made through the course editor are caught by wrapping its dispatch(), which every
 * component calls through: Flexible sections replaces the editor's mutations when it loads, so
 * wrapping those would not last. Duplicate, and the actions Flexible sections still does with a
 * page load, are links, caught as they are clicked.
 *
 * @module     aiplacement_modgen/structure_guard
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getCurrentCourseEditor} from 'core_courseformat/courseeditor';
import Templates from 'core/templates';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getString} from 'core/str';

/** @type {Object<string, string>} Course editor mutations that change the section structure, and what each does. */
const GUARDED_MUTATIONS = {
    sectionDelete: 'delete',
    sectionMove: 'move',
    sectionMoveAfter: 'move',
    addSection: 'add',
    sectionMergeUp: 'merge',
    addSubSection: 'add',
    insertSubSection: 'add',
};

/** @type {Object<string, string>} URL parameters of the section changes made with a page load, and what each does. */
const GUARDED_LINK_PARAMS = {
    duplicatesection: 'duplicate',
    addchildsection: 'add',
    mergeup: 'merge',
    deletesection: 'delete',
    movesection: 'move',
};

/**
 * @type {Object<string, {working: string, workingmany: ?string, done: ?string, donemany: ?string}>}
 * The cover's heading for each kind of change while it is saved and once it is: one section, or
 * several ({$a} is how many). A change that loads a new page shows no tick, so needs no done heading.
 */
const ACTION_STRINGS = {
    'delete': {
        working: 'structureguard_delete', workingmany: 'structureguard_delete_many',
        done: 'structureguard_deleted', donemany: 'structureguard_deleted_many',
    },
    move: {
        working: 'structureguard_move', workingmany: 'structureguard_move_many',
        done: 'structureguard_moved', donemany: 'structureguard_moved_many',
    },
    add: {working: 'structureguard_add', workingmany: null, done: 'structureguard_added', donemany: null},
    merge: {working: 'structureguard_merge', workingmany: null, done: 'structureguard_merged', donemany: null},
    duplicate: {working: 'structureguard_duplicate', workingmany: null, done: null, donemany: null},
};

/** @type {{kind: ?string, count: number}} The change being made now: its kind (ACTION_STRINGS) and how many sections. */
let currentAction = {kind: null, count: 1};

/**
 * The cover's heading for the change being made now, or the general one.
 *
 * @param {string} which 'working' or 'done'
 * @param {string} fallback String identifier to use when the change is not one this knows
 * @returns {Promise<string>}
 */
const actionTitle = (which, fallback) => {
    const strings = ACTION_STRINGS[currentAction.kind];
    const many = currentAction.count > 1 ? strings?.[which + 'many'] : null;
    if (many) {
        return getString(many, 'aiplacement_modgen', currentAction.count);
    }
    return getString(strings?.[which] ?? fallback, 'aiplacement_modgen');
};

/**
 * What the cover says as it goes up, for a change, at once rather than as a promise.
 *
 * The cover is named by its heading the moment it takes focus, when a screen reader reads it out,
 * so the heading has to be right by then. The strings are sent with the page (lib.php), so are
 * there to read straight away; any that is not is left to setCoverText(), as before.
 *
 * @param {{kind: ?string, count: number}} action The change (ACTION_STRINGS)
 * @returns {{title: ?string, message: ?string}}
 */
const immediateText = (action) => {
    const string = (id, a) => {
        const value = M.util.get_string(id, 'aiplacement_modgen', a);
        // Not sent with the page: get_string() gives "[[id,component]]" rather than the string.
        return value.startsWith('[[') ? null : value;
    };
    const strings = ACTION_STRINGS[action.kind];
    const many = action.count > 1 ? strings?.workingmany : null;
    return {
        title: many ? string(many, action.count) : string(strings?.working ?? STATES.working.title),
        message: string(STATES.working.message),
    };
};

/** @type {number} How often to ask again while the course is busy, in milliseconds. */
const POLL_INTERVAL = 2000;

/** @type {number} How long to wait for the course before giving up, in milliseconds. */
const WAIT_LIMIT = 120000;

/** @type {number} How long a change that loads a new page may take to leave this one before it is uncovered, in ms. */
const NAVIGATION_LIMIT = 180000;

/** @type {number} The course ID. */
let courseId = 0;

/** @type {Promise} The change in progress, so the next waits for it. */
let queue = Promise.resolve();

/** @type {HTMLElement|null} The overlay covering the page while a change is made. */
let overlay = null;

/** @type {HTMLElement|null} The cover, rendered as the page loads, copied for each change. */
let coverTemplate = null;

/** @type {Element|null} What had focus when the cover went up, to go back to after. */
let returnFocus = null;

/**
 * @type {Element[]} Where else focus can go once the cover closes, nearest first, for when what
 * had focus has gone: the page control last used (a confirmation dialogue's button is gone once
 * it closes), then the section it was in and the sections around it (a deleted section is gone).
 */
let focusFallbacks = [];

/** @type {Element|null} The last thing on the page itself, not in a dialogue, to have had focus. */
let pageFocus = null;

/** @type {string} The heading the screen reader was last given, so it is not read out twice. */
let spokenTitle = '';

/** @type {string} The message the screen reader was last given, so it is not read out twice. */
let spokenMessage = '';

/** @type {HTMLElement[]} Elements made inert while the overlay is shown. */
let inertElements = [];

/** @type {boolean} The server said the guard is off for this course now: stop guarding. */
let switchedOff = false;

/** @type {boolean} The page is unloading for a change that loads a new one: start no other change. */
let pageLeaving = false;

/**
 * A change refused, with the string to tell the user why.
 */
class GuardRefusal extends Error {
    /**
     * @param {string} stringId String identifier in aiplacement_modgen
     */
    constructor(stringId) {
        super(stringId);
        this.stringId = stringId;
    }
}

/**
 * A change the server refused. The course editor has already told the user why, so this says nothing more.
 */
class ChangeFailed extends Error {}

/** @type {Object<string, {title: string, message: string}>} What the cover says in each state. */
const STATES = {
    working: {title: 'structureguard_working_title', message: 'structureguard_working'},
    stillworking: {title: 'structureguard_working_title', message: 'structureguard_stillworking'},
    waiting: {title: 'structureguard_waiting_title', message: 'structureguard_waiting'},
};

/** @type {number} How long a change runs before the cover shows, in milliseconds: quick ones do not flash it. */
const SHOW_DELAY = 500;

/** @type {number} Least time the spinner stays once shown, in milliseconds, so it never flashes. */
const MIN_VISIBLE = 800;

/** @type {number} When the spinner faded in (performance.now()), or 0 while it has not. */
let visibleAt = 0;

/** @type {number} How long a change runs before the cover says it is still working, in milliseconds. */
const STILL_WORKING_DELAY = 15000;

/** @type {number} How long the tick shows once a change is saved, in milliseconds: long enough to see it drawn and read. */
const DONE_HOLD = 1100;

/** @type {number} How long the cover takes to fade out after the tick, in milliseconds (matches styles.css). */
const LEAVE_DURATION = 200;

/** @type {number|null} Timer that fades the cover in. */
let showTimer = null;

/** @type {number|null} Timer that says the change is still working. */
let stillWorkingTimer = null;

/** @type {number} Counts uncovers, so a cover asked for before one is dropped. */
let coverGeneration = 0;

/** @type {Array<{element: Element, top: number, left: number}>} Scroll positions held while covered. */
let heldScroll = [];

/** @type {Set<string>} Keys that scroll the page. */
const SCROLL_KEYS = new Set([' ', 'Spacebar', 'PageUp', 'PageDown', 'End', 'Home', 'ArrowUp', 'ArrowDown', 'ArrowLeft',
    'ArrowRight']);

/**
 * Put the page back where it was if anything scrolls it while covered (dragging the scrollbar, say).
 */
const keepScroll = () => {
    heldScroll.forEach((held) => {
        if (held.element.scrollTop !== held.top || held.element.scrollLeft !== held.left) {
            held.element.scrollTop = held.top;
            held.element.scrollLeft = held.left;
        }
    });
};

/**
 * Stop the keys that scroll the page while it is covered.
 *
 * @param {KeyboardEvent} event The key press
 */
const stopScrollKeys = (event) => {
    if (SCROLL_KEYS.has(event.key) && !coverScrolls()) {
        event.preventDefault();
    }
};

/**
 * Whether the card is taller than the window, so the cover scrolls to show all of it.
 *
 * At high zoom on a short window (WCAG 1.4.10 Reflow) the card can overflow: the cover then
 * scrolls, by wheel, touch or the scrolling keys, rather than cut off what it says. The page
 * behind still cannot scroll: the cover does not pass a scroll on, and keepScroll() undoes any.
 *
 * @returns {boolean}
 */
const coverScrolls = () => !!overlay && overlay.scrollHeight > overlay.clientHeight;

/**
 * Stop the page behind the cover scrolling, without touching its layout.
 *
 * Setting overflow: hidden on the page would reset where it is scrolled to in themes that make
 * html and body 100% high, so nothing about the page is changed. A wheel or touch scroll lands
 * on the cover, which stops it; the scrolling keys are stopped; and anything else that scrolls
 * the page, such as its scrollbar, is undone. The document scrolls in most themes and #page in
 * some, so both are held.
 */
const lockScroll = () => {
    const candidates = [document.scrollingElement || document.documentElement, document.getElementById('page')];
    heldScroll = candidates
        .filter((element, index) => element && candidates.indexOf(element) === index)
        .map((element) => ({element, top: element.scrollTop, left: element.scrollLeft}));
    document.addEventListener('scroll', keepScroll, true);
    document.addEventListener('keydown', stopScrollKeys, true);
};

/**
 * Let the page scroll again.
 */
const unlockScroll = () => {
    document.removeEventListener('scroll', keepScroll, true);
    document.removeEventListener('keydown', stopScrollKeys, true);
    heldScroll = [];
};

/**
 * Stop a wheel or touch scroll that starts on the cover, unless the cover itself needs to scroll.
 *
 * @param {Event} event The wheel or touchmove event
 */
const stopScroll = (event) => {
    if (!coverScrolls()) {
        event.preventDefault();
    }
};

/**
 * The cover's heading and message for a state.
 *
 * While this page's own change is saved the heading names it ("Deleting 3 sections"); waiting
 * on another change, which this page cannot see, it stays general.
 *
 * @param {string} state One of STATES
 * @returns {Promise<{title: string, message: string}>}
 */
const stateText = async(state) => {
    const [title, message] = await Promise.all([
        state === 'waiting'
            ? getString(STATES[state].title, 'aiplacement_modgen')
            : actionTitle('working', STATES[state].title),
        getString(STATES[state].message, 'aiplacement_modgen'),
    ]);
    return {title, message};
};

/**
 * Whether Behat has made the cover unable to render, to check a change goes ahead without it.
 *
 * @returns {boolean}
 */
const coverBroken = () => !!(M.cfg.behatsiterunning && window.aiplacementModgenGuardBreakCover);

/**
 * Render the cover from its template.
 *
 * @param {{title: string, message: string}} text What it says
 * @returns {Promise<HTMLElement>}
 */
const renderCover = async(text) => {
    const {html} = await Templates.renderForPromise('aiplacement_modgen/structure_guard_overlay', text);
    const holder = document.createElement('div');
    // Safe: the HTML is a rendered template, its only variables language strings.
    holder.innerHTML = html;
    return holder.firstElementChild;
};

/**
 * Render the cover once, as the page loads, to copy for each change.
 *
 * So a change never waits on rendering it. If it cannot be rendered, changes get a plain cover.
 */
const prepareCover = async() => {
    try {
        coverTemplate = await renderCover(await stateText('working'));
    } catch (error) {
        coverTemplate = null;
    }
};

/**
 * Cover the page at once.
 *
 * Synchronous, so nothing on the page can be clicked from the moment a change is asked for: the
 * cover is a copy of the one rendered as the page loaded, or a plain one if that is not ready. It
 * stops clicks at once, but only fades in once the change has taken SHOW_DELAY, so a quick change
 * does not flash it.
 *
 * It is a modal dialog, and takes focus straight away with its heading already naming the change,
 * so a screen reader says what is happening as it starts, even for a change too quick to see or
 * one that loads a new page. Focus on an unnamed element instead had Narrator read out the whole
 * window, and never get to what the cover said.
 *
 * @param {{kind: ?string, count: number}} action The change (ACTION_STRINGS)
 */
const attachCover = (action) => {
    if (overlay) {
        return;
    }
    overlay = coverTemplate && !coverBroken() ? coverTemplate.cloneNode(true) : plainCover();
    const text = immediateText(action);
    const title = overlay.querySelector('[data-region="title"]');
    const message = overlay.querySelector('[data-region="message"]');
    if (title && text.title) {
        title.textContent = text.title;
    }
    if (message && text.message) {
        message.textContent = text.message;
    }
    if (!title) {
        overlay.setAttribute('aria-label', text.title || '');
    }
    // Read out as the cover takes focus, by its name. The message waits for the card to show.
    spokenTitle = coverTitle();
    spokenMessage = '';

    // Where the user was, to go back to once the cover closes, and where else to go if it is gone.
    returnFocus = document.activeElement;
    focusFallbacks = fallbacksFor([returnFocus, pageFocus]);

    // Nothing behind the cover can be reached by keyboard or screen reader either, except the
    // announce region: an inert live region is not read out.
    const announcer = getAnnouncer();
    announcer.textContent = '';
    inertElements = [...document.body.children].filter((element) => !element.inert && element !== announcer);
    inertElements.forEach((element) => {
        element.inert = true;
    });
    overlay.addEventListener('wheel', stopScroll, {passive: false});
    overlay.addEventListener('touchmove', stopScroll, {passive: false});
    lockScroll();
    document.body.appendChild(overlay);
    // The cover, not its card: the card is hidden until it fades in, and a hidden element cannot
    // take focus, which would leave focus on the page now inert behind the cover.
    overlay.focus({preventScroll: true});
    showTimer = setTimeout(reveal, SHOW_DELAY);
};

/**
 * A cover with no card, for when the template is not ready: it stops clicks, and is named by
 * attachCover() so a screen reader still hears what is happening.
 *
 * @returns {HTMLElement}
 */
const plainCover = () => {
    const cover = document.createElement('div');
    cover.className = 'aiplacement-modgen-structure-guard';
    cover.dataset.region = 'aiplacement-modgen-structure-guard';
    cover.tabIndex = -1;
    cover.setAttribute('role', 'dialog');
    cover.setAttribute('aria-modal', 'true');
    return cover;
};

/**
 * The cover's heading as it is now, which is also its name.
 *
 * @returns {string}
 */
const coverTitle = () => {
    const title = overlay?.querySelector('[data-region="title"]');
    return title ? title.textContent : overlay?.getAttribute('aria-label') || '';
};

/**
 * The cover's message as it is now: none once done, when the card shows only its heading.
 *
 * @returns {string}
 */
const coverMessage = () => {
    const message = overlay?.querySelector('[data-region="message"]');
    return message && !overlay.classList.contains('is-done') ? message.textContent : '';
};

/** @type {string} The control in a section that focus goes to when it goes back to the section. */
const SECTION_FOCUS = '[data-for="section_title"] a[href], [data-for="section_title"] [tabindex="0"], a[href], button';

/**
 * Where focus could go once the cover closes, nearest first.
 *
 * @param {Array<Element|null>} elements What had focus, nearest first
 * @returns {Element[]}
 */
const fallbacksFor = (elements) => {
    const found = [];
    elements.forEach((element) => {
        if (!element || element === document.body) {
            return;
        }
        found.push(element);
        const section = element.closest('[data-for="section"]');
        if (section) {
            found.push(
                section,
                section.previousElementSibling,
                section.nextElementSibling,
                section.parentElement?.closest('[data-for="section"]'),
            );
        }
    });
    return found.filter((element, index) => element && found.indexOf(element) === index);
};

/**
 * Put focus back where the user was, or as near it as is still on the page.
 *
 * A section is focused by its heading link (or its first control). Left on the body instead,
 * focus has Narrator read out the whole window.
 */
const restoreFocus = () => {
    const candidates = [returnFocus, ...focusFallbacks];
    returnFocus = null;
    focusFallbacks = [];
    for (const candidate of candidates) {
        if (!candidate || candidate === document.body || !document.contains(candidate)) {
            continue;
        }
        const target = candidate.matches('[data-for="section"]') ? candidate.querySelector(SECTION_FOCUS) : candidate;
        if (!target) {
            continue;
        }
        target.focus({preventScroll: true});
        if (document.activeElement === target) {
            return;
        }
    }
};

/**
 * The live region that reads out what the card says, added to the page once, as it loads.
 *
 * Not part of the cover: a screen reader only reliably reads out a live region that was already on
 * the page before its text changed, and the cover is added the moment a change starts, with its
 * text often set within a few milliseconds (waiting on another change, say). Narrator in Edge read
 * nothing out when the region came with the cover. It stays on the page between changes, so what
 * was last said is still finished after the cover has gone.
 *
 * @returns {HTMLElement}
 */
const getAnnouncer = () => {
    let announcer = document.querySelector('[data-region="aiplacement-modgen-structure-guard-announce"]');
    if (!announcer) {
        announcer = document.createElement('div');
        announcer.className = 'sr-only';
        announcer.dataset.region = 'aiplacement-modgen-structure-guard-announce';
        announcer.setAttribute('role', 'status');
        announcer.setAttribute('aria-live', 'polite');
        announcer.setAttribute('aria-atomic', 'true');
        document.body.appendChild(announcer);
    }
    return announcer;
};

/**
 * Fade the card in, and read out what it says.
 */
const reveal = () => {
    if (!overlay) {
        return;
    }
    overlay.classList.add('is-visible');
    visibleAt = visibleAt || performance.now();
    announceCard();
};

/**
 * Read out what the card says that has not been read out yet (WCAG 4.1.3 Status Messages).
 *
 * The heading is read as the cover takes focus, as its name. The card's own text is not a live
 * region, so the rest goes through the announce region (getAnnouncer()), on the page since it
 * loaded: the message once the card shows, as it is shown, and any heading or message that changes
 * after that (waiting on another change, saved). Only the part that changed is read out.
 */
const announceCard = () => {
    if (!overlay) {
        return;
    }
    const title = coverTitle();
    const message = visibleAt ? coverMessage() : '';
    const say = [];
    if (title && title !== spokenTitle) {
        say.push(title);
        spokenTitle = title;
    }
    if (message && message !== spokenMessage) {
        say.push(message);
        spokenMessage = message;
    }
    if (say.length) {
        getAnnouncer().textContent = say.join(' ');
    }
};

/**
 * Say on the cover what is happening.
 *
 * Never throws: what the cover says is no reason to hold a change back, so if its text or template
 * cannot be had the change goes ahead behind the cover as it is. A change still running after
 * STILL_WORKING_DELAY says so.
 *
 * @param {string} state One of STATES
 * @returns {Promise<void>}
 */
const setCoverText = async(state) => {
    const generation = coverGeneration;
    clearTimeout(stillWorkingTimer);
    stillWorkingTimer = null;
    if (state === 'working') {
        stillWorkingTimer = setTimeout(() => setCoverText('stillworking'), STILL_WORKING_DELAY);
    }
    try {
        if (coverBroken()) {
            throw new Error('The cover was made unable to render.');
        }
        const text = await stateText(state);
        if (generation !== coverGeneration || !overlay) {
            return;
        }
        if (!overlay.querySelector('[data-region="title"]')) {
            // A plain cover, put up before the template was ready: give it the card now.
            const rendered = await renderCover(text);
            if (generation !== coverGeneration || !overlay) {
                return;
            }
            overlay.appendChild(rendered.querySelector('[data-region="card"]'));
            overlay.removeAttribute('aria-label');
            overlay.setAttribute('aria-labelledby', rendered.getAttribute('aria-labelledby'));
        }
        overlay.querySelector('[data-region="title"]').textContent = text.title;
        overlay.querySelector('[data-region="message"]').textContent = text.message;
        if (state === 'waiting') {
            // Waiting on someone else may be long: show it now rather than after SHOW_DELAY.
            reveal();
        } else {
            announceCard();
        }
    } catch (error) {
        // The cover keeps what it said before, or says nothing; the change goes ahead regardless.
    }
};

/**
 * Uncover the page, and put the user back where they were.
 */
const hideOverlay = () => {
    coverGeneration++;
    visibleAt = 0;
    clearTimeout(showTimer);
    clearTimeout(stillWorkingTimer);
    showTimer = null;
    stillWorkingTimer = null;
    if (!overlay) {
        return;
    }
    overlay.remove();
    overlay = null;
    unlockScroll();
    inertElements.forEach((element) => {
        element.inert = false;
    });
    inertElements = [];
    // Back to where the user was, or the nearest place still there (a deleted section's menu is not).
    restoreFocus();
};

/**
 * Show that the change is saved, then close the cover.
 *
 * The spinner gives way to a tick in a circle, drawn in, with "Changes saved", closing the loop on
 * a wait the user saw. A change too quick for the spinner to have shown gets no tick: the page
 * updating is confirmation enough. It closes itself after DONE_HOLD, or at once on a click or key press.
 *
 * @returns {Promise<void>} Resolves once the cover has closed
 */
const showDone = async() => {
    if (!overlay) {
        return;
    }
    // Too quick for the spinner to have shown: the page updating is all the confirmation needed.
    if (!visibleAt) {
        hideOverlay();
        return;
    }
    const generation = coverGeneration;
    clearTimeout(showTimer);
    clearTimeout(stillWorkingTimer);
    showTimer = null;
    stillWorkingTimer = null;

    const title = await actionTitle('done', 'structureguard_done_title');
    if (generation !== coverGeneration || !overlay) {
        return;
    }
    // A spinner that has faded in stays at least MIN_VISIBLE before the tick, so it is never a flash.
    if (visibleAt) {
        const left = MIN_VISIBLE - (performance.now() - visibleAt);
        if (left > 0) {
            await new Promise((resolve) => setTimeout(resolve, left));
            if (generation !== coverGeneration || !overlay) {
                return;
            }
        }
    }
    const cover = overlay;
    if (!cover.querySelector('[data-region="title"]')) {
        // A plain cover never got its card, so has no tick to show.
        hideOverlay();
        return;
    }
    cover.querySelector('[data-region="title"]').textContent = title;
    cover.classList.add('is-visible', 'is-done');
    announceCard();

    // Wait out the hold, or less if the user clicks or presses a key: they are in control.
    await new Promise((resolve) => {
        const timer = setTimeout(finish, DONE_HOLD);
        /**
         * Stop waiting.
         */
        function finish() {
            clearTimeout(timer);
            cover.removeEventListener('click', finish);
            document.removeEventListener('keydown', finish, true);
            resolve();
        }
        cover.addEventListener('click', finish);
        document.addEventListener('keydown', finish, true);
    });
    if (generation !== coverGeneration) {
        return;
    }

    cover.classList.add('is-leaving');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    await new Promise((resolve) => setTimeout(resolve, reduced ? 0 : LEAVE_DURATION));
    if (generation === coverGeneration) {
        hideOverlay();
    }
};

/**
 * Ask the server whether another change to the course's sections is under way.
 *
 * The guard is a safety net, so a check that fails (the request is lost, or the server answers
 * with an error) lets the change go ahead rather than stopping the user editing. The change still
 * waits for this page's own last one; only another tab's or person's is then not waited for. If
 * the session has ended, the change itself fails and says so.
 *
 * @returns {Promise<boolean>} True when busy
 */
const isBusy = async() => {
    let data;
    try {
        const response = await fetch(M.cfg.wwwroot + '/ai/placement/modgen/ajax/structure_busy.php?' + new URLSearchParams({
            courseid: courseId,
            sesskey: M.cfg.sesskey,
        }));
        data = await response.json();
    } catch (error) {
        return false;
    }
    if (!data || !data.success) {
        return false;
    }
    if (data.guarded === false) {
        // Switched off since this page loaded: changes from now on go straight through.
        switchedOff = true;
        return false;
    }
    return !!data.busy;
};

/**
 * Wait until no other change to the course's sections is under way.
 *
 * Waiting on someone else is not this page's work in progress, so the pending operation is let
 * go meanwhile, and taken again once the course is free: anything that waits for the page to
 * settle, such as Behat, is not held up by another person's change.
 *
 * @param {Object} work Holds the pending operation for this change, in work.pending
 * @returns {Promise<void>}
 */
const waitUntilFree = async(work) => {
    // Behat sets a shorter limit, so a test can see the wait give up without waiting two minutes.
    const limit = (M.cfg.behatsiterunning && window.aiplacementModgenGuardWaitLimit) || WAIT_LIMIT;
    const giveup = Date.now() + limit;
    let told = false;
    while (await isBusy()) {
        if (Date.now() >= giveup) {
            throw new GuardRefusal('structureguard_timedout');
        }
        if (!told) {
            await setCoverText('waiting');
            work.pending.resolve();
            work.pending = null;
            told = true;
        }
        await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL));
    }
    if (told) {
        work.pending = new Pending('aiplacement_modgen/structure_guard');
        await setCoverText('working');
    }
};

/**
 * Let a change the server refused pass quietly.
 *
 * The course editor has shown the error already, and its own dispatch() never rejects, so
 * neither should this page's.
 *
 * @param {Error} error What the change failed with
 * @returns {undefined}
 * @throws {Error} Anything other than a refused change
 */
const absorbChangeFailed = (error) => {
    if (error instanceof ChangeFailed) {
        return undefined;
    }
    throw error;
};

/**
 * Follow a link that makes a section change, resolving only if the page does not go after all.
 *
 * While the page goes the change stays under way, holding every later change back: the page is
 * leaving, so no change from it should start. Resolves false if it stays after all (the browser
 * reports the navigation failed, it is still here after NAVIGATION_LIMIT, as when the user stops
 * it, or it comes back from the history), so the page is uncovered and can be used again.
 *
 * @param {string} href Where the link goes
 * @returns {Promise<boolean>}
 */
const navigate = (href) => new Promise((resolve) => {
    const limit = (M.cfg.behatsiterunning && window.aiplacementModgenGuardNavigationLimit) || NAVIGATION_LIMIT;
    const stayed = () => {
        clearTimeout(timer);
        window.removeEventListener('pagehide', leaving);
        window.removeEventListener('pageshow', returned);
        window.navigation?.removeEventListener('navigateerror', stayed);
        pageLeaving = false;
        resolve(false);
    };
    // Once the page goes, it is gone: the limit no longer applies, and nothing else starts.
    const leaving = () => {
        clearTimeout(timer);
        pageLeaving = true;
    };
    // Back from the history, the page is as it was left, covered and waiting: let it go on.
    const returned = (event) => {
        if (event.persisted) {
            stayed();
        }
    };
    const timer = setTimeout(stayed, limit);
    window.addEventListener('pagehide', leaving);
    window.addEventListener('pageshow', returned);
    window.navigation?.addEventListener('navigateerror', stayed);
    // Behat can make the page stay, as a navigation the user stops would.
    if (!(M.cfg.behatsiterunning && window.aiplacementModgenGuardStayOnPage)) {
        window.location.assign(href);
    }
});

/**
 * Make one section change, after the one before it and once the course is free.
 *
 * The page is covered at once, before anything waits, so a second click cannot slip in while
 * this one is checked. What the cover says is filled in alongside the check, not before it,
 * and never holds the change back.
 *
 * @param {Function} change Makes the change; resolves when it is saved. For a change that loads
 *     a new page, resolves whether the page left (navigate()).
 * @param {boolean} leavesPage The change loads a new page, which shows its result
 * @param {{kind: ?string, count: number}} action What the change does (ACTION_STRINGS) and to how many sections
 * @returns {Promise<*>} What the change resolves with
 */
const guard = (change, leavesPage = false, action = {kind: null, count: 1}) => {
    // The page is going for an earlier change: one asked for now would run as it unloads.
    if (pageLeaving) {
        return Promise.resolve();
    }
    if (switchedOff) {
        return Promise.resolve().then(change).catch(absorbChangeFailed);
    }
    attachCover(action);
    const run = async() => {
        if (pageLeaving) {
            return undefined;
        }
        currentAction = action;
        // The change before this one took its cover down when it finished.
        attachCover(action);
        const work = {pending: new Pending('aiplacement_modgen/structure_guard')};
        let covered = false;
        try {
            const text = setCoverText('working');
            await waitUntilFree(work);
            if (switchedOff) {
                hideOverlay();
                return await change();
            }
            if (leavesPage) {
                const leaving = change();
                // Behat follows the page load itself, so this page has nothing left to settle.
                work.pending.resolve();
                work.pending = null;
                // Stay covered while the page goes; uncover if it does not go after all.
                covered = await leaving;
                return undefined;
            }
            const result = await change();
            await text;
            await showDone();
            return result;
        } catch (error) {
            covered = false;
            if (error instanceof ChangeFailed) {
                // The course editor has shown the error already: close the cover, with no tick.
                return undefined;
            }
            if (error instanceof GuardRefusal) {
                Notification.addNotification({
                    message: await getString(error.stringId, 'aiplacement_modgen'),
                    type: 'error',
                });
                return undefined;
            }
            throw error;
        } finally {
            if (!covered) {
                hideOverlay();
            }
            if (work.pending) {
                work.pending.resolve();
            }
        }
    };
    const result = queue.then(run);
    queue = result.catch(() => undefined);
    return result;
};

/**
 * Send a change through the course editor, and say whether the server refused it.
 *
 * The course editor catches a refused change itself: it shows the error and unlocks the page,
 * and its dispatch() then resolves as if all were well. So for the length of this one change its
 * mutation is wrapped to see the error on its way to the course editor, which still handles it
 * exactly as before. Changes go one at a time, so only one mutation is ever wrapped.
 *
 * @param {Object} editor The course editor
 * @param {Function} dispatch Its own dispatch(), bound to it
 * @param {string} actionName The mutation
 * @param {Array} params Its parameters
 * @returns {Promise<void>} Resolves when the change is saved
 * @throws {ChangeFailed} If the server refused it
 */
const dispatchReportingFailure = async(editor, dispatch, actionName, params) => {
    const mutations = editor.mutations;
    const mutation = mutations?.[actionName];
    if (typeof mutation !== 'function') {
        return dispatch(actionName, ...params);
    }
    const own = Object.prototype.hasOwnProperty.call(mutations, actionName);
    let failed = false;
    mutations[actionName] = async function(...args) {
        try {
            return await mutation.apply(this, args);
        } catch (error) {
            failed = true;
            throw error;
        }
    };
    try {
        await dispatch(actionName, ...params);
    } finally {
        if (own) {
            mutations[actionName] = mutation;
        } else {
            delete mutations[actionName];
        }
    }
    if (failed) {
        throw new ChangeFailed();
    }
    return undefined;
};

/**
 * Wrap the course editor's dispatch() so section changes wait for each other.
 */
const guardCourseEditor = () => {
    const editor = getCurrentCourseEditor();
    if (!editor || editor.aiplacementModgenGuarded) {
        return;
    }
    const dispatch = editor.dispatch.bind(editor);
    editor.dispatch = (actionName, ...params) => {
        const kind = GUARDED_MUTATIONS[actionName];
        if (!kind) {
            return dispatch(actionName, ...params);
        }
        // Delete and move take a list of section ids, which a bulk edit fills with several.
        const count = Array.isArray(params[0]) ? Math.max(1, params[0].length) : 1;
        return guard(() => dispatchReportingFailure(editor, dispatch, actionName, params), false, {kind, count});
    };
    editor.aiplacementModgenGuarded = true;
};

/**
 * The section change a link makes with a page load, or null when it makes none.
 *
 * @param {HTMLAnchorElement} link The link
 * @returns {?string} The kind of change (ACTION_STRINGS)
 */
const linkAction = (link) => {
    let url;
    try {
        url = new URL(link.href, window.location.href);
    } catch (error) {
        return null;
    }
    if (url.origin !== window.location.origin || !url.searchParams.has('sesskey')) {
        return null;
    }
    const param = Object.keys(GUARDED_LINK_PARAMS).find((name) => url.searchParams.has(name));
    return param ? GUARDED_LINK_PARAMS[param] : null;
};

/**
 * Hold back the section changes made by following a link.
 */
const guardLinks = () => {
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
            return;
        }
        const link = event.target.closest('a[href]');
        const kind = link ? linkAction(link) : null;
        if (switchedOff || !kind) {
            return;
        }
        // Menu items the course editor or Flexible sections handle in the page are left to them:
        // their link is only for browsers without JavaScript, and the change reaches dispatch().
        if (link.dataset.action || link.dataset.actionFlexsections) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const href = link.href;
        // The page stays covered, and the next change held back, until the next page loads.
        guard(() => navigate(href), true, {kind, count: 1});
    }, true);

    // Coming back to this page from the history shows it as it was left: covered.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            hideOverlay();
        }
    });
};

/**
 * Start holding back section changes on the course page.
 *
 * @param {number} courseid Course ID
 */
export const init = (courseid) => {
    if (courseId) {
        return;
    }
    courseId = courseid;
    // Now, not with the first change, so it is on the page well before anything is said in it.
    getAnnouncer();
    // The control on the page a change came from, which a confirmation dialogue's button is not.
    document.addEventListener('focusin', (event) => {
        if (!overlay && !event.target.closest('.modal, [role="dialog"], [data-region="aiplacement-modgen-structure-guard"]')) {
            pageFocus = event.target;
        }
    }, true);
    guardCourseEditor();
    guardLinks();
    prepareCover();
};
