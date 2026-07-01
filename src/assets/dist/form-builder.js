/**
 * Form Builder JavaScript - Page & Row-based Layout
 * Refactored modular version with multi-page support
 */

import { createRowManager } from './modules/row-manager.js';
import { createFieldManager, localizedField } from './modules/field-manager.js';
import { setupEventHandlers } from './modules/event-handlers.js';
import { setupDragAndDrop } from './modules/drag-handler.js';
import { setupNotifications } from './modules/notifications.js';
import { setupFrontendFields } from './modules/frontend-fields.js';
import { setupSaveValidation } from './modules/save-validation.js';
import { setupPaletteClickToAdd, setActiveRow, syncActiveRowToPane } from './modules/palette-add.js';
import { siteEnableBlock } from './modules/site-enable.js';

(function() {
    'use strict';

    let pageIndexRef = { current: 0 };

    // Defensive wrapper: a thrown error in one piece of builder setup (or one
    // handler) must not brick the whole editor and leave a half-wired, stale
    // interface. Failures are logged to the console and surfaced once so the
    // admin knows to reload, while the rest of the builder keeps working.
    let errorNotified = false;
    function safe(label, fn) {
        try {
            return fn();
        } catch (e) {
            console.error('[easy-form] ' + label + ' failed:', e);
            if (!errorNotified) {
                errorNotified = true;
                if (window.Craft && Craft.cp && typeof Craft.cp.displayError === 'function') {
                    Craft.cp.displayError('The form builder hit an unexpected error. Some controls may not work — reload the page to recover.');
                }
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        setupFormNameHandleGeneration();
        safe('notifications setup', setupNotifications);
        safe('frontend-fields setup', setupFrontendFields);
        safe('save-validation setup', setupSaveValidation);

        const pagePanes = document.getElementById('page-panes');
        if (!pagePanes) return;

        // Count existing pages
        const existingPanes = pagePanes.querySelectorAll('.page-pane');
        pageIndexRef.current = existingPanes.length;

        // Wire the palette "+" click-to-add once (it's a single global palette).
        safe('palette setup', setupPaletteClickToAdd);

        // Initialize each existing page pane — isolate each so one bad pane
        // doesn't stop the others from wiring up.
        existingPanes.forEach(pane => {
            safe('init page pane', () => initPagePane(pane));
        });

        // Highlight an initial add-target row on the first (active) page.
        const firstPane = pagePanes.querySelector('.page-pane.active') || pagePanes.querySelector('.page-pane');
        if (firstPane) safe('active-row highlight', () => syncActiveRowToPane(firstPane));

        // Page tab switching
        const pageTabs = document.getElementById('page-tabs');
        if (pageTabs) {
            pageTabs.addEventListener('click', function(e) {
                const tab = e.target.closest('.page-tab');
                if (!tab) return;
                safe('page tab switch', () => {
                    const pageIdx = tab.dataset.tab;
                    pageTabs.querySelectorAll('.page-tab').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    pagePanes.querySelectorAll('.page-pane').forEach(p => {
                        p.style.display = 'none';
                        p.classList.remove('active');
                    });
                    const target = pagePanes.querySelector(`.page-pane[data-page-pane="${pageIdx}"]`);
                    if (target) {
                        target.style.display = '';
                        target.classList.add('active');
                        // Keep the add-target highlight on the visible page.
                        syncActiveRowToPane(target);
                    }
                });
            });

            // Add Page button
            const addPageBtn = document.getElementById('add-page-btn');
            if (addPageBtn) {
                addPageBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    safe('add page', () => addPage(pagePanes, pageTabs));
                });
            }
        }
    }

    // Wire a page pane's inner tabs (Fields / Labels / Sites). Scoped to the
    // pane so it never clashes with the row-level tabs inside .form-rows.
    function setupPageInnerTabs(pane) {
        const nav = pane.querySelector('.ef-page-inner-tabs');
        if (!nav) return;
        nav.addEventListener('click', function(e) {
            const tab = e.target.closest('.ef-tab');
            if (!tab) return;
            e.preventDefault();
            const name = tab.dataset.innerTab;
            nav.querySelectorAll('.ef-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            pane.querySelectorAll(':scope > .page-inner-pane').forEach(p => {
                const match = p.dataset.innerPane === name;
                p.style.display = match ? '' : 'none';
                p.classList.toggle('active', match);
            });
        });
    }

    function initPagePane(pane) {
        const pageIndex = parseInt(pane.dataset.pagePane, 10);
        safe('page inner tabs', () => setupPageInnerTabs(pane));
        const formRows = pane.querySelector('.form-rows');
        if (!formRows) return;

        const rowIndexRef = { current: parseInt(formRows.dataset.rowCount || '0', 10) };
        const rowManager = createRowManager(formRows, rowIndexRef, pageIndex);
        const fieldManager = createFieldManager();

        // Expose this page's row manager so the global palette click-to-add
        // handler can target the active pane's rows.
        pane._efRowManager = rowManager;

        // Add Row button for this page
        const addRowBtn = pane.querySelector('.add-row-btn');
        if (addRowBtn) {
            addRowBtn.addEventListener('click', function() {
                safe('add row', () => {
                    const layoutRow = rowManager.addRow();
                    rowManager.updateFieldCountBadge(layoutRow);
                    // A freshly added row becomes the active add-target.
                    setActiveRow(layoutRow);
                });
            });
        }

        safe('event handlers setup', () => setupEventHandlers(formRows, fieldManager, rowManager));
        safe('drag-and-drop setup', () => setupDragAndDrop(formRows, rowManager));
    }

    function addPage(pagePanes, pageTabs) {
        const idx = pageIndexRef.current;
        const label = 'Page ' + (idx + 1);

        // Create tab link
        const tabBtn = document.createElement('a');
        tabBtn.className = 'ef-tab page-tab';
        tabBtn.dataset.tab = idx;
        tabBtn.textContent = label;
        // Insert before the "Add Page" button
        const addPageBtn = document.getElementById('add-page-btn');
        addPageBtn.parentNode.insertBefore(tabBtn, addPageBtn);

        // Create pane
        const pane = document.createElement('div');
        pane.className = 'page-pane';
        pane.dataset.pagePane = idx;
        pane.style.display = 'none';
        // Per-site enable block is empty on single-site installs — only then
        // do we drop the Sites tab to mirror the server-rendered panes.
        const sitesBlock = siteEnableBlock(`pages[${idx}]`, {}, 'page');
        const sitesTab = sitesBlock ? `<a class="ef-tab" data-inner-tab="sites">Sites</a>` : '';
        const sitesPane = sitesBlock
            ? `<div class="page-inner-pane" data-inner-pane="sites" style="display: none;">${sitesBlock}</div>`
            : '';

        pane.innerHTML = `
            <input type="hidden" name="pages[${idx}][id]" value="page_${idx + 1}">
            <div class="page-header" style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                <div class="field">
                    <div class="heading"><label>Page Label</label></div>
                    <div class="input ltr">
                        <input type="text" name="pages[${idx}][label]" value="${label}" class="text page-label-input" size="30">
                    </div>
                </div>
                <button type="button" class="btn small remove-page-btn" data-page-index="${idx}">Remove Page</button>
            </div>
            <nav class="ef-tabs ef-page-inner-tabs">
                <a class="ef-tab active" data-inner-tab="fields">Fields</a>
                <a class="ef-tab" data-inner-tab="labels">Labels</a>
                ${sitesTab}
            </nav>
            <div class="page-inner-pane active" data-inner-pane="fields">
                <div class="form-rows" data-page-index="${idx}" data-row-count="0"></div>
                <div class="buttons">
                    <button type="button" class="btn add-row-btn" data-page-index="${idx}">Add Row</button>
                </div>
            </div>
            <div class="page-inner-pane" data-inner-pane="labels" style="display: none;">
                ${localizedField({
                    label: 'Next button label',
                    instructions: 'Shown on this page\'s "Next" button. Defaults to "Next".',
                    baseName: `pages[${idx}][nextLabel]`,
                    mapName: `pages[${idx}][siteNextLabels]`,
                    placeholder: 'Next',
                })}
                ${localizedField({
                    label: 'Previous button label',
                    instructions: 'Shown on this page\'s "Previous" button. Defaults to "Previous".',
                    baseName: `pages[${idx}][prevLabel]`,
                    mapName: `pages[${idx}][sitePrevLabels]`,
                    placeholder: 'Previous',
                })}
            </div>
            ${sitesPane}
        `;
        pagePanes.appendChild(pane);

        // Sync page label input to tab text
        const labelInput = pane.querySelector('.page-label-input');
        if (labelInput) {
            labelInput.addEventListener('input', function() {
                tabBtn.textContent = this.value || ('Page ' + (idx + 1));
            });
        }

        // Remove page handler
        const removeBtn = pane.querySelector('.remove-page-btn');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                safe('remove page', () => {
                    if (confirm('Remove this page and all its rows/fields?')) {
                        tabBtn.remove();
                        pane.remove();
                        // Activate first remaining page
                        const firstTab = pageTabs.querySelector('.page-tab');
                        if (firstTab) firstTab.click();
                    }
                });
            });
        }

        initPagePane(pane);
        pageIndexRef.current++;

        // Activate the new page
        tabBtn.click();
    }

    function setupFormNameHandleGeneration() {
        const nameInput = document.getElementById('name');
        const handleInput = document.getElementById('handle');
        
        if (nameInput && handleInput) {
            nameInput.addEventListener('input', function() {
                const handle = this.value
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '');
                handleInput.value = handle;
            });
        }
    }
})();
