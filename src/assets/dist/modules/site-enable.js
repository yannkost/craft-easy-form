/**
 * Per-site enable toggles (structural localization)
 *
 * Builds the "Enabled on these sites" lightswitch list shown in field and row
 * settings. Turning a site off drops that field/row from the site's form — it's
 * excluded from rendering, validation and the stored submission. Mirrors the
 * server-rendered block in the field/row settings twig.
 *
 * Only rendered on multi-site installs (a single site is always enabled). Each
 * site posts a 1/0 flag (the lightswitch hidden input), so an unchecked site is
 * stored as '0'; an absent map means "enabled everywhere" (old forms).
 */

function efSiteEsc(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
        .replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * @param {string} prefix Input-name prefix, e.g. "pages[0][rows][1][fields][2]"
 * @param {object} siteEnabled Saved map of site-handle => '1'|'0' (absent = enabled)
 * @param {string} kind 'field', 'row' or 'page' — used only in the instructions text
 * @returns {string} HTML (empty string on single-site installs)
 */
export function siteEnableBlock(prefix, siteEnabled, kind) {
    const sites = window.CraftSites || [];
    if (sites.length < 2) {
        return '';
    }
    const map = siteEnabled || {};
    const noun = kind === 'row' ? 'row' : (kind === 'page' ? 'page' : 'field');

    // Show the primary site's toggle inline; collapse the rest behind a
    // disclosure (mirrors the localized "Translations" block) so the panel
    // stays compact on multi-site installs.
    const primary = sites.find(s => s.primary) || sites[0];
    const others = sites.filter(s => s !== primary);

    const switchRow = s => {
        const v = map[s.handle];
        // Default enabled — only an explicit falsy flag disables a site.
        const on = !(v === '0' || v === 0 || v === false);
        return `<div class="field">
                    <div class="heading"><label><span class="ef-localized-badge" aria-hidden="true">${efSiteEsc(s.lang || '')}</span>${efSiteEsc(s.name)} <span class="ef-localized-site">(${efSiteEsc(s.handle)})</span></label></div>
                    <div class="input ltr">
                        <div class="lightswitch ${on ? 'on' : ''}" data-value="1" tabindex="0">
                            <input type="hidden" name="${prefix}[siteEnabled][${s.handle}]" value="${on ? '1' : '0'}">
                            <div class="lightswitch-container"><div class="handle"></div></div>
                        </div>
                    </div>
                </div>`;
    };

    let othersBlock = '';
    if (others.length) {
        const word = others.length === 1 ? 'site' : 'sites';
        othersBlock = `<button type="button" class="ef-localized-toggle" aria-expanded="false"><span class="ef-localized-caret" aria-hidden="true"></span><span class="ef-localized-globe" aria-hidden="true"></span>Other sites <span class="ef-localized-count">${others.length} ${word}</span></button>
                <div class="ef-localized-others" hidden>${others.map(switchRow).join('')}</div>`;
    }

    return `<div class="ef-site-enable">
                <div class="field"><div class="heading"><label>Enabled on these sites</label><div class="instructions"><p>Turn a site off to drop this ${noun} from that site's form. It's excluded from rendering, validation and the stored submission.</p></div></div></div>
                ${switchRow(primary)}
                ${othersBlock}
            </div>`;
}
