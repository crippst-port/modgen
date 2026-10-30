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
 * Steppers, choice cards and a live summary for the Quick Add and From prompt forms.
 *
 * The forms mark their count fields with data-modgen-stepper, their choices with data-modgen-choice,
 * and add an empty summary line (see classes/local/quick_add_ui.php). This adds the - and + buttons
 * and the cards, and fills in the summary; the fields themselves, and what the form submits, are unchanged.
 *
 * @module     aiplacement_modgen/quick_add_form
 * @copyright  2026 Tom Cripps <tom.cripps@port.ac.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';

/**
 * Read a field's number, or null when it is not a whole number.
 *
 * @param {HTMLInputElement} input Count field
 * @returns {number|null}
 */
const readNumber = (input) => {
    const value = input.value.trim();
    return /^\d+$/.test(value) ? parseInt(value, 10) : null;
};

/**
 * Turn a count field into a stepper: a - button, the field, a + button.
 *
 * The buttons are marked data-keep-in-form so they are not moved to the modal footer.
 *
 * @param {HTMLInputElement} input Count field
 */
const addStepper = async(input) => {
    const min = parseInt(input.dataset.min, 10) || 1;
    const max = parseInt(input.dataset.max, 10) || min;
    // Look in the form, not the document: the modal may not be on the page yet.
    const label = input.form?.querySelector(`label[for="${input.id}"]`)?.textContent.trim() || '';
    const [decrease, increase] = await Promise.all([
        getString('stepperdecrease', 'aiplacement_modgen', label),
        getString('stepperincrease', 'aiplacement_modgen', label),
    ]);

    const makeButton = (text, ariaLabel, step) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'aiplacement-modgen-qa__step';
        button.textContent = text;
        button.setAttribute('aria-label', ariaLabel);
        button.title = ariaLabel;
        button.setAttribute('aria-controls', input.id);
        button.dataset.keepInForm = '';
        button.addEventListener('click', () => {
            const current = readNumber(input) ?? min;
            input.value = String(Math.min(max, Math.max(min, current + step)));
            input.dispatchEvent(new Event('input', {bubbles: true}));
        });
        return button;
    };

    const minus = makeButton('−', decrease, -1);
    const plus = makeButton('+', increase, 1);
    const refresh = () => {
        const current = readNumber(input);
        minus.disabled = current !== null && current <= min;
        plus.disabled = current !== null && current >= max;
    };

    const wrapper = document.createElement('div');
    wrapper.className = 'aiplacement-modgen-qa__stepper';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.append(minus, input, plus);
    input.addEventListener('input', refresh);
    input.addEventListener('change', refresh);
    refresh();
};

/**
 * Fill in the summary line from the current counts, or hide it while a count is not valid.
 *
 * @param {HTMLElement} root Form container
 * @param {HTMLElement} summary Summary line
 */
const updateSummary = async(root, summary) => {
    const count = (name) => {
        const input = root.querySelector(`input[name="${name}"]`);
        return input ? readNumber(input) : null;
    };
    const perweek = parseInt(summary.dataset.sectionsperweek, 10);

    let text = null;
    if (summary.dataset.kind === 'themes') {
        const themes = count('themecount');
        const weeks = count('weeksperTheme');
        if (themes && weeks) {
            text = await getString('quickaddsummarythemes', 'aiplacement_modgen', {
                themes: themes,
                weeks: themes * weeks,
                sections: themes + themes * weeks * perweek,
            });
        }
    } else {
        const weeks = count('weekcount');
        if (weeks) {
            text = await getString('quickaddsummaryweeks', 'aiplacement_modgen', {
                weeks: weeks,
                sections: weeks * perweek,
            });
        }
    }

    summary.hidden = text === null;
    summary.textContent = text ?? '';
};

/**
 * Show a select as a group of choice cards: real radio buttons, labelled by the field's label.
 *
 * The select stays in the form, hidden, and the cards set it, so the form submits what it did before.
 *
 * @param {HTMLSelectElement} select Select marked with data-modgen-choice
 */
const addChoiceCards = (select) => {
    let choices;
    try {
        choices = JSON.parse(select.dataset.modgenChoice);
    } catch (e) {
        return;
    }

    const group = document.createElement('div');
    group.className = 'aiplacement-modgen-qa__choices';
    group.setAttribute('role', 'radiogroup');
    const label = select.form?.querySelector(`label[for="${select.id}"]`);
    if (label?.id) {
        group.setAttribute('aria-labelledby', label.id);
    }

    Array.from(select.options).forEach((option) => {
        const choice = choices[option.value] || {};
        const card = document.createElement('label');
        card.className = 'aiplacement-modgen-qa__choice';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = `${select.name}_choice`;
        radio.value = option.value;
        radio.checked = option.selected;
        radio.className = 'aiplacement-modgen-qa__choice-input';
        card.classList.toggle('is-selected', radio.checked);
        radio.addEventListener('change', () => {
            select.value = option.value;
            select.dispatchEvent(new Event('change', {bubbles: true}));
            group.querySelectorAll('.aiplacement-modgen-qa__choice').forEach((other) => {
                other.classList.toggle('is-selected', other === card);
            });
        });

        const icon = document.createElement('span');
        icon.className = 'aiplacement-modgen-qa__icon';
        icon.setAttribute('aria-hidden', 'true');
        const glyph = document.createElement('i');
        glyph.className = `fa fa-${choice.icon || 'circle'}`;
        icon.append(glyph);

        const text = document.createElement('span');
        text.className = 'aiplacement-modgen-qa__choice-text';
        const title = document.createElement('span');
        title.className = 'aiplacement-modgen-qa__choice-title';
        title.textContent = option.textContent.trim();
        text.append(title);
        if (choice.description) {
            const description = document.createElement('span');
            description.className = 'aiplacement-modgen-qa__choice-description';
            description.textContent = choice.description;
            text.append(description);
        }

        card.append(radio, icon, text);
        group.append(card);
    });

    select.hidden = true;
    select.parentNode.insertBefore(group, select);
    // The label pointed at the select, which is now hidden; it names the group instead.
    label?.removeAttribute('for');
};

/**
 * Add steppers, choice cards and the live summary to a Quick Add form, once.
 *
 * @param {HTMLElement} root Element containing the form (the modal body)
 * @returns {Promise}
 */
export const enhance = async(root) => {
    if (!root || root.dataset.modgenQaEnhanced) {
        return;
    }
    root.dataset.modgenQaEnhanced = '1';

    root.querySelectorAll('select[data-modgen-choice]').forEach(addChoiceCards);

    const summary = root.querySelector('[data-region="modgen-qa-summary"]');
    const refreshSummary = () => (summary ? updateSummary(root, summary) : Promise.resolve());

    const inputs = Array.from(root.querySelectorAll('input[data-modgen-stepper]'));
    await Promise.all(inputs.map(addStepper));
    inputs.forEach((input) => {
        input.addEventListener('input', refreshSummary);
        input.addEventListener('change', refreshSummary);
    });
    await refreshSummary();
};
