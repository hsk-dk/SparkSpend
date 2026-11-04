<?php
require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
?>
<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
  <title>SparkSpend</title>
  <link rel="icon" type="image/svg+xml" href="includes/favicon.svg">
  <!-- PWA -->
  <meta name="description" content="Energiforbrug og EV-opladningsoverblik">
  <meta name="theme-color" content="#1a2635">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="SparkSpend">
  <link rel="manifest" href="/manifest.json">
  <link rel="apple-touch-icon" href="/images/icon-192.png">
  <!-- Inter Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <!-- Material Symbols -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">  
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" crossorigin="anonymous">

  <!-- Flatpickr CSS og JS -->
  <!-- All library scripts use defer: they no longer block first paint and
       execute in document order after parsing, before the deferred module
       scripts below (which depend on them). -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">
  <script defer src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/da.js" integrity="sha384-dgq9j9754/u8n76Jzr7O87ddWLz93g5aKCUh0P6GcfqWbrK2886Or8M9UGOY7BQ4" crossorigin="anonymous"></script>
  
  <!-- Bootstrap CSS og JS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
  <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>
   <!-- Chart.js -->
  <script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.js" integrity="sha384-MH1axGwz/uQzfIcjFdjEfsM0xlf5mmWfAwwggaOh5IPFvgKFGbJ2PZ4VBbgSYBQN" crossorigin="anonymous"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js" integrity="sha384-y49Zu59jZHJL/PLKgZPv3k2WI9c0Yp3pWB76V8OBVCb0QBKS8l4Ff3YslzHVX76Y" crossorigin="anonymous"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js" integrity="sha384-cVMg8E3QFwTvGCDuK+ET4PD341jF3W8nO1auiXfuZNQkzbUUiBGLsIQUE+b1mxws" crossorigin="anonymous"></script>
  <link rel="stylesheet" href="<?= asset('includes/style.css') ?>">

  <script defer src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.0/dist/apexcharts.min.js" integrity="sha384-5u6RO0Q8xx8HD9yfm1MUPRgt4kTTlZxjJmXIFZxa5pEZy7U9XOtkAZgbF1fSYmsw" crossorigin="anonymous"></script>

</head>
<body>
  <!-- Toast notifications (aria-live region for app-wide feedback) -->
  <div id="toastContainer" class="toast-container" role="status" aria-live="polite" aria-atomic="false"></div>

  <!-- Modern Tab-Based Navigation Header -->
  <header>
    <div class="header-left">
      <div class="d-flex align-items-center gap-2">
        <div style="width: 48px; height: 48px;">
          <img src="images/logo.svg" width="48" height="48" alt="SparkSpend Logo">
        </div>
        <h1>SparkSpend</h1>
      </div>
    </div>

    <!-- Main Navigation Tabs -->
    <nav class="header-nav">
      <button class="header-tab-button" data-target="oversigt-section">
        <i class="fas fa-gauge-high"></i> <span class="tab-label">Oversigt</span>
      </button>
      <button class="header-tab-button" data-target="elbil-section">
        <i class="fas fa-charging-station"></i> <span class="tab-label">Elbil</span>
        <span class="anomaly-tab-badge" id="anomaly-badge-ev" style="display:none"></span>
      </button>
      <button class="header-tab-button" data-target="jordvarme-section">
        <i class="fas fa-fire"></i> <span class="tab-label">Jordvarme</span>
        <span class="anomaly-tab-badge" id="anomaly-badge-hp" style="display:none"></span>
      </button>
      <button class="header-tab-button" data-target="hus-section">
        <i class="fas fa-house-chimney"></i> <span class="tab-label">Hus</span>
        <span class="anomaly-tab-badge" id="anomaly-badge-hus" style="display:none"></span>
      </button>
      <button class="header-tab-button" data-target="aarsrapport-section">
        <i class="fas fa-calendar-alt"></i> <span class="tab-label">Årsrapport</span>
      </button>
    </nav>

    <!-- Header Right: Sync Status, Filter Button + Create Charge Button -->
    <div class="header-right">
      <button type="button" class="btn btn-secondary" id="syncStatusBtn"
              data-bs-toggle="modal" data-bs-target="#syncModal"
              title="Synkroniseringsstatus">
        <i class="fas fa-sync-alt"></i>
      </button>
      <button type="button" class="btn btn-secondary" id="filterToggleBtn" title="Åbn filtre"
              aria-expanded="false" aria-controls="filterDrawer" aria-haspopup="dialog">
        <i class="fas fa-sliders-h"></i>
      </button>
      <button type="button" class="btn btn-primary button-create-charge" data-bs-toggle="modal" data-bs-target="#createExChargeModal">
        <i class="fas fa-plus"></i> <span>Ny Ladning</span>
      </button>
    </div>
  </header>

  <!-- Filter Drawer (Sidebar) -->
  <div id="filterDrawer" class="filter-drawer" role="dialog" aria-modal="false" aria-label="Filtre">
    <div class="filter-drawer-header">
      <h3>Filtre</h3>
      <button type="button" class="btn-close-drawer" id="filterCloseBtn" title="Luk filtre">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <div class="filter-drawer-content">
      <div class="filter-group">
        <label for="filter">Filtrer efter bil:</label>
        <select id="filter">
          <option value="all">Alle</option>
        </select>
      </div>

      <div class="filter-group">
        <label>
          <input type="checkbox" id="showZeroKwh"> Vis ladninger med 0 kWh
        </label>
      </div>

      <div class="filter-group">
        <label for="dateRange">Vælg dato interval:</label>
        <input type="text" id="dateRange">
      </div>

      <div class="filter-group">
        <label for="quickFilter">Hurtigt filter:</label>
        <select id="quickFilter">
          <option value="all">Alle</option>
          <option value="today">I dag</option>
          <option value="week">Denne uge</option>
          <option value="lmonth">Sidste måned</option>
          <option value="month">Denne måned</option>
          <option value="year">Dette år</option>
        </select>
      </div>
    </div>
  </div>

  <!-- Filter Drawer Backdrop -->
  <div id="filterBackdrop" class="filter-backdrop"></div>

<section id="oversigt-section" class="tab-section">
  <div class="dashboard-grid">

    <!-- EV Dashboard Card -->
    <div class="dashboard-card" id="ev-dashboard-card" role="button" tabindex="0" aria-label="Åbn Elbil-oversigt" data-action="nav" data-section="elbil-section" data-sub="elbil-ladninger">
      <div class="dash-header">
        <span class="dash-icon"><i class="fas fa-charging-station"></i></span>
        <div>
          <div class="dash-title">Elbil</div>
          <div class="dash-period"></div>
        </div>
      </div>
      <div class="dash-body">
        <div class="dash-stats">
          <div class="dash-stat">
            <div class="dash-stat-charges dash-stat-value">—</div>
            <div class="dash-stat-label">Ladninger</div>
          </div>
          <div class="dash-stat">
            <div class="dash-stat-kwh dash-stat-value">—</div>
            <div class="dash-stat-label">kWh</div>
          </div>
          <div class="dash-stat">
            <div class="dash-stat-cost dash-stat-value">—</div>
            <div class="dash-stat-label">Pris</div>
          </div>
          <div class="dash-stat">
            <div class="dash-stat-cpkwh dash-stat-value">—</div>
            <div class="dash-stat-label">kr/kWh</div>
          </div>
        </div>
        <div class="dash-meta">
          <span class="dash-projected"></span>
        </div>
        <div class="dash-split-wrap" style="display:none">
          <div class="dash-split-track">
            <div class="dash-split-home-fill"></div>
            <div class="dash-split-ext-fill"></div>
          </div>
          <div class="dash-split-labels">
            <span class="dash-split-home-lbl"></span>
            <span class="dash-split-ext-lbl"></span>
          </div>
        </div>
        <div class="dash-trend"></div>
        <div class="dash-sparkline">
          <canvas id="ev-sparkline"></canvas>
        </div>
        <div class="dash-sync" id="ev-sync-label"></div>
      </div>
    </div>

    <!-- Heatpump Dashboard Card -->
    <div class="dashboard-card" id="hp-dashboard-card" role="button" tabindex="0" aria-label="Åbn Jordvarme-oversigt" data-action="nav" data-section="jordvarme-section">
      <div class="dash-header">
        <span class="dash-icon"><i class="fas fa-fire"></i></span>
        <div>
          <div class="dash-title">Jordvarme</div>
          <div class="dash-period"></div>
        </div>
      </div>
      <div class="dash-body">
        <div class="dash-stats">
          <div class="dash-stat">
            <div class="dash-stat-kwh dash-stat-value">—</div>
            <div class="dash-stat-label">kWh</div>
          </div>
          <div class="dash-stat">
            <div class="dash-stat-daily dash-stat-value">—</div>
            <div class="dash-stat-label">kWh/dag</div>
          </div>
          <div class="dash-stat" id="hp-cost-stat" style="display:none">
            <div class="dash-est-cost dash-stat-value">—</div>
            <div class="dash-stat-label">~kr</div>
          </div>
        </div>
        <div class="dash-meta">
          <span class="dash-projected"></span>
        </div>
        <div class="dash-trend"></div>
        <div class="dash-sparkline">
          <canvas id="hp-sparkline"></canvas>
        </div>
        <div class="dash-sync" id="hp-sync-label"></div>
      </div>
    </div>

    <!-- Hus Dashboard Card -->
    <div class="dashboard-card" id="hus-dashboard-card" role="button" tabindex="0" aria-label="Åbn Hus-oversigt" data-action="nav" data-section="hus-section" data-sub="hus-forbrug">
      <div class="dash-header">
        <span class="dash-icon"><i class="fas fa-house-chimney"></i></span>
        <div>
          <div class="dash-title" title="Husets måler minus varmepumpe og hjemmeladning">Hus</div>
          <div class="dash-period"></div>
        </div>
      </div>
      <div class="dash-body">
        <div class="dash-stats">
          <div class="dash-stat">
            <div class="dash-stat-kwh dash-stat-value">—</div>
            <div class="dash-stat-label">kWh</div>
          </div>
          <div class="dash-stat">
            <div class="dash-stat-daily dash-stat-value">—</div>
            <div class="dash-stat-label">kWh/dag</div>
          </div>
          <div class="dash-stat" id="hus-cost-stat" style="display:none">
            <div class="dash-est-cost dash-stat-value">—</div>
            <div class="dash-stat-label">~kr</div>
          </div>
        </div>
        <div class="dash-meta">
          <span class="dash-projected"></span>
        </div>
        <div class="dash-trend"></div>
        <div class="dash-sparkline">
          <canvas id="hus-sparkline"></canvas>
        </div>
        <div class="dash-sync" id="hus-sync-label"></div>
      </div>
    </div>

  </div>

  <!-- Auto-refresh indicator (shown briefly while the dashboard reloads) -->
  <div id="dashRefreshIndicator" class="dash-refresh-indicator" role="status" aria-live="polite" style="display:none">
    <span class="dash-refresh-spinner" aria-hidden="true"></span>
    <span>Opdaterer…</span>
  </div>

  <!-- Total Electricity Cost Estimate -->
  <div id="total-cost-estimate" class="total-cost-card" style="display:none"></div>

  <!-- Anomaly Detection Panel -->
  <div id="anomaly-panel" class="anomaly-panel" style="display:none">
    <div class="anomaly-panel-header">
      <i class="fas fa-exclamation-triangle"></i>
      <span>Afvigelser fra samme periode sidste år</span>
    </div>
    <div id="anomaly-list"></div>
  </div>

  <!-- Elspot Forecast Widget -->
  <div id="elspot-forecast-card" class="card p-4 mt-4" style="display:none">
    <div class="d-flex align-items-baseline justify-content-between gap-2 mb-1">
      <h3 class="mb-0">Elpris i morgen</h3>
      <span class="text-muted small" id="elspot-forecast-meta"></span>
    </div>
    <div id="elspot-forecast-chart"></div>
    <div id="elspot-cheapest-window" class="mt-2 small"></div>
  </div>

</section>

<section id="elbil-section" class="tab-section">

  <!-- Sub-Navigation -->
  <nav class="sub-nav">
    <button class="sub-nav-btn" data-sub="elbil-ladninger">
      <i class="fas fa-list"></i> Ladninger
    </button>
    <button class="sub-nav-btn" data-sub="elbil-analyse">
      <i class="fas fa-chart-line"></i> Analyse
    </button>
    <button class="sub-nav-btn" data-sub="elbil-sammenligning">
      <i class="fas fa-car"></i> Sammenligning
    </button>
    <button class="sub-nav-btn ms-auto" id="manageBtn" data-action="elbil-manage-open">
      <i class="fas fa-sliders"></i> Administrér
    </button>
  </nav>

  <!-- Sub-section: Ladninger -->
  <div id="elbil-ladninger" class="sub-section active">

  <!-- Opsummering og statistikker (respekterer filtrer øverst) -->
<div id="summaryBox">
  <!-- Opsummeringstabel -->
  <div id="summaryText" style="margin-bottom: 24px;">
    <!-- Her indsættes opsummeringstabellen -->
  </div>

  <!-- Tre diagrammer side om side med shared legend -->
  <div class="pie-charts-wrapper">
    <div class="pie-chart-compact">
      <canvas id="pieChartCount"></canvas>
    </div>
    <div class="pie-chart-compact">
      <canvas id="pieChartKwh"></canvas>
    </div>
    <div class="pie-chart-compact">
      <canvas id="pieChartPrice"></canvas>
    </div>
  </div>
  <!-- Shared legend for all pie charts -->
  <div class="pie-charts-legend" id="pieChartsSharedLegend">
    <div class="legend-item">
      <span class="legend-color internal"></span>
      <span class="legend-label">Interne</span>
    </div>
    <div class="legend-item">
      <span class="legend-color external"></span>
      <span class="legend-label">Eksterne</span>
    </div>
  </div>
</div>
<!-- All analytics moved to analytics-section -->


  <div class="table-responsive">
    <table>
    <thead id="chargeTableHead">
      <tr>
        <th data-sort="datetime">Dato <span class="sort-arrow"></span></th>
        <th data-sort="kwh">Forbrugt kWh <span class="sort-arrow"></span></th>
        <th>Varighed</th>
        <th data-sort="pris">Pris <span class="sort-arrow"></span></th>
        <th data-sort="ppkwh">Pris pr kWh <span class="sort-arrow"></span></th>
        <th data-sort="vehicle">Bil <span class="sort-arrow"></span></th>
        <th>Type</th>
        <th>Handling</th>
      </tr>
    </thead>
    <tbody id="chargeTableBody">
      <tr><td colspan="8">Indlæser data...</td></tr>
    </tbody>
  </table>
  </div>

  </div><!-- /#elbil-ladninger -->

  <!-- EV Modals (inside elbil-section, accessible from any sub-tab via Bootstrap) -->
  <!-- Modal til oprettelse af ekstern ladning -->
  <div class="modal fade" id="createExChargeModal" tabindex="-1" aria-labelledby="createExChargeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="createExChargeModalLabel">Opret ekstern ladning</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Luk"></button>
        </div>
        <div class="modal-body">
          <form id="exChargeForm">
            <div class="mb-3">
              <label for="vehicleId" class="form-label">Bil:</label>
              <select id="vehicleId" name="vehicleId" class="form-select" required>
                <option value="">Vælg bil</option>
              </select>
            </div>
            <div class="mb-3">
              <label for="providerId" class="form-label">Udbyder:</label>
              <select id="providerId" name="providerId" class="form-select" required>
                <option value="">Vælg udbyder</option>
              </select>
            </div>
            <div class="mb-3">
              <label for="chargeDateTime" class="form-label">Tidspunkt:</label>
              <input type="text" id="chargeDateTime" name="chargeDateTime" class="form-control" required>
            </div>
            <div class="mb-3">
              <label for="kwh" class="form-label">Forbrugt kWh:</label>
              <input type="number" step="0.01" id="kwh" name="kwh" class="form-control" required min="0" max="10000">
            </div>
            <div class="mb-3">
              <label for="pris" class="form-label">Pris:</label>
              <input type="number" step="0.01" id="pris" name="pris" class="form-control" required min="0" max="100000">
            </div>
            <button type="submit" class="btn btn-primary">Opret session</button>
          </form>
          <div id="exChargeMsg" class="mt-2"></div>
        </div>
      </div>
    </div>
  </div>
  <!-- Slut på oprettelsesmodal -->

  <!-- Modal til redigering af interne ladninger (charges) – kun bil kan ændres -->
  <div class="modal fade" id="internalChargeModal" tabindex="-1" aria-labelledby="internalChargeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="internalChargeModalLabel">Rediger intern ladning</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Luk"></button>
        </div>
        <div class="modal-body">
          <form id="internalChargeForm">
            <input type="hidden" id="internalChargeId">
            <div class="mb-3">
              <label for="internalVehicleId" class="form-label">Bil:</label>
              <select id="internalVehicleId" name="vehicleId" class="form-select" required>
                <option value="">Vælg bil</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary">Gem ændringer</button>
          </form>
          <div id="internalChargeMsg" class="mt-2"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal til redigering af eksterne ladninger (ext_charges) – alle felter redigerbare -->
  <div class="modal fade" id="externalChargeModal" tabindex="-1" aria-labelledby="externalChargeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="externalChargeModalLabel">Rediger ekstern ladning</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Luk"></button>
        </div>
        <div class="modal-body">
          <form id="externalChargeForm">
            <input type="hidden" id="externalChargeId">
            <div class="mb-3">
              <label for="externalVehicleId" class="form-label">Bil:</label>
              <select id="externalVehicleId" name="vehicleId" class="form-select" required>
                <option value="">Vælg bil</option>
              </select>
            </div>
            <div class="mb-3">
              <label for="externalProviderId" class="form-label">Udbyder:</label>
              <select id="externalProviderId" name="providerId" class="form-select" required>
                <option value="">Vælg udbyder</option>
              </select>
            </div>
            <div class="mb-3">
              <label for="externalChargeDateTime" class="form-label">Dato:</label>
              <input type="text" id="externalChargeDateTime" name="datetime" class="form-control" required>
            </div>
            <div class="mb-3">
              <label for="externalKwh" class="form-label">Forbrugt kWh:</label>
              <input type="number" step="0.01" id="externalKwh" name="kwh" class="form-control" required min="0" max="10000">
            </div>
            <div class="mb-3">
              <label for="externalPris" class="form-label">Pris:</label>
              <input type="number" step="0.01" id="externalPris" name="pris" class="form-control" required min="0" max="100000">
            </div>
            <button type="submit" class="btn btn-primary">Gem ændringer</button>
          </form>
		  <button id="deleteExternalChargeButton" class="btn btn-danger mt-2">Slet ladning</button>
          <div id="externalChargeMsg" class="mt-2"></div>
        </div>
      </div>
    </div>
  </div>
  <!-- Delete confirmation modal -->
  <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Bekræft sletning</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Luk"></button>
        </div>
        <div class="modal-body">
          <p>Er du sikker på, at du vil slette denne ladning?</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuller</button>
          <button type="button" class="btn btn-danger" id="deleteConfirmBtn">Slet</button>
        </div>
      </div>
    </div>
  </div>
  <!-- Slut på modaler -->

  <!-- Sub-section: Analyse -->
  <div id="elbil-analyse" class="sub-section">

    <!-- Charging optimisation: what the charges cost vs. the cheapest hours -->
    <div class="card p-4" id="chargeOptCard">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <h3 class="mb-0">Ladeoptimering</h3>
        <label class="small text-muted d-flex align-items-center gap-2 mb-0">Periode
          <select id="chargeOptMonths" class="form-select form-select-sm w-auto">
            <option value="3" selected>3 måneder</option>
            <option value="6">6 måneder</option>
            <option value="12">12 måneder</option>
          </select>
        </label>
      </div>
      <p class="text-muted small mb-3">
        Hvad dine ladninger hjemme kostede til timeprisen, og hvad de samme kWh ville have kostet i de billigste timer, bilen stod tilsluttet.
      </p>
      <div id="chargeOptContent"><p class="text-muted small mb-0">Indlæser…</p></div>
    </div>

    <!-- Efficiency Charts Card -->
    <div class="card p-4">
      <h3>Forbrug pr. km</h3>
      <p class="text-muted small mb-3">
        Beregnet pr. periode ud fra kilometertælleren ved tilslutning og den energi, der er ladet (netenergi, inkl. ladetab).
        Uden batteriets ladeniveau kan en periode afvige med op til ét batteris energi, så tallet er mere sikkert, jo flere kWh og km der er med.
      </p>
      <div id="efficiencySummary"></div>
      <h5 class="mt-3">Kumuleret udvikling</h5>
      <div id="kmPerKwhChart"></div>
      <div id="krPerKmChart"></div>
    </div>

    <!-- Cost Analytics Card -->
    <div class="card p-4 mt-4">
      <h3>Omkostningsanalyse</h3>
      <div class="hp-mode-nav mb-3">
        <button class="hp-mode-btn" data-grouping="day">Pr. dag</button>
        <button class="hp-mode-btn active" data-grouping="week">Pr. uge</button>
        <button class="hp-mode-btn" data-grouping="month">Pr. måned</button>
      </div>
      <div class="row">
        <div class="col-md-6">
          <div id="costTrendChart"></div>
        </div>
        <div class="col-md-6">
          <div id="costStatsBox" class="card p-3">
            <h5>Omkostningsstatistik</h5>
            <div id="costStatsContent">
              <p>Indlæser data...</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Provider Stats Card -->
    <div class="card p-4 mt-4">
      <h3>Ladesteder</h3>
      <div id="providerStatsContent">
        <p class="text-muted small">Indlæser data...</p>
      </div>
    </div>

    <!-- Time-of-use heatmap card -->
    <div class="card p-4 mt-4" id="touHeatmapCard">
      <h3>Elspot-tidsmønster</h3>
      <p class="text-muted small mb-2">Gennemsnitlig elpris (kr/kWh inkl. afgifter) og dine ladninger fordelt på time og ugedag.</p>
      <div class="hp-mode-nav mb-3" id="touPeriodNav">
        <button class="hp-mode-btn" data-tou-days="30">30 dage</button>
        <button class="hp-mode-btn active" data-tou-days="90">90 dage</button>
        <button class="hp-mode-btn" data-tou-days="180">6 mdr</button>
        <button class="hp-mode-btn" data-tou-days="365">1 år</button>
      </div>
      <div id="touHeatmapContainer">
        <p class="text-muted small">Kræver elspot-konfiguration (ELSPOT_AREA + ELSPOT_GLN).</p>
      </div>
    </div>

  </div><!-- /#elbil-analyse -->

  <!-- Sub-section: Sammenligning -->
  <div id="elbil-sammenligning" class="sub-section">

    <!-- År-over-år forbrug -->
    <div class="card p-4 mb-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <h3 class="mb-0">År-over-år forbrug</h3>
        <div class="hp-mode-nav">
          <button class="hp-mode-btn active" id="evCompareKwhBtn">kWh</button>
          <button class="hp-mode-btn" id="evCompareCostBtn">kr</button>
        </div>
      </div>
      <div id="evYearToggleContainer" class="year-chip-bar mb-3" style="display:none"></div>
      <div style="position:relative; min-height:220px">
        <canvas id="evYearCompareChart"></canvas>
        <div id="evYearCompareNoData" class="text-muted text-center py-4" style="display:none">Ingen data at vise.</div>
        <div id="evYearCompareError" class="text-danger text-center py-4" style="display:none">Kunne ikke indlæse data.</div>
      </div>
      <div id="evYearCompareStats" class="mt-3"></div>
    </div>

    <!-- Vehicle Comparison Card -->
    <div class="card p-4 mt-4">
      <h3>Bilsammenligning</h3>
      <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span class="text-muted small">Sorter efter:</span>
        <button type="button" class="btn btn-sm btn-secondary" data-action="vehicle-comparison-sort" data-sort="total_cost">Samlede omkostninger</button>
        <button type="button" class="btn btn-sm btn-secondary" data-action="vehicle-comparison-sort" data-sort="avg_cost_per_kwh">Pris pr kWh</button>
        <button type="button" class="btn btn-sm btn-secondary" data-action="vehicle-comparison-sort" data-sort="total_kwh">Samlet forbrug</button>
        <button type="button" class="btn btn-sm btn-secondary" data-action="vehicle-comparison-sort" data-sort="charge_count">Antal ladninger</button>
      </div>
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>Bil</th>
              <th>Antal ladninger</th>
              <th>Samlet kWh</th>
              <th>Samlet pris (DKK)</th>
              <th>Pris pr kWh</th>
              <th>Internt (%)</th>
              <th>Eksternt (%)</th>
            </tr>
          </thead>
          <tbody id="vehicleComparisonTableBody">
            <tr><td colspan="7" class="text-center">Indlæser data...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /#elbil-sammenligning -->

  <!-- Manage modal: vehicles + providers -->
  <div class="modal fade" id="manageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-sliders me-2"></i>Administrér</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

          <!-- Vehicles -->
          <h6 class="fw-semibold mb-2"><i class="fas fa-car me-1"></i> Køretøjer</h6>
          <div id="manageVehiclesList" class="mb-4"></div>

          <hr>

          <!-- Providers -->
          <h6 class="fw-semibold mb-2"><i class="fas fa-charging-station me-1"></i> Udbydere</h6>
          <div id="manageProvidersList" class="mb-3"></div>

          <!-- Add provider -->
          <div class="d-flex gap-2" id="addProviderRow">
            <input type="text" id="newProviderName" class="form-control form-control-sm"
                   placeholder="Ny udbyder…" maxlength="80">
            <button class="btn btn-sm btn-primary text-nowrap" data-action="provider-create">
              <i class="fas fa-plus"></i> Tilføj
            </button>
          </div>
          <div id="manageMsg" class="mt-2 small"></div>

        </div>
      </div>
    </div>
  </div><!-- /#manageModal -->

</section><!-- /#elbil-section -->

<section id="jordvarme-section" class="tab-section">

  <div class="card p-4">
    <h3>Jordvarmeforbrug</h3>
    <div class="hp-mode-nav mb-3">
      <button class="hp-mode-btn active" data-mode="daily">Daglig</button>
      <button class="hp-mode-btn" data-mode="monthly">Månedlig</button>
      <button class="hp-mode-btn" data-mode="compare">Sammenligning</button>
      <button class="hp-mode-btn" data-mode="ytd">År til dato</button>
    </div>

    <!-- Year toggle chips (compare mode only, populated by JS) -->
    <div id="yearToggleContainer" class="year-chip-bar" style="display:none"></div>

    <div class="row">
      <div class="col-md-8">
        <div class="d-flex align-items-center mb-2" id="heatpumpPeriodNav">
          <button class="btn btn-sm btn-secondary me-2" id="heatpumpPrev">&laquo; Forrige</button>
          <span id="heatpumpPeriodLabel" class="fw-bold"></span>
          <button class="btn btn-sm btn-secondary ms-2" id="heatpumpNext">Næste &raquo;</button>
        </div>
        <canvas id="heatpumpChart"></canvas>
        <div id="heatpumpNoData" class="text-muted text-center py-4" style="display:none">Ingen data tilgængelig for denne periode.</div>
        <div id="heatpumpError" class="text-danger text-center py-4" style="display:none">Fejl ved hentning af data.</div>
      </div>
      <div class="col-md-4">
        <div class="card p-3" id="heatpumpStatsBox">
          <h5>Statistik</h5>
          <div id="heatpumpStatsContent"><p>Indlæser data...</p></div>
        </div>
        <div class="mt-2 text-muted small" id="heatpumpSyncStatus"></div>
      </div>
    </div>
  </div>

</section><!-- /#jordvarme-section -->

<section id="hus-section" class="tab-section">

  <!-- Sub-Navigation -->
  <nav class="sub-nav">
    <button class="sub-nav-btn" data-sub="hus-forbrug">
      <i class="fas fa-bolt"></i> Forbrug
    </button>
    <button class="sub-nav-btn" data-sub="hus-regning">
      <i class="fas fa-file-invoice"></i> Regning
    </button>
    <button class="sub-nav-btn" data-sub="hus-fordeling">
      <i class="fas fa-chart-pie"></i> Fordeling
    </button>
  </nav>

  <!-- Sub-section: Forbrug -->
  <div id="hus-forbrug" class="sub-section active">
    <div class="card p-4">
      <h3>Husforbrug</h3>
      <div class="hp-mode-nav mb-3">
        <button class="hp-mode-btn active" data-mode="daily">Daglig</button>
        <button class="hp-mode-btn" data-mode="monthly">Månedlig</button>
        <button class="hp-mode-btn" data-mode="compare">Sammenligning</button>
        <button class="hp-mode-btn" data-mode="ytd">År til dato</button>
      </div>

      <!-- Year toggle chips (compare/ytd only, populated by JS) -->
      <div id="husYearToggleContainer" class="year-chip-bar" style="display:none"></div>

      <div class="row">
        <div class="col-md-8">
          <div class="d-flex align-items-center mb-2" id="husPeriodNav">
            <button class="btn btn-sm btn-secondary me-2" id="husPrev">&laquo; Forrige</button>
            <span id="husPeriodLabel" class="fw-bold"></span>
            <button class="btn btn-sm btn-secondary ms-2" id="husNext">Næste &raquo;</button>
          </div>
          <canvas id="husChart"></canvas>
          <div id="husNoData" class="text-muted text-center py-4" style="display:none">Ingen data tilgængelig for denne periode.</div>
          <div id="husError" class="text-danger text-center py-4" style="display:none">Fejl ved hentning af data.</div>
        </div>
        <div class="col-md-4">
          <div class="card p-3" id="husStatsBox">
            <h5>Statistik</h5>
            <div id="husStatsContent"><p>Indlæser data...</p></div>
          </div>
          <div class="mt-2 text-muted small" id="husSyncStatus"></div>
        </div>
      </div>
    </div>
  </div><!-- /#hus-forbrug -->

  <!-- Sub-section: Regning -->
  <div id="hus-regning" class="sub-section">
    <div class="card p-4">
      <h3>Elforbrug til regning</h3>
      <p class="text-muted mb-3">
        Månedlig oversigt over forbrug til elbil og varmepumpe — brug kolonnerne til at fratrække
        disse poster fra din samlede elregning og finde restforbruget.
      </p>
      <div id="regningTableWrapper">
        <p class="text-muted">Indlæser…</p>
      </div>
      <div id="regningStatus" class="small mt-2 text-muted"></div>
    </div>
  </div><!-- /#hus-regning -->

  <!-- Sub-section: Fordeling -->
  <div id="hus-fordeling" class="sub-section">
    <div class="card p-4">
      <h3>Omkostningsfordeling</h3>
      <p class="text-muted mb-3">
        Fordeling af el-forbrug og -omkostninger på tværs af elbil, varmepumpe og restforbrug —
        de seneste 12 måneder. Giver et hurtigt svar på, hvad vi bruger pengene på.
      </p>
      <div id="fordelingStatus" class="small mb-3 text-muted"></div>
      <div id="fordelingContent">
        <div id="fordelingSummaryCards" class="row row-cols-auto g-3 mb-4"></div>
        <div class="row g-4 align-items-center">
          <div class="col-md-8">
            <canvas id="fordelingChart"></canvas>
          </div>
          <div class="col-md-4 d-flex flex-column align-items-center">
            <canvas id="fordelingDonutChart" style="max-height:280px"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div><!-- /#hus-fordeling -->

</section><!-- /#hus-section -->

<section id="aarsrapport-section" class="tab-section">
  <div class="card p-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
      <h3 class="mb-0">Årsrapport</h3>
      <div class="hp-mode-nav">
        <button class="hp-mode-btn active" id="annualKwhBtn">kWh</button>
        <button class="hp-mode-btn" id="annualKrBtn">kr</button>
      </div>
    </div>
    <p class="text-muted mb-3">Samlet energiforbrug pr. kalenderår på tværs af el-bil, jordvarme og hus.</p>
    <div id="annualLoading" class="text-muted py-3">Indlæser…</div>
    <div id="annualContent" style="display:none">
      <div class="row mb-2">
        <div class="col-lg-9 col-md-12">
          <canvas id="annualChart"></canvas>
          <p id="annualKrNote" class="text-muted small mt-1" style="display:none">Viser kun EV-pris — sæt ELSPOT_AREA og ELSPOT_GLN i .env for at estimere jordvarme- og husomkostninger.</p>
        </div>
      </div>
      <div id="annualTable"></div>
    </div>
  </div>
</section><!-- /#aarsrapport-section -->

<!-- ── Sync Status Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="syncModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0"><i class="fas fa-sync-alt me-2"></i>Synkronisering</h6>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="syncStatusList" class="px-3 py-2">
          <p class="text-muted text-center mb-0 py-2">Indlæser…</p>
        </div>
        <!-- Collapsible system log -->
        <div class="border-top">
          <button class="btn btn-link btn-sm w-100 text-start px-3 py-2 text-muted text-decoration-none d-flex align-items-center justify-content-between"
                  id="syncLogToggle" type="button" aria-expanded="false" aria-controls="syncLogPanel">
            <span><i class="fas fa-terminal me-2 fa-xs"></i>Systemlog</span>
            <i class="fas fa-chevron-down fa-xs" id="syncLogChevron"></i>
          </button>
          <div id="syncLogPanel" style="display:none">
            <div id="syncLogContent"
                 style="font-family:monospace;font-size:11px;line-height:1.5;
                        max-height:260px;overflow-y:auto;
                        background:#0f172a;color:#94a3b8;
                        padding:10px 12px;white-space:pre-wrap;word-break:break-all">
              <span class="text-muted">Indlæser log…</span>
            </div>
            <div class="d-flex align-items-center justify-content-between px-3 py-1 border-top" style="background:#f8fafc">
              <small id="syncLogMeta" class="text-muted"></small>
              <button class="btn btn-link btn-sm py-0 text-muted text-decoration-none" id="syncLogRefresh" title="Genindlæs log">
                <i class="fas fa-redo fa-xs"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2 justify-content-between">
        <small id="syncModalMsg" class="text-muted"></small>
        <button class="btn btn-primary btn-sm" id="syncAllBtn">
          <i class="fas fa-sync-alt me-1"></i> Sync alle
        </button>
      </div>
    </div>
  </div>
</div><!-- /#syncModal -->

  <!-- App Utilities -->
  <!-- Server configuration for JS modules (area/GLN from .env, never from user input) -->
  <script type="application/json" id="spark-config"><?= json_encode([
      'elspotArea'         => Config::elspotArea(),
      'elspotGln'          => Config::elspotGln(),
      'weatherLat'         => Config::weatherLat(),
      'weatherLon'         => Config::weatherLon(),
      'subscriptionPerDay' => Config::subscriptionPerDay(),
      'csrfToken'          => $_SESSION['csrf_token'] ?? '',
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script defer src="<?= asset('includes/config.js') ?>"></script>
  <script defer src="<?= asset('includes/actions.js') ?>"></script>
  <script defer src="<?= asset('includes/app.js') ?>"></script>

  <!-- Module Scripts (defer: run in order after the deferred libraries above) -->
  <script defer src="<?= asset('includes/nav.js') ?>"></script>
  <script defer src="<?= asset('includes/dashboard.js') ?>"></script>
  <!-- Elbil: parts share the Elbil namespace (core first, main last) -->
  <script defer src="<?= asset('includes/elbil/core.js') ?>"></script>
  <script defer src="<?= asset('includes/elbil/charges.js') ?>"></script>
  <script defer src="<?= asset('includes/elbil/optimization.js') ?>"></script>
  <script defer src="<?= asset('includes/elbil/efficiency.js') ?>"></script>
  <script defer src="<?= asset('includes/elbil/analytics.js') ?>"></script>
  <script defer src="<?= asset('includes/elbil/yearly.js') ?>"></script>
  <script defer src="<?= asset('includes/elbil/manage.js') ?>"></script>
  <script defer src="<?= asset('includes/elbil/tou.js') ?>"></script>
  <script defer src="<?= asset('includes/elbil/main.js') ?>"></script>
  <script defer src="<?= asset('includes/jordvarme.js') ?>"></script>
  <script defer src="<?= asset('includes/hus.js') ?>"></script>
  <script defer src="<?= asset('includes/regning.js') ?>"></script>
  <script defer src="<?= asset('includes/annual.js') ?>"></script>

  <!-- Sync Status Modal -->
  <script defer src="<?= asset('includes/sync-modal.js') ?>"></script>

  <!-- Footer -->
  <footer class="site-footer">
    <div class="footer-content">
      <p>SparkSpend • <a href="https://useful.dk" target="_blank" rel="noopener noreferrer">Powered by Useful</a></p>
      <div class="logo">
        <img src="images/useful-logo.svg" alt="Useful Logo" />
      </div>
    </div>
  </footer>

  <script defer src="<?= asset('includes/sw-register.js') ?>"></script>
</body>
</html>
