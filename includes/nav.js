/**
 * SparkSpend Navigation
 *
 * Manages:
 * - Top-level tabs: Oversigt, Elbil, Jordvarme
 * - Sub-tabs within Elbil: Ladninger, Analyse, Sammenligning
 * - URL hash routing (#oversigt, #elbil/ladninger, #jordvarme)
 * - Contextual header buttons (filter toggle + new-charge only on Elbil)
 * - Lazy initialisation of Elbil and Jordvarme data on first visit
 */

const SparkNav = (() => {
    let currentTab = 'oversigt-section';
    let elbilLoaded = false;
    let jordvarmeLoaded = false;

    // -------------------------------------------------------------------------
    // Main tab switching
    // -------------------------------------------------------------------------
    function switchMainTab(tabId, writeHash = true) {
        document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.header-tab-button').forEach(b => b.classList.remove('active'));

        const section = document.getElementById(tabId);
        if (section) section.classList.add('active');

        const btn = document.querySelector(`.header-tab-button[data-target="${tabId}"]`);
        if (btn) btn.classList.add('active');

        currentTab = tabId;
        updateContextualButtons();
        if (writeHash) updateHash();

        // Lazy-load on first visit
        if (tabId === 'elbil-section' && !elbilLoaded) {
            window.elbilApp?.init();
            elbilLoaded = true;
        }
        if (tabId === 'jordvarme-section' && !jordvarmeLoaded) {
            window.jordvarmeApp?.init();
            jordvarmeLoaded = true;
        }
    }

    // -------------------------------------------------------------------------
    // Sub-tab switching (Elbil only)
    // -------------------------------------------------------------------------
    function switchSubTab(subId) {
        const elbilSection = document.getElementById('elbil-section');
        if (!elbilSection) return;

        elbilSection.querySelectorAll('.sub-section').forEach(s => s.classList.remove('active'));
        elbilSection.querySelectorAll('.sub-nav-btn').forEach(b => b.classList.remove('active'));

        const sub = document.getElementById(subId);
        if (sub) sub.classList.add('active');

        const btn = elbilSection.querySelector(`.sub-nav-btn[data-sub="${subId}"]`);
        if (btn) btn.classList.add('active');

        updateHash();
    }

    // -------------------------------------------------------------------------
    // Context-aware header buttons
    // -------------------------------------------------------------------------
    function updateContextualButtons() {
        const isElbil = currentTab === 'elbil-section';
        const filterBtn    = document.getElementById('filterToggleBtn');
        const newChargeBtn = document.querySelector('.button-create-charge');
        const filterDrawer = document.getElementById('filterDrawer');
        const filterBackdrop = document.getElementById('filterBackdrop');

        if (filterBtn)    filterBtn.style.display    = isElbil ? '' : 'none';
        if (newChargeBtn) newChargeBtn.style.display = isElbil ? '' : 'none';

        if (filterDrawer) {
            filterDrawer.style.display = isElbil ? '' : 'none';
            if (!isElbil) {
                filterDrawer.classList.remove('open');
                if (filterBackdrop) filterBackdrop.classList.remove('visible');
                document.body.classList.remove('filter-open');
            }
        }
    }

    // -------------------------------------------------------------------------
    // URL hash helpers
    // -------------------------------------------------------------------------
    function getActiveSubId() {
        const active = document.querySelector('#elbil-section .sub-section.active');
        return active ? active.id : 'elbil-ladninger';
    }

    function updateHash() {
        let hash = '#oversigt';
        if (currentTab === 'elbil-section') {
            const sub = getActiveSubId().replace('elbil-', '');
            hash = '#elbil/' + sub;
        } else if (currentTab === 'jordvarme-section') {
            hash = '#jordvarme';
        }
        history.replaceState(null, '', hash);
    }

    function readHash() {
        const hash = location.hash.slice(1);
        if (hash.startsWith('elbil')) {
            const parts = hash.split('/');
            const sub = parts[1] ? 'elbil-' + parts[1] : 'elbil-ladninger';
            switchMainTab('elbil-section', false);
            switchSubTab(sub);
        } else if (hash === 'jordvarme') {
            switchMainTab('jordvarme-section', false);
        } else {
            switchMainTab('oversigt-section', false);
        }
        updateHash();
    }

    // -------------------------------------------------------------------------
    // Public helper: navigate programmatically (e.g. from dashboard cards)
    // -------------------------------------------------------------------------
    function navigateTo(mainTabId, subTabId) {
        switchMainTab(mainTabId, false);
        if (subTabId) switchSubTab(subTabId);
        updateHash();
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------
    function init() {
        // Main tab buttons
        document.querySelectorAll('.header-tab-button').forEach(btn => {
            btn.addEventListener('click', () => switchMainTab(btn.dataset.target));
        });

        // Sub-tab buttons
        document.querySelectorAll('.sub-nav-btn').forEach(btn => {
            btn.addEventListener('click', () => switchSubTab(btn.dataset.sub));
        });

        // Apply hash or default to oversigt
        if (location.hash) {
            readHash();
        } else {
            switchMainTab('oversigt-section', false);
        }
    }

    return { init, switchMainTab, switchSubTab, navigateTo };
})();

window.SparkNav = SparkNav;
document.addEventListener('DOMContentLoaded', SparkNav.init);
