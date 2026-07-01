/**
 * EasyForm - AJAX Form Submission Handler
 */

(function() {
    'use strict';

    // Callback Registry
    const formCallbacks = {};

    // Public API to register callbacks
    window.registerFormCallback = function(formHandle, type, callback) {
        if (!formCallbacks[formHandle]) {
            formCallbacks[formHandle] = {};
        }
        if (!formCallbacks[formHandle][type]) {
            formCallbacks[formHandle][type] = [];
        }
        formCallbacks[formHandle][type].push(callback);
    };
    
    // Helper to run callbacks
    async function runCallbacks(formHandle, type, ...args) {
        if (formCallbacks[formHandle] && formCallbacks[formHandle][type]) {
            for (const callback of formCallbacks[formHandle][type]) {
                // Allow async callbacks
                try {
                    const result = await callback(...args);
                    
                    // Strict check for beforeSubmit: MUST return true
                    if (type === 'beforeSubmit') {
                        if (result !== true) return false;
                    } 
                    // Standard check for others: return false to stop
                    else if (result === false) {
                        return false;
                    }
                } catch (e) {
                    console.error(`Error in ${type} callback for form ${formHandle}:`, e);
                    // For safety, stop submission on error
                    if (type === 'beforeSubmit') return false;
                }
            }
        }
        return true;
    }

    // Dispatch a cancelable `easyform:<name>` CustomEvent on the form (bubbles, so
    // document-level listeners work). Returns false if a listener called
    // preventDefault() — used to let `beforesubmit` cancel the submit.
    function emit(form, name, detail) {
        const event = new CustomEvent('easyform:' + name, {
            bubbles: true,
            cancelable: true,
            detail: Object.assign({ form: form, formHandle: form.dataset.formHandle || null }, detail || {}),
        });
        return form.dispatchEvent(event);
    }

    // Report a client-side error to the plugin log (debug only; the data-ef-log-url
    // attribute is only present when EASY_FORM_DEBUG is enabled server-side).
    function reportClientError(form, context, error) {
        try {
            const url = form && form.dataset ? form.dataset.efLogUrl : null;
            if (!url) return;
            const body = new FormData();
            body.append('context', context || '');
            body.append('formHandle', (form.dataset.formHandle) || '');
            body.append('message', (error && error.message) ? error.message : String(error));
            fetch(url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).catch(() => {});
        } catch (e) {
            // Reporting must never throw.
        }
    }

    // ── Accessibility helpers ─────────────────────────────────────────
    let efErrorSeq = 0;
    function describedByIds(field) {
        return (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    }
    function markFieldInvalid(field, errorDiv) {
        errorDiv.setAttribute('role', 'alert');
        if (!errorDiv.id) errorDiv.id = 'ef-err-' + (++efErrorSeq);
        if (field) {
            field.setAttribute('aria-invalid', 'true');
            // Append the error id, preserving an existing help-text reference.
            const ids = describedByIds(field);
            if (ids.indexOf(errorDiv.id) === -1) ids.push(errorDiv.id);
            field.setAttribute('aria-describedby', ids.join(' '));
        }
    }
    function clearFieldInvalid(field) {
        if (!field) return;
        field.removeAttribute('aria-invalid');
        // Keep the field's own help reference (…-help); drop error ids.
        const help = field.id ? field.id + '-help' : null;
        const kept = describedByIds(field).filter(function (id) { return id === help; });
        if (kept.length) field.setAttribute('aria-describedby', kept.join(' '));
        else field.removeAttribute('aria-describedby');
    }

    // Initialize all EasyForms on the page
    function initEasyForms() {
        const forms = document.querySelectorAll('.easy-form');
        
        forms.forEach(form => {
            // Check if already initialized
            if (form.dataset.easyFormInitialized) {
                return;
            }
            
            // Disable native browser validation UI
            form.setAttribute('novalidate', 'novalidate');

            // Prevent native :invalid CSS styling on load by swapping 'required' for 'data-required'
            form.querySelectorAll('[required]').forEach(field => {
                field.dataset.required = 'true';
                field.setAttribute('aria-required', 'true');
                field.removeAttribute('required');
            });
            
            form.dataset.easyFormInitialized = 'true';
            form.addEventListener('submit', handleFormSubmit);

            // Initialize multi-page stepping
            if (form.dataset.multiPage === 'true') {
                initMultiPage(form);
            }

            // Pre-fill fields from URL query params (opt-in per form)
            if (form.dataset.urlPrefill === 'true') {
                prefillFromUrl(form);
            }

            // Initialize condition evaluator
            initConditions(form);

            // Initialize file fields (removable selected-file list)
            initFileFields(form);

            // Associate field help text with its input for screen readers.
            form.querySelectorAll('.easy-form-field-instructions[id$="-help"]').forEach(helpEl => {
                const input = document.getElementById(helpEl.id.replace(/-help$/, ''));
                if (!input) return;
                const ids = describedByIds(input);
                if (ids.indexOf(helpEl.id) === -1) {
                    ids.push(helpEl.id);
                    input.setAttribute('aria-describedby', ids.join(' '));
                }
            });

            // Run init callbacks
            const handle = form.dataset.formHandle;
            if (handle) {
                runCallbacks(handle, 'init', form);
            }
            emit(form, 'init');
        });
    }

    // ── URL pre-fill ─────────────────────────────────────────────────
    // Fill fields[handle] inputs from matching ?handle=value query params.
    function prefillFromUrl(form) {
        const params = new URLSearchParams(window.location.search);
        if (![...params.keys()].length) return;

        params.forEach((value, key) => {
            const inputs = form.querySelectorAll(
                '[name="fields[' + key + ']"], [name="fields[' + key + '][]"]'
            );
            if (!inputs.length) return;

            inputs.forEach(input => {
                const type = (input.type || '').toLowerCase();
                if (type === 'checkbox' || type === 'radio') {
                    if (input.value === value) input.checked = true;
                } else if (input.tagName === 'SELECT') {
                    if ([...input.options].some(o => o.value === value)) input.value = value;
                } else {
                    input.value = value;
                }
                // Let conditions / listeners react to the prefilled value.
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    }

    // ── Multi-page stepping ──────────────────────────────────────────

    function initMultiPage(form) {
        const pages = form.querySelectorAll('.easy-form-page');
        const prevBtn = form.querySelector('.easy-form-prev');
        const nextBtn = form.querySelector('.easy-form-next');
        const submitBtn = form.querySelector('button[type="submit"]');
        let currentPage = 0;

        function showPage(index) {
            const from = currentPage;
            pages.forEach((page, i) => {
                if (i === index) {
                    page.classList.remove('easy-form-page-hidden');
                    page.style.display = '';
                } else {
                    page.classList.add('easy-form-page-hidden');
                    page.style.display = 'none';
                }
            });
            currentPage = index;
            updateButtons();
            updateStepIndicator(index);
            if (from !== index) {
                emit(form, 'pagechange', { from: from, to: index, total: pages.length });
            }
        }

        function updateStepIndicator(index) {
            const steps = form.querySelectorAll('.easy-form-step');
            steps.forEach((step, i) => {
                step.classList.toggle('is-active', i === index);
                step.classList.toggle('is-complete', i < index);
            });
        }

        function updateButtons() {
            if (prevBtn) prevBtn.style.display = currentPage > 0 ? '' : 'none';
            if (nextBtn) nextBtn.style.display = currentPage < pages.length - 1 ? '' : 'none';
            if (submitBtn) submitBtn.style.display = currentPage === pages.length - 1 ? '' : 'none';

            // Each page carries its own Next/Previous labels (resolved per-site
            // server-side); apply the current page's labels to the shared buttons.
            const page = pages[currentPage];
            if (page) {
                if (nextBtn && page.dataset.nextLabel) nextBtn.textContent = page.dataset.nextLabel;
                if (prevBtn && page.dataset.prevLabel) prevBtn.textContent = page.dataset.prevLabel;
            }
        }

        function validateCurrentPage() {
            const page = pages[currentPage];
            if (!page) return true;

            const fields = page.querySelectorAll('input[data-field-type], textarea[data-field-type], select[data-field-type]');
            const errors = [];

            // Clear previous errors on this page
            page.querySelectorAll('.field-error').forEach(el => el.remove());
            page.querySelectorAll('.error').forEach(el => el.classList.remove('error'));

            fields.forEach(field => {
                // Skip fields hidden by field- or row-level conditions.
                const wrapper = field.closest('.easy-form-field');
                if (wrapper && wrapper.style.display === 'none') return;
                const row = field.closest('.easy-form-row');
                if (row && row.style.display === 'none') return;

                const result = validateField(field);
                if (!result.valid) {
                    errors.push({ field, message: result.message });
                    field.classList.add('error');
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'field-error easy-form-error-message';
                    errorDiv.textContent = result.message;
                    markFieldInvalid(field, errorDiv);
                    field.parentElement.appendChild(errorDiv);
                }
            });

            return errors.length === 0;
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const disableValidation = form.dataset.disableFrontendValidation === 'true';
                // Per-form opt-out: when off, steps aren't validated on advance
                // (the server still validates the whole submission at the end).
                const validateSteps = form.dataset.validateSteps !== 'false';
                if (!disableValidation && validateSteps && !validateCurrentPage()) {
                    return;
                }
                if (currentPage < pages.length - 1) {
                    showPage(currentPage + 1);
                }
            });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (currentPage > 0) {
                    showPage(currentPage - 1);
                }
            });
        }

        // Initial state
        showPage(0);

        // After a successful submit the handler calls form.reset() (when the
        // form isn't hidden). Return to the first page so the cleared form is
        // ready to fill again from the top. Defer so the native field reset
        // completes first.
        form.addEventListener('reset', () => {
            setTimeout(() => showPage(0), 0);
        });
    }

    // ── Condition evaluator (client-side) ────────────────────────────

    function initConditions(form) {
        const conditionalElements = form.querySelectorAll('[data-conditions]');
        if (conditionalElements.length === 0) return;

        // Collect all form inputs for listening
        const allInputs = form.querySelectorAll('input, textarea, select');

        function getFormValues() {
            const values = {};
            form.querySelectorAll('[name^="fields["]').forEach(input => {
                const match = input.name.match(/fields\[([^\]]+)\]/);
                if (!match) return;
                const handle = match[1];

                if (input.type === 'checkbox') {
                    // A sibling hidden companion (e.g. agree fields) may have
                    // already set this handle to a string; coerce to an array so
                    // .push() is always valid.
                    if (!Array.isArray(values[handle])) values[handle] = [];
                    if (input.checked) values[handle].push(input.value);
                } else if (input.type === 'radio') {
                    if (input.checked) values[handle] = input.value;
                } else {
                    values[handle] = input.value;
                }
            });
            return values;
        }

        function evaluateRule(rule, values) {
            const actual = values[rule.field];
            const expected = rule.value || '';
            const actualStr = Array.isArray(actual) ? '' : String(actual || '');

            switch (rule.operator) {
                case 'equals':
                    if (Array.isArray(actual)) return actual.includes(expected);
                    return actualStr === expected;
                case 'notEquals':
                    if (Array.isArray(actual)) return !actual.includes(expected);
                    return actualStr !== expected;
                case 'contains':
                    if (Array.isArray(actual)) return actual.includes(expected);
                    return actualStr.includes(expected);
                case 'notContains':
                    if (Array.isArray(actual)) return !actual.includes(expected);
                    return !actualStr.includes(expected);
                case 'isEmpty':
                    if (Array.isArray(actual)) return actual.length === 0;
                    return actualStr.trim() === '';
                case 'isNotEmpty':
                    if (Array.isArray(actual)) return actual.length > 0;
                    return actualStr.trim() !== '';
                default:
                    return false;
            }
        }

        function evaluateConditions(conditions, values) {
            // Drop rules scoped to another site (rule.site is 'all' or a handle).
            // Mirrors the server's ConditionEvaluator so client/server agree.
            const siteHandle = form.dataset.site || null;
            const rules = (conditions.rules || []).filter(r => {
                const s = r.site || 'all';
                return !siteHandle || s === 'all' || s === siteHandle;
            });
            if (rules.length === 0) return true; // No applicable rules = always visible

            const logic = conditions.logic || 'all';
            const action = conditions.action || 'show';

            let rulesMatch;
            if (logic === 'all') {
                rulesMatch = rules.every(r => evaluateRule(r, values));
            } else {
                rulesMatch = rules.some(r => evaluateRule(r, values));
            }

            return action === 'show' ? rulesMatch : !rulesMatch;
        }

        function applyConditions() {
            const values = getFormValues();

            conditionalElements.forEach(el => {
                try {
                    const conditions = JSON.parse(el.dataset.conditions);
                    const visible = evaluateConditions(conditions, values);
                    el.style.display = visible ? '' : 'none';
                } catch (e) {
                    console.error('Error parsing conditions:', e);
                }
            });
        }

        // Listen for changes on all inputs
        allInputs.forEach(input => {
            input.addEventListener('change', applyConditions);
            input.addEventListener('input', applyConditions);
        });

        // form.reset() doesn't fire change/input on fields, so re-evaluate
        // visibility once the native reset has restored default values.
        form.addEventListener('reset', () => {
            setTimeout(applyConditions, 0);
        });

        // Initial evaluation
        applyConditions();
    }

    // Validation patterns
    const validationPatterns = {
        email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
        phone: /^[+]?[0-9\s().-]{6,25}$/,
        url: /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/
    };

    /**
     * Wire up file fields so the user sees the selected files as a removable list.
     * Each entry has an ✕ button that drops just that file (rebuilding the input's
     * FileList via DataTransfer). For multi-file inputs, picking again appends to
     * the current selection instead of replacing it (deduped by name+size).
     */
    function initFileFields(form) {
        const wrappers = form.querySelectorAll('[data-ef-file-field]');
        if (!wrappers.length || typeof DataTransfer === 'undefined') return;

        const formatSize = function(bytes) {
            if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
            return bytes + ' B';
        };

        wrappers.forEach(function(wrapper) {
            const input = wrapper.querySelector('input[type="file"]');
            const list = wrapper.querySelector('[data-ef-file-list]');
            if (!input || !list) return;

            const isMultiple = input.multiple;

            const setFiles = function(files) {
                const dt = new DataTransfer();
                files.forEach(f => dt.items.add(f));
                input.files = dt.files;
            };

            const render = function() {
                const files = Array.from(input.files || []);
                list.innerHTML = '';
                if (!files.length) {
                    list.hidden = true;
                    return;
                }
                list.hidden = false;
                files.forEach(function(file, index) {
                    const li = document.createElement('li');
                    li.className = 'ef-file-item';

                    const name = document.createElement('span');
                    name.className = 'ef-file-name';
                    name.textContent = file.name;

                    const size = document.createElement('span');
                    size.className = 'ef-file-size';
                    size.textContent = formatSize(file.size);

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'ef-file-remove';
                    remove.setAttribute('aria-label', 'Remove ' + file.name);
                    remove.textContent = '✕';
                    remove.addEventListener('click', function() {
                        const kept = Array.from(input.files || []).filter((_, i) => i !== index);
                        current = kept; // keep the append-tracker in sync before the synthetic change
                        setFiles(kept);
                        render();
                        // Re-validate so any stale error clears once the offending file is gone.
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    });

                    li.appendChild(name);
                    li.appendChild(size);
                    li.appendChild(remove);
                    list.appendChild(li);
                });
            };

            // Track the previous selection so multi-file inputs can append.
            let current = [];
            input.addEventListener('change', function() {
                const picked = Array.from(input.files || []);
                if (isMultiple && picked.length) {
                    const merged = current.slice();
                    picked.forEach(function(f) {
                        const dup = merged.some(m => m.name === f.name && m.size === f.size && m.lastModified === f.lastModified);
                        if (!dup) merged.push(f);
                    });
                    current = merged;
                    setFiles(merged);
                } else {
                    current = Array.from(input.files || []);
                }
                render();
            });

            render();
        });
    }

    // Custom validation function
    function validateField(field) {
        const fieldType = field.dataset.fieldType;
        const isRequired = field.dataset.required === 'true';
        
        // Handle file inputs differently
        if (fieldType === 'file') {
            if (isRequired && (!field.files || field.files.length === 0)) {
                return { 
                    valid: false, 
                    message: field.dataset.requiredMessage || 'Please select a file' 
                };
            }
            
            // Validate file count (server enforces this too).
            if (field.files && field.files.length > 0) {
                const maxFiles = parseInt(field.dataset.maxFiles || '0', 10);
                if (maxFiles > 0 && field.files.length > maxFiles) {
                    return {
                        valid: false,
                        message: `Please upload no more than ${maxFiles} file(s)`
                    };
                }
            }

            // Validate file size
            if (field.files && field.files.length > 0) {
                const maxSize = parseFloat(field.dataset.maxFileSize) * 1024 * 1024; // Convert MB to bytes
                for (let i = 0; i < field.files.length; i++) {
                    if (field.files[i].size > maxSize) {
                        return {
                            valid: false,
                            message: field.dataset.fileSizeMessage
                                || `File "${field.files[i].name}" exceeds maximum size of ${field.dataset.maxFileSize}MB`
                        };
                    }
                }

                // Validate combined (total) upload size (server enforces this too).
                const maxTotal = parseFloat(field.dataset.maxTotalSize || '0');
                if (maxTotal > 0) {
                    let total = 0;
                    for (let i = 0; i < field.files.length; i++) {
                        total += field.files[i].size;
                    }
                    if (total > maxTotal * 1024 * 1024) {
                        return {
                            valid: false,
                            message: `Combined upload size exceeds maximum of ${maxTotal}MB`
                        };
                    }
                }
            }

            return { valid: true };
        }
        
        // Single agree checkbox
        if (fieldType === 'agree') {
            if (isRequired && !field.checked) {
                return {
                    valid: false,
                    message: field.dataset.requiredMessage || 'This field is required'
                };
            }
            return { valid: true };
        }

        // Checkbox/radio group: required means at least one option selected.
        if (fieldType === 'checkboxes') {
            if (isRequired) {
                const group = field.closest('.checkbox-group') || field.form;
                const name = field.getAttribute('name');
                const checked = group.querySelectorAll(
                    `input[name="${CSS.escape(name)}"]:checked`
                ).length > 0;
                if (!checked) {
                    return {
                        valid: false,
                        message: field.dataset.requiredMessage || 'Please select at least one option'
                    };
                }
            }
            return { valid: true };
        }
        
        const value = field.value.trim();
        
        // Skip validation for empty non-required fields
        if (!value && !isRequired) {
            return { valid: true };
        }
        
        // Required field check
        if (isRequired && !value) {
            return { 
                valid: false, 
                message: field.dataset.requiredMessage || 'This field is required' 
            };
        }
        
        // Email validation
        if (fieldType === 'email' && value) {
            if (!validationPatterns.email.test(value)) {
                return {
                    valid: false,
                    message: field.dataset.invalidMessage || 'Please enter a valid email address'
                };
            }
        }

        // Phone validation — lenient sanity check (see ValidationService::validatePhone).
        if (fieldType === 'phone' && value) {
            const digitCount = (value.match(/[0-9]/g) || []).length;
            if (!validationPatterns.phone.test(value) || digitCount < 6) {
                return {
                    valid: false,
                    message: 'Please enter a valid phone number'
                };
            }
        }
        
        // URL validation. With "require scheme" on, http(s):// is mandatory;
        // otherwise the scheme is optional (matches ValidationService).
        if (fieldType === 'url' && value) {
            const requireScheme = field.dataset.requireScheme === 'true';
            const hasScheme = /^https?:\/\//i.test(value);
            const looksValid = validationPatterns.url.test(value) && (!requireScheme || hasScheme);
            if (!looksValid) {
                return {
                    valid: false,
                    message: requireScheme
                        ? 'Please enter a URL starting with http:// or https://'
                        : 'Please enter a valid URL'
                };
            }
        }
        
        // Min/Max length validation (only for text/textarea fields)
        const minLength = field.getAttribute('minlength');
        const maxLength = field.getAttribute('maxlength');
        
        if (minLength && parseInt(minLength) > 0 && value.length < parseInt(minLength)) {
            return {
                valid: false,
                message: field.dataset.minlengthMessage || `Minimum ${minLength} characters required`
            };
        }

        if (maxLength && parseInt(maxLength) > 0 && value.length > parseInt(maxLength)) {
            return {
                valid: false,
                message: field.dataset.maxlengthMessage || `Maximum ${maxLength} characters allowed`
            };
        }
        
        // Number validation
        if (fieldType === 'number' && value) {
            const numValue = parseFloat(value);
            
            if (isNaN(numValue)) {
                return { 
                    valid: false, 
                    message: 'Please enter a valid number' 
                };
            }
            
            // Decimal-places constraint (data-decimals: '' = any, 0 = whole number).
            const decimals = field.dataset.decimals;
            if (decimals !== undefined && decimals !== '') {
                const allowed = parseInt(decimals, 10);
                const actual = value.indexOf('.') !== -1 ? value.split('.')[1].length : 0;
                if (actual > allowed) {
                    return {
                        valid: false,
                        message: allowed === 0
                            ? 'Please enter a whole number'
                            : `Use no more than ${allowed} decimal place(s)`
                    };
                }
            }

            const minValue = field.getAttribute('min');
            const maxValue = field.getAttribute('max');

            if (minValue !== null && numValue < parseFloat(minValue)) {
                return {
                    valid: false,
                    message: field.dataset.minMessage || `Minimum value is ${minValue}`
                };
            }

            if (maxValue !== null && numValue > parseFloat(maxValue)) {
                return {
                    valid: false,
                    message: field.dataset.maxMessage || `Maximum value is ${maxValue}`
                };
            }
        }
        
        return { valid: true };
    }

    // Validate entire form (skips conditionally hidden fields)
    function validateForm(form) {
        const fields = form.querySelectorAll('input[data-field-type], textarea[data-field-type], select[data-field-type]');
        const errors = [];
        
        fields.forEach(field => {
            // Skip fields that are conditionally hidden (field- or row-level)
            const wrapper = field.closest('.easy-form-field');
            if (wrapper && wrapper.style.display === 'none') return;
            const row = field.closest('.easy-form-row');
            if (row && row.style.display === 'none') return;

            // Skip fields on hidden pages (for multi-page, all pages validate on final submit)
            const page = field.closest('.easy-form-page');
            if (page && page.style.display === 'none') return;

            const result = validateField(field);
            if (!result.valid) {
                errors.push({
                    field: field,
                    message: result.message
                });
                
                // Add error class to field
                field.classList.add('error');
                
                // Show error message below field
                const errorDiv = document.createElement('div');
                errorDiv.className = 'field-error easy-form-error-message';
                errorDiv.textContent = result.message;
                markFieldInvalid(field, errorDiv);

                // For checkbox groups, append error after the entire group
                const fieldType = field.dataset.fieldType;
                if (fieldType === 'checkboxes') {
                    const checkboxGroup = field.closest('.checkbox-group');
                    if (checkboxGroup) {
                        checkboxGroup.appendChild(errorDiv);
                    } else {
                        field.parentElement.appendChild(errorDiv);
                    }
                } else if (fieldType === 'agree') {
                    const checkboxGroup = field.closest('.input-checkbox-group');
                    if (checkboxGroup) {
                        checkboxGroup.appendChild(errorDiv);
                    } else {
                        field.parentElement.appendChild(errorDiv);
                    }
                } else {
                    field.parentElement.appendChild(errorDiv);
                }
            } else {
                // Remove error class if valid
                field.classList.remove('error');
                clearFieldInvalid(field);
            }
        });

        return errors;
    }

    // Resolve a CAPTCHA token before submitting.
    // Turnstile and reCAPTCHA v2 inject their own hidden token input via the
    // widget, so nothing is needed here. reCAPTCHA v3 is invisible and must be
    // executed on demand.
    async function ensureCaptchaToken(form) {
        const provider = form.dataset.efCaptcha;
        const siteKey = form.dataset.efCaptchaSitekey;
        if (provider !== 'recaptchaV3' || !siteKey || typeof grecaptcha === 'undefined') {
            return;
        }
        await new Promise((resolve) => grecaptcha.ready(resolve));
        const token = await grecaptcha.execute(siteKey, { action: 'submit' });
        let input = form.querySelector('.easy-form-recaptcha-token');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'g-recaptcha-response';
            input.className = 'easy-form-recaptcha-token';
            form.appendChild(input);
        }
        input.value = token;
    }

    // Handle form submission
    async function handleFormSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const submitButton = form.querySelector('button[type="submit"]');

        // Remove any existing messages and errors
        removeMessages(form);
        form.querySelectorAll('.field-error').forEach(el => el.remove());
        form.querySelectorAll('.error').forEach(el => el.classList.remove('error'));
        form.querySelectorAll('[aria-invalid]').forEach(clearFieldInvalid);

        // Check if frontend validation is disabled
        const disableFrontendValidation = form.dataset.disableFrontendValidation === 'true';

        emit(form, 'beforevalidate');

        // Validate form (unless disabled)
        if (!disableFrontendValidation) {
            const validationErrors = validateForm(form);
            if (validationErrors.length > 0) {
                emit(form, 'invalid', {
                    errors: validationErrors.map(function (e) { return { field: e.field, message: e.message }; }),
                });
                // Focus on first error field
                validationErrors[0].field.focus();
                showMessage(form, 'Please correct the errors below', 'error');
                return;
            }
        }
        
        // Run beforeSubmit callbacks
        const handle = form.dataset.formHandle;
        if (handle) {
            // Prepare fields map for easier access
            const fields = {};
            form.querySelectorAll('[name^="fields["]').forEach(input => {
                const match = input.name.match(/fields\[(.*?)\]/);
                if (match) {
                    fields[match[1]] = input;
                }
            });

            // Prepare validation helper
            const validationHelper = {
                form: form,
                setError: function(fieldHandle, message) {
                    const errors = {};
                    errors[fieldHandle] = [message];
                    showFieldErrors(form, errors);
                }
            };

            const shouldContinue = await runCallbacks(handle, 'beforeSubmit', validationHelper, fields);
            if (shouldContinue === false) return;
        }

        // Cancelable lifecycle event — a listener may call preventDefault() to
        // stop the submission.
        if (!emit(form, 'beforesubmit')) return;

        // Resolve a CAPTCHA token if required (reCAPTCHA v3 needs explicit execute).
        try {
            await ensureCaptchaToken(form);
        } catch (err) {
            console.error('CAPTCHA error:', err);
            reportClientError(form, 'captcha', err);
            showMessage(form, 'CAPTCHA failed to load. Please try again.', 'error');
            return;
        }

        // Disable submit button
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.dataset.originalText = submitButton.textContent;
            submitButton.textContent = 'Submitting...';
        }
        
        // Get fresh CSRF token from backend
        try {
            const csrfUrl = form.dataset.csrfUrl || '/actions/easy-form/forms/get-csrf-token';
            const csrfResponse = await fetch(csrfUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const csrfData = await csrfResponse.json();
            
            if (csrfData.csrfToken) {
                // Remove any existing CSRF input
                const existingCsrf = form.querySelector('input[name="CRAFT_CSRF_TOKEN"]');
                if (existingCsrf) {
                    existingCsrf.remove();
                }
                
                // Add fresh CSRF token
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'CRAFT_CSRF_TOKEN';
                csrfInput.value = csrfData.csrfToken;
                form.appendChild(csrfInput);
            }
        } catch (error) {
            console.error('Failed to fetch CSRF token:', error);
            reportClientError(form, 'csrf', error);
            showMessage(form, 'Security token error. Please refresh the page.', 'error');
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = submitButton.dataset.originalText;
            }
            return;
        }
        
        // Prepare form data
        const formData = new FormData(form);

        // Don't submit values for conditionally-hidden fields. We only remove
        // them from the payload — the DOM keeps the user's input, so toggling a
        // field back to visible restores it. Field- and row-level hiding is
        // unambiguous (display:none = a failed condition); page conditions are
        // enforced server-side (multi-page step-hiding also uses display:none).
        form.querySelectorAll('[name^="fields["]').forEach(input => {
            const wrap = input.closest('.easy-form-field');
            const row = input.closest('.easy-form-row');
            if ((wrap && wrap.style.display === 'none') || (row && row.style.display === 'none')) {
                formData.delete(input.name);
            }
        });

        emit(form, 'submit', { formData: formData });

        try {
            // Submit via AJAX
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            // Guard against non-JSON responses (e.g. a 500 HTML error page).
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                const text = await response.text();
                throw new Error('Unexpected ' + response.status + ' response: ' + text.slice(0, 200));
            }

            const result = await response.json();

            if (result.success) {
                emit(form, 'success', { response: result });
                // Show success message
                showMessage(form, result.message, 'success', {
                    keep: result.keepMessage !== false,
                    duration: result.messageDuration
                });

                // Hide form if configured
                if (result.hideForm) {
                    hideFormFields(form);
                } else {
                    // Reset form if not hiding it
                    form.reset();
                }

                // Redirect if specified
                if (result.redirect) {
                    setTimeout(() => {
                        window.location.href = result.redirect;
                    }, 1500);
                }
            } else {
                emit(form, 'error', { response: result });
                // Show error message
                showMessage(form, result.error || 'An error occurred', 'error');

                // Show field-specific errors if available
                if (result.errors) {
                    showFieldErrors(form, result.errors);
                }
            }
        } catch (error) {
            console.error('Form submission error:', error);
            reportClientError(form, 'submit', error);
            emit(form, 'error', { error: error });
            showMessage(form, 'An unexpected error occurred. Please try again.', 'error');
        } finally {
            // Re-enable submit button
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = submitButton.dataset.originalText;
            }
        }
    }

    // Hide form fields and actions
    function hideFormFields(form) {
        // Hide all pages and rows
        const pages = form.querySelectorAll('.easy-form-page');
        pages.forEach(page => { page.style.display = 'none'; });
        const rows = form.querySelectorAll('.easy-form-row');
        rows.forEach(row => { row.style.display = 'none'; });
        
        // Hide submit button and navigation
        const actions = form.querySelector('.easy-form-actions');
        if (actions) {
            actions.style.display = 'none';
        }

        // Hide the multi-page step indicator
        const steps = form.querySelector('.easy-form-steps');
        if (steps) {
            steps.style.display = 'none';
        }
    }

    // Show a message above the form
    function showMessage(form, message, type, options) {
        options = options || {};
        const messageDiv = document.createElement('div');
        messageDiv.className = `easy-form-message ${type}`;
        messageDiv.setAttribute('role', type === 'error' ? 'alert' : 'status');
        messageDiv.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        messageDiv.textContent = message;
        
        // Insert before the first page or row or at the beginning
        const firstPage = form.querySelector('.easy-form-page');
        const firstRow = form.querySelector('.easy-form-row');
        const insertBefore = firstPage || firstRow;
        if (insertBefore) {
            form.insertBefore(messageDiv, insertBefore);
        } else {
            form.insertBefore(messageDiv, form.firstChild);
        }
        
        // Auto-remove the success message after a delay, unless it's configured
        // to stay until the page is reloaded (the default).
        if (type === 'success' && options.keep === false) {
            const seconds = Number(options.duration) > 0 ? Number(options.duration) : 5;
            setTimeout(() => {
                messageDiv.remove();
            }, seconds * 1000);
        }
    }

    // Remove all messages
    function removeMessages(form) {
        const messages = form.querySelectorAll('.easy-form-message');
        messages.forEach(msg => msg.remove());
        
        // Remove field errors
        const errorMessages = form.querySelectorAll('.easy-form-field-error');
        errorMessages.forEach(err => err.remove());
        
        const errorFields = form.querySelectorAll('.easy-form-field.has-error');
        errorFields.forEach(field => field.classList.remove('has-error'));
    }

    // Show field-specific errors
    function showFieldErrors(form, errors) {
        Object.keys(errors).forEach(fieldName => {
            const input = form.querySelector(`[name="fields[${fieldName}]"]`) || form.querySelector(`[name="fields[${fieldName}][]"]`);
            if (input) {
                const fieldWrapper = input.closest('.easy-form-field');
                if (fieldWrapper) {
                    fieldWrapper.classList.add('has-error');

                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'easy-form-field-error easy-form-error-message';
                    errorDiv.textContent = errors[fieldName].join(', ');
                    markFieldInvalid(input, errorDiv);

                    input.parentNode.insertBefore(errorDiv, input.nextSibling);
                }
            }
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEasyForms);
    } else {
        initEasyForms();
    }

    // Re-initialize if new forms are added dynamically
    window.EasyForm = {
        init: initEasyForms
    };
})();
