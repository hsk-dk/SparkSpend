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
    window.fetch = async function(input, init) {
        const response = await _nativeFetch(input, init);

        // Direct auth failure
        if (response.status === 401 || response.status === 403) {
            _handleAuthExpiry();
            return response; // still return so caller can reject gracefully
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

// Export for use in index.php inline scripts
window.appUtils = {
  debounce,
  handleFetchResponse,
  initializeTooltips,
  formatCurrency,
  formatDate,
  escapeHtml,
  updateChartInstance,
  showFormMessage,
  validateForm,
  setLoadingState,
  parseDateRange,
  extractChargeData,
  initializeAppUtilities
};
