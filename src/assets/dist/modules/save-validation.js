/**
 * Save Validation Module
 *
 * Before the form builder is submitted, make sure every field a user dropped in
 * is actually configured. The server silently drops fields with an empty handle
 * (FormsController::actionSave), so without this an unconfigured field just
 * disappears on save. Instead we block the submit, mark the offending field
 * cards red, reveal the first one and tell the user what to fix.
 */

import { openFieldDialog } from './event-handlers.js';

const ERROR_CLASS = 'has-config-error';

export function setupSaveValidation() {
    const editor = document.querySelector('.ef-editor');
    if (!editor) return;
    const form = editor.closest('form');
    if (!form) return;

    // Our JS validation is the single gate. Disable native constraint
    // validation: the generated handle/label inputs are `required` but sit in
    // display:none popovers, which makes browsers abort the submit
    // inconsistently (sometimes silently). Turning it off keeps behaviour
    // deterministic across the submit paths below.
    form.noValidate = true;

    const onSubmit = function (e) {
        const problems = validate();
        if (problems.length === 0) return;

        e.preventDefault();
        e.stopImmediatePropagation();
        revealFirstProblem(problems[0]);
        announce(problems);
    };

    // Craft submits the primary form through jQuery — `$form.trigger('submit')`
    // — for BOTH the header Save button and the Cmd/Ctrl+S shortcut
    // (Craft.submitForm → _submitFormInternal). A native capture listener never
    // sees a jQuery-triggered submit, so the guard has to be bound via jQuery
    // when it's present (it's always loaded in the Craft CP). preventDefault on
    // the jQuery event also cancels jQuery's default submit action, so no POST
    // is made when fields are invalid. A native capture listener is kept as a
    // fallback for the (unlikely) case jQuery isn't available.
    const $ = window.jQuery;
    if ($) {
        $(form).on('submit', onSubmit);
    } else {
        form.addEventListener('submit', onSubmit, true);
    }

    // Clear a field's error state as soon as the user fixes its label/handle.
    document.addEventListener('input', function (e) {
        const t = e.target;
        if (t.classList && (t.classList.contains('field-label-input') || t.classList.contains('field-handle-input'))) {
            const field = t.closest('.field-in-row');
            if (field && field.classList.contains(ERROR_CLASS) && fieldErrors(field).length === 0) {
                clearFieldError(field);
            }
        }
    });
}

/**
 * Validate every field card across all pages. Returns the list of invalid
 * `.field-in-row` elements (also marks them, and marks duplicate handles).
 *
 * @returns {HTMLElement[]}
 */
function validate() {
    const fields = Array.from(document.querySelectorAll('.field-in-row'));

    // Reset previous marks.
    fields.forEach(clearFieldError);

    const invalid = [];
    const byHandle = {};

    fields.forEach(field => {
        const errors = fieldErrors(field);
        if (errors.length) {
            markFieldError(field, errors);
            invalid.push(field);
        }

        // Track handles to flag duplicates (which silently overwrite on save).
        const handle = handleValue(field);
        if (handle) (byHandle[handle] = byHandle[handle] || []).push(field);
    });

    Object.keys(byHandle).forEach(handle => {
        const dupes = byHandle[handle];
        if (dupes.length > 1) {
            dupes.forEach(field => {
                if (!field.classList.contains(ERROR_CLASS)) invalid.push(field);
                markFieldError(field, ['Duplicate handle “' + handle + '”']);
            });
        }
    });

    return invalid;
}

/** Per-field config checks. Returns an array of human-readable problems. */
function fieldErrors(field) {
    const errors = [];
    if (!labelValue(field)) errors.push('Missing label');
    if (!handleValue(field)) errors.push('Missing handle');
    return errors;
}

function labelValue(field) {
    const el = field.querySelector('.field-label-input');
    return el ? el.value.trim() : '';
}

function handleValue(field) {
    const el = field.querySelector('.field-handle-input');
    return el ? el.value.trim() : '';
}

function markFieldError(field, errors) {
    field.classList.add(ERROR_CLASS);

    // Flag the specific empty inputs.
    [['Missing label', '.field-label-input'], ['Missing handle', '.field-handle-input']].forEach(([msg, sel]) => {
        const input = field.querySelector(sel);
        if (input) input.classList.toggle('error', errors.indexOf(msg) !== -1);
    });

    // Surface the reason on the collapsed card so it's visible without opening.
    const labelBox = field.querySelector('.field-in-row-label');
    if (labelBox) {
        let note = labelBox.querySelector('.field-config-error');
        if (!note) {
            note = document.createElement('span');
            note.className = 'field-config-error';
            labelBox.appendChild(note);
        }
        note.textContent = errors.join(' · ');
    }
}

function clearFieldError(field) {
    field.classList.remove(ERROR_CLASS);
    field.querySelectorAll('.field-label-input.error, .field-handle-input.error')
        .forEach(i => i.classList.remove('error'));
    const note = field.querySelector('.field-config-error');
    if (note) note.remove();
}

/** Bring the first invalid field into view: right tab, right page, open popover. */
function revealFirstProblem(field) {
    // Activate the Layout top-tab.
    const layoutTab = document.querySelector('.ef-tabnav-item[data-pane="layout"]');
    if (layoutTab && !layoutTab.classList.contains('is-active')) layoutTab.click();

    // Activate the page tab the field lives on.
    const pane = field.closest('.page-pane');
    if (pane) {
        const idx = pane.dataset.pagePane;
        const pageTab = document.querySelector('#page-tabs .page-tab[data-tab="' + idx + '"]');
        if (pageTab && !pageTab.classList.contains('active')) pageTab.click();

        // Fields live in the page's "Fields" inner tab — activate it so the
        // invalid field isn't hidden behind the Labels/Sites tabs.
        const fieldsTab = pane.querySelector('.ef-page-inner-tabs .ef-tab[data-inner-tab="fields"]');
        if (fieldsTab && !fieldsTab.classList.contains('active')) fieldsTab.click();
    }

    // Open the field's settings dialog (snapshots state) and focus the first
    // empty required input.
    openFieldDialog(field);

    field.scrollIntoView({ behavior: 'smooth', block: 'center' });

    const firstBad = field.querySelector('.field-label-input.error, .field-handle-input.error');
    if (firstBad) setTimeout(() => firstBad.focus(), 50);
}

function announce(problems) {
    const count = problems.length;
    const msg = count === 1
        ? 'A field is not fully configured. Give it a label and handle before saving.'
        : count + ' fields are not fully configured. Fix the highlighted fields before saving.';

    if (window.Craft && Craft.cp && typeof Craft.cp.displayError === 'function') {
        Craft.cp.displayError(msg);
    } else {
        alert(msg);
    }
}
