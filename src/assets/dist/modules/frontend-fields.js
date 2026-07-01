/**
 * Frontend Fields Module
 * Builder-style tabbed cards in the "Frontend & Data" tab:
 * add/remove, collapse/expand, tab switching, and header sync.
 */

export function setupFrontendFields() {
    const container = document.getElementById('frontend-fields-list');
    const addButton = document.getElementById('add-frontend-field-btn');
    const template = document.getElementById('frontend-field-template');

    if (!container || !addButton || !template) {
        return;
    }

    let index = container.children.length;

    // Collapse existing field bodies (scannable list, like the builder).
    container.querySelectorAll('.ef-frontend-field-body').forEach(function (body) {
        body.style.display = 'none';
    });

    addButton.addEventListener('click', function () {
        const html = template.innerHTML.replace(/NEW_INDEX/g, index);

        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        const block = wrapper.querySelector('.ef-frontend-field');

        if (block) {
            container.appendChild(block);
            // New fields start expanded so they can be filled in.
            const body = block.querySelector('.ef-frontend-field-body');
            if (body) body.style.display = '';
            if (window.Craft && window.Craft.initUiElements) {
                window.Craft.initUiElements(block);
            }
        }

        index++;
    });

    container.addEventListener('click', function (e) {
        // Delete
        if (e.target.closest('.delete-frontend-field')) {
            const block = e.target.closest('.ef-frontend-field');
            if (block && confirm('Remove this frontend field?')) {
                block.remove();
            }
            return;
        }

        // Collapse / expand
        if (e.target.closest('.ef-ff-toggle')) {
            const body = e.target.closest('.ef-frontend-field').querySelector('.ef-frontend-field-body');
            if (body) body.style.display = body.style.display === 'none' ? '' : 'none';
            return;
        }

        // Tab switching (scoped to this card's body)
        const tab = e.target.closest('.ef-ff-tabs .ef-tab');
        if (tab) {
            const body = tab.closest('.ef-frontend-field-body');
            body.querySelectorAll('.ef-ff-tabs .ef-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            const name = tab.dataset.ffTab;
            body.querySelectorAll('.ef-ff-pane').forEach(function (pane) {
                pane.style.display = pane.dataset.ffPane === name ? '' : 'none';
            });
        }
    });

    // Keep the card title in sync with the handle input.
    container.addEventListener('input', function (e) {
        if (e.target.classList.contains('ef-ff-handle')) {
            const title = e.target.closest('.ef-frontend-field').querySelector('.ef-frontend-field-title');
            if (title) title.textContent = e.target.value || 'New frontend field';
        }
    });

    // Keep the type badge in sync with the type select.
    container.addEventListener('change', function (e) {
        if (e.target.classList.contains('ef-ff-type')) {
            const badge = e.target.closest('.ef-frontend-field').querySelector('.ef-ff-type-badge');
            if (badge) badge.textContent = (e.target.value || 'string').toUpperCase();
        }
    });
}
