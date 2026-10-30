/**
 * SparkSpend Dashboard Application
 * Modern, organized JavaScript for vehicle charging analytics
 *
 * Structure:
 * - Utility Functions
 * - API/Data Fetching
 * - Chart Management
 * - Form Handlers
 * - Event Delegation
 * - Initialization
 */

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Debounce function to limit execution frequency
 * @param {Function} fn - Function to debounce
 * @param {number} delayMs - Delay in milliseconds
 * @returns {Function} Debounced function
 */
const debounce = (fn, delayMs) => {
  let timeoutId = null;
  return function(...args) {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => fn(...args), delayMs);
  };
};

/**
 * Centralized fetch error handling
 * @param {Response} response - Fetch response
 * @returns {Promise} Parsed JSON or throws error
 */
const handleFetchResponse = async (response) => {
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  }
  const data = await response.json();
  if (data.error) {
    throw new Error(data.error);
  }
  return data;
};

/**
 * Initialize tooltips for elements with data-bs-toggle="tooltip"
 * Modern approach: one instance per element, no memory leaks
 */
const initializeTooltips = () => {
  document.querySelectorAll('[data-bs-toggle="tooltip"]:not(.tooltip-initialized)').forEach(el => {
    new bootstrap.Tooltip(el);
    el.classList.add('tooltip-initialized');
  });
};

/**
 * Danish electricity-heating tax model. The meter can't tell purpose apart, so
 * the first `highKwhPerMonth` kWh/month of VP+Rest consumption is taxed at the
 * HIGH rate and the rest at the LOW (elvarme) rate. EV charging always uses the
 * LOW rate (VP+Rest is assumed to consume the whole high-rate allowance).
 *
 * Returns the blended cost split back onto VP and Rest by their kWh share.
 *
 * @param {object} p
 * @param {number} p.vpKwh   VP kWh for the month
 * @param {number} p.restKwh Rest (household) kWh for the month
 * @param {number} p.evKwh   EV home-charging kWh for the month (optional)
 * @param {number} p.highRate kr/kWh HIGH rate (incl. tariffs+VAT)
 * @param {number} p.lowRate  kr/kWh LOW rate
 * @param {number} p.highKwhPerMonth kWh taxed at HIGH before LOW kicks in
 * @param {{high:number,low:number}} [p.vpRates]   per-component rates for VP (e.g. consumption-weighted)
 * @param {{high:number,low:number}} [p.restRates] per-component rates for Rest
 * @returns {{vpCost:number, restCost:number, evCost:number, total:number}}
 */
const elvarmeCost = ({ vpKwh = 0, restKwh = 0, evKwh = 0, highRate, lowRate, highKwhPerMonth, vpRates, restRates }) => {
  const vpRest = Math.max(0, vpKwh) + Math.max(0, restKwh);
  const highKwh = Math.min(vpRest, highKwhPerMonth);
  const lowKwh = Math.max(0, vpRest - highKwh);
  const evCost = Math.max(0, evKwh) * lowRate;

  // Optional per-component rates ({high, low}, e.g. consumption-weighted, see
  // pricing.js). The high-rate allowance is shared pro rata, so each component
  // pays high/low in the same proportion. With equal rates this is identical to
  // the flat model below.
  if (vpRates || restRates) {
    const flat = { high: highRate, low: lowRate };
    const f = vpRest > 0 ? highKwh / vpRest : 0;
    const mix = r => f * r.high + (1 - f) * r.low;
    const vpCost = Math.max(0, vpKwh) * mix(vpRates || flat);
    const restCost = Math.max(0, restKwh) * mix(restRates || flat);
    return { vpCost, restCost, evCost, total: vpCost + restCost + evCost };
  }

  const vpRestCost = highKwh * highRate + lowKwh * lowRate;
  // Split the blended cost back onto VP and Rest by their kWh share.
  const vpCost = vpRest > 0 ? vpRestCost * (Math.max(0, vpKwh) / vpRest) : 0;
  const restCost = vpRest > 0 ? vpRestCost * (Math.max(0, restKwh) / vpRest) : 0;
  return { vpCost, restCost, evCost, total: vpRestCost + evCost };
};

/**
 * Format currency to Danish Krone format
 * @param {number} value - Value to format
 * @returns {string} Formatted value
 */
const formatCurrency = (value) => {
  return new Intl.NumberFormat('da-DK', {
    style: 'currency',
    currency: 'DKK',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(value);
};

/**
 * Format date to Danish locale
 * @param {string|Date} dateStr - Date string or Date object
 * @returns {string} Formatted date
 */
const formatDate = (dateStr) => {
  return new Date(dateStr).toLocaleString('da-DK', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

/**
 * Escape HTML special characters to prevent XSS
 * @param {string} text - Text to escape
 * @returns {string} Escaped text
 */
const escapeHtml = (text) => {
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return text.replace(/[&<>"']/g, m => map[m]);
};

// ============================================================================
// CHART MANAGEMENT
// ============================================================================

/**
 * Destroy and recreate a Chart.js instance
 * Prevents memory leaks from accumulating canvas contexts
 * @param {Chart} chartInstance - Previous chart instance
 * @param {string} canvasId - Canvas element ID
 * @param {Object} config - Chart configuration
 _ @returns {Chart} New chart instance
 */
const updateChartInstance = (chartInstance, canvasId, config) => {
  if (chartInstance) {
    chartInstance.destroy();
  }
  const ctx = document.getElementById(canvasId).getContext('2d');
  return new Chart(ctx, config);
};

// ============================================================================
// FORM UTILITIES
// ============================================================================

/**
 * Show form message with auto-hide for success
 * @param {string} elementId - Element to show message in
 * @param {string} message - Message text
 * @param {string} type - 'success' or 'danger'
 * @param {number} autoHideMs - Auto-hide delay (0 = no auto-hide)
 */
const showFormMessage = (elementId, message, type = 'success', autoHideMs = 3000) => {
  const msgEl = document.getElementById(elementId);
  const className = type === 'success' ? 'alert-success' : 'alert-danger';
  msgEl.innerHTML = `<div class="alert ${className}" role="alert">${escapeHtml(message)}</div>`;

  if (autoHideMs > 0 && type === 'success') {
    setTimeout(() => {
      msgEl.innerHTML = '';
    }, autoHideMs);
  }
};

/**
 * Validate form using HTML5 validation API
 * @param {HTMLFormElement} form - Form to validate
 * @returns {boolean} Is form valid
 */
const validateForm = (form) => {
  return form.checkValidity() === true;
};

// ============================================================================
// LOADING STATES
// ============================================================================

/**
 * Set loading state for a data container
 * @param {string} elementId - Element to show loading in
 * @param {boolean} isLoading - Loading state
 */
const setLoadingState = (elementId, isLoading) => {
  const el = document.getElementById(elementId);
  if (isLoading) {
    el.innerHTML = `
      <tr>
        <td colspan="999" class="text-center">
          <span class="spinner-border spinner-border-sm me-2"></span>
          Indlæser data...
        </td>
      </tr>
    `;
  }
};

// ============================================================================
// DATA PARSING
// ============================================================================

/**
 * Parse date range string and return standardized dates
 * @param {string} dateStr - Date string in format "YYYY-MM-DD til YYYY-MM-DD"
 * @returns {Object} { start, end } dates
 */
const parseDateRange = (dateStr) => {
  const { start, end } = dateStr.split(' til ').reduce((acc, date, idx) => {
    if (idx === 0) acc.start = date.trim();
    else acc.end = date.trim();
    return acc;
  }, {});
  return { start, end };
};

/**
 * Extract data from table row using data-attributes
 * Modern approach to avoid inline event handlers
 * @param {HTMLTableRowElement} row - Table row element
 * @returns {Object} Charge data from row
 */
const extractChargeData = (row) => {
  return {
    id: row.dataset.chargeId,
    source: row.dataset.source,
    datetime: row.dataset.datetime,
    kwh: parseFloat(row.dataset.kwh),
    pris: parseFloat(row.dataset.pris),
    vehicleId: parseInt(row.dataset.vehicleId),
    providerId: row.dataset.providerId ? parseInt(row.dataset.providerId) : null
  };
};

// ============================================================================
// INITIALIZATION HELPERS
// ============================================================================

/**
 * Initialize application utilities when DOM is ready
 * Call this once on DOMContentLoaded
 */
const initializeAppUtilities = () => {
  // Any global initialization needed
};

// Global event bus for cross-module communication
window.SparkEvents = new EventTarget();

// ============================================================================
// SESSION EXPIRY DETECTION
// Intercepts all fetch() calls globally so every module automatically detects
// when the Authentik (or any auth-proxy) session has expired, without requiring
// changes to individual modules.
//
// Expiry is signalled by either:
//   - HTTP 401 / 403 (proxy configured to return these for API requests), or
//   - A redirect that was transparently followed to an HTML login page
//     (fetch follows 302 → 200 HTML; response.redirected === true and
//      Content-Type is text/html, originating from a different path).
// ============================================================================

let _authExpiryNotified = false;

function _handleAuthExpiry() {
    if (_authExpiryNotified) return;
    _authExpiryNotified = true;

    // Show a non-blocking banner at the top of the page
    const banner = document.createElement('div');
    banner.style.cssText = [
        'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:99999',
        'background:#1e293b', 'color:#f8fafc',
        'padding:.75rem 1.25rem',
        'font-size:.9rem', 'text-align:center',
        'box-shadow:0 2px 8px rgba(0,0,0,.4)',
    ].join(';');
    banner.textContent = 'Din session er udløbet — du videresendes til login…';
    document.body.prepend(banner);

    // Reload after a short pause so the user sees the message
    setTimeout(() => window.location.reload(), 2000);
}

(function _installFetchInterceptor() {
    const _nativeFetch = window.fetch;
    const _getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    window.fetch = async function(input, init) {
        // Attach CSRF token to all state-mutating requests
        const method = ((init && init.method) || 'GET').toUpperCase();
        if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            const token = _getCsrfToken();
            if (token) {
                init = init ? Object.assign({}, init) : {};
                init.headers = Object.assign({}, init.headers || {}, { 'X-CSRF-Token': token });
            }
        }

        const response = await _nativeFetch(input, init);

        // Direct auth failure from the reverse proxy (Authentik).
        // Our own PHP endpoints also return 401/403 for CSRF or admin-key failures,
        // but those have Content-Type: application/json. The auth proxy returns
        // HTML or has no JSON content-type. Only treat non-JSON 401/403 as session expiry.
        if (response.status === 401 || response.status === 403) {
            const ct = response.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                _handleAuthExpiry();
            }
            return response;
        }

        // Auth-proxy transparent redirect: 302 → 200 HTML login page
        // response.redirected is true when fetch followed at least one redirect.
        // We only treat it as auth expiry when Content-Type is HTML, because
        // legitimate same-origin redirects (if any) would not return HTML here.
        if (response.redirected) {
            const ct = response.headers.get('content-type') || '';
            if (ct.includes('text/html')) {
                _handleAuthExpiry();
                // Throw so callers that check .ok or catch errors surface an error,
                // but don't wait for JSON parsing (which would fail on HTML anyway).
                throw new Error('Session udløbet');
            }
        }

        return response;
    };
})();

// ============================================================================
// TOAST NOTIFICATIONS
// ============================================================================

/**
 * Show a transient toast notification in the corner of the screen.
 *
 * Toasts are the app-wide way to confirm async actions (sync, save, delete)
 * that close a modal and would otherwise leave the user without feedback.
 * The container is an aria-live region so screen readers announce the message.
 *
 * @param {string} message - Text to show (plain text; HTML is escaped)
 * @param {'success'|'danger'|'info'} [type='success'] - Visual variant
 * @param {number} [durationMs=3500] - Auto-dismiss delay (0 = sticky)
 */
const showToast = (message, type = 'success', durationMs = 3500) => {
  let container = document.getElementById('toastContainer');
  if (!container) {
    // Self-heal if the markup is missing so callers never crash.
    container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container';
    container.setAttribute('role', 'status');
    container.setAttribute('aria-live', 'polite');
    container.setAttribute('aria-atomic', 'false');
    document.body.appendChild(container);
  }

  const icons = {
    success: 'fa-circle-check',
    danger:  'fa-circle-exclamation',
    info:    'fa-circle-info',
  };
  const toast = document.createElement('div');
  toast.className = `spark-toast spark-toast-${type}`;
  toast.innerHTML =
    `<i class="fas ${icons[type] || icons.info} spark-toast-icon" aria-hidden="true"></i>` +
    `<span class="spark-toast-msg">${escapeHtml(String(message))}</span>` +
    `<button type="button" class="spark-toast-close" aria-label="Luk besked">` +
      `<i class="fas fa-times" aria-hidden="true"></i></button>`;

  const dismiss = () => {
    toast.classList.remove('spark-toast-in');
    toast.classList.add('spark-toast-out');
    // Remove after the CSS transition; fall back to a timer if none runs.
    const done = () => toast.remove();
    toast.addEventListener('transitionend', done, { once: true });
    setTimeout(done, 400);
  };

  toast.querySelector('.spark-toast-close').addEventListener('click', dismiss);
  container.appendChild(toast);
  // Force reflow so the enter transition plays.
  requestAnimationFrame(() => toast.classList.add('spark-toast-in'));

  if (durationMs > 0) setTimeout(dismiss, durationMs);
  return { dismiss };
};

// ============================================================================
// ERROR STATE WITH RETRY
// ============================================================================

/**
 * Render a consistent error state with a "Prøv igen" button into a container.
 *
 * Replaces the many dead-end "Fejl: …" messages scattered across modules so a
 * failed fetch always offers a way forward without a full page reload.
 *
 * @param {string|HTMLElement} target - Element or element id to render into
 * @param {object} [opts]
 * @param {string}  [opts.message='Kunne ikke hente data.'] - Error text
 * @param {Function} [opts.onRetry] - Called when the user clicks "Prøv igen"
 * @param {boolean} [opts.asTableRow=false] - Render as a <tr>/<td> (for tbody)
 * @param {number}  [opts.colspan=1] - Column span when asTableRow is true
 */
const showRetryError = (target, opts = {}) => {
  const {
    message = 'Kunne ikke hente data.',
    onRetry,
    asTableRow = false,
    colspan = 1,
  } = opts;

  const el = typeof target === 'string' ? document.getElementById(target) : target;
  if (!el) return;

  const safeMsg = escapeHtml(String(message));
  const btnHtml = onRetry
    ? `<button type="button" class="btn btn-sm btn-outline-danger spark-retry-btn">
         <i class="fas fa-rotate-right me-1" aria-hidden="true"></i>Prøv igen</button>`
    : '';

  if (asTableRow) {
    el.innerHTML =
      `<tr><td colspan="${colspan}" class="text-center">
        <div class="spark-error" role="alert">
          <i class="fas fa-triangle-exclamation spark-error-icon" aria-hidden="true"></i>
          <span class="spark-error-msg">${safeMsg}</span>
          ${btnHtml}
        </div></td></tr>`;
  } else {
    el.innerHTML =
      `<div class="spark-error" role="alert">
        <i class="fas fa-triangle-exclamation spark-error-icon" aria-hidden="true"></i>
        <span class="spark-error-msg">${safeMsg}</span>
        ${btnHtml}
      </div>`;
  }

  if (onRetry) {
    el.querySelector('.spark-retry-btn')?.addEventListener('click', () => onRetry());
  }
};

// ============================================================================
// EMPTY STATE
// ============================================================================

/**
 * Render a consistent empty state (icon + message + optional action) so the
 * "no data" case looks the same across the app and can point the user forward.
 *
 * @param {string|HTMLElement} target - Element or element id to render into
 * @param {object} [opts]
 * @param {string}  [opts.message='Ingen data at vise.'] - Explanation text
 * @param {string}  [opts.icon='fa-inbox']  - Font Awesome icon (without "fas")
 * @param {string}  [opts.actionLabel]      - Optional action button label
 * @param {Function} [opts.onAction]        - Called when the action is clicked
 * @param {boolean} [opts.asTableRow=false] - Render as a <tr>/<td>
 * @param {number}  [opts.colspan=1]        - Column span when asTableRow
 */
const showEmptyState = (target, opts = {}) => {
  const {
    message = 'Ingen data at vise.',
    icon = 'fa-inbox',
    actionLabel,
    onAction,
    asTableRow = false,
    colspan = 1,
  } = opts;

  const el = typeof target === 'string' ? document.getElementById(target) : target;
  if (!el) return;

  const btnHtml = (actionLabel && onAction)
    ? `<button type="button" class="btn btn-sm btn-secondary spark-empty-btn">${escapeHtml(actionLabel)}</button>`
    : '';
  const inner =
    `<div class="spark-empty">
      <i class="fas ${escapeHtml(icon)} spark-empty-icon" aria-hidden="true"></i>
      <span class="spark-empty-msg">${escapeHtml(String(message))}</span>
      ${btnHtml}
    </div>`;

  el.innerHTML = asTableRow
    ? `<tr><td colspan="${colspan}" class="text-center">${inner}</td></tr>`
    : inner;

  if (actionLabel && onAction) {
    el.querySelector('.spark-empty-btn')?.addEventListener('click', () => onAction());
  }
};

// ============================================================================
// CONFIRM DIALOG
// ============================================================================

/**
 * Promise-based confirmation dialog rendered as a styled Bootstrap modal.
 *
 * Replaces the native window.confirm() so destructive actions share one
 * consistent, keyboard-accessible look across the app. Reuses a single
 * reusable modal element (#confirmDialogModal), creating it on first use.
 *
 * @param {object} [opts]
 * @param {string} [opts.title='Bekræft']       - Modal heading
 * @param {string} [opts.message='Er du sikker?'] - Body text
 * @param {string} [opts.confirmLabel='Bekræft'] - Confirm button label
 * @param {string} [opts.cancelLabel='Annuller'] - Cancel button label
 * @param {boolean} [opts.danger=true]           - Style confirm button as destructive
 * @returns {Promise<boolean>} Resolves true if confirmed, false otherwise
 */
const confirmDialog = (opts = {}) => {
  const {
    title = 'Bekræft',
    message = 'Er du sikker?',
    confirmLabel = 'Bekræft',
    cancelLabel = 'Annuller',
    danger = true,
  } = opts;

  return new Promise((resolve) => {
    // Fallback if Bootstrap is unavailable for any reason.
    if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
      resolve(window.confirm(message));
      return;
    }

    let modalEl = document.getElementById('confirmDialogModal');
    if (!modalEl) {
      modalEl = document.createElement('div');
      modalEl.id = 'confirmDialogModal';
      modalEl.className = 'modal fade';
      modalEl.tabIndex = -1;
      modalEl.setAttribute('aria-hidden', 'true');
      modalEl.innerHTML =
        `<div class="modal-dialog modal-sm modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header py-2">
              <h6 class="modal-title mb-0" data-confirm-title></h6>
              <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Luk"></button>
            </div>
            <div class="modal-body" data-confirm-message></div>
            <div class="modal-footer py-2">
              <button type="button" class="btn btn-secondary btn-sm" data-confirm-cancel></button>
              <button type="button" class="btn btn-sm" data-confirm-ok></button>
            </div>
          </div>
        </div>`;
      document.body.appendChild(modalEl);
    }

    modalEl.querySelector('[data-confirm-title]').textContent = title;
    modalEl.querySelector('[data-confirm-message]').textContent = message;
    const cancelBtn = modalEl.querySelector('[data-confirm-cancel]');
    const okBtn     = modalEl.querySelector('[data-confirm-ok]');
    cancelBtn.textContent = cancelLabel;
    okBtn.textContent     = confirmLabel;
    okBtn.className        = 'btn btn-sm ' + (danger ? 'btn-danger' : 'btn-primary');

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    let settled = false;

    const cleanup = (result) => {
      if (settled) return;
      settled = true;
      okBtn.removeEventListener('click', onConfirm);
      modalEl.removeEventListener('hidden.bs.modal', onHidden);
      resolve(result);
    };
    const onConfirm = () => { cleanup(true); modal.hide(); };
    const onHidden  = () => cleanup(false); // covers cancel, backdrop, Esc, close

    okBtn.addEventListener('click', onConfirm);
    modalEl.addEventListener('hidden.bs.modal', onHidden);
    // Move focus to the confirm button once shown for keyboard users.
    modalEl.addEventListener('shown.bs.modal', () => okBtn.focus(), { once: true });
    modal.show();
  });
};

// Export for use in index.php inline scripts
window.appUtils = {
  debounce,
  handleFetchResponse,
  initializeTooltips,
  formatCurrency,
  elvarmeCost,
  formatDate,
  escapeHtml,
  updateChartInstance,
  showFormMessage,
  showToast,
  confirmDialog,
  showRetryError,
  showEmptyState,
  validateForm,
  setLoadingState,
  parseDateRange,
  extractChargeData,
  initializeAppUtilities
};
