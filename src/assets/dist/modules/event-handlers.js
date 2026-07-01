/**
 * Event Handlers Module
 * Manages all event listeners for the form builder.
 *
 * Listeners come in two flavours:
 *  - Per-page: bound to a single page's `.form-rows` container. Safe to bind once
 *    per page since each page has its own container.
 *  - Global: delegated on `document`, because field settings render in a fixed
 *    overlay that lives outside the page container. These MUST be installed only
 *    once — setupEventHandlers runs per page, so without the `globalHandlersInstalled`
 *    guard an N-page form would attach each global listener N times (which broke
 *    custom lightswitches, multiplied "Add Rule" clicks, etc.).
 */

import { setActiveRow } from './palette-add.js';
import { reindexRows } from './drag-handler.js';

let globalHandlersInstalled = false;

// Field-settings dialog. Edits are committed only when "Save field" is clicked;
// the ✕, a backdrop click and "Cancel" all revert. On open we snapshot every
// control so Cancel can restore it, and the card title / required marker are
// pushed out of the dialog only on a successful save (see saveFieldDialog).
const HANDLE_PATTERN = /^[a-zA-Z][a-zA-Z0-9_]*$/;
const dialogSnapshots = new WeakMap();

export function setupEventHandlers(formRows, fieldManager, rowManager) {
    setupRowScopedHandlers(formRows, rowManager);

    // Initialize validation-field visibility for this page's existing fields.
    formRows.querySelectorAll('.field-in-row').forEach(fieldElement => {
        const typeInput = fieldElement.querySelector('[name*="[type]"]');
        if (typeInput) {
            fieldManager.updateValidationFields(fieldElement, typeInput.value);
        }
    });

    setupChangeHandlers(formRows);

    // Document-level listeners: install exactly once across all pages.
    installGlobalHandlers();
}

/**
 * Per-page click handlers, scoped to one page's `.form-rows` container.
 */
function setupRowScopedHandlers(formRows, rowManager) {
    // Clicking anywhere in a row makes it the active add-target (where the
    // palette "+" drops a field). Skip the delete button, which removes the row.
    formRows.addEventListener('click', function(e) {
        if (e.target.closest('.delete-row')) return;
        const row = e.target.closest('.layout-row');
        if (row) setActiveRow(row);
    });

    // Row tabs + row/field actions.
    formRows.addEventListener('click', function(e) {
        const rowTab = e.target.closest('.layout-row-content > .ef-tabs > .ef-tab');
        if (rowTab) {
            e.preventDefault();
            const tabName = rowTab.getAttribute('data-tab');
            const row = rowTab.closest('.layout-row');

            if (row && tabName) {
                rowTab.closest('.ef-tabs').querySelectorAll('.ef-tab').forEach(function(tab) {
                    tab.classList.remove('active');
                });
                rowTab.classList.add('active');

                row.querySelectorAll('.row-tab-pane').forEach(function(pane) {
                    pane.style.display = 'none';
                    pane.classList.remove('active');
                });

                const targetPane = row.querySelector('.row-tab-pane[data-pane="' + tabName + '"]');
                if (targetPane) {
                    targetPane.style.display = 'block';
                    targetPane.classList.add('active');
                }
            }
            return;
        }

        const layoutRow = e.target.closest('.layout-row');
        if (!layoutRow) return;

        // Delete row
        if (e.target.classList.contains('delete-row')) {
            if (confirm('Are you sure you want to delete this row and all its fields?')) {
                // Tear down the row's field Sortable so it isn't leaked.
                const fieldsContainer = layoutRow.querySelector('.layout-row-fields');
                if (fieldsContainer && window.Sortable) {
                    const instance = window.Sortable.get(fieldsContainer);
                    if (instance) instance.destroy();
                }
                layoutRow.remove();
                // Renumber the remaining rows so labels (and name indices) reflect
                // their new positions — otherwise a lone surviving row keeps a
                // stale "Row 2" label and a gap in its input-name indices.
                reindexRows(formRows);
            }
        }

        // Open the field settings dialog (snapshots state so Cancel can revert).
        if (e.target.classList.contains('toggle-field-settings')) {
            openFieldDialog(e.target.closest('.field-in-row'));
        }

        // Delete field
        if (e.target.classList.contains('delete-field')) {
            if (confirm('Are you sure you want to delete this field?')) {
                const fieldElement = e.target.closest('.field-in-row');
                const rowElement = e.target.closest('.layout-row');
                fieldElement.remove();

                const remainingFields = rowElement.querySelectorAll('.field-in-row');
                if (remainingFields.length === 0) {
                    const rowFields = rowElement.querySelector('.layout-row-fields');
                    const emptyMessage = document.createElement('div');
                    emptyMessage.className = 'empty-row-message';
                    emptyMessage.innerHTML = '<p class="light">This row is empty. Drag a field here from the sidebar.</p>';
                    rowFields.appendChild(emptyMessage);
                }

                rowManager.updateFieldCountBadge(rowElement);
            }
        }
    });

    // Click a field header to open its settings popover.
    formRows.addEventListener('click', function(e) {
        const fieldHeader = e.target.closest('.field-in-row-header');
        if (fieldHeader && !e.target.closest('button') && !e.target.closest('.lightswitch') && !e.target.closest('input')) {
            openFieldDialog(fieldHeader.closest('.field-in-row'));
        }
    });
}

/**
 * Install the document-delegated listeners a single time, regardless of how many
 * pages call setupEventHandlers.
 */
function installGlobalHandlers() {
    if (globalHandlersInstalled) return;
    globalHandlersInstalled = true;

    // A backdrop "click" should only close the dialog when the press genuinely
    // started on the backdrop. Selecting text inside the dialog and releasing the
    // mouse over the backdrop also fires a click whose target is the backdrop —
    // that must NOT close the dialog (the user is still editing). We remember where
    // the press began and require press + release to both land on the backdrop.
    let backdropPressTarget = null;
    document.addEventListener('mousedown', function(e) {
        backdropPressTarget = e.target.classList.contains('field-popover-backdrop')
            ? e.target
            : null;
    }, true);

    // Dialog actions. "Save field" commits (after validation); the ✕, a backdrop
    // click and "Cancel" all discard edits by reverting to the open-time snapshot.
    document.addEventListener('click', function(e) {
        const fieldEl = e.target.closest('.field-in-row');
        if (!fieldEl) return;

        const isBackdrop = e.target.classList.contains('field-popover-backdrop');
        // Only honour a backdrop click if the press also started on the backdrop.
        const backdropClosed = isBackdrop && backdropPressTarget === e.target;
        if (isBackdrop) backdropPressTarget = null;

        if (e.target.closest('.field-dialog-save')) {
            e.preventDefault();
            saveFieldDialog(fieldEl);
        } else if (e.target.closest('.field-dialog-cancel')
            || e.target.classList.contains('field-popover-close')
            || backdropClosed) {
            e.preventDefault();
            cancelFieldDialog(fieldEl);
        }
    });

    // While the dialog is open, keep the handle auto-derived from the label until
    // the user edits the handle directly. The card title and required marker are
    // deliberately NOT touched here — they update only on "Save field".
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('field-label-input')) {
            const fieldElement = e.target.closest('.field-in-row');
            const handleInput = fieldElement.querySelector('.field-handle-input');
            if (handleInput && handleInput.dataset.manuallyEdited !== 'true') {
                handleInput.value = (e.target.value || '').toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '');
            }
        }

        if (e.target.classList.contains('field-handle-input')) {
            e.target.dataset.manuallyEdited = 'true';
        }
    });

    // Field settings tab clicks (popover is a fixed overlay outside .form-rows).
    document.addEventListener('click', function(e) {
        const fieldTab = e.target.closest('.field-in-row-settings > .ef-tabs > .ef-tab');
        if (fieldTab) {
            e.preventDefault();
            const tabName = fieldTab.dataset.tab;
            const fieldSettings = fieldTab.closest('.field-in-row-settings');

            fieldTab.closest('.ef-tabs').querySelectorAll('.ef-tab').forEach(t => t.classList.remove('active'));
            fieldTab.classList.add('active');

            fieldSettings.querySelectorAll('.field-settings-pane').forEach(pane => {
                pane.style.display = pane.dataset.pane === tabName ? '' : 'none';
            });
        }
    });

    setupLocalizedToggles();
    setupLightswitchHandlers();
    setupQuickRequiredToggle();
    const refreshConditionHandles = setupConditionHandleDatalist();
    setupConditionRuleHandlers(refreshConditionHandles);
    setupLinkRowHandlers();
}

/**
 * The quick "Required" toggle in a field's row header. It flips the field's
 * required state without opening the dialog, keeping the Required-tab lightswitch
 * (the source-of-truth control) and the card's asterisk in step.
 *
 * The Required control is rendered two ways, so we toggle each correctly:
 *  - Saved/server-rendered fields use Craft's native `<button class="lightswitch">`,
 *    managed by Garnish. We drive it through its own instance API
 *    (`jQuery(el).data('lightswitch').turnOn()/.turnOff()`) so the class,
 *    aria-checked, hidden input and Garnish's internal state all stay in sync.
 *    (A synthetic `.click()` doesn't toggle it — Garnish toggles on its own
 *    drag/mouse handlers, not a plain click.)
 *  - Freshly-added fields use our custom `<div class="lightswitch">`; we flip its
 *    `.on` class and `'0'/'1'` hidden value directly (a synthetic click would
 *    re-enter this handler and desync — the original "only fires once" bug).
 */
function setupQuickRequiredToggle() {
    document.addEventListener('click', function(e) {
        const toggle = e.target.closest('.ef-quick-required');
        if (!toggle) return;
        e.preventDefault();

        const fieldEl = toggle.closest('.field-in-row');
        const requiredInput = fieldEl && fieldEl.querySelector('input[name$="[required]"]');
        if (!requiredInput) return;

        const lightswitch = requiredInput.closest('.lightswitch');
        const jq = window.jQuery;
        const garnish = (lightswitch && lightswitch.tagName === 'BUTTON' && jq)
            ? jq(lightswitch).data('lightswitch')
            : null;

        // Current state from each switch type's source of truth.
        const isOn = garnish ? !!garnish.on
            : (lightswitch ? lightswitch.classList.contains('on') : requiredInput.value === '1');
        const willBeOn = !isOn;

        if (garnish) {
            willBeOn ? garnish.turnOn() : garnish.turnOff();
        } else if (lightswitch) {
            lightswitch.classList.toggle('on', willBeOn);
            requiredInput.value = willBeOn ? '1' : '0';
        } else {
            requiredInput.value = willBeOn ? '1' : '0';
        }

        toggle.classList.toggle('is-on', willBeOn);
        toggle.setAttribute('aria-pressed', willBeOn ? 'true' : 'false');
        setRequiredIndicator(fieldEl, willBeOn);
    });
}

/**
 * Suggest the form's known field handles in condition-rule "Field Handle" inputs
 * via a shared <datalist>, so authors get autocomplete without losing free-text
 * flexibility. Scans the whole document so handles from every page are offered.
 * Returns a refresh fn to call after the field set changes.
 */
function setupConditionHandleDatalist() {
    let datalist = document.getElementById('ef-condition-handles');
    if (!datalist) {
        datalist = document.createElement('datalist');
        datalist.id = 'ef-condition-handles';
        document.body.appendChild(datalist);
    }

    function refresh() {
        const handles = new Set();
        document.querySelectorAll('.field-handle-input, input[name$="[handle]"]').forEach(input => {
            const v = (input.value || '').trim();
            if (v) handles.add(v);
        });
        datalist.innerHTML = [...handles]
            .map(h => `<option value="${h.replace(/"/g, '&quot;')}"></option>`)
            .join('');

        // Associate every condition source input (server-rendered + JS-built).
        document.querySelectorAll('.condition-rule input[name$="[field]"]').forEach(input => {
            input.setAttribute('list', 'ef-condition-handles');
        });
    }

    refresh();

    // Keep suggestions current as fields are added / renamed.
    document.addEventListener('input', function (e) {
        if (e.target.classList && (e.target.classList.contains('field-handle-input') || e.target.classList.contains('field-label-input'))) {
            refresh();
        }
    });

    return refresh;
}

/** Add/remove the required asterisk on a field card from an explicit state. */
function setRequiredIndicator(fieldElement, isRequired) {
    if (!fieldElement) return;

    const labelContainer = fieldElement.querySelector('.field-in-row-label');
    const labelText = fieldElement.querySelector('.field-label-text');
    if (!labelContainer || !labelText) return;

    const existingIndicator = labelContainer.querySelector('.required-indicator');
    if (existingIndicator) {
        existingIndicator.remove();
    }

    if (isRequired) {
        const indicator = document.createElement('span');
        indicator.className = 'required-indicator';
        indicator.textContent = '*';
        labelText.insertAdjacentElement('afterend', indicator);
    }
}

function setupChangeHandlers(formRows) {
    formRows.addEventListener('change', function(e) {
        if (e.target.classList.contains('field-type-select')) {
            const fieldElement = e.target.closest('.field-in-row');
            const type = e.target.value;
            fieldElement.querySelector('.field-type-badge').textContent = type.toUpperCase();
        }
    });
}

/**
 * Expand/collapse the per-site "Translations" block under a localized field.
 * Delegated once on the document so it covers server-rendered and JS-built
 * fields alike (the block is the toggle's next sibling, `.ef-localized-others`).
 */
function setupLocalizedToggles() {
    document.addEventListener('click', function (e) {
        const toggle = e.target.closest('.ef-localized-toggle');
        if (!toggle) return;
        const others = toggle.nextElementSibling;
        if (!others || !others.classList.contains('ef-localized-others')) return;
        const willOpen = others.hidden;
        others.hidden = !willOpen;
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
}

function setupLightswitchHandlers() {
    document.addEventListener('click', function(e) {
        // Only our custom <div class="lightswitch"> switches (built in
        // field-manager.js). Craft's native lightswitches are <button> elements
        // managed by Garnish — never touch those, or they double-toggle.
        const lightswitch = e.target.closest('div.lightswitch');
        if (lightswitch && !e.target.classList.contains('btn')) {
            const hiddenInput = lightswitch.querySelector('input[type="hidden"]');

            if (hiddenInput) {
                if (lightswitch.classList.contains('on')) {
                    lightswitch.classList.remove('on');
                    hiddenInput.value = '0';
                } else {
                    lightswitch.classList.add('on');
                    hiddenInput.value = '1';
                }
                hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });
}

function setupConditionRuleHandlers(refreshHandles) {
    document.addEventListener('click', function(e) {
        // Add condition rule
        if (e.target.classList.contains('add-condition-rule') || e.target.closest('.add-condition-rule')) {
            const btn = e.target.closest('.add-condition-rule') || e.target;
            const prefix = btn.dataset.fieldPrefix;
            // Locate the rules container by its matching prefix — the button no
            // longer sits directly after it (it shares the Action/Logic row).
            const rulesContainer = document.querySelector(
                '.condition-rules[data-field-prefix="' + prefix + '"]'
            );
            if (!rulesContainer) return;

            const ruleIndex = rulesContainer.querySelectorAll('.condition-rule').length;
            const rp = `${prefix}[conditions][rules][${ruleIndex}]`;

            const ruleDiv = document.createElement('div');
            ruleDiv.className = 'condition-rule';
            // Layout (flex row + part widths) lives in form-builder.css so saved
            // (Twig) and new (JS) rules render identically.
            // Per-rule site scope — only meaningful on multi-site installs.
            const sites = window.CraftSites || [];
            const siteSelect = sites.length > 1
                ? `<select name="${rp}[site]" class="text ef-cond-site" title="Applies on">
                        <option value="all">All sites</option>
                        ${sites.map(s => `<option value="${escapeHtml(s.handle)}">${escapeHtml(s.name)}</option>`).join('')}
                    </select>`
                : '';
            ruleDiv.innerHTML = `
                <input type="text" name="${rp}[field]" placeholder="Field handle" class="text ef-cond-field" list="ef-condition-handles">
                <select name="${rp}[operator]" class="text ef-cond-operator">
                    <option value="equals">Equals</option>
                    <option value="notEquals">Not Equals</option>
                    <option value="contains">Contains</option>
                    <option value="notContains">Not Contains</option>
                    <option value="isEmpty">Is Empty</option>
                    <option value="isNotEmpty">Is Not Empty</option>
                </select>
                <input type="text" name="${rp}[value]" placeholder="Value" class="text ef-cond-value">
                ${siteSelect}
                <button type="button" class="btn small remove-condition-rule" title="Remove"><span data-icon="trash" aria-label="Remove"></span></button>
            `;
            rulesContainer.appendChild(ruleDiv);
            if (refreshHandles) refreshHandles();
        }

        // Remove condition rule
        if (e.target.classList.contains('remove-condition-rule') || e.target.closest('.remove-condition-rule')) {
            const btn = e.target.closest('.remove-condition-rule') || e.target;
            const ruleDiv = btn.closest('.condition-rule');
            if (ruleDiv) ruleDiv.remove();
        }
    });
}

/* ── Field settings dialog: open / save / cancel ─────────────────────────── */

function fieldSettingsEl(fieldEl) {
    return fieldEl ? fieldEl.querySelector('.field-in-row-settings') : null;
}

/**
 * Agree-field link rows: add (server-rendered so the Entry picker works without
 * saving) and remove. Mirrors the server-rendered field flow in server-field.js:
 * the row's element select is Craft markup that needs the init JS Craft registers
 * while rendering, so we inject the returned html and run its head/body HTML.
 */
function setupLinkRowHandlers() {
    document.addEventListener('click', function(e) {
        const addBtn = e.target.closest('.add-link-row');
        if (addBtn) {
            const prefix = addBtn.dataset.fieldPrefix;
            const site = addBtn.dataset.site;
            // The prefix carries [] brackets; they're literal inside a quoted
            // attribute selector, matching how condition rules locate their list.
            const list = document.querySelector(
                '.ef-link-list[data-field-prefix="' + prefix + '"][data-site="' + site + '"]'
            );
            if (!list) return;

            // A monotonic per-list counter avoids name collisions after removals
            // (PHP re-indexes the list on save anyway).
            const index = parseInt(list.dataset.nextIndex || list.querySelectorAll('.ef-link-row').length, 10);
            list.dataset.nextIndex = index + 1;

            addBtn.disabled = true;
            Craft.sendActionRequest('POST', 'easy-form/forms/link-row', {
                data: { fieldPrefix: prefix, site: site, index: index },
            }).then(response => {
                const payload = (response && response.data) || {};
                const tmp = document.createElement('div');
                tmp.innerHTML = (payload.html || '').trim();
                const rowEl = tmp.firstElementChild;
                if (!rowEl) return;
                list.appendChild(rowEl);
                // Markup is in the DOM first so Craft's init finds its container,
                // then run the CSS/JS Craft registered (the element select).
                if (payload.headHtml) Craft.appendHeadHtml(payload.headHtml);
                if (payload.bodyHtml) Craft.appendBodyHtml(payload.bodyHtml);
                if (Craft.initUiElements) Craft.initUiElements(rowEl);
            }).catch(error => {
                console.error('easy-form: could not add link row', error);
                if (window.Craft && Craft.cp && typeof Craft.cp.displayError === 'function') {
                    Craft.cp.displayError('Could not add link. Please try again.');
                }
            }).finally(() => { addBtn.disabled = false; });
            return;
        }

        const rmBtn = e.target.closest('.remove-link-row');
        if (rmBtn) {
            const row = rmBtn.closest('.ef-link-row');
            if (row) row.remove();
        }
    });
}

/**
 * Serialize each `.condition-rules` container to HTML for snapshot/compare.
 *
 * Condition rules are added/removed dynamically, so HTML is the simplest faithful
 * capture — but a control's typed value lives on its DOM *property*, not its
 * `value` attribute, so plain innerHTML would miss edits. We mirror each control's
 * current value into its attribute first, making the serialized HTML accurate for
 * both dirty-checking and restore-on-cancel.
 */
function serializeConditionRules(settings) {
    return Array.from(settings.querySelectorAll('.condition-rules')).map(container => {
        container.querySelectorAll('input, textarea, select').forEach(el => {
            if (el.tagName === 'SELECT') {
                Array.from(el.options).forEach(o => o.toggleAttribute('selected', o.selected));
            } else if (el.tagName === 'TEXTAREA') {
                el.textContent = el.value;
            } else if (el.type === 'checkbox' || el.type === 'radio') {
                el.toggleAttribute('checked', el.checked);
            } else {
                el.setAttribute('value', el.value);
            }
        });
        return container.innerHTML;
    });
}

/** Whether the dialog's current state differs from its open-time snapshot. */
function isDialogDirty(fieldEl) {
    const settings = fieldSettingsEl(fieldEl);
    const snap = dialogSnapshots.get(fieldEl);
    if (!settings || !snap) return false;

    if (snap.values.some(({ el, value }) => el.isConnected && el.value !== value)) return true;
    if (snap.checks.some(({ el, checked }) => el.isConnected && el.checked !== checked)) return true;

    const switches = settings.querySelectorAll('.lightswitch');
    if (snap.lightswitches.some((on, i) => switches[i] && switches[i].classList.contains('on') !== on)) {
        return true;
    }

    const conditions = serializeConditionRules(settings);
    if (conditions.length !== snap.conditions.length) return true;
    return conditions.some((html, i) => html !== snap.conditions[i]);
}

/**
 * Open a field's settings dialog. Snapshots every control's current state so a
 * later Cancel / ✕ / backdrop click can restore it untouched.
 *
 * Lightswitches are captured as on/off booleans (and reverted via a real click,
 * which routes correctly through both our custom switches and Craft's native,
 * Garnish-managed ones). Condition rules are dynamic, so we snapshot each
 * `.condition-rules` container's HTML. Everything else is captured by value.
 */
export function openFieldDialog(fieldEl) {
    const settings = fieldSettingsEl(fieldEl);
    if (settings) {
        const snap = { values: [], checks: [], lightswitches: [], conditions: [] };
        settings.querySelectorAll('input, textarea, select').forEach(el => {
            if (!el.name || el.closest('.lightswitch') || el.closest('.condition-rules')) return;
            if (el.type === 'checkbox' || el.type === 'radio') {
                snap.checks.push({ el: el, checked: el.checked });
            } else {
                snap.values.push({ el: el, value: el.value });
            }
        });
        snap.lightswitches = Array.from(settings.querySelectorAll('.lightswitch'))
            .map(ls => ls.classList.contains('on'));
        snap.conditions = serializeConditionRules(settings);
        dialogSnapshots.set(fieldEl, snap);
        clearDialogError(fieldEl);
    }

    const backdrop = fieldEl && fieldEl.querySelector('.field-popover-backdrop');
    if (backdrop) backdrop.style.display = 'flex';

    // The backdrop is position:fixed but it lives inside .layout-row, which is
    // a stacking context (position:relative; z-index:1). A later sibling row
    // (same z-index, later in DOM) would otherwise paint over the modal. Lift
    // this row's stacking context above its siblings while the dialog is open.
    const ownerRow = fieldEl && fieldEl.closest('.layout-row');
    if (ownerRow) ownerRow.classList.add('ef-dialog-open');
}

/** Commit the dialog: validate, then push label/required to the card and close. */
function saveFieldDialog(fieldEl) {
    const problems = validateFieldDialog(fieldEl);
    if (problems.length) {
        showDialogError(fieldEl, problems);
        return;
    }
    // Push label/required to the card defensively: an unexpected error here must
    // still close the dialog (the field's inputs persist in the DOM and are saved
    // with the form), so it can never leave a stuck-open modal.
    try {
        applyCardFromSettings(fieldEl);
    } catch (e) {
        console.error('[easy-form] applying field settings failed:', e);
    }
    clearDialogError(fieldEl);
    dialogSnapshots.delete(fieldEl);
    closeBackdrop(fieldEl);
}

/** Discard the dialog's edits, restoring the snapshot taken when it opened. */
function cancelFieldDialog(fieldEl) {
    // Warn before throwing away edits — but only if something actually changed,
    // so an accidental ✕/backdrop click on an untouched dialog closes silently.
    if (isDialogDirty(fieldEl)
        && !window.confirm('You have unsaved changes to this field. Discard them?')) {
        return;
    }

    // Restore the snapshot defensively, then always close — a failed revert must
    // not leave the dialog stuck open.
    try {
        const settings = fieldSettingsEl(fieldEl);
        const snap = dialogSnapshots.get(fieldEl);
        if (settings && snap) {
            // Rebuild condition rules first (they're the only structural change).
            const condContainers = settings.querySelectorAll('.condition-rules');
            snap.conditions.forEach((html, i) => {
                if (condContainers[i]) condContainers[i].innerHTML = html;
            });
            snap.values.forEach(({ el, value }) => { if (el.isConnected) el.value = value; });
            snap.checks.forEach(({ el, checked }) => { if (el.isConnected) el.checked = checked; });

            // Lightswitches: click only when the current state differs from the
            // snapshot, so we go through the proper (custom or Garnish) toggle path.
            const switches = settings.querySelectorAll('.lightswitch');
            snap.lightswitches.forEach((wasOn, i) => {
                const ls = switches[i];
                if (ls && ls.classList.contains('on') !== wasOn) ls.click();
            });
        }
    } catch (e) {
        console.error('[easy-form] reverting field settings failed:', e);
    }
    clearDialogError(fieldEl);
    dialogSnapshots.delete(fieldEl);
    closeBackdrop(fieldEl);
}

function closeBackdrop(fieldEl) {
    const backdrop = fieldEl && fieldEl.querySelector('.field-popover-backdrop');
    if (backdrop) backdrop.style.display = 'none';
    const ownerRow = fieldEl && fieldEl.closest('.layout-row');
    if (ownerRow) ownerRow.classList.remove('ef-dialog-open');
}

/** Per-field checks run on "Save field": label + handle present, valid, unique. */
function validateFieldDialog(fieldEl) {
    const problems = [];
    const labelInput = fieldEl.querySelector('.field-label-input');
    const handleInput = fieldEl.querySelector('.field-handle-input');
    [labelInput, handleInput].forEach(i => i && i.classList.remove('error'));

    const label = labelInput ? labelInput.value.trim() : '';
    const handle = handleInput ? handleInput.value.trim() : '';

    if (!label) {
        problems.push('Give the field a label.');
        if (labelInput) labelInput.classList.add('error');
    }

    if (!handle) {
        problems.push('Give the field a handle.');
        if (handleInput) handleInput.classList.add('error');
    } else if (!HANDLE_PATTERN.test(handle)) {
        problems.push('Handle “' + handle + '” is invalid — start with a letter and use only letters, numbers and underscores.');
        if (handleInput) handleInput.classList.add('error');
    } else {
        const duplicate = Array.from(document.querySelectorAll('.field-in-row')).some(other => {
            if (other === fieldEl) return false;
            const h = other.querySelector('.field-handle-input');
            return h && h.value.trim() === handle;
        });
        if (duplicate) {
            problems.push('Handle “' + handle + '” is already used by another field.');
            if (handleInput) handleInput.classList.add('error');
        }
    }

    return problems;
}

/** Push the committed label / required state from the dialog onto the field card. */
function applyCardFromSettings(fieldEl) {
    const labelInput = fieldEl.querySelector('.field-label-input');
    const label = labelInput && labelInput.value.trim() ? labelInput.value.trim() : 'New Field';

    const cardLabel = fieldEl.querySelector('.field-label-text');
    if (cardLabel) cardLabel.textContent = label;
    const header = fieldEl.querySelector('.field-popover-header strong');
    if (header) header.textContent = label;

    const requiredInput = fieldEl.querySelector('[name$="[required]"]');
    if (requiredInput) {
        // Read the committed state from the switch's `.on` class — reliable for
        // both our custom switch and Craft's native one (whose off-value is empty).
        const ls = requiredInput.closest('.lightswitch');
        const isRequired = ls ? ls.classList.contains('on') : requiredInput.value === '1';
        setRequiredIndicator(fieldEl, isRequired);
        syncQuickRequiredToggle(fieldEl, isRequired);
    }
}

/** Reflect the field's required state on the header's quick "Required" toggle. */
function syncQuickRequiredToggle(fieldEl, isRequired) {
    const toggle = fieldEl.querySelector('.ef-quick-required');
    if (!toggle) return;
    toggle.classList.toggle('is-on', isRequired);
    toggle.setAttribute('aria-pressed', isRequired ? 'true' : 'false');
}

function showDialogError(fieldEl, problems) {
    const box = fieldEl.querySelector('.field-dialog-error');
    if (box) {
        box.innerHTML = problems.map(p => '<div>' + escapeHtml(p) + '</div>').join('');
        box.hidden = false;
    }

    // Surface the offending inputs: they live on the General tab.
    const settings = fieldSettingsEl(fieldEl);
    const generalTab = settings && settings.querySelector('.ef-tab[data-tab="general"]');
    if (generalTab && !generalTab.classList.contains('active')) generalTab.click();

    const firstBad = fieldEl.querySelector('.field-label-input.error, .field-handle-input.error');
    if (firstBad) firstBad.focus();
}

function clearDialogError(fieldEl) {
    if (!fieldEl) return;
    const box = fieldEl.querySelector('.field-dialog-error');
    if (box) { box.hidden = true; box.innerHTML = ''; }
    fieldEl.querySelectorAll('.field-label-input.error, .field-handle-input.error')
        .forEach(i => i.classList.remove('error'));
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, c => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
}
