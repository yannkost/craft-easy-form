/**
 * Server-Rendered Field Module
 *
 * Most fields are built client-side (field-manager.js), but some need settings
 * controls the JS builder can't reproduce — the agree field's Entry selector is
 * Craft's elementSelectField, which only works as server-rendered markup plus
 * Craft's init JS. For those types we ask the server to render the field
 * (FormsController::actionRenderField) and inject the returned HTML together
 * with the head/body HTML Craft registered while rendering, so the element
 * select initialises and works without saving the form first.
 *
 * The field is rendered at its target index so the element-select input names
 * match where it lands; the existing delegated handlers (tabs, lightswitches,
 * popover) then adopt the injected markup automatically.
 */

import { createFieldManager } from './field-manager.js';

// Field types rendered server-side on add. Mirrors $serverRenderedTypes in
// FormsController::actionRenderField — keep the two in sync.
export const SERVER_RENDERED_TYPES = ['agree'];

// Stateless; only used here for updateValidationFields on the injected element.
const fieldManager = createFieldManager();

/**
 * Render `type` server-side and add it to `rowElement`'s field list.
 *
 * @param {HTMLElement} rowElement  The .layout-row to add into.
 * @param {string}      type        Field type (must be in SERVER_RENDERED_TYPES).
 * @param {function}    updateFieldCountBadge
 * @param {{index?: number, referenceNode?: Node|null}} [opts]
 *        index: the field index to render at (defaults to the current count, i.e.
 *        appended at the end); referenceNode: insert before this node instead of
 *        appending (used by drag-drop to honour the drop position).
 * @returns {Promise<HTMLElement|null>} the inserted .field-in-row, or null.
 */
export function addServerRenderedField(rowElement, type, updateFieldCountBadge, opts) {
    opts = opts || {};
    const rowFields = rowElement.querySelector('.layout-row-fields');
    const pageIndex = parseInt(rowElement.dataset.pageIndex || '0', 10);
    const rowIndex = parseInt(rowElement.dataset.rowIndex || '0', 10);
    const fieldIndex = (opts.index == null)
        ? rowFields.querySelectorAll('.field-in-row').length
        : opts.index;

    // Insert a placeholder synchronously so the new field is present — and the
    // *last* field — the instant we're called, before the (async) server render
    // returns. Without it, code that grabs "the last field" right after adding
    // would resolve to the previous field, and the user gets no feedback during
    // the round-trip. The real markup replaces the placeholder on arrival.
    const placeholder = document.createElement('div');
    placeholder.className = 'field-in-row ef-field-loading';
    placeholder.dataset.fieldType = type;
    placeholder.dataset.fieldIndex = fieldIndex;
    placeholder.dataset.pageIndex = pageIndex;
    placeholder.setAttribute('aria-busy', 'true');
    placeholder.innerHTML = '<div class="field-in-row-header"><div class="field-in-row-label"><strong class="light">Adding field…</strong></div></div>';

    const emptyMsg = rowFields.querySelector('.empty-row-message');
    if (emptyMsg) emptyMsg.remove();
    if (opts.referenceNode && opts.referenceNode.parentNode === rowFields) {
        rowFields.insertBefore(placeholder, opts.referenceNode);
    } else {
        rowFields.appendChild(placeholder);
    }
    if (updateFieldCountBadge) updateFieldCountBadge(rowElement);

    return Craft.sendActionRequest('POST', 'easy-form/forms/render-field', {
        data: { type, pageIndex, rowIndex, fieldIndex },
    }).then(response => {
        const payload = (response && response.data) || {};
        const tmp = document.createElement('div');
        tmp.innerHTML = (payload.html || '').trim();
        const fieldEl = tmp.firstElementChild;
        if (!fieldEl) { placeholder.remove(); return null; }

        placeholder.replaceWith(fieldEl);

        // The markup is in the DOM first so Craft's init can find its container,
        // then run the CSS/JS Craft registered while rendering (element select).
        if (payload.headHtml) Craft.appendHeadHtml(payload.headHtml);
        if (payload.bodyHtml) Craft.appendBodyHtml(payload.bodyHtml);

        // Wire up Craft's auto-initialised UI inside the injected field — most
        // importantly the native lightswitches, which register no per-instance
        // JS and would otherwise stay uninitialised (and zero-size). This does
        // NOT touch the element select, which the bodyHtml above already inits.
        if (Craft.initUiElements) Craft.initUiElements(fieldEl);

        // Apply the field's type-based show/hide (Link tab etc.), matching how
        // existing server-rendered fields are initialised in event-handlers.js.
        fieldManager.updateValidationFields(fieldEl, type);

        if (updateFieldCountBadge) updateFieldCountBadge(rowElement);
        return fieldEl;
    }).catch(error => {
        placeholder.remove();
        if (updateFieldCountBadge) updateFieldCountBadge(rowElement);
        console.error('easy-form: could not render field', error);
        if (window.Craft && Craft.cp && typeof Craft.cp.displayError === 'function') {
            Craft.cp.displayError('Could not add field. Please try again.');
        }
        return null;
    });
}
