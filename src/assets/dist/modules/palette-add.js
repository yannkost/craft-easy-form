/**
 * Palette Click-to-Add Module
 *
 * The field palette is drag-and-drop, but each palette item also has a "+"
 * button. Clicking it (or pressing Enter/Space, since it's a real <button>)
 * adds that field type to the *active row* — the one row highlighted with the
 * "Fields land here" badge. This is the keyboard-accessible complement to drag,
 * and the only path automated tests can drive (headless browsers can't perform
 * native HTML5 drag-and-drop).
 *
 * Exactly one row across the builder is the active target at a time. It is set
 * when a row is clicked, when a row is added, and when a field is added; on a
 * page switch it follows to a row on the now-visible page.
 */

import { createFieldManager } from './field-manager.js';
import { SERVER_RENDERED_TYPES, addServerRenderedField } from './server-field.js';

// createFieldManager is stateless, so a single shared instance is fine.
const fieldManager = createFieldManager();

let installed = false;

/** Make `row` the single active add-target, clearing any previous one. */
export function setActiveRow(row) {
    document.querySelectorAll('.layout-row.is-add-target').forEach(r => {
        if (r !== row) r.classList.remove('is-add-target');
    });
    if (row) row.classList.add('is-add-target');
}

/** The currently visible page pane (falls back to the first one). */
function activePane() {
    return document.querySelector('#page-panes .page-pane.active')
        || document.querySelector('#page-panes .page-pane');
}

/** The add-target row inside a pane: the flagged one, else its last row. */
function targetRowIn(pane) {
    if (!pane) return null;
    const flagged = pane.querySelector('.layout-row.is-add-target');
    if (flagged) return flagged;
    const rows = pane.querySelectorAll('.layout-row');
    return rows.length ? rows[rows.length - 1] : null;
}

/**
 * After switching pages, keep the highlight on the visible page: if the active
 * row isn't in this pane, move it to that pane's last row (or clear it).
 */
export function syncActiveRowToPane(pane) {
    if (!pane) return;
    const current = document.querySelector('.layout-row.is-add-target');
    if (current && pane.contains(current)) return;
    setActiveRow(targetRowIn(pane));
}

/** Install the one-time, document-delegated click-to-add handler. */
export function setupPaletteClickToAdd() {
    if (installed) return;
    installed = true;

    document.addEventListener('click', function (e) {
        const addBtn = e.target.closest('.ef-palette-item-add');
        if (!addBtn) return;
        e.preventDefault();

        const item = addBtn.closest('.ef-palette-item');
        const type = item && item.dataset.fieldType;
        if (!type) return;

        const pane = activePane();
        if (!pane) return;
        const rowManager = pane._efRowManager;
        if (!rowManager) return;

        // Land in the active row; if the page has none yet, make one.
        let row = targetRowIn(pane);
        if (!row) {
            row = rowManager.addRow();
            rowManager.updateFieldCountBadge(row);
        }

        if (SERVER_RENDERED_TYPES.includes(type)) {
            // Needs server-rendered controls (e.g. agree's Entry selector):
            // render + inject it (appends to the end of the active row).
            addServerRenderedField(row, type, rowManager.updateFieldCountBadge);
        } else {
            fieldManager.addFieldToRow(row, { type }, rowManager.updateFieldCountBadge);
        }
        setActiveRow(row);
    });
}
