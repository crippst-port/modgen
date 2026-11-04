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
 * Collapsing, adding and removing themes and weeks on the structure preview.
 *
 * The preview (templates/prompt_preview.mustache) renders its buttons hidden, and a blank theme and
 * week in <template> elements whose field names carry __KEY__. This shows the buttons, folds cards,
 * and adds a copy of a blank card with a new key: n1, n2... for a theme or a week of a weekly
 * structure, and the theme's key, _, and n1... for a week in a theme. classes/local/structure_edits.php
 * creates those themes and weeks when the structure is approved. Without this script the preview
 * still works, with nothing to add or fold.
 *
 * @module     aiplacement_modgen/preview_editor
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    root: '.aiplacement-modgen-preview',
    card: '.aiplacement-modgen-preview__card',
    body: '.aiplacement-modgen-preview__card-body',
    weeks: '.aiplacement-modgen-preview__weeks',
    structure: '.aiplacement-modgen-preview__structure',
    control: '[data-preview-control]',
};

/** @var {number} Last new key handed out on this page; keys only need to be unique within one form. */
let lastkey = 0;

/**
 * Fold or unfold a card.
 *
 * @param {HTMLElement} card Theme or week card
 * @param {boolean} expanded Whether to show its body
 */
const setExpanded = (card, expanded) => {
    const toggle = card.querySelector(':scope > .aiplacement-modgen-preview__card-head [data-action="toggle"]');
    const body = card.querySelector(':scope > ' + SELECTORS.body);
    if (!toggle || !body) {
        return;
    }
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    body.hidden = !expanded;
    card.classList.toggle('aiplacement-modgen-preview__card--collapsed', !expanded);
};

/**
 * Show the theme and week counts for what is now on the page.
 *
 * @param {HTMLElement} root The preview
 */
const updateCounts = (root) => {
    const themes = root.querySelector('[data-count="themes"]');
    const weeks = root.querySelector('[data-count="weeks"]');
    if (themes) {
        themes.textContent = root.querySelectorAll(SELECTORS.structure + ' .aiplacement-modgen-preview__card--theme').length;
    }
    if (weeks) {
        weeks.textContent = root.querySelectorAll(SELECTORS.structure + ' .aiplacement-modgen-preview__card--week').length;
    }
};

/**
 * Copy a blank card from its <template>, with a new key.
 *
 * @param {HTMLElement} root The preview
 * @param {string} kind theme or week
 * @param {string} key Key for its fields
 * @return {HTMLElement|null} The card
 */
const newCard = (root, kind, key) => {
    const template = root.querySelector('template[data-preview-template="' + kind + '"]');
    if (!template) {
        return null;
    }
    const holder = document.createElement('div');
    holder.innerHTML = template.innerHTML.split('__KEY__').join(key);
    const card = holder.firstElementChild;
    card.querySelectorAll(SELECTORS.control).forEach((control) => {
        control.hidden = false;
    });
    return card;
};

/**
 * Add a week to a theme, or to the end of a weekly structure.
 *
 * @param {HTMLElement} root The preview
 * @param {HTMLElement} button The Add week button
 */
const addWeek = (root, button) => {
    const themekey = button.dataset.themekey || '';
    const key = (themekey === '' ? '' : themekey + '_') + 'n' + (++lastkey);
    const card = newCard(root, 'week', key);
    if (!card) {
        return;
    }
    button.before(card);
    updateCounts(root);
    card.querySelector('input[type="text"]').focus();
};

/**
 * Add an empty theme at the end.
 *
 * @param {HTMLElement} root The preview
 * @param {HTMLElement} button The Add theme button
 */
const addTheme = (root, button) => {
    const card = newCard(root, 'theme', 'n' + (++lastkey));
    if (!card) {
        return;
    }
    button.before(card);
    updateCounts(root);
    card.querySelector('input[type="text"]').focus();
};

/**
 * Collapse or expand every card, by what the button says.
 *
 * @param {HTMLElement} root The preview
 * @param {HTMLElement} button The Collapse all / Expand all button
 */
const toggleAll = (root, button) => {
    const expand = button.dataset.state === 'collapsed';
    root.querySelectorAll(SELECTORS.structure + ' ' + SELECTORS.card).forEach((card) => setExpanded(card, expand));
    button.dataset.state = expand ? 'expanded' : 'collapsed';
    button.textContent = expand ? button.dataset.collapselabel : button.dataset.expandlabel;
};

/**
 * Turn on the preview's controls.
 *
 * @param {HTMLElement} container Anything holding the preview: the modal body, or the page
 */
export const enhance = (container) => {
    const root = container ? container.querySelector(SELECTORS.root) : null;
    if (!root || root.dataset.previewEnhanced) {
        return;
    }
    root.dataset.previewEnhanced = '1';
    root.querySelectorAll(SELECTORS.control).forEach((control) => {
        // Not in the <template> copies: those are shown when a copy is added.
        if (!control.closest('template')) {
            control.hidden = false;
        }
    });

    // Enter in a title would submit the approval form, which builds the course: it does nothing here.
    root.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.matches('input[type="text"]')) {
            e.preventDefault();
        }
    });

    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action]');
        if (!button || !root.contains(button)) {
            return;
        }
        const action = button.dataset.action;
        if (action === 'toggle') {
            e.preventDefault();
            const card = button.closest(SELECTORS.card);
            setExpanded(card, button.getAttribute('aria-expanded') !== 'true');
        } else if (action === 'addweek') {
            e.preventDefault();
            addWeek(root, button);
        } else if (action === 'addtheme') {
            e.preventDefault();
            addTheme(root, button);
        } else if (action === 'remove') {
            e.preventDefault();
            button.closest(SELECTORS.card).remove();
            updateCounts(root);
        } else if (action === 'toggleall') {
            e.preventDefault();
            toggleAll(root, button);
        }
    });
};

/**
 * Turn on the controls of a preview shown as a page rather than in the modal.
 */
export const init = () => enhance(document);
