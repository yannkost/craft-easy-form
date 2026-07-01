/**
 * Email content editor
 *
 * Upgrades each notification "Email Content" textarea into a contenteditable
 * surface with two dropdowns — Insert field value and Insert field label — that
 * drop **chips** into the text. A chip is a highlighted, non-editable token with
 * a small ✕ to remove it; it maps to a `{field[handle]}` / `{label[handle]}`
 * placeholder. The original textarea stays in the DOM (hidden) and remains the
 * submitted source of truth: the editor serializes its content back into it on
 * every change, so nothing else in the save pipeline changes.
 */

// field[h] = value, label[h] = label, combo[h] = label + value stacked,
// comboInline[h] = label + value on one line. A combo chip carries a layout
// toggle that flips its kind between combo and comboInline. An optional trailing
// "|b" before the closing brace marks the token as bold.
// table[h1,h2,…] (or bare {table} = every field) renders a Label | Value table.
const TOKEN_RE = /\{(field|label|combo|comboInline)\[([a-zA-Z][a-zA-Z0-9_]*)\](\|b)?\}|\{table(?:\[([a-zA-Z0-9_,]*)\])?\}/g;

// Only the per-site notification content textareas (not field-content textareas).
const CONTENT_SELECTOR = 'textarea[name^="notificationSettings"][name*="[siteContent]"]';

/** Current form fields (handle + label), read live from the builder DOM so the menus stay in sync. */
function collectFields() {
    const out = [];
    const seen = new Set();
    const push = (handle, label) => {
        handle = (handle || '').trim();
        if (!handle || seen.has(handle)) return;
        seen.add(handle);
        out.push({ handle, label: (label || '').trim() || handle });
    };
    document.querySelectorAll('.field-in-row').forEach((card) => {
        if (['heading', 'divider', 'callout', 'paragraph'].includes(card.dataset.fieldType)) return;
        const h = card.querySelector('.field-handle-input');
        const l = card.querySelector('.field-label-input');
        if (h) push(h.value, l ? l.value : '');
    });
    document.querySelectorAll('.ef-frontend-field').forEach((card) => {
        const h = card.querySelector('.ef-ff-handle');
        const l = card.querySelector('input[name$="[label]"]');
        if (h) push(h.value, l ? l.value : '');
    });
    return out;
}

function labelFor(handle) {
    const f = collectFields().find((x) => x.handle === handle);
    return f ? f.label : handle;
}

/** Build a small chip button (✕ remove, B bold, ⇅ layout). */
function chipButton(cls, label, text) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = cls;
    btn.setAttribute('aria-label', label);
    btn.textContent = text;
    return btn;
}

/** Write the chip's data-token + title from its current kind/handle/bold state. */
function syncChipToken(chip) {
    const bold = chip.dataset.bold === '1';
    chip.dataset.token = `{${chip.dataset.kind}[${chip.dataset.handle}]${bold ? '|b' : ''}}`;
    chip.title = chip.dataset.token;
    chip.classList.toggle('is-bold', bold);
    const layout = chip.querySelector('.ef-token-layout');
    if (layout) {
        const inline = chip.dataset.kind === 'comboInline';
        layout.textContent = inline ? '↔' : '↕';
        layout.title = inline ? 'Inline (Label: Value)' : 'Stacked (Label / Value)';
    }
}

/** Build a chip element for a token kind + handle (optionally bold). */
function makeChip(kind, handle, bold = false) {
    const chip = document.createElement('span');
    // combo and comboInline share one colour; their kind only differs by layout.
    chip.className = 'ef-token ef-token-' + (kind === 'comboInline' ? 'combo' : kind);
    chip.contentEditable = 'false';
    chip.dataset.handle = handle;
    chip.dataset.kind = kind;
    chip.dataset.bold = bold ? '1' : '0';

    const text = document.createElement('span');
    text.className = 'ef-token-text';
    text.textContent = (kind === 'label' ? '🏷 ' : kind === 'field' ? '🔹 ' : '🔸 ') + labelFor(handle);
    chip.appendChild(text);

    // Label+value chips get a layout toggle (stacked ↕ / inline ↔).
    if (kind === 'combo' || kind === 'comboInline') {
        chip.appendChild(chipButton('ef-token-layout', 'Toggle layout', ''));
    }
    chip.appendChild(chipButton('ef-token-b', 'Bold', 'B'));
    chip.appendChild(chipButton('ef-token-x', 'Remove', '×'));

    syncChipToken(chip);
    return chip;
}

/** Write a table chip's text + token from its handle list (empty = all fields). */
function syncTableChip(chip) {
    const csv = (chip.dataset.handles || '').trim();
    const handles = csv ? csv.split(',').filter(Boolean) : [];
    chip.dataset.token = handles.length ? `{table[${handles.join(',')}]}` : '{table}';
    const n = handles.length;
    const summary = n ? `${n} ${n === 1 ? 'field' : 'fields'}` : 'all fields';
    chip.querySelector('.ef-token-text').textContent = `📋 Table · ${summary}`;
    chip.title = chip.dataset.token;
}

/** Build a table chip from a comma-separated handle list ('' = all fields). */
function makeTableChip(handlesCsv) {
    const chip = document.createElement('span');
    chip.className = 'ef-token ef-token-table';
    chip.contentEditable = 'false';
    chip.dataset.kind = 'table';
    chip.dataset.handles = handlesCsv || '';

    const text = document.createElement('span');
    text.className = 'ef-token-text';
    chip.appendChild(text);
    chip.appendChild(chipButton('ef-token-x', 'Remove', '×'));

    syncTableChip(chip);
    return chip;
}

/** Parse the stored content string → fill the editable surface with text + chips. */
function renderInto(editable, value) {
    editable.textContent = '';
    let last = 0;
    let m;
    TOKEN_RE.lastIndex = 0;
    while ((m = TOKEN_RE.exec(value)) !== null) {
        if (m.index > last) {
            editable.appendChild(document.createTextNode(value.slice(last, m.index)));
        }
        // m[1] set → field/label/combo token; otherwise it's a {table[…]} token.
        editable.appendChild(m[1] ? makeChip(m[1], m[2], !!m[3]) : makeTableChip(m[4] || ''));
        last = m.index + m[0].length;
    }
    if (last < value.length) {
        editable.appendChild(document.createTextNode(value.slice(last)));
    }
}

/** Serialize the editable surface back to a token string for the textarea. */
function serialize(node) {
    let out = '';
    node.childNodes.forEach((child) => {
        if (child.nodeType === Node.TEXT_NODE) {
            out += child.nodeValue;
        } else if (child.nodeType === Node.ELEMENT_NODE) {
            if (child.classList && child.classList.contains('ef-token')) {
                out += child.dataset.token;
            } else if (child.tagName === 'BR') {
                out += '\n';
            } else {
                // contenteditable wraps new lines in <div>/<p>; treat each as a line break.
                if (/^(DIV|P)$/.test(child.tagName) && out && !out.endsWith('\n')) out += '\n';
                out += serialize(child);
            }
        }
    });
    return out;
}

const MENU_PLACEHOLDER = {
    field: 'Insert value…',
    label: 'Insert label…',
    combo: 'Insert label + value…',
};

/** Populate an insert menu with the current fields (rebuilt on open so it's current). */
function fillMenu(select, kind) {
    const fields = collectFields();
    const placeholder = MENU_PLACEHOLDER[kind] || 'Insert…';
    select.innerHTML = `<option value="">${placeholder}</option>`
        + fields.map((f) => `<option value="${f.handle}">${escapeAttr(f.label)} (${escapeAttr(f.handle)})</option>`).join('');
}

function escapeAttr(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** Insert a chip at the current caret inside `editable` (falls back to the end). */
function insertChipAtCaret(editable, chip, savedRange) {
    const sel = window.getSelection();
    let range = savedRange;
    if (!range || !editable.contains(range.commonAncestorContainer)) {
        range = document.createRange();
        range.selectNodeContents(editable);
        range.collapse(false);
    }
    range.deleteContents();
    const space = document.createTextNode(' ');
    range.insertNode(space);
    range.insertNode(chip);
    // Move caret after the inserted space.
    range.setStartAfter(space);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
}

/**
 * Open the field picker for a table token. With `existingChip` it edits that
 * chip's selection; otherwise it inserts a new table chip at the caret. An empty
 * selection means "all fields". Fields are rendered in builder order server-side.
 */
function openTablePicker(wrap, editable, sync, savedRange, existingChip) {
    wrap.querySelector('.ef-table-picker')?.remove();
    const fields = collectFields();
    const preset = new Set((existingChip?.dataset.handles || '').split(',').filter(Boolean));

    const pick = document.createElement('div');
    pick.className = 'ef-table-picker';
    pick.innerHTML =
        `<div class="ef-table-picker-head">${existingChip ? 'Edit table fields' : 'Choose table fields'}</div>`
        + `<label class="ef-tp-row ef-tp-all-row"><input type="checkbox" class="ef-tp-all"> <strong>Select all</strong></label>`
        + `<div class="ef-table-picker-list">`
        + (fields.length
            ? fields.map((f) => `<label class="ef-tp-row"><input type="checkbox" value="${escapeAttr(f.handle)}"${preset.has(f.handle) ? ' checked' : ''}> ${escapeAttr(f.label)} <span class="ef-tp-handle">(${escapeAttr(f.handle)})</span></label>`).join('')
            : `<div class="ef-tp-empty">No fields yet.</div>`)
        + `</div>`
        + `<div class="ef-table-picker-actions"><button type="button" class="btn small ef-tp-cancel">Cancel</button>`
        + `<button type="button" class="btn small submit ef-tp-insert">${existingChip ? 'Update' : 'Insert table'}</button></div>`;
    wrap.appendChild(pick);

    const boxes = () => Array.from(pick.querySelectorAll('.ef-table-picker-list input[type=checkbox]'));
    const allBox = pick.querySelector('.ef-tp-all');
    allBox.checked = preset.size === 0 && fields.length > 0 ? false : boxes().every((b) => b.checked);
    allBox.addEventListener('change', () => boxes().forEach((b) => { b.checked = allBox.checked; }));

    pick.querySelector('.ef-tp-cancel').addEventListener('click', () => pick.remove());
    pick.querySelector('.ef-tp-insert').addEventListener('click', () => {
        const csv = boxes().filter((b) => b.checked).map((b) => b.value).join(',');
        if (existingChip) {
            existingChip.dataset.handles = csv;
            syncTableChip(existingChip);
        } else {
            editable.focus();
            insertChipAtCaret(editable, makeTableChip(csv), savedRange);
        }
        pick.remove();
        sync();
    });
}

/** Upgrade a single content textarea into the chip editor. */
function buildEditor(textarea) {
    if (textarea.dataset.efEditorReady === '1') return;
    textarea.dataset.efEditorReady = '1';

    const wrap = document.createElement('div');
    wrap.className = 'ef-email-editor';

    const toolbar = document.createElement('div');
    toolbar.className = 'ef-email-toolbar';
    const valueMenu = document.createElement('select');
    valueMenu.className = 'ef-email-insert ef-email-insert-value';
    const labelMenu = document.createElement('select');
    labelMenu.className = 'ef-email-insert ef-email-insert-label';
    const comboMenu = document.createElement('select');
    comboMenu.className = 'ef-email-insert ef-email-insert-combo';
    const tableBtn = document.createElement('button');
    tableBtn.type = 'button';
    tableBtn.className = 'ef-email-insert ef-email-table-btn';
    tableBtn.textContent = '📋 Insert table…';
    toolbar.appendChild(valueMenu);
    toolbar.appendChild(labelMenu);
    toolbar.appendChild(comboMenu);
    toolbar.appendChild(tableBtn);

    // Color legend so the chip colors are self-explanatory.
    const legend = document.createElement('div');
    legend.className = 'ef-email-legend';
    legend.innerHTML =
        '<span class="ef-legend-item"><span class="ef-legend-swatch ef-swatch-field"></span>Value</span>'
        + '<span class="ef-legend-item"><span class="ef-legend-swatch ef-swatch-label"></span>Label</span>'
        + '<span class="ef-legend-item"><span class="ef-legend-swatch ef-swatch-combo"></span>Label + value</span>'
        + '<span class="ef-legend-item"><span class="ef-legend-swatch ef-swatch-table"></span>Table</span>';
    toolbar.appendChild(legend);

    const editable = document.createElement('div');
    editable.className = 'ef-email-surface';
    editable.contentEditable = 'true';
    editable.setAttribute('role', 'textbox');
    editable.setAttribute('aria-multiline', 'true');

    wrap.appendChild(toolbar);
    wrap.appendChild(editable);
    textarea.parentNode.insertBefore(wrap, textarea);
    textarea.style.display = 'none';

    renderInto(editable, textarea.value || '');

    const sync = () => {
        textarea.value = serialize(editable);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    };

    // Keep a live selection so a menu pick inserts where the caret was.
    let savedRange = null;
    document.addEventListener('selectionchange', () => {
        const sel = window.getSelection();
        if (sel.rangeCount && editable.contains(sel.getRangeAt(0).commonAncestorContainer)) {
            savedRange = sel.getRangeAt(0).cloneRange();
        }
    });

    editable.addEventListener('input', sync);

    // Chip controls: ✕ removes, B toggles bold, ⇅ flips layout, table chip edits.
    editable.addEventListener('click', (e) => {
        const x = e.target.closest('.ef-token-x');
        if (x) {
            e.preventDefault();
            x.closest('.ef-token').remove();
            sync();
            return;
        }
        const layout = e.target.closest('.ef-token-layout');
        if (layout) {
            e.preventDefault();
            const chip = layout.closest('.ef-token');
            chip.dataset.kind = chip.dataset.kind === 'comboInline' ? 'combo' : 'comboInline';
            syncChipToken(chip);
            sync();
            return;
        }
        const b = e.target.closest('.ef-token-b');
        if (b) {
            e.preventDefault();
            const chip = b.closest('.ef-token');
            chip.dataset.bold = chip.dataset.bold === '1' ? '0' : '1';
            syncChipToken(chip);
            sync();
            return;
        }
        const tableChip = e.target.closest('.ef-token-table');
        if (tableChip) {
            e.preventDefault();
            openTablePicker(wrap, editable, sync, savedRange, tableChip);
        }
    });

    tableBtn.addEventListener('click', () => openTablePicker(wrap, editable, sync, savedRange, null));

    const wireMenu = (menu, kind) => {
        menu.addEventListener('mousedown', () => fillMenu(menu, kind));
        menu.addEventListener('focus', () => fillMenu(menu, kind));
        menu.addEventListener('change', () => {
            const handle = menu.value;
            if (!handle) return;
            editable.focus();
            insertChipAtCaret(editable, makeChip(kind, handle), savedRange);
            menu.value = '';
            sync();
        });
    };
    wireMenu(valueMenu, 'field');
    wireMenu(labelMenu, 'label');
    wireMenu(comboMenu, 'combo');

    fillMenu(valueMenu, 'field');
    fillMenu(labelMenu, 'label');
    fillMenu(comboMenu, 'combo');
}

/** Upgrade every not-yet-initialised content textarea within `root`. */
export function setupEmailEditors(root = document) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll(CONTENT_SELECTOR).forEach(buildEditor);
}
