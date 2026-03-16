/**
 * SparkSpend Navigation
 *
 * Manages:
 * - Top-level tabs: Oversigt, Elbil, Jordvarme, Hus
 * - Sub-tabs within Elbil: Ladninger, Analyse, Sammenligning
 * - Sub-tabs within Hus: Forbrug, Regning
 * - URL hash routing (#oversigt, #elbil/ladninger, #jordvarme, #hus/forbrug, #hus/regning)
 * - Contextual header buttons (filter toggle + new-charge only on Elbil)
 * - Lazy initialisation of modules on first visit
 */

const SparkNav = (() => {
    let currentTab = 'oversigt-section';
    let dashboardLoaded   = false;
    let elbilLoaded       = false;
    let jordvarmeLoaded   = false;
    let husForbrugLoaded  = false;
    let husRegningLoaded  = false;
    let aarsrapportLoaded = false;

    // Resize all Chart.js charts in a section after it becomes visible.
    // Must run after a paint so the browser has applied display:block.
    function _resizeSection(sectionId) {
        requestAnimationFrame(() => {
            document.querySelectorAll(`#${sectionId} canvas`).forEach(canvas => {
                const chart = typeof Chart !== 'undefined' && Chart.getChart(canvas);
                if (chart) chart.resize();
            });
        });
    }

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

        // Lazy-load on first visit; resize charts on return visits
        if (tabId === 'oversigt-section') {
            if (!dashboardLoaded) {
                loadDashboard();
                dashboardLoaded = true;
            } else {
                _resizeSection('oversigt-section');
            }
        }
        if (tabId === 'elbil-section') {
            if (!elbilLoaded) {
                elbilLoaded = true;
                requestAnimationFrame(() => window.elbilApp?.init());
            }
        }
        if (tabId === 'jordvarme-section') {
            if (!jordvarmeLoaded) {
                jordvarmeLoaded = true;
                // Defer one frame so the browser completes layout (display:block) before
                // Chart.js measures the canvas — prevents intermittent 0-height charts.
                requestAnimationFrame(() => window.jordvarmeApp?.init());
            }
        }
        if (tabId === 'hus-section') {
            // Init whichever hus sub-tab is currently active
            _lazyInitHusSub();
            _resizeSection('hus-section');
        }
        if (tabId === 'aarsrapport-section') {
            if (!aarsrapportLoaded) {
                aarsrapportLoaded = true;
                requestAnimationFrame(() => window.annualApp?.init());
            } else {
                _resizeSection('aarsrapport-section');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Sub-tab switching (Elbil)
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
    // Sub-tab switching (Hus)
    // -------------------------------------------------------------------------
    function switchHusSubTab(subId) {
        const husSection = document.getElementById('hus-section');
        if (!husSection) return;

        husSection.querySelectorAll('.sub-section').forEach(s => s.classList.remove('active'));
        husSection.querySelectorAll('.sub-nav-btn').forEach(b => b.classList.remove('active'));

        const sub = document.getElementById(subId);
        if (sub) sub.classList.add('active');

        const btn = husSection.querySelector(`.sub-nav-btn[data-sub="${subId}"]`);
        if (btn) btn.classList.add('active');

        _lazyInitHusSub();
        _resizeSection('hus-section');
        updateHash();
    }

    function _lazyInitHusSub() {
        const husSection  = document.getElementById('hus-section');
        const activeSub   = husSection?.querySelector('.sub-section.active');
        const activeSubId = activeSub?.id;

        if (activeSubId === 'hus-forbrug' && !husForbrugLoaded) {
            husForbrugLoaded = true;
            requestAnimationFrame(() => window.husApp?.init());
        }
        if (activeSubId === 'hus-regning' && !husRegningLoaded) {
            husRegningLoaded = true;
            requestAnimationFrame(() => window.regningApp?.init());
        }
    }

    // -------------------------------------------------------------------------
    // Context-aware header buttons
    // -------------------------------------------------------------------------
    function updateContextualButtons() {
        const isElbil = currentTab === 'elbil-section';
        const filterBtn     = document.getElementById('filterToggleBtn');
        const newChargeBtn  = document.querySelector('.button-create-charge');
        const filterDrawer  = document.getElementById('filterDrawer');
        const filterBackdrop= document.getElementById('filterBackdrop');

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
    function _getActiveElbilSubId() {
        const active = document.querySelector('#elbil-section .sub-section.active');
        return active ? active.id : 'elbil-ladninger';
    }

    function _getActiveHusSubId() {
        const active = document.querySelector('#hus-section .sub-section.active');
        return active ? active.id : 'hus-forbrug';
    }

    function updateHash() {
        let hash = '#oversigt';
        if (currentTab === 'elbil-section') {
            const sub = _getActiveElbilSubId().replace('elbil-', '');
            hash = '#elbil/' + sub;
        } else if (currentTab === 'jordvarme-section') {
            hash = '#jordvarme';
        } else if (currentTab === 'hus-section') {
            const sub = _getActiveHusSubId().replace('hus-', '');
            hash = '#hus/' + sub;
        } else if (currentTab === 'aarsrapport-section') {
            hash = '#aarsrapport';
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
        } else if (hash.startsWith('hus')) {
            const parts = hash.split('/');
            const sub = parts[1] ? 'hus-' + parts[1] : 'hus-forbrug';
            switchMainTab('hus-section', false);
            switchHusSubTab(sub);
        } else if (hash === 'aarsrapport') {
            switchMainTab('aarsrapport-section', false);
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
        if (subTabId) {
            if (mainTabId === 'hus-section') {
                switchHusSubTab(subTabId);
            } else {
                switchSubTab(subTabId);
            }
        }
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

        // Elbil sub-tab buttons
        document.querySelectorAll('#elbil-section .sub-nav-btn').forEach(btn => {
            btn.addEventListener('click', () => switchSubTab(btn.dataset.sub));
        });

        // Hus sub-tab buttons
        document.querySelectorAll('#hus-section .sub-nav-btn').forEach(btn => {
            btn.addEventListener('click', () => switchHusSubTab(btn.dataset.sub));
        });

        // Apply hash or default to oversigt
        if (location.hash) {
            readHash();
        } else {
            switchMainTab('oversigt-section', false);
        }
    }

    return { init, switchMainTab, switchSubTab, switchHusSubTab, navigateTo };
})();

window.SparkNav = SparkNav;
document.addEventListener('DOMContentLoaded', SparkNav.init);
