/**
 * Drag and Drop Handler Module
 * Manages sortable functionality for rows and fields using SortableJS
 */

import { createFieldManager } from './field-manager.js';
import { setActiveRow } from './palette-add.js';
import { SERVER_RENDERED_TYPES, addServerRenderedField } from './server-field.js';

const Sortable = window.Sortable;

// Shared, stateless field manager used to materialize fields dropped in from the
// palette (createFieldManager holds no per-row state).
const fieldManager = createFieldManager();

export function setupDragAndDrop(formRows, rowManager) {
    if (!Sortable) {
        console.error('SortableJS failed to load.');
        return;
    }

    // Initialize Row Sorting
    initRowSortable(formRows);

    // Initialize Field Sorting for existing rows
    formRows.querySelectorAll('.layout-row').forEach(row => {
        initFieldSortable(row.querySelector('.layout-row-fields'), rowManager);
    });

    // The field palette is global (one per editor); init it once.
    const palette = document.querySelector('.ef-field-palette');
    if (palette) initPaletteSortable(palette);
}

/**
 * Make the right-sidebar field palette a drag source. Items are cloned on drag
 * and dropped into any row's field list (shared 'fields' group). The drop is
 * intercepted in initFieldSortable's onAdd, which builds the real field.
 */
export function initPaletteSortable(palette) {
    if (!palette || palette.dataset.sortableInit) return;
    palette.dataset.sortableInit = 'true';

    palette.querySelectorAll('.ef-palette-items').forEach(list => {
        new Sortable(list, {
            group: { name: 'fields', pull: 'clone', put: false },
            sort: false,
            draggable: '.ef-palette-item',
            // Only the left zone starts a drag; the "+" button stays clickable.
            handle: '.ef-palette-item-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            // The palette sits in Craft's details sidebar, which can clip overflow;
            // drag the helper on <body> so it isn't cut off on the way to a row.
            fallbackOnBody: true,
        });
    });
}

export function initRowSortable(container) {
    if (!container) return;

    new Sortable(container, {
        handle: '.layout-row-handle',
        draggable: '.layout-row',
        animation: 150,
        ghostClass: 'sortable-ghost',
        onEnd: function() {
            reindexRows(container);
        }
    });
}

export function initFieldSortable(container, rowManager) {
    if (!container) return;

    new Sortable(container, {
        handle: '.field-in-row-handle',
        draggable: '.field-in-row',
        group: 'fields', // Allow dragging between rows + accept palette clones
        animation: 150,
        ghostClass: 'sortable-ghost',
        onAdd: function(evt) {
            const item = evt.item;
            // Palette clones carry data-field-type and are not real fields yet.
            if (!item.dataset || !item.dataset.fieldType || item.classList.contains('field-in-row')) {
                return; // a real field moved in from another row — handled by onEnd
            }

            const type = item.dataset.fieldType;
            const fieldsContainer = evt.to;
            const rowEl = fieldsContainer.closest('.layout-row');

            if (SERVER_RENDERED_TYPES.includes(type)) {
                // Render server-side (async). Render it at its drop index so the
                // element-select input names match where it lands and survive the
                // reindex below unchanged. The drop index = number of real fields
                // before the clone; insert before whatever followed the clone.
                const allFields = fieldsContainer.querySelectorAll('.field-in-row');
                let dropIndex = 0;
                allFields.forEach(f => {
                    if (item.compareDocumentPosition(f) & Node.DOCUMENT_POSITION_PRECEDING) dropIndex++;
                });
                const refNode = item.nextElementSibling;
                item.remove();

                addServerRenderedField(rowEl, type, rowManager.updateFieldCountBadge, {
                    index: dropIndex,
                    referenceNode: refNode,
                }).then(() => {
                    reindexFields(fieldsContainer);
                    rowManager.updateFieldCountBadge(rowEl);
                    checkEmptyRow(fieldsContainer);
                    setActiveRow(rowEl);
                });
                return;
            }

            // Build the real field (appends to the end + clears the empty-row msg).
            const created = fieldManager.addFieldToRow(rowEl, { type }, rowManager.updateFieldCountBadge);
            // Move it to where the clone was dropped, then drop the clone.
            if (created) fieldsContainer.insertBefore(created, item);
            item.remove();

            reindexFields(fieldsContainer);
            rowManager.updateFieldCountBadge(rowEl);
            checkEmptyRow(fieldsContainer);

            // The row a field was dropped into becomes the active add-target.
            setActiveRow(rowEl);
        },
        onEnd: function(evt) {
            // Reindex fields in source row
            reindexFields(evt.from);
            rowManager.updateFieldCountBadge(evt.from.closest('.layout-row'));
            checkEmptyRow(evt.from);

            // Reindex fields in destination row (if different)
            if (evt.from !== evt.to) {
                reindexFields(evt.to);
                rowManager.updateFieldCountBadge(evt.to.closest('.layout-row'));
                checkEmptyRow(evt.to);
            }
        }
    });
}

export function reindexRows(container) {
    if (!container) return;
    const rows = container.querySelectorAll(':scope > .layout-row');
    const pageIndex = container.dataset.pageIndex || '0';
    
    rows.forEach((row, rowIndex) => {
        row.dataset.rowIndex = rowIndex;

        // Update row label
        const label = row.querySelector('.layout-row-label strong');
        if (label) {
            label.textContent = `Row ${rowIndex + 1}`;
        }

        // Update inputs: name="pages[X][rows][OLD]..." -> name="pages[X][rows][NEW]..."
        const inputs = row.querySelectorAll('[name*="[rows]"]');
        inputs.forEach(input => {
            input.name = input.name.replace(
                /pages\[\d+\]\[rows\]\[\d+\]/g,
                `pages[${pageIndex}][rows][${rowIndex}]`
            );
        });

        // Keep the row-level condition prefixes in sync (these live in
        // data-field-prefix, not in a name, so the loop above misses them).
        const rowPrefix = `pages[${pageIndex}][rows][${rowIndex}]`;
        row.querySelectorAll('.condition-rules, .add-condition-rule').forEach(el => {
            if (!el.closest('.field-in-row')) {
                el.dataset.fieldPrefix = rowPrefix;
            }
        });

        // The row index changed, so refresh field names + prefixes within it.
        const fieldsContainer = row.querySelector('.layout-row-fields');
        if (fieldsContainer) {
            reindexFields(fieldsContainer);
        }
    });
}

function reindexFields(container) {
    // Container is .layout-row-fields
    const row = container.closest('.layout-row');
    const rowIndex = row.dataset.rowIndex;
    const pageIndex = row.dataset.pageIndex || '0';
    const fields = container.querySelectorAll(':scope > .field-in-row');
    
    fields.forEach((field, fieldIndex) => {
        field.dataset.fieldIndex = fieldIndex;

        const fieldPrefix = `pages[${pageIndex}][rows][${rowIndex}][fields][${fieldIndex}]`;

        // Update inputs: name="pages[X][rows][Y][fields][OLD]..." -> "pages[X][rows][Y][fields][NEW]..."
        const inputs = field.querySelectorAll('[name*="[fields]"]');
        inputs.forEach(input => {
            input.name = input.name.replace(
                /pages\[\d+\]\[rows\]\[\d+\]\[fields\]\[\d+\]/g,
                fieldPrefix
            );
        });

        // Keep this field's condition prefixes (data-field-prefix) in sync too.
        field.querySelectorAll('.condition-rules, .add-condition-rule').forEach(el => {
            el.dataset.fieldPrefix = fieldPrefix;
        });
    });
}

function checkEmptyRow(container) {
    const fields = container.querySelectorAll('.field-in-row');
    const emptyMsg = container.querySelector('.empty-row-message');
    
    if (fields.length === 0) {
        if (!emptyMsg) {
            const msg = document.createElement('div');
            msg.className = 'empty-row-message';
            msg.innerHTML = '<p class="light">This row is empty. Drag a field here from the sidebar.</p>';
            container.appendChild(msg);
        }
    } else {
        if (emptyMsg) {
            emptyMsg.remove();
        }
    }
}
