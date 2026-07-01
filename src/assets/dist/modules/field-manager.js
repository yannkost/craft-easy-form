/**
 * Field Manager Module
 * Handles field creation, deletion, and management within rows
 */

import { siteEnableBlock } from './site-enable.js';

function efEsc(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
        .replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function localizedControl(type, name, value, placeholder, cls, required, rows) {
    const ph = placeholder ? ` placeholder="${efEsc(placeholder)}"` : '';
    if (type === 'textarea') {
        return `<textarea name="${name}" rows="${rows || 3}" class="${cls}"${ph}>${efEsc(value)}</textarea>`;
    }
    return `<input type="text" name="${name}" value="${efEsc(value)}" class="${cls}"${ph}${required ? ' required' : ''}>`;
}

/**
 * A localized text field: the primary site's input plus a collapsible
 * "Translations" block for the other sites. Mirrors _localized.twig so
 * newly-built fields match server-rendered ones. New fields have no saved
 * values, so mapValues is normally empty.
 *
 * opts: label, instructions, type ('text'|'textarea'), rows, placeholder,
 *       baseName, baseValue, mapName, mapValues, inputClass, required,
 *       othersFallback ('primary' | 'placeholder').
 */
export function localizedField(opts) {
    const sites = window.CraftSites || [];
    const primary = sites.find(s => s.primary) || sites[0] || { name: '', handle: '' };
    const others = sites.filter(s => s.handle !== primary.handle);
    const multi = others.length > 0;
    const type = opts.type || 'text';
    const rows = opts.rows || 3;
    const mapValues = opts.mapValues || {};
    const primaryName = opts.baseName != null ? opts.baseName : `${opts.mapName}[${primary.handle}]`;
    const primaryValue = opts.baseName != null ? (opts.baseValue || '') : (mapValues[primary.handle] || '');
    const primaryClass = ('text fullwidth ' + (opts.inputClass || '')).trim();
    const othersPlaceholder = opts.othersFallback === 'placeholder' ? (opts.placeholder || '') : primaryValue;

    const siteTag = multi ? ` <span class="ef-localized-site">${efEsc(primary.name)} (${efEsc(primary.handle)})</span>` : '';
    const instr = opts.instructions ? `<div class="instructions"><p>${efEsc(opts.instructions)}</p></div>` : '';

    let html = `<div class="field ef-localized">
                        <div class="heading"><label>${efEsc(opts.label)}${siteTag}</label>${instr}</div>
                        <div class="input ltr">${localizedControl(type, primaryName, primaryValue, opts.placeholder, primaryClass, opts.required, rows)}</div>`;

    if (multi) {
        const word = others.length === 1 ? 'site' : 'sites';
        const rowsHtml = others.map(s => `
                            <div class="field">
                                <div class="heading"><label><span class="ef-localized-badge" aria-hidden="true">${efEsc(s.lang || '')}</span>${efEsc(s.name)} <span class="ef-localized-site">(${efEsc(s.handle)})</span></label></div>
                                <div class="input ltr">${localizedControl(type, `${opts.mapName}[${s.handle}]`, mapValues[s.handle], othersPlaceholder, 'text fullwidth', false, rows)}</div>
                            </div>`).join('');
        html += `
                        <button type="button" class="ef-localized-toggle" aria-expanded="false"><span class="ef-localized-caret" aria-hidden="true"></span><span class="ef-localized-globe" aria-hidden="true"></span>Translations <span class="ef-localized-count">${others.length} ${word}</span></button>
                        <div class="ef-localized-others" hidden>${rowsHtml}</div>`;
    }

    return html + `</div>`;
}

export function createFieldManager() {
    function addFieldToRow(rowElement, fieldData, updateFieldCountBadge) {
        const rowFields = rowElement.querySelector('.layout-row-fields');
        const currentRowIndex = parseInt(rowElement.dataset.rowIndex, 10);
        const currentPageIndex = parseInt(rowElement.dataset.pageIndex || '0', 10);
        const existingFields = rowFields.querySelectorAll('.field-in-row');
        const fieldIndex = existingFields.length;
        const fp = `pages[${currentPageIndex}][rows][${currentRowIndex}][fields][${fieldIndex}]`;
        
        const defaults = {
            id: 'field_' + currentPageIndex + '_' + currentRowIndex + '_' + fieldIndex,
            type: 'text',
            label: '',
            handle: '',
            placeholder: '',
            required: false,
            fieldId: '',
            classList: '',
            headingLevel: 'h3',
            calloutStyle: 'info',
            calloutColor: '',
            content: ''
        };
        fieldData = Object.assign({}, defaults, fieldData || {});

        // Presentational (render-only) fields get a sensible default text + handle
        // so the admin can drop one in without filling required inputs. The list
        // is injected from PHP (FormSchemaService::PRESENTATIONAL_TYPES) in
        // edit.twig; the literal is only a defensive fallback.
        const PRESENTATIONAL = (window.EasyForm && window.EasyForm.presentationalTypes)
            || ['heading', 'divider', 'callout', 'paragraph'];
        const isPresentational = PRESENTATIONAL.includes(fieldData.type);
        const isTextarea = fieldData.type === 'textarea';
        const DT_NATIVE = { date: 'date', datetime: 'datetime-local', time: 'time' };

        // Default-value control, rendered per field type so the stored value is
        // always valid: a native picker for date/time, none for presentational +
        // file, a (translatable) text/textarea default otherwise.
        let defaultValueBlock = '';
        if (DT_NATIVE.hasOwnProperty(fieldData.type)) {
            defaultValueBlock = `<div class="field"><div class="heading"><label>Default value</label><div class="instructions"><p>Pre-fills the field. Pick a value so it's stored in the format this input expects.</p></div></div><div class="input ltr">
                            <input type="${DT_NATIVE[fieldData.type]}" name="${fp}[defaultValue]" value="${efEsc(fieldData.defaultValue || '')}" class="text">
                        </div></div>`;
        } else if (!isPresentational && fieldData.type !== 'file' && fieldData.type !== 'agree'
            && fieldData.type !== 'select' && fieldData.type !== 'checkboxes') {
            // select/checkboxes set their default via the "*" marker in the
            // Options list (Values tab), not a free-text default value.
            defaultValueBlock = localizedField({
                label: 'Default value',
                instructions: 'Pre-fills the field (and the stored value for hidden fields).',
                type: isTextarea ? 'textarea' : 'text',
                rows: 3,
                baseName: `${fp}[defaultValue]`,
                baseValue: fieldData.defaultValue || '',
                mapName: `${fp}[siteDefaultValues]`
            });
        }

        // Agree: per-language default state + what each state means (stored value).
        // Mirrors the `.ef-agree-values` block in _field-in-row.twig.
        let agreeValuesBlock = '';
        if (fieldData.type === 'agree') {
            const sites = window.CraftSites || [];
            const primary = sites.find(s => s.primary) || sites[0] || { name: '', handle: '' };
            const others = sites.filter(s => s.handle !== primary.handle);
            const agDefaults = fieldData.siteAgreeDefault || {};
            const agChecked = fieldData.siteAgreeChecked || {};
            const agUnchecked = fieldData.siteAgreeUnchecked || {};
            const agreeRow = s => {
                const isChk = (agDefaults[s.handle] ?? '0') === '1';
                return `<div class="ef-agree-srow">
                            <div class="ef-agree-sname">${efEsc(s.name)} <span>(${efEsc(s.handle)})</span></div>
                            <div class="ef-agree-controls">
                                <label class="ef-agree-ctl"><span>Default</span>
                                    <div class="select"><select name="${fp}[siteAgreeDefault][${s.handle}]">
                                        <option value="0"${isChk ? '' : ' selected'}>Unchecked</option>
                                        <option value="1"${isChk ? ' selected' : ''}>Checked</option>
                                    </select></div>
                                </label>
                                <label class="ef-agree-ctl"><span>Checked =</span>
                                    <input type="text" class="text" name="${fp}[siteAgreeChecked][${s.handle}]" value="${efEsc(agChecked[s.handle] || '')}" placeholder="e.g. Yes">
                                </label>
                                <label class="ef-agree-ctl"><span>Unchecked =</span>
                                    <input type="text" class="text" name="${fp}[siteAgreeUnchecked][${s.handle}]" value="${efEsc(agUnchecked[s.handle] || '')}" placeholder="e.g. No">
                                </label>
                            </div>
                        </div>`;
            };
            // Primary site shown; other sites collapse under a "Translations" toggle.
            let othersBlock = '';
            if (others.length) {
                const word = others.length === 1 ? 'site' : 'sites';
                othersBlock = `<button type="button" class="ef-localized-toggle" aria-expanded="false"><span class="ef-localized-caret" aria-hidden="true"></span><span class="ef-localized-globe" aria-hidden="true"></span>Translations <span class="ef-localized-count">${others.length} ${word}</span></button>
                        <div class="ef-localized-others" hidden>${others.map(agreeRow).join('')}</div>`;
            }
            agreeValuesBlock = `<div class="ef-agree-values" data-show-for-types="agree">
                        <div class="field"><div class="heading"><label>Checkbox values</label><div class="instructions"><p>For each language: the default state, and what “checked” and “unchecked” mean. The meaning is what gets stored on submit.</p></div></div></div>
                        ${agreeRow(primary)}
                        ${othersBlock}
                    </div>`;
        }
        if (isPresentational && !fieldData.label && !fieldData.handle) {
            const defaultText = { heading: 'Heading', divider: 'Divider', callout: 'Callout', paragraph: 'Paragraph' };
            fieldData.label = defaultText[fieldData.type];
            fieldData.handle = `${fieldData.type}_${currentPageIndex}_${currentRowIndex}_${fieldIndex}`;
        }
        
        const fieldInRow = document.createElement('div');
        fieldInRow.className = 'field-in-row';
        fieldInRow.dataset.fieldIndex = fieldIndex;
        fieldInRow.dataset.pageIndex = currentPageIndex;
        fieldInRow.dataset.fieldType = fieldData.type;
        
        fieldInRow.innerHTML = `<div class="field-in-row-header">
                <div class="field-in-row-handle">
                    <span class="move icon" title="Reorder"></span>
                </div>
                <div class="field-in-row-label">
                    <strong class="field-label-text">${fieldData.label || 'New Field'}</strong>
                    <span class="field-type-badge">${fieldData.type.toUpperCase()}</span>
                </div>
                <div class="field-in-row-actions">
                    <button type="button" class="ef-quick-required${fieldData.required ? ' is-on' : ''}" data-hide-for-types="heading,divider,callout,paragraph" aria-pressed="${fieldData.required ? 'true' : 'false'}" title="Toggle required"><span class="ef-quick-required-star" aria-hidden="true">*</span>Required</button>
                    <button type="button" class="btn small toggle-field-settings" title="Settings"><span class="icon settings"></span></button>
                    <button type="button" class="btn small delete-field" title="Delete"><span data-icon="trash" aria-label="Delete"></span></button>
                </div>
            </div>
            <div class="field-popover-backdrop" style="display: none;">
            <div class="field-in-row-settings">
                <div class="field-popover-header">
                    <strong>${fieldData.label || 'New Field'}</strong>
                    <span class="field-type-badge">${fieldData.type.toUpperCase()}</span>
                    <button type="button" class="field-popover-close" title="Close">&times;</button>
                </div>
                <input type="hidden" name="${fp}[id]" value="${fieldData.id}">
                <input type="hidden" name="${fp}[type]" value="${fieldData.type}">
                <nav class="ef-tabs">
                    <a class="ef-tab active" data-tab="general">General</a>
                    <a class="ef-tab" data-tab="content" data-show-for-types="heading,callout,paragraph">Content</a>
                    <a class="ef-tab" data-tab="required" data-hide-for-types="heading,divider,callout,paragraph">Required</a>
                    <a class="ef-tab" data-tab="validation" data-show-for-types="text,textarea,email,tel,url,number,file">Validation &amp; Error Messages</a>
                    <a class="ef-tab" data-tab="link" data-show-for-types="agree">Link</a>
                    <a class="ef-tab" data-tab="help" data-hide-for-types="heading,divider,callout,paragraph,hidden">Help message</a>
                    <a class="ef-tab" data-tab="values" data-show-for-types="select,checkboxes">Values</a>
                    <a class="ef-tab" data-tab="conditions">Conditions</a>
                    <a class="ef-tab" data-tab="advanced">ID/Classes</a>
                    ${(window.CraftSites || []).length > 1 ? '<a class="ef-tab" data-tab="sites">Sites</a>' : ''}
                </nav>
                <div class="field-settings-pane" data-pane="general">
                    ${localizedField({
                        label: 'Label',
                        baseName: `${fp}[label]`,
                        baseValue: fieldData.label || '',
                        mapName: `${fp}[siteLabels]`,
                        inputClass: 'field-label-input',
                        required: true
                    })}
                    <div class="field"><div class="heading"><label>Handle</label></div><div class="input ltr">
                        <input type="text" name="${fp}[handle]" value="${fieldData.handle || ''}" class="text code fullwidth field-handle-input" required>
                    </div></div>
                    <div data-hide-for-types="heading,divider,callout,paragraph,file,hidden,date,datetime,time,agree,checkboxes">
                        <div class="field"><div class="heading"><label>Placeholder</label></div><div class="input ltr">
                            ${isTextarea
                                ? `<textarea name="${fp}[placeholder]" rows="2" class="text fullwidth">${efEsc(fieldData.placeholder || '')}</textarea>`
                                : `<input type="text" name="${fp}[placeholder]" value="${efEsc(fieldData.placeholder || '')}" class="text fullwidth">`}
                        </div></div>
                    </div>
                    ${defaultValueBlock}
                    ${agreeValuesBlock}
                    <p class="light" data-show-for-types="heading" style="display:none;">The <strong>Label</strong> above is the text shown on the form (use Translations for other sites).</p>
                    <p class="light" data-show-for-types="paragraph,callout" style="display:none;">The <strong>Label</strong> is only an internal name shown here in the builder. Enter the text shown on the form on the <strong>Content</strong> tab.</p>
                    <p class="light" data-show-for-types="divider" style="display:none;">A divider has no settings — it renders a horizontal rule.</p>
                </div>
                <div class="field-settings-pane" data-pane="content" style="display: none;">
                    <div data-show-for-types="heading">
                        <div class="field"><div class="heading"><label>Heading Level</label></div><div class="input ltr">
                            <select name="${fp}[headingLevel]" class="text">
                                <option value="h2"${fieldData.headingLevel === 'h2' ? ' selected' : ''}>H2</option>
                                <option value="h3"${fieldData.headingLevel === 'h3' ? ' selected' : ''}>H3</option>
                                <option value="h4"${fieldData.headingLevel === 'h4' ? ' selected' : ''}>H4</option>
                            </select>
                        </div></div>
                    </div>
                    <div data-show-for-types="callout">
                        <div class="field"><div class="heading"><label>Style</label></div><div class="input ltr">
                            <select name="${fp}[calloutStyle]" class="text callout-style-input">
                                <option value="info"${fieldData.calloutStyle === 'info' ? ' selected' : ''}>Info</option>
                                <option value="warning"${fieldData.calloutStyle === 'warning' ? ' selected' : ''}>Warning</option>
                                <option value="success"${fieldData.calloutStyle === 'success' ? ' selected' : ''}>Success</option>
                                <option value="error"${fieldData.calloutStyle === 'error' ? ' selected' : ''}>Error</option>
                            </select>
                        </div></div>
                        <div class="field"><div class="heading"><label>Accent Color</label><div class="instructions"><p>Optional hex color (e.g. #2160e6) overriding the style's accent.</p></div></div><div class="input ltr">
                            <input type="text" name="${fp}[calloutColor]" value="${fieldData.calloutColor || ''}" class="text" placeholder="#2160e6">
                        </div></div>
                    </div>
                    <div data-show-for-types="paragraph,callout">
                        ${localizedField({
                            label: 'Content',
                            instructions: 'The text shown on the form. Line breaks are preserved.',
                            type: 'textarea',
                            rows: 4,
                            baseName: `${fp}[content]`,
                            baseValue: fieldData.content || '',
                            mapName: `${fp}[siteContent]`
                        })}
                    </div>
                </div>
                <div class="field-settings-pane" data-pane="help" style="display: none;">
                    ${localizedField({
                        label: 'Help text',
                        instructions: 'Optional hint shown under the field on the front end.',
                        type: 'textarea',
                        rows: 2,
                        baseName: `${fp}[helpText]`,
                        baseValue: fieldData.helpText || '',
                        mapName: `${fp}[siteHelpTexts]`
                    })}
                </div>
                <div class="field-settings-pane" data-pane="required" style="display: none;">
                    <div class="field"><div class="heading"><label>Required</label></div><div class="input ltr">
                        <div class="lightswitch ${fieldData.required ? 'on' : ''}" data-value="1" tabindex="0">
                            <input type="hidden" name="${fp}[required]" value="${fieldData.required ? '1' : '0'}">
                            <div class="lightswitch-container"><div class="handle"></div></div>
                        </div>
                    </div></div>

                    <div class="validation-required-messages" data-hide-for-types="hidden" style="margin-top: 20px;">
                        ${localizedField({
                            label: 'Required error message',
                            instructions: 'Shown if the field is left empty. Leave blank for the default.',
                            mapName: `${fp}[siteRequiredMessages]`,
                            placeholder: `${fieldData.label || 'This field'} is required`,
                            othersFallback: 'placeholder'
                        })}
                    </div>
                </div>

                <div class="field-settings-pane" data-pane="validation" style="display: none;">
                    <div class="validation-unique-field" data-show-for-types="text,email,tel,url,number">
                        <div class="field"><div class="heading"><label>Must be unique</label><div class="instructions"><p>Reject the submission if another submission of this form already has the same value here (e.g. one entry per email). Empty values aren't checked.</p></div></div><div class="input ltr">
                            <div class="lightswitch ${fieldData.unique ? 'on' : ''}" data-value="1" tabindex="0">
                                <input type="hidden" name="${fp}[unique]" value="${fieldData.unique ? '1' : '0'}">
                                <div class="lightswitch-container"><div class="handle"></div></div>
                            </div>
                        </div></div>
                        <div style="margin-top: 20px;">
                            ${localizedField({
                                label: 'Unique error message',
                                instructions: 'Shown when the value is already used. Leave blank for the default.',
                                mapName: `${fp}[siteUniqueMessages]`,
                                mapValues: fieldData.siteUniqueMessages || {},
                                placeholder: `${fieldData.label || 'This value'} has already been used.`,
                                othersFallback: 'placeholder'
                            })}
                        </div>
                    </div>
                    <div class="validation-length-fields" data-show-for-types="text,textarea,tel,url">
                        <div class="field"><div class="heading"><label>Min Length</label><div class="instructions"><p>Minimum number of characters.</p></div></div><div class="input ltr">
                            <input type="number" name="${fp}[minLength]" value="" class="text" min="0">
                        </div></div>
                        <div class="field"><div class="heading"><label>Max Length</label><div class="instructions"><p>Maximum number of characters.</p></div></div><div class="input ltr">
                            <input type="number" name="${fp}[maxLength]" value="" class="text" min="0">
                        </div></div>
                        <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e3e5e8;">
                            ${localizedField({
                                label: 'Min length error message',
                                instructions: 'Shown when the value is shorter than the minimum. Leave blank for the default.',
                                mapName: `${fp}[siteMinLengthMessages]`,
                                placeholder: 'Value is too short',
                                othersFallback: 'placeholder'
                            })}
                            ${localizedField({
                                label: 'Max length error message',
                                instructions: 'Shown when the value is longer than the maximum. Leave blank for the default.',
                                mapName: `${fp}[siteMaxLengthMessages]`,
                                placeholder: 'Value is too long',
                                othersFallback: 'placeholder'
                            })}
                        </div>
                    </div>
                    <div class="validation-url-fields" data-show-for-types="url">
                        <div class="field"><div class="heading"><label>Require a URL scheme</label><div class="instructions"><p>When on, the value must start with <code>http://</code> or <code>https://</code> and the field shows a hint. When off, entries like <code>example.com</code> are accepted.</p></div></div><div class="input ltr">
                            <div class="lightswitch" data-value="1" tabindex="0">
                                <input type="hidden" name="${fp}[requireScheme]" value="0">
                                <div class="lightswitch-container"><div class="handle"></div></div>
                            </div>
                        </div></div>
                    </div>
                    <div class="validation-value-fields" data-show-for-types="number">
                        <div class="field"><div class="heading"><label>Min Value</label><div class="instructions"><p>Minimum numeric value.</p></div></div><div class="input ltr">
                            <input type="number" name="${fp}[min]" value="" class="text">
                        </div></div>
                        <div class="field"><div class="heading"><label>Max Value</label><div class="instructions"><p>Maximum numeric value.</p></div></div><div class="input ltr">
                            <input type="number" name="${fp}[max]" value="" class="text">
                        </div></div>
                        <div class="field"><div class="heading"><label>Decimal places</label><div class="instructions"><p>Leave empty to allow any. 0 = whole numbers only.</p></div></div><div class="input ltr">
                            <input type="number" name="${fp}[decimals]" value="" class="text" min="0">
                        </div></div>
                        <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e3e5e8;">
                            ${localizedField({
                                label: 'Min value error message',
                                instructions: 'Shown when the entered value is below the minimum.',
                                mapName: `${fp}[siteMinMessages]`,
                                placeholder: 'Value is too low',
                                othersFallback: 'placeholder'
                            })}
                            ${localizedField({
                                label: 'Max value error message',
                                instructions: 'Shown when the entered value is above the maximum.',
                                mapName: `${fp}[siteMaxMessages]`,
                                placeholder: 'Value is too high',
                                othersFallback: 'placeholder'
                            })}
                        </div>
                    </div>
                    <div class="validation-email-fields" data-show-for-types="email">
                        ${localizedField({
                            label: 'Invalid email error message',
                            instructions: 'Shown when the field is filled out but is not a valid email address.',
                            mapName: `${fp}[siteInvalidMessages]`,
                            placeholder: 'Please enter a valid email address',
                            othersFallback: 'placeholder'
                        })}
                    </div>
                    <div class="validation-file-fields" data-show-for-types="file">
                        <div class="field"><div class="heading"><label>Max File Size (MB)</label><div class="instructions"><p>Maximum size per file in megabytes. Leave empty to use the global setting.</p></div></div><div class="input ltr">
                            <input type="number" name="${fp}[maxFileSize]" value="" class="text" min="1" placeholder="10">
                        </div></div>
                        <div class="field"><div class="heading"><label>Allow Multiple Files</label></div><div class="input ltr">
                            <div class="lightswitch" data-value="1" tabindex="0">
                                <input type="hidden" name="${fp}[allowMultiple]" value="0">
                                <div class="lightswitch-container"><div class="handle"></div></div>
                            </div>
                        </div></div>
                        <div class="field"><div class="heading"><label>Max number of files</label><div class="instructions"><p>Caps how many files can be uploaded (the size limit above is per file). Only applies when "Allow Multiple Files" is on; leave empty for no limit.</p></div></div><div class="input ltr">
                            <input type="number" name="${fp}[maxFiles]" value="" class="text" min="1" placeholder="5">
                        </div></div>
                        <div class="field"><div class="heading"><label>Allowed File Types</label><div class="instructions"><p>Comma-separated list of allowed file extensions. Leave empty to allow all types.</p></div></div><div class="input ltr">
                            <input type="text" name="${fp}[allowedFileTypes]" value="" class="text fullwidth" placeholder="jpg,png,pdf,doc,docx">
                        </div></div>
                        <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e3e5e8;">
                            ${localizedField({
                                label: 'File too large error message',
                                instructions: 'Shown when an uploaded file exceeds the maximum size.',
                                mapName: `${fp}[siteFileSizeMessages]`,
                                placeholder: 'File is too large',
                                othersFallback: 'placeholder'
                            })}
                        </div>
                    </div>
                </div>
                <div class="field-settings-pane" data-pane="link" style="display: none;">
                    <div class="validation-agree-fields" data-show-for-types="agree">
                        ${localizedField({
                            label: 'Display Text',
                            instructions: 'The text to display next to the checkbox. If empty, the Label will be used.',
                            baseName: `${fp}[agreeText]`,
                            baseValue: fieldData.agreeText || '',
                            mapName: `${fp}[siteAgreeText]`,
                            mapValues: fieldData.siteAgreeText || {},
                            placeholder: 'e.g., I agree to the Privacy Policy'
                        })}
                        <div class="ef-info">Links are managed when the field is saved.</div>
                    </div>
                </div>
                <div class="field-settings-pane" data-pane="values" style="display: none;">
                    <div class="field"><div class="heading"><label>Allow Multiple Selection</label><div class="instructions"><p>Allow users to select multiple options. If disabled for Checkboxes, this will render as Radio Buttons.</p></div></div><div class="input ltr">
                        <div class="lightswitch ${['checkboxes'].includes(fieldData.type) ? 'on' : ''}" data-value="1" tabindex="0">
                            <input type="hidden" name="${fp}[multiple]" value="${['checkboxes'].includes(fieldData.type) ? '1' : '0'}">
                            <div class="lightswitch-container"><div class="handle"></div></div>
                        </div>
                    </div></div>
                    <div class="validation-options-fields" style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e3e5e8;">
                        ${localizedField({
                            label: 'Options',
                            instructions: 'One per line. Format: "value:label" or just "value". Prefix a line with * to pre-select it by default (e.g. *pro:Pro); mark several lines for a multi-select.',
                            type: 'textarea',
                            rows: 4,
                            mapName: `${fp}[siteOptions]`,
                            othersFallback: 'primary'
                        })}
                    </div>
                </div>
                <div class="field-settings-pane" data-pane="conditions" style="display: none;">
                    <div class="ef-info">Show or hide this field based on the value of another field.</div>
                    <div class="ef-cond-action-logic">
                        <div class="field"><div class="heading"><label>Action</label></div><div class="input ltr">
                            <select name="${fp}[conditions][action]" class="text fullwidth">
                                <option value="show">Show this field when...</option>
                                <option value="hide">Hide this field when...</option>
                            </select>
                        </div></div>
                        <div class="field"><div class="heading"><label>Logic</label></div><div class="input ltr">
                            <select name="${fp}[conditions][logic]" class="text fullwidth">
                                <option value="all">All rules match (AND)</option>
                                <option value="any">Any rule matches (OR)</option>
                            </select>
                        </div></div>
                        <button type="button" class="btn add-condition-rule" data-field-prefix="${fp}">Add Rule</button>
                    </div>
                    <div class="condition-rules" data-field-prefix="${fp}"></div>
                </div>
                <div class="field-settings-pane" data-pane="advanced" style="display: none;">
                    <div class="field"><div class="heading"><label>Field ID</label><div class="instructions"><p>Applied to the input element itself. The field's <code>&lt;label for&gt;</code> is wired to match it. Defaults to <code>formId-handle</code> when left empty.</p></div></div><div class="input ltr">
                        <input type="text" name="${fp}[fieldId]" value="${fieldData.fieldId || ''}" class="text code fullwidth" placeholder="e.g., email-input">
                    </div></div>
                    <div class="field"><div class="heading"><label>CSS Classes</label><div class="instructions"><p>Added to the field container (<code>.easy-form-field</code>), not the input — styles the whole block (label, input and help text).</p></div></div><div class="input ltr">
                        <input type="text" name="${fp}[classList]" value="${fieldData.classList || ''}" class="text code fullwidth" placeholder="e.g., form-control custom-input">
                    </div></div>
                </div>
                <div class="field-settings-pane" data-pane="sites" style="display: none;">
                    ${siteEnableBlock(fp, fieldData.siteEnabled, 'field')}
                </div>
                <div class="field-dialog-footer">
                    <div class="field-dialog-error" role="alert" hidden></div>
                    <div class="field-dialog-actions">
                        <button type="button" class="btn field-dialog-cancel">Cancel</button>
                        <button type="button" class="btn submit field-dialog-save">Save field</button>
                    </div>
                </div>
            </div>
            </div>`;
        
        const emptyMessage = rowFields.querySelector('.empty-row-message');
        if (emptyMessage) {
            emptyMessage.remove();
        }
        
        rowFields.appendChild(fieldInRow);
        
        updateValidationFields(fieldInRow, fieldData.type);
        // Lightswitch toggling is handled by the delegated click handler in
        // event-handlers.js (setupLightswitchHandlers), which covers
        // dynamically-added fields too. No per-element wiring needed here.

        // The settings popover stays closed on add, so several fields can be
        // dropped in a row and configured later (click a field to open it).

        updateFieldCountBadge(rowElement);

        return fieldInRow;
    }

    function updateValidationFields(fieldElement, fieldType) {
        fieldElement.querySelectorAll('[data-show-for-types]').forEach(el => {
            const showForTypes = el.dataset.showForTypes.split(',');
            el.style.display = showForTypes.includes(fieldType) ? '' : 'none';
        });

        fieldElement.querySelectorAll('[data-hide-for-types]').forEach(el => {
            const hideForTypes = el.dataset.hideForTypes.split(',');
            el.style.display = hideForTypes.includes(fieldType) ? 'none' : '';
        });
    }
    
    return {
        addFieldToRow,
        updateValidationFields
    };
}
