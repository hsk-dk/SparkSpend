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
  <title>Ladninger</title>
  <link rel="icon" type="image/svg+xml" href="includes/favicon.svg">
  <script src="includes/jordvarme.js?v=20260305b"></script>
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
  <link rel="stylesheet" href="includes/style.css?v=20260305">

  <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
  <script src="includes/app.js"></script>

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
      <button class="header-tab-button active" data-target="charges-section">
        <i class="fas fa-battery-three-quarters"></i> Ladninger
      </button>
      <button class="header-tab-button" data-target="analytics-section">
        <i class="fas fa-chart-line"></i> Analyse
      </button>
      <button class="header-tab-button" data-target="settings-section">
        <i class="fas fa-cog"></i> Indstillinger
      </button>
    </nav>

    <!-- Header Right: Filter Button + Create Charge Button -->
    <div class="header-right">
      <button type="button" class="btn btn-secondary" id="filterToggleBtn" title="Åbn filtre">
        <i class="fas fa-sliders-h"></i>
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

<section id="charges-section" class="tab-section active">
  <h2>Ladninger</h2>

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
</section>

  <!-- ANALYTICS SECTION - Secondary Tab -->
  <section id="analytics-section" class="tab-section">
    <h2>Analyse og Statistik</h2>
    
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
      <div class="mb-3">
        <label class="form-check-label">Gruppering:</label>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="costGrouping" id="groupDay" value="day">
          <label class="form-check-label" for="groupDay">Pr. dag</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="costGrouping" id="groupWeek" value="week" checked>
          <label class="form-check-label" for="groupWeek">Pr. uge</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="costGrouping" id="groupMonth" value="month">
          <label class="form-check-label" for="groupMonth">Pr. måned</label>
        </div>
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

    <!-- Vehicle Comparison Card -->
    <div class="card p-4 mt-4">
      <h3>Bilsammenligning</h3>
      <div class="mb-3">
        <label class="form-check-label">Sortering:</label>
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

    <!-- Jordvarme Card -->
    <div class="card p-4 mt-4">
      <h3>Jordvarmeforbrug</h3>
      <div class="mb-3">
        <label class="form-check-label me-2">Vis:</label>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="heatpumpMode" id="heatpumpDaily" value="daily" checked>
          <label class="form-check-label" for="heatpumpDaily">Daglig</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="heatpumpMode" id="heatpumpMonthly" value="monthly">
          <label class="form-check-label" for="heatpumpMonthly">Månedlig</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="heatpumpMode" id="heatpumpCompare" value="compare">
          <label class="form-check-label" for="heatpumpCompare">Sammenligning</label>
        </div>
      </div>
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

  </section>

  <!-- SETTINGS SECTION - Tertiary Tab -->
  <section id="settings-section" class="tab-section">
    <h2>Indstillinger</h2>
  </section>
  <script>
    // Cache DOM-elementer
    const filterEl = document.getElementById("filter");
    const showZeroKwhEl = document.getElementById("showZeroKwh");
    const dateRangeEl = document.getElementById("dateRange");
    const quickFilterEl = document.getElementById("quickFilter");
    const chargeTableBodyEl = document.getElementById("chargeTableBody");
    const summaryBoxEl = document.getElementById("summaryBox");
    
    let vehicles = []; // Liste over biler
    let provideres = [];
	let pieChart = null;  // Referencen til vores Chart.js instans
	let pieChartCount, pieChartKwh, pieChartPrice;
	

    // ============================================================================
    // Tab Navigation System
    // ============================================================================
    
    // Handle tab switching
    function switchTab(tabName) {
      // Hide all sections
      document.querySelectorAll('.tab-section').forEach(section => {
        section.classList.remove('active');
      });
      
      // Remove active class from all tab buttons
      document.querySelectorAll('.header-tab-button').forEach(btn => {
        btn.classList.remove('active');
      });
      
      // Show selected section
      const section = document.getElementById(tabName);
      if (section) {
        section.classList.add('active');
      }
      
      // Activate corresponding tab button
      const activeBtn = document.querySelector(`[data-target="${tabName}"]`);
      if (activeBtn) {
        activeBtn.classList.add('active');
      }
    }
    
    // Setup tab button listeners
    document.querySelectorAll('.header-tab-button').forEach(button => {
      button.addEventListener('click', (e) => {
        e.preventDefault();
        const tabName = button.getAttribute('data-target');
        switchTab(tabName);
      });
    });

	document.addEventListener("DOMContentLoaded", () => {
		Chart.register(ChartDataLabels);
		setupFlatpickr();
		setupEventListeners();

		// Apply default filter (this month) - set date AFTER Flatpickr initialization
		setTimeout(() => {
			const today = new Date();
			const startDate = new Date(today.getFullYear(), today.getMonth(), 1);
			const endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
			dateRangeEl._flatpickr.setDate([startDate, endDate]);
			// Set quickFilter to "month" to match the displayed date range
			quickFilterEl.value = "month";
			// Trigger fetch after date is set
			fetchVehicles();
			fetchProviders();
		}, 50);

  // Initier Flatpickr for dato og klokkeslæt i oprettelsesmodal
		flatpickr(document.getElementById("chargeDateTime"), {
			dateFormat: "Y-m-d H:i", // Ændret format til at inkludere tid
			locale: "da",
			enableTime: true, // Aktiver tidspicking
			noCalendar: false, // Kalender skal stadig vises
			time_24hr: true // Brug 24-timers format
		});

  // Initier Flatpickr for dato og klokkeslæt i ekstern redigeringsmodal
		flatpickr(document.getElementById("externalChargeDateTime"), {
			dateFormat: "Y-m-d H:i", // Ændret format til at inkludere tid
			locale: "da",
			enableTime: true, // Aktiver tidspicking
			noCalendar: false, // Kalender skal stadig vises
			time_24hr: true // Brug 24-timers format
		});
	});

    // Hent biler
    const fetchVehicles = async () => {
      try {
        const response = await fetch('getVehicles.php');
        const data = await appUtils.handleFetchResponse(response);
        if (data.error) {
          console.error("Fejl ved hentning af biler:", data.error);
          return;
        }
        vehicles = data;
        populateFilter();
        fetchCharges();
        fetchEfficiencyStats();
        fetchCostAnalytics();
        fetchVehicleComparison();
      } catch (error) {
        console.error('Fejl ved hentning af biler:', error);
      }
    };

    const fetchProviders = async () => {
      try {
        const response = await fetch('getProvideres.php');
        const data = await appUtils.handleFetchResponse(response);
        if (data.error) {
          console.error("Fejl ved hentning af ladeudbydere:", data.error);
          return;
        }
        provideres = data;
      } catch (error) {
        console.error('Fejl ved hentning af biler:', error);
      }
    };
    const populateFilter = () => {
      // Keep the "Alle" option that's already in HTML
      // Remove any vehicle options that might already exist (from previous loads)
      const existingOptions = filterEl.querySelectorAll('option[data-vehicle="true"]');
      existingOptions.forEach(option => option.remove());

      // Add vehicle options
      vehicles.forEach(vehicle => {
        const option = document.createElement("option");
        option.value = vehicle.id;
        option.textContent = vehicle.vehicleName;
        option.setAttribute('data-vehicle', 'true');
        filterEl.appendChild(option);
      });

      // Ensure "Alle" is selected by default
      filterEl.value = "all";
    };

    // Hent ladninger (samler data fra både charges og ext_charges)
    const fetchCharges = async () => {
      const params = new URLSearchParams({
        filter: filterEl.value,
        showZeroKwh: showZeroKwhEl.checked,
        dateRange: dateRangeEl.value
      });

      appUtils.setLoadingState('chargeTableBody', true);
      try {
        const response = await fetch(`getCharges.php?${params.toString()}`);
        const data = await appUtils.handleFetchResponse(response);
        renderCharges(data);
      } catch (error) {
        console.error('Fejl ved hentning af data:', error);
        chargeTableBodyEl.innerHTML = `<tr><td colspan="7" class="text-center text-danger">Fejl ved indlæsning: ${error.message}</td></tr>`;
      }
    };

   const renderCharges = data => {
  chargeTableBodyEl.innerHTML = "";
  const totals = {};
  let overallKwh = 0, overallPris = 0;
   // akkumulatorer til piechart-data
  let internalCount = 0, externalCount = 0;
  let internalKwh = 0, externalKwh = 0;
  let internalPrice = 0, externalPrice = 0;
  let pricePerKwhArray = [];

  data.forEach(charge => {
    const vehicle = vehicles.find(v => v.id == charge.vehicleId);
    const vehicleName = vehicle ? vehicle.vehicleName : "Ukendt";
    const provider = provideres.find(p => p.id == charge.providerId);
    const providerName = provider ? provider.providerName : "Ukendt";
    const kwh = parseFloat(charge.kwh) || 0;
    const pris = parseFloat(charge.pris) || 0;
    const pricePerKwh = kwh > 0 ? pris / kwh : 0;
    pricePerKwhArray.push(pricePerKwh);
    const source = charge.source; // "internal" eller "external"
	 if (source === "internal") {
      internalCount++;
      internalKwh += kwh;
      internalPrice += pris;
    } else {
      externalCount++;
      externalKwh += kwh;
      externalPrice += pris;
    }
    // Saml totaler per bil
    if (!totals[vehicleName]) {
      totals[vehicleName] = { totalKwh: 0, totalPris: 0, internalPris: 0, externalPris: 0 };
    }
    totals[vehicleName].totalKwh += kwh;
    totals[vehicleName].totalPris += pris;
    
    // Tilføj til den specifikke kategori baseret på kilde
    if (source === "internal") {
      totals[vehicleName].internalPris += pris;
    } else {
      totals[vehicleName].externalPris += pris;
    }
    overallKwh += kwh;
    overallPris += pris;
  });

  // Beregn gennemsnitlig pris pr. kWh
  const avgPricePerKwh = overallKwh > 0 ? overallPris / overallKwh : 0;

  // Opret rækker
  data.forEach(charge => {
    const vehicle = vehicles.find(v => v.id == charge.vehicleId);
    const vehicleName = vehicle ? vehicle.vehicleName : "Ukendt";
    const provider = provideres.find(p => p.id == charge.providerId);
    const providerName = provider ? provider.providerName : "Ukendt";
    const kwh = parseFloat(charge.kwh) || 0;
    const pris = parseFloat(charge.pris) || 0;
    const pricePerKwh = kwh > 0 ? pris / kwh : 0;
    const source = charge.source; // "internal" eller "external"

    // Vælg ikon baseret på kilde (source)
    const icon = source === "internal" 
      ? '<span class="material-symbols-outlined" data-bs-toggle="tooltip" title="ID: ' + charge.id + '">electrical_services</span>' 
      : '<span class="material-symbols-outlined" data-bs-toggle="tooltip" title="' + providerName + '">ev_station</span>';

    // Bestem ikon for pris pr. kWh (med tekst, ikke kun farve)
	let priceIcon = "";
	let priceComparisonText = "";
	if (pricePerKwh > avgPricePerKwh) {
		priceIcon = '<i class="fas fa-arrow-up"></i>';
		priceComparisonText = "højere end gennemsnit";
	} else if (pricePerKwh < avgPricePerKwh) {
		priceIcon = '<i class="fas fa-arrow-down"></i>';
		priceComparisonText = "lavere end gennemsnit";
	}

    // Formater datoen pænere
    const formattedDate = new Date(charge.datetime).toLocaleString("da-DK", {
      day: "2-digit", month: "2-digit", year: "numeric",
      hour: "2-digit", minute: "2-digit"
    });

    // Opret række (moderne data-attributes, ingen inline onclick)
    const row = document.createElement('tr');
    row.setAttribute('data-charge-id', String(charge.id));
    row.setAttribute('data-source', source);
    row.setAttribute('data-datetime', charge.datetime);
    row.setAttribute('data-kwh', String(kwh));
    row.setAttribute('data-pris', String(pris));
    row.setAttribute('data-vehicle-id', String(charge.vehicleId));
    if (charge.providerId) {
      row.setAttribute('data-provider-id', String(charge.providerId));
    }

    row.innerHTML = `
      <td>${formattedDate}</td>
      <td>${kwh.toFixed(2)} kWh</td>
      <td>${pris.toFixed(2)} kr</td>
      <td aria-label="Pris pr. kWh: ${pricePerKwh.toFixed(3)} kr/kWh, ${priceComparisonText}">${priceIcon} ${(pricePerKwh).toFixed(2)} kr/kWh <small>${priceComparisonText}</small></td>
      <td>${vehicleName}</td>
      <td>${icon}</td>
      <td>
        <button class="btn btn-sm btn-secondary" data-action="edit">Rediger</button>
      </td>
    `;
    chargeTableBodyEl.appendChild(row);
  });

  // Opdater summary med tabel og opdater piechartet med de akkumulerede værdier
  updateSummary(totals, overallKwh, overallPris, internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice);

  // Initialiser tooltips for nye elementer (non-destructive approach)
  appUtils.initializeTooltips();
};


const updateSummary = (totals, overallKwh, overallPris, internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice) => {
  const summaryTextEl = document.getElementById("summaryText");
  let summaryHtml = `<h3>Opsummering</h3>
  <table class="table table-sm">
    <tr>
      <th>Bil</th>
      <th>Samlet kWh</th>
      <th>Hjemme</th>
      <th>Ude</th>
      <th>Samlet Pris</th>
      <th>Gns. Pris/kWh</th>
    </tr>`;

  Object.keys(totals).forEach(vehicle => {
    const avgPrice = totals[vehicle].totalKwh > 0 ? totals[vehicle].totalPris / totals[vehicle].totalKwh : 0;
    summaryHtml += `
    <tr>
      <td>${vehicle}</td>
      <td>${totals[vehicle].totalKwh.toFixed(2)} kWh</td>
      <td>${totals[vehicle].internalPris.toFixed(2)} kr</td>
      <td>${totals[vehicle].externalPris.toFixed(2)} kr</td>
      <td>${totals[vehicle].totalPris.toFixed(2)} kr</td>
      <td>${avgPrice.toFixed(2)} kr/kWh</td>
    </tr>`;
  });

  const overallAvgPrice = overallKwh > 0 ? overallPris / overallKwh : 0;
  summaryHtml += `
    <tr style="font-weight:bold;">
      <td>Total</td>
      <td>${overallKwh.toFixed(2)} kWh</td>
      <td>${internalPrice.toFixed(2)} kr</td>
      <td>${externalPrice.toFixed(2)} kr</td>
      <td>${overallPris.toFixed(2)} kr</td>
      <td>${overallAvgPrice.toFixed(2)} kr/kWh</td>
    </tr>
  </table>`;
  summaryTextEl.innerHTML = summaryHtml;

  // Opdater piechartet med de akkumulerede værdier
  updatePieCharts(internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice);
};

function updatePieCharts(internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice) {
  // Diagram 1: Fordeling af antal ladninger
  const ctxCount = document.getElementById('pieChartCount').getContext('2d');
  const dataCount = {
    labels: ['Interne', 'Eksterne'],
    datasets: [{
      data: [internalCount, externalCount],
      backgroundColor: ['#4e73df', '#e74a3b']
    }]
  };
  const optionsCount = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },  // Hidden - shared legend shown below
      title: { display: true, text: "Antal", font: { size: 12, weight: 'bold' } },
      datalabels: {
        formatter: (value, context) => {
          const dataArr = context.chart.data.datasets[0].data;
          const sum = dataArr.reduce((a, b) => a + b, 0);
          const percentage = sum ? ((value / sum) * 100).toFixed(0) + "%" : "0%";
          return percentage;
        },
        color: '#fff',
        font: { weight: 'bold', size: 11 }
      }
    }
  };

  if (pieChartCount) {
    pieChartCount.destroy();  // Fix: Always destroy before recreating
  }
  pieChartCount = new Chart(ctxCount, {
    type: 'pie',
    data: dataCount,
    options: optionsCount
  });

  // Diagram 2: Fordeling af ladet KWH
  const ctxKwh = document.getElementById('pieChartKwh').getContext('2d');
  const dataKwh = {
    labels: ['Interne', 'Eksterne'],
    datasets: [{
      data: [internalKwh, externalKwh],
      backgroundColor: ['#4e73df', '#e74a3b']
    }]
  };
  const optionsKwh = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },  // Hidden - shared legend shown below
      title: { display: true, text: "kWh", font: { size: 12, weight: 'bold' } },
      datalabels: {
        formatter: (value, context) => {
          const dataArr = context.chart.data.datasets[0].data;
          const sum = dataArr.reduce((a, b) => a + b, 0);
          const percentage = sum ? ((value / sum) * 100).toFixed(0) + "%" : "0%";
          return percentage;
        },
        color: '#fff',
        font: { weight: 'bold', size: 11 }
      }
    }
  };

  if (pieChartKwh) {
    pieChartKwh.destroy();  // Fix: Always destroy before recreating
  }
  pieChartKwh = new Chart(ctxKwh, {
    type: 'pie',
    data: dataKwh,
    options: optionsKwh
  });

  // Diagram 3: Fordeling af brugte kr
  const ctxPrice = document.getElementById('pieChartPrice').getContext('2d');
  const dataPrice = {
    labels: ['Interne', 'Eksterne'],
    datasets: [{
      data: [internalPrice, externalPrice],
      backgroundColor: ['#4e73df', '#e74a3b']
    }]
  };
  const optionsPrice = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },  // Hidden - shared legend shown below
      title: { display: true, text: "Pris", font: { size: 12, weight: 'bold' } },
      datalabels: {
        formatter: (value, context) => {
          const dataArr = context.chart.data.datasets[0].data;
          const sum = dataArr.reduce((a, b) => a + b, 0);
          const percentage = sum ? ((value / sum) * 100).toFixed(0) + "%" : "0%";
          return percentage;
        },
        color: '#fff',
        font: { weight: 'bold', size: 11 }
      }
    }
  };

  if (pieChartPrice) {
    pieChartPrice.destroy();  // Fix: Always destroy before recreating
  }
  pieChartPrice = new Chart(ctxPrice, {
    type: 'pie',
    data: dataPrice,
    options: optionsPrice
  });
}
    
    const setupFlatpickr = () => {
      flatpickr(dateRangeEl, {
        mode: "range",
        dateFormat: "Y-m-d",
        locale: "da",
        onClose: () => {
          fetchCharges();
          fetchEfficiencyStats();
          fetchCostAnalytics();
          fetchVehicleComparison();
        }
      });
    };

    const setupEventListeners = () => {
      // Event delegation for charge table edit buttons (modern approach)
      chargeTableBodyEl.addEventListener('click', (event) => {
        const editBtn = event.target.closest('[data-action="edit"]');
        if (!editBtn) return;

        const row = editBtn.closest('tr');
        const id = row.dataset.chargeId;
        const source = row.dataset.source;
        const datetime = row.dataset.datetime;
        const kwh = parseFloat(row.dataset.kwh);
        const pris = parseFloat(row.dataset.pris);
        const vehicleId = parseInt(row.dataset.vehicleId);
        const providerId = row.dataset.providerId ? parseInt(row.dataset.providerId) : null;

        editCharge(id, source, datetime, kwh, pris, vehicleId, providerId);
      });

      filterEl.addEventListener("change", () => {
        fetchCharges();
        fetchEfficiencyStats();
        fetchCostAnalytics();
        fetchVehicleComparison();
      });
      showZeroKwhEl.addEventListener("change", () => {
        fetchCharges();
        fetchEfficiencyStats();
      });
      quickFilterEl.addEventListener("change", applyQuickFilter);

      // Event listener for cost grouping radio buttons
      document.querySelectorAll('input[name="costGrouping"]').forEach(radio => {
        radio.addEventListener("change", () => {
          fetchCostAnalytics(); // Re-render omkostningsdiagram med ny gruppering
        });
      });

      // Add date range change listener for cost analytics
      dateRangeEl.addEventListener("change", () => {
        fetchCharges();
        fetchEfficiencyStats();
        fetchCostAnalytics();
        fetchVehicleComparison();
      });

      // Filter Drawer Toggle
      const filterDrawer = document.getElementById('filterDrawer');
      const filterBackdrop = document.getElementById('filterBackdrop');
      const filterToggleBtn = document.getElementById('filterToggleBtn');
      const filterCloseBtn = document.getElementById('filterCloseBtn');

      filterToggleBtn.addEventListener('click', () => {
        filterDrawer.classList.toggle('open');
        filterBackdrop.classList.toggle('visible');
      });

      filterCloseBtn.addEventListener('click', () => {
        filterDrawer.classList.remove('open');
        filterBackdrop.classList.remove('visible');
      });

      filterBackdrop.addEventListener('click', () => {
        filterDrawer.classList.remove('open');
        filterBackdrop.classList.remove('visible');
      });
    };

    const applyQuickFilter = () => {
      const quickFilter = quickFilterEl.value;
      const today = new Date();
      let startDate, endDate;

      switch (quickFilter) {
        case "today":
          startDate = endDate = today;
          break;
        case "week":
          startDate = new Date(today);
          startDate.setDate(today.getDate() - today.getDay());
          endDate = new Date(startDate);
          endDate.setDate(startDate.getDate() + 6);
          break;
		case "lmonth":
		  startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
          endDate = new Date(today.getFullYear(), today.getMonth(), 0);
          break;
        case "month":
          startDate = new Date(today.getFullYear(), today.getMonth(), 1);
          endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
          break;
        case "year":
          startDate = new Date(today.getFullYear(), 0, 1);
          endDate = new Date(today.getFullYear(), 11, 31);
          break;
        default:
          startDate = endDate = null;
      }

      if (startDate && endDate) {
        dateRangeEl._flatpickr.setDate([startDate, endDate]);
      } else {
        dateRangeEl._flatpickr.clear();
      }
      fetchCharges();
      fetchEfficiencyStats();
      fetchCostAnalytics();
    };

    // Funktion, der kaldes når "Rediger" klikkes.
    // For interne ladninger redigeres kun vehicleId, for eksterne alle felter.
    function editCharge(id, source, datetime, kwh, pris, vehicleId, providerId = null) {
      // Clean up any stale backdrop elements before opening to prevent them blocking the modal
      document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
      document.body.classList.remove('modal-open');
      document.body.style.overflow = '';
      document.body.style.paddingRight = '';

      if (source === 'internal') {
        document.getElementById('internalChargeId').value = id;
        document.getElementById('internalVehicleId').value = vehicleId;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('internalChargeModal')).show();
      } else if (source === 'external') {
        document.getElementById('externalChargeId').value = id;
        document.getElementById('externalVehicleId').value = vehicleId;
        document.getElementById('externalProviderId').value = providerId;
        // Sæt dato vha. flatpickr, hvis den er initieret
        const extDateInput = document.getElementById('externalChargeDateTime');
        if(extDateInput._flatpickr) {
          extDateInput._flatpickr.setDate(datetime);
        } else {
          extDateInput.value = datetime;
        }
        document.getElementById('externalKwh').value = kwh;
        document.getElementById('externalPris').value = pris;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('externalChargeModal')).show();
      }
    }

    // Håndter formularindsendelse for opdatering af interne ladninger
    const internalChargeForm = document.getElementById('internalChargeForm');
    const internalChargeMsgEl = document.getElementById('internalChargeMsg');
    internalChargeForm.addEventListener('submit', async e => {
      e.preventDefault();

      // Validate required fields
      if (!internalChargeForm.checkValidity()) {
        appUtils.showFormMessage('internalChargeMsg', 'Venligst udfyld alle påkrævede felter', 'danger');
        return;
      }

      const id = document.getElementById('internalChargeId').value;
      const vehicleId = document.getElementById('internalVehicleId').value;
      try {
        const response = await fetch('updateInternalCharge.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, vehicleId })
        });
        const result = await appUtils.handleFetchResponse(response);
        if (result.success) {
          appUtils.showFormMessage('internalChargeMsg', 'Intern ladning opdateret!', 'success');
          bootstrap.Modal.getInstance(document.getElementById('internalChargeModal')).hide();
          fetchCharges();
        } else {
          appUtils.showFormMessage('internalChargeMsg', result.error || 'Fejl ved opdatering', 'danger');
        }
      } catch (error) {
        console.error('Fejl ved opdatering af intern ladning:', error);
        appUtils.showFormMessage('internalChargeMsg', 'Der opstod en fejl: ' + error.message, 'danger');
      }
    });

    // Håndter formularindsendelse for opdatering af eksterne ladninger
    const externalChargeForm = document.getElementById('externalChargeForm');
    const externalChargeMsgEl = document.getElementById('externalChargeMsg');
    externalChargeForm.addEventListener('submit', async e => {
      e.preventDefault();

      // Validate required fields
      if (!externalChargeForm.checkValidity()) {
        appUtils.showFormMessage('externalChargeMsg', 'Venligst udfyld alle påkrævede felter', 'danger');
        return;
      }

      const id = document.getElementById('externalChargeId').value;
      const vehicleId = document.getElementById('externalVehicleId').value;
      const providerId = document.getElementById('externalProviderId').value;
      const datetime = document.getElementById('externalChargeDateTime').value;
      const kwh = document.getElementById('externalKwh').value;
      const pris = document.getElementById('externalPris').value;
      try {
        const response = await fetch('updateExtCharge.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, vehicleId, providerId, datetime, kwh, pris })
        });
        const result = await appUtils.handleFetchResponse(response);
        if (result.success) {
          appUtils.showFormMessage('externalChargeMsg', 'Ekstern ladning opdateret!', 'success');
          bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
          fetchCharges();
        } else {
          appUtils.showFormMessage('externalChargeMsg', result.error || 'Fejl ved opdatering', 'danger');
        }
      } catch (error) {
        console.error('Fejl ved opdatering af ekstern ladning:', error);
        appUtils.showFormMessage('externalChargeMsg', 'Der opstod en fejl: ' + error.message, 'danger');
      }
    });

    // Håndter formular til oprettelse af ekstern ladning
    const exChargeForm = document.getElementById('exChargeForm');
    const exChargeMsgEl = document.getElementById('exChargeMsg');
    exChargeForm.addEventListener('submit', async e => {
      e.preventDefault();

      // Validate required fields
      if (!exChargeForm.checkValidity()) {
        appUtils.showFormMessage('exChargeMsg', 'Venligst udfyld alle påkrævede felter', 'danger');
        return;
      }

      const formData = new FormData(exChargeForm);
      try {
        const response = await fetch('createExCharge.php', {
          method: 'POST',
          body: formData
        });

        // Better error handling
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();

        if (data.error) {
          appUtils.showFormMessage('exChargeMsg', data.error, 'danger');
        } else if (data.success) {
          appUtils.showFormMessage('exChargeMsg', 'Ekstern ladning oprettet!', 'success');
          exChargeForm.reset();
          bootstrap.Modal.getInstance(document.getElementById('createExChargeModal')).hide();
          fetchCharges();
          fetchVehicleComparison();
        } else {
          appUtils.showFormMessage('exChargeMsg', 'Fejl ved oprettelse af ladning', 'danger');
        }
      } catch (error) {
        console.error('Fejl ved oprettelse af extern ladning:', error);
        appUtils.showFormMessage('exChargeMsg', 'Der opstod en fejl: ' + error.message, 'danger');
      }
    });

// Hent slet-knappen
const deleteExternalChargeButton = document.getElementById('deleteExternalChargeButton');

deleteExternalChargeButton.addEventListener('click', async () => {
  // Hent id'et på den eksterne ladning, der skal slettes
  const id = document.getElementById('externalChargeId').value;

  // Vis en bekræftelsesdialog
  if (!confirm("Er du sikker på, at du vil slette denne ladning?")) {
    return; // Afbryd, hvis brugeren ikke bekræfter
  }

  try {
    // Send en POST-anmodning om at slette ladningen
    const response = await fetch('deleteExtCharge.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ id })
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const result = await response.json();
    if (result.success) {
      // Vis en succesbesked og opdater oversigten
      appUtils.showFormMessage('externalChargeMsg', 'Ladningen er slettet!', 'success');
      // Luk modal-vinduet
      bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
      // Opdater oversigten med ladninger
      fetchCharges();
    } else {
      appUtils.showFormMessage('externalChargeMsg', result.error || 'Fejl ved sletning', 'danger');
    }
  } catch (error) {
    console.error('Fejl ved sletning af ekstern ladning:', error);
    appUtils.showFormMessage('externalChargeMsg', 'Der opstod en fejl: ' + error.message, 'danger');
  }
});

// Funktion der henter effektivitetsstatistik for den valgte tidsperiode
function fetchEfficiencyStats() {
  // Brug samme parametre som fetchCharges for at matche filtrene
  const params = new URLSearchParams({
    filter: filterEl.value,
    showZeroKwh: showZeroKwhEl.checked,
    dateRange: dateRangeEl.value
  });

  fetch('getEfficiencyStats.php?' + params.toString())
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return response.json();
    })
    .then(data => {
      if (data.error) {
        throw new Error(data.error);
      }
      renderEfficiencyCharts(data);
    })
    .catch(error => {
      console.error('Fejl ved hentning af effektivitetsstatistik:', error);
      document.getElementById('kmPerKwhChart').innerHTML = `<div class="alert alert-danger">Fejl ved indlæsning: ${error.message}</div>`;
      document.getElementById('krPerKmChart').innerHTML = `<div class="alert alert-danger">Fejl ved indlæsning</div>`;
    });
}

// Globale variabler til ApexCharts-instancer (hvis du skal opdatere dem)
let kmPerKwhChartInstance;
let krPerKmChartInstance;

function renderEfficiencyCharts(data) {
  // Gruppér data pr. bil
  const groupedData = {};
  data.forEach(entry => {
    const vehicleId = entry.vehicleId;
    if (!groupedData[vehicleId]) {
      groupedData[vehicleId] = [];
    }
    groupedData[vehicleId].push(entry);
  });

  // Forbered serier til ApexCharts: Én serie per bil
  const kmPerKwhSeries = [];
  const krPerKmSeries = [];

  Object.keys(groupedData).forEach(vehicleId => {
    const vehicleEntries = groupedData[vehicleId];
    // Sortér data efter timestamp
    vehicleEntries.sort((a, b) => a.timestamp - b.timestamp);

    // Find bilnavn baseret på vehicleId
    const vehicle = vehicles.find(v => v.id == vehicleId);
    const vehicleName = vehicle ? vehicle.vehicleName : `Bil ${vehicleId}`;

    // Brug samlet gennemsnit (totalKmPerKwh, totalKrPerKm)
    const kmData = vehicleEntries.map(entry => [entry.timestamp * 1000, entry.totalKmPerKwh]);
    const krData = vehicleEntries.map(entry => [entry.timestamp * 1000, entry.totalKrPerKm]);

    kmPerKwhSeries.push({
      name: vehicleName,
      data: kmData
    });

    krPerKmSeries.push({
      name: vehicleName,
      data: krData
    });
  });

  // Konfigurer diagram for KM per kWh
  const optionsKmPerKwh = {
    chart: {
      type: 'line',
      height: 300
    },
    series: kmPerKwhSeries,
    xaxis: {
      type: 'datetime',
      title: {
        text: 'Dato'
      }
    },
    yaxis: {
      title: {
        text: 'KM per kWh'
      },
      min: 0
    },
    title: {
      text: 'KM per kWh pr. bil'
    },
    stroke: {
      curve: 'smooth'
    }
  };

  // Hvis der allerede findes et diagram, destruer det
  if (kmPerKwhChartInstance) {
    kmPerKwhChartInstance.destroy();
  }
  kmPerKwhChartInstance = new ApexCharts(document.querySelector("#kmPerKwhChart"), optionsKmPerKwh);
  kmPerKwhChartInstance.render();

  // Konfigurer diagram for Kr per KM
  const optionsKrPerKm = {
    chart: {
      type: 'line',
      height: 300
    },
    series: krPerKmSeries,
    xaxis: {
      type: 'datetime',
      title: {
        text: 'Dato'
      }
    },
    yaxis: {
      title: {
        text: 'Kr per KM'
      },
      min: 0
    },
    title: {
      text: 'Kr per KM pr. bil'
    },
    stroke: {
      curve: 'smooth'
    }
  };

  // Destruer evt. eksisterende diagram og opret nyt
  if (krPerKmChartInstance) {
    krPerKmChartInstance.destroy();
  }
  krPerKmChartInstance = new ApexCharts(document.querySelector("#krPerKmChart"), optionsKrPerKm);
  krPerKmChartInstance.render();
}

// Hjælpefunktion til at generere en tilfældig farve (kan bruges til yderligere styling, hvis nødvendigt)
function randomColor() {
  const r = Math.floor(Math.random() * 256);
  const g = Math.floor(Math.random() * 256);
  const b = Math.floor(Math.random() * 256);
  return `rgb(${r}, ${g}, ${b})`;
}

// Cost Analytics Functions
let costTrendChartInstance;

function fetchCostAnalytics() {
  const groupBy = document.querySelector('input[name="costGrouping"]:checked')?.value || 'week';
  const params = new URLSearchParams({
    filter: filterEl.value,
    dateRange: dateRangeEl.value,
    groupBy: groupBy,
    showZeroKwh: showZeroKwhEl.checked
  });

  fetch('getChargeAnalytics.php?' + params.toString())
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return response.json();
    })
    .then(data => {
      if (data.error) {
        throw new Error(data.error);
      }
      renderCostAnalytics(data);
    })
    .catch(error => {
      console.error('Fejl ved hentning af omkostningsdata:', error);
      document.getElementById('costTrendChart').innerHTML = `<div class="alert alert-danger">Fejl ved indlæsning: ${error.message}</div>`;
      document.getElementById('costStatsContent').innerHTML = `<div class="alert alert-danger">Fejl: ${error.message}</div>`;
    });
}

function renderCostAnalytics(data) {
  // Render cost trend chart
  renderCostTrendChart(data.trend.daily_totals);

  // Render cost statistics
  renderCostStatistics(data.statistics);
}

function renderCostTrendChart(dailyData) {
  // Prepare data for ApexCharts
  const dates = dailyData.map(d => d.date);
  const internalCosts = dailyData.map(d => parseFloat(d.internal_cost) || 0);
  const externalCosts = dailyData.map(d => parseFloat(d.external_cost) || 0);

  const options = {
    chart: {
      type: 'area',
      height: 400,
      stacked: true
    },
    series: [
      {
        name: 'Indre ladning (kr)',
        data: internalCosts
      },
      {
        name: 'Ekstern ladning (kr)',
        data: externalCosts
      }
    ],
    xaxis: {
      categories: dates
      // Removed type: 'datetime' - use categories instead for string date labels
    },
    yaxis: {
      title: {
        text: 'Omkostning (kr)'
      }
    },
    title: {
      text: 'Omkostningstendenser'
    },
    stroke: {
      curve: 'smooth'
    },
    tooltip: {
      y: {
        formatter: function(value) {
          return value.toFixed(2) + ' kr';
        }
      }
    }
  };

  if (costTrendChartInstance) {
    costTrendChartInstance.destroy();
  }
  costTrendChartInstance = new ApexCharts(document.querySelector("#costTrendChart"), options);
  costTrendChartInstance.render();
}

function renderCostStatistics(stats) {
  const statsBox = document.getElementById("costStatsContent");

  if (!stats || Object.keys(stats).length === 0) {
    statsBox.innerHTML = '<p>Ingen data tilgængelig</p>';
    return;
  }

  const minCost = parseFloat(stats.min_cost) || 0;
  const maxCost = parseFloat(stats.max_cost) || 0;
  const avgCost = parseFloat(stats.avg_cost) || 0;
  const totalCost = parseFloat(stats.total_cost) || 0;
  const chargeCount = parseInt(stats.charge_count) || 0;
  const totalKwh = parseFloat(stats.total_kwh) || 0;
  const avgCostPerKwh = parseFloat(stats.avg_cost_per_kwh) || 0;

  statsBox.innerHTML = `
    <table class="table table-sm">
      <tr>
        <td><strong>Ladninger:</strong></td>
        <td>${chargeCount}</td>
      </tr>
      <tr>
        <td><strong>I alt kWh:</strong></td>
        <td>${totalKwh.toFixed(2)}</td>
      </tr>
      <tr>
        <td><strong>I alt kr:</strong></td>
        <td>${totalCost.toFixed(2)}</td>
      </tr>
      <tr>
        <td><strong>Gennemsnit pr. ladning:</strong></td>
        <td>${avgCost.toFixed(2)} kr</td>
      </tr>
      <tr>
        <td><strong>Gennemsnit pr. kWh:</strong></td>
        <td>${avgCostPerKwh.toFixed(3)} kr/kWh</td>
      </tr>
      <tr>
        <td><strong>Min pr. ladning:</strong></td>
        <td>${minCost.toFixed(2)} kr</td>
      </tr>
      <tr>
        <td><strong>Max pr. ladning:</strong></td>
        <td>${maxCost.toFixed(2)} kr</td>
      </tr>
    </table>
  `;
}

// Vehicle Comparison Functions
function fetchVehicleComparison() {
  const params = new URLSearchParams({
    dateRange: dateRangeEl.value
  });

  fetch('getVehicleComparison.php?' + params.toString())
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return response.json();
    })
    .then(data => {
      if (data.error) {
        throw new Error(data.error);
      }
      renderVehicleComparison(data.vehicles);
    })
    .catch(error => {
      console.error('Fejl ved hentning af bilsammenligningsdata:', error);
      document.getElementById('vehicleComparisonTableBody').innerHTML =
        `<tr><td colspan="7" class="text-center text-danger">Fejl: ${error.message}</td></tr>`;
    });
}

function renderVehicleComparison(vehicles) {
  const tableBody = document.getElementById("vehicleComparisonTableBody");

  if (!vehicles || vehicles.length === 0) {
    tableBody.innerHTML = '<tr><td colspan="7" class="text-center">Ingen data tilgængelig</td></tr>';
    return;
  }

  tableBody.innerHTML = vehicles.map(vehicle => `
    <tr>
      <td><strong>${vehicle.vehicleName}</strong></td>
      <td>${vehicle.charge_count}</td>
      <td>${vehicle.total_kwh.toFixed(2)} kWh</td>
      <td>${vehicle.total_cost.toFixed(2)} kr</td>
      <td>${vehicle.avg_cost_per_kwh.toFixed(3)} kr/kWh</td>
      <td>${vehicle.internal_percentage.toFixed(1)}%</td>
      <td>${vehicle.external_percentage.toFixed(1)}%</td>
    </tr>
  `).join('');
}

function sortVehicleComparison(sortBy) {
  const params = new URLSearchParams({
    dateRange: dateRangeEl.value,
    sortBy: sortBy
  });

  fetch('getVehicleComparison.php?' + params.toString())
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return response.json();
    })
    .then(data => {
      if (data.error) {
        throw new Error(data.error);
      }
      renderVehicleComparison(data.vehicles);
    })
    .catch(error => {
      console.error('Fejl ved sortering af bilsammenligningsdata:', error);
      document.getElementById('vehicleComparisonTableBody').innerHTML =
        `<tr><td colspan="7" class="text-center text-danger">Fejl: ${error.message}</td></tr>`;
    });
}


  </script>

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
