/**
 * Notifications Module
 * Handles adding, removing, and managing notification blocks
 */

import { setupEmailEditors } from './email-editor.js';

export function setupNotifications() {
    const container = document.getElementById('notifications-list');
    const addButton = document.getElementById('add-notification-btn');
    const template = document.getElementById('notification-template');

    if (!container || !addButton || !template) {
        return;
    }

    // Upgrade the content textareas of existing notifications into chip editors.
    setupEmailEditors(container);

    let notificationIndex = container.children.length;

    // Operators must match ConditionEvaluator + _notification.twig.
    const CONDITION_OPERATORS = [
        ['equals', 'equals'],
        ['notEquals', 'does not equal'],
        ['contains', 'contains'],
        ['notContains', 'does not contain'],
        ['isEmpty', 'is empty'],
        ['isNotEmpty', 'is not empty'],
    ];

    function ruleRowHtml(prefix, index) {
        const rname = `${prefix}[rules][${index}]`;
        const options = CONDITION_OPERATORS
            .map(([v, l]) => `<option value="${v}">${l}</option>`)
            .join('');
        return `<div class="notification-rule">`
            + `<input type="text" class="text" name="${rname}[field]" value="" placeholder="field handle">`
            + `<div class="select"><select name="${rname}[operator]">${options}</select></div>`
            + `<input type="text" class="text" name="${rname}[value]" value="" placeholder="value">`
            + `<button type="button" class="btn small remove-notification-rule" title="Remove"><span data-icon="remove"></span></button>`
            + `</div>`;
    }

    // Add new notification
    addButton.addEventListener('click', function() {
        // Get template HTML and replace all NEW_INDEX references
        const html = template.innerHTML.replace(/NEW_INDEX/g, notificationIndex);
        
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        const block = wrapper.querySelector('.notification-block');
        
        if (block) {
            container.appendChild(block);
            
            // Initialize Craft UI elements for the new block
            if (window.Craft && window.Craft.initUiElements) {
                window.Craft.initUiElements(block);
            }
            // Upgrade this block's content textareas into chip editors.
            setupEmailEditors(block);
        }

        notificationIndex++;
    });

    // Enter inside a notification text input would submit the whole CP form.
    // Swallow it so editing a rule/recipient/subject field never saves the page.
    container.addEventListener('keydown', function(e) {
        if (e.key === 'Enter'
            && e.target.tagName === 'INPUT'
            && e.target.type !== 'submit'
            && e.target.type !== 'button') {
            e.preventDefault();
        }
    });

    // Event delegation for delete and toggle
    container.addEventListener('click', function(e) {
        const target = e.target;

        // Add a condition rule
        const addRule = target.closest('.add-notification-rule');
        if (addRule) {
            const condWrap = addRule.closest('.notification-conditions');
            const rulesWrap = condWrap.querySelector('.notification-rules');
            const prefix = condWrap.dataset.conditionPrefix;
            rulesWrap.insertAdjacentHTML('beforeend', ruleRowHtml(prefix, rulesWrap.children.length));
            return;
        }

        // Remove a condition rule
        const removeRule = target.closest('.remove-notification-rule');
        if (removeRule) {
            removeRule.closest('.notification-rule').remove();
            return;
        }

        // Delete
        if (target.closest('.delete-notification')) {
            const block = target.closest('.notification-block');
            if (confirm('Are you sure you want to delete this notification?')) {
                block.remove();
            }
            return;
        }

        // Toggle a per-site email config card (collapsed by default)
        const siteToggle = target.closest('.site-email-toggle');
        if (siteToggle) {
            const card = siteToggle.closest('.site-email-config');
            const body = card.querySelector('.site-email-body');
            const isOpen = card.classList.toggle('is-open');
            body.classList.toggle('hidden', !isOpen);
            siteToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            return;
        }

        // Toggle Edit
        // Trigger if clicked on toggle button OR header (but not other buttons in header)
        const toggleBtn = target.closest('.toggle-notification');
        const header = target.closest('.notification-header');
        
        if (toggleBtn || (header && !target.closest('.btn'))) {
            const block = target.closest('.notification-block');
            const body = block.querySelector('.notification-body');
            
            if (body.style.display === 'none') {
                body.style.display = 'block';
            } else {
                body.style.display = 'none';
            }
        }
    });
    
    // Keyboard support for per-site email config toggles
    container.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const siteToggle = e.target.closest('.site-email-toggle');
        if (!siteToggle) return;
        e.preventDefault();
        siteToggle.click();
    });

    // Sync Name input with Header Title
    container.addEventListener('input', function(e) {
        if (e.target.classList.contains('notification-name-input')) {
            const block = e.target.closest('.notification-block');
            const title = block.querySelector('.notification-title strong');
            title.textContent = e.target.value || 'New Notification';
        }
    });
    
    // Sync Status indicator with Enabled toggle
    container.addEventListener('change', function(e) {
        if (e.target.name && e.target.name.endsWith('[enabled]')) {
            const block = e.target.closest('.notification-block');
            const status = block.querySelector('.notification-status');
            const isChecked = e.target.checked || e.target.value === '1';
            
            if (isChecked) {
                status.classList.add('on');
            } else {
                status.classList.remove('on');
            }
        }
    });
}
