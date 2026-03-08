<?php
require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';
?>
<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SparkSpend</title>
  <link rel="icon" type="image/svg+xml" href="includes/favicon.svg">
  <!-- Material Symbols -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">  
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

  <!-- Flatpickr CSS og JS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/da.js"></script>
  
  <!-- Bootstrap CSS og JS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
   <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
  <link rel="stylesheet" href="includes/style.css?v=20260311">

  <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

</head>
<body>
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
        <i class="fas fa-home"></i> Oversigt
      </button>
      <button class="header-tab-button" data-target="elbil-section">
        <i class="fas fa-charging-station"></i> Elbil
      </button>
      <button class="header-tab-button" data-target="jordvarme-section">
        <i class="fas fa-fire"></i> Jordvarme
      </button>
    </nav>

    <!-- Header Right: Filter Button + Create Charge Button -->
    <div class="header-right">
      <button type="button" class="btn btn-secondary" id="filterToggleBtn" title="Åbn filtre">
        <i class="fas fa-sliders-h"></i>
      </button>
      <button type="button" class="btn btn-secondary" id="settingsBtn" title="Indstillinger" data-bs-toggle="modal" data-bs-target="#settingsModal">
        ⚙
      </button>
      <button type="button" class="btn btn-primary button-create-charge" data-bs-toggle="modal" data-bs-target="#createExChargeModal">
        <i class="fas fa-plus"></i> <span>Ny Ladning</span>
      </button>
    </div>
  </header>

  <!-- Filter Drawer (Sidebar) -->
  <div id="filterDrawer" class="filter-drawer">
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
    <div class="dashboard-card" id="ev-dashboard-card" onclick="SparkNav.navigateTo('elbil-section','elbil-ladninger')">
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
          <span class="dash-split"></span>
          <span class="dash-projected"></span>
        </div>
        <div class="dash-sparkline">
          <canvas id="ev-sparkline"></canvas>
        </div>
      </div>
    </div>

    <!-- Heatpump Dashboard Card -->
    <div class="dashboard-card" id="hp-dashboard-card" onclick="SparkNav.navigateTo('jordvarme-section')">
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
        </div>
        <div class="dash-meta">
          <span class="dash-projected"></span>
        </div>
        <div class="dash-trend"></div>
        <div class="dash-sparkline">
          <canvas id="hp-sparkline"></canvas>
        </div>
      </div>
    </div>

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
    <thead>
      <tr>
        <th>Dato</th>
        <th>Forbrugt kWh</th>
        <th>Pris</th>
		<th>Pris pr kWh</th>
        <th>Bil</th>
        <th>Type</th>
        <th>Handling</th>
      </tr>
    </thead>
    <tbody id="chargeTableBody">
      <tr><td colspan="7">Indlæser data...</td></tr>
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
                <?php
                  $db = DatabaseManager::getChargesDb();
                  $vehicles = QueryBuilder::selectAllVehicles($db);
                  foreach ($vehicles as $row) {
                    echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['vehicleName']) . '</option>';
                  }
                ?>
              </select>
            </div>
            <div class="mb-3">
              <label for="providerId" class="form-label">Udbyder:</label>
              <select id="providerId" name="providerId" class="form-select" required>
                <option value="">Vælg udbyder</option>
                <?php
                  $providers = QueryBuilder::selectAllProviders($db);
                  foreach ($providers as $row) {
                    echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['providerName']) . '</option>';
                  }
                ?>
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
                <?php
                  $vehicles = QueryBuilder::selectAllVehicles($db);
                  foreach ($vehicles as $row) {
                    echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['vehicleName']) . '</option>';
                  }
                ?>
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
                <?php
                  $vehicles = QueryBuilder::selectAllVehicles($db);
                  foreach ($vehicles as $row) {
                    echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['vehicleName']) . '</option>';
                  }
                ?>
              </select>
            </div>
            <div class="mb-3">
              <label for="externalProviderId" class="form-label">Udbyder:</label>
              <select id="externalProviderId" name="providerId" class="form-select" required>
                <option value="">Vælg udbyder</option>
                <?php
                  $providers = QueryBuilder::selectAllProviders($db);
                  foreach ($providers as $row) {
                    echo '<option value="' . htmlspecialchars($row['id']) . '">' . htmlspecialchars($row['providerName']) . '</option>';
                  }
                ?>
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
  <!-- Slut på modaler -->

  <!-- Sub-section: Analyse -->
  <div id="elbil-analyse" class="sub-section">

    <!-- Efficiency Charts Card -->
    <div class="card p-4">
      <h3>Effektivitetsmålinger</h3>
      <div class="mb-3">
        <p class="text-muted small">Viser samlet gennemsnit (per tur beregninger er upålidelige)</p>
      </div>
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

  </div><!-- /#elbil-analyse -->

  <!-- Sub-section: Sammenligning -->
  <div id="elbil-sammenligning" class="sub-section">

    <!-- Vehicle Comparison Card -->
    <div class="card p-4 mt-4">
      <h3>Bilsammenligning</h3>
      <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <span class="text-muted small">Sorter efter:</span>
        <button type="button" class="btn btn-sm btn-secondary" onclick="sortVehicleComparison('total_cost')">Samlede omkostninger</button>
        <button type="button" class="btn btn-sm btn-secondary" onclick="sortVehicleComparison('avg_cost_per_kwh')">Pris pr kWh</button>
        <button type="button" class="btn btn-sm btn-secondary" onclick="sortVehicleComparison('total_kwh')">Samlet forbrug</button>
        <button type="button" class="btn btn-sm btn-secondary" onclick="sortVehicleComparison('charge_count')">Antal ladninger</button>
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
  <!-- App Utilities -->
  <script src="includes/app.js?v=20260312"></script>

  <!-- Module Scripts -->
  <script src="includes/nav.js?v=20260309"></script>
  <script src="includes/dashboard.js?v=20260314"></script>
  <script src="includes/elbil.js?v=20260315"></script>
  <script src="includes/jordvarme.js?v=20260318"></script>

  <!-- Settings Modal -->
  <div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="settingsModalLabel">Indstillinger</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="settingsPriszone" class="form-label">Priszone</label>
            <select class="form-select" id="settingsPriszone">
              <option value="DK1">DK1 — Jylland og Fyn</option>
              <option value="DK2">DK2 — Sjælland og øer</option>
            </select>
          </div>
          <div class="mb-3">
            <label for="settingsNetselskab" class="form-label">Netselskab</label>
            <select class="form-select" id="settingsNetselskab">
              <option value="" data-gln="">Vælg netselskab…</option>
              <option value="Radius"  data-gln="5790000705689">Radius Elnet</option>
              <option value="Cerius"  data-gln="5790000705184">Cerius (tidl. SEAS-NVE)</option>
              <option value="N1"      data-gln="5790001089030">N1</option>
              <option value="Dinel"   data-gln="5790000610099">Dinel</option>
              <option value="Elektrus" data-gln="5790000836239">Elektrus</option>
              <option value="RAH"     data-gln="5790000610822">RAH Net</option>
              <option value="FLOW"    data-gln="5790000392551">FLOW Elnet</option>
            </select>
          </div>
          <div class="form-text">
            Beregner estimerede elomkostninger for varmepumpen inkl. spotpris,
            nettarif C, systemtarif, elafgift og moms (25%).
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" id="settingsSaveBtn">Gem</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer class="site-footer">
    <div class="footer-content">
      <p>SparkSpend • <a href="https://useful.dk" target="_blank" rel="noopener noreferrer">Powered by Useful</a></p>
      <div class="logo">
        <img src="https://auth.useful.dk/media/public/logo_2.svg" alt="Useful Logo" />
      </div>
    </div>
  </footer>
</body>
</html>
