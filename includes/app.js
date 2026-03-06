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
  console.log('SparkSpend app utilities initialized');
};

// Global event bus for cross-module communication
window.SparkEvents = new EventTarget();

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
