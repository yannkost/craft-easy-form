/**
 * Row Manager Module
 * Handles row creation, deletion, and management
 */

import { initFieldSortable } from './drag-handler.js';
import { siteEnableBlock } from './site-enable.js';

export function createRowManager(formRows, rowIndexRef, pageIndex) {
    pageIndex = pageIndex || 0;
    const prefix = `pages[${pageIndex}][rows]`;

    function addRow(rowData) {
        rowData = rowData || {
            id: 'row_' + (pageIndex + 1) + '_' + (rowIndexRef.current + 1),
            fields: [],
            rowId: '',
            classList: ''
        };
        
        const ri = rowIndexRef.current;
        const rp = `${prefix}[${ri}]`;
        const layoutRow = document.createElement('div');
        layoutRow.className = 'layout-row';
        layoutRow.dataset.rowIndex = ri;
        layoutRow.dataset.pageIndex = pageIndex;
        
        layoutRow.innerHTML = `<div class="layout-row-header">
                <div class="layout-row-handle">
                    <span class="move icon" title="Reorder"></span>
                </div>
                <div class="layout-row-label">
                    <strong>Row ${ri + 1}</strong>
                    <span class="field-count-badge">0 fields</span>
                    <span class="ef-row-target-badge">Fields land here</span>
                </div>
                <div class="layout-row-actions">
                    <button type="button" class="btn small delete-row" title="Delete Row"><span data-icon="trash" aria-label="Delete Row"></span></button>
                </div>
            </div>
            <div class="layout-row-content">
                <nav class="ef-tabs">
                    <a class="ef-tab active" data-tab="fields">Fields</a>
                    <a class="ef-tab" data-tab="settings">Settings</a>
                    <a class="ef-tab" data-tab="conditions">Conditions</a>
                </nav>
                <div class="row-tab-content">
                    <div class="row-tab-pane active" data-pane="fields">
                        <div class="layout-row-fields">
                            <input type="hidden" name="${prefix}[${ri}][id]" value="${rowData.id}">
                            <div class="empty-row-message">
                                <p class="light">This row is empty. Drag a field here from the sidebar.</p>
                            </div>
                        </div>
                    </div>
                    <div class="row-tab-pane" data-pane="settings" style="display: none;">
                        <div class="layout-row-settings">
                            <div class="field">
                                <div class="heading"><label>Row ID</label></div>
                                <div class="input ltr">
                                    <input type="text" name="${prefix}[${ri}][rowId]" value="${rowData.rowId || ''}" class="text code fullwidth" placeholder="e.g., contact-row">
                                </div>
                                <div class="instructions"><p>HTML ID attribute for this row.</p></div>
                            </div>
                            <div class="field">
                                <div class="heading"><label>CSS Classes</label></div>
                                <div class="input ltr">
                                    <input type="text" name="${prefix}[${ri}][classList]" value="${rowData.classList || ''}" class="text code fullwidth" placeholder="e.g., form-row custom-row">
                                </div>
                                <div class="instructions"><p>Space-separated CSS classes for this row.</p></div>
                            </div>
                            ${siteEnableBlock(rp, rowData.siteEnabled, 'row')}
                        </div>
                    </div>
                    <div class="row-tab-pane" data-pane="conditions" style="display: none;">
                        <p class="light">Show or hide this entire row based on the value of another field.</p>
                        <div class="field"><div class="heading"><label>Action</label></div><div class="input ltr">
                            <select name="${rp}[conditions][action]" class="text fullwidth">
                                <option value="show">Show this row when...</option>
                                <option value="hide">Hide this row when...</option>
                            </select>
                        </div></div>
                        <div class="field"><div class="heading"><label>Logic</label></div><div class="input ltr">
                            <select name="${rp}[conditions][logic]" class="text fullwidth">
                                <option value="all">All rules match (AND)</option>
                                <option value="any">Any rule matches (OR)</option>
                            </select>
                        </div></div>
                        <div class="condition-rules" data-field-prefix="${rp}"></div>
                        <button type="button" class="btn add-condition-rule" data-field-prefix="${rp}">Add Rule</button>
                    </div>
                </div>
            </div>`;
        
        formRows.appendChild(layoutRow);
        rowIndexRef.current++;
        
        // Initialize Sortable for the new row's fields
        initFieldSortable(layoutRow.querySelector('.layout-row-fields'), { updateFieldCountBadge });
        
        return layoutRow;
    }
    
    function getPageIndex() {
        return pageIndex;
    }

    function updateFieldCountBadge(rowElement) {
        const fieldCount = rowElement.querySelectorAll('.field-in-row').length;
        const badge = rowElement.querySelector('.field-count-badge');
        if (badge) {
            badge.textContent = fieldCount + ' field' + (fieldCount !== 1 ? 's' : '');
        }
    }
    
    return {
        addRow,
        getPageIndex,
        updateFieldCountBadge
    };
}
