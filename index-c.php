<?php
require 'includes/configuration.php';
?>
<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ladninger</title>
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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
   <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
  <link rel="stylesheet" href="includes/style.css">
  
  <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

</head>
<body>
  <header>
    <div class="d-flex align-items-center">
      <!-- Embedded SVG logo -->
      <div style="width: 64px; height: 64px;">
		<img src="images/logo.svg" width="64" height="64" alt="SparkSpend Logo">
      </div>
      <h1>SparkSpend</h1>
    </div>
    <!-- Knap til oprettelse af ekstern ladning -->
    <button type="button" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#createExChargeModal">
      <span class="navbar-toggler-icon"></span>
    </button>
  </header>
<div id="summaryBox" class="row">
  <!-- Venstre kolonne: Opsummeringstabel -->
  <div class="col-md-6" id="summaryText">
    <!-- Her indsættes opsummeringstabellen -->
  </div>

  <!-- Højre kolonne: Tre små diagrammer side om side -->
  <div class="col-md-6">
    <div class="row">
      <div class="col-4">
        <div class="chart-container">
          <canvas id="pieChartCount"></canvas>
        </div>
      </div>
      <div class="col-4">
        <div class="chart-container">
          <canvas id="pieChartKwh"></canvas>
        </div>
      </div>
      <div class="col-4">
        <div class="chart-container">
          <canvas id="pieChartPrice"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>
<div>
<h2>Effektivitetsgrafer pr. bil</h2>
<div id="kmPerKwhChart"></div>
<div id="krPerKmChart"></div>

</div>
  <h2>Ladninger</h2>

  <!-- Filter og datovælger -->
  <label for="filter">Filtrer efter bil:</label>
  <select id="filter">
    <option value="all">Alle</option>
  </select>
  <label>
    <input type="checkbox" id="showZeroKwh"> Vis ladninger med 0 kWh
  </label>
  <label for="dateRange">Vælg dato interval:</label>
  <input type="text" id="dateRange">
  <select id="quickFilter">
    <option value="all">Alle</option>
    <option value="today">I dag</option>
    <option value="week">Denne uge</option>
    <option value="month">Denne måned</option>
    <option value="year">Dette år</option>
  </select>

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
                  $db = new SQLite3($dbPath);
                  $vehicles = $db->query('SELECT * FROM vehicles');
                  while ($row = $vehicles->fetchArray(SQLITE3_ASSOC)) {
                    echo '<option value="' . $row['id'] . '">' . $row['vehicleName'] . '</option>';
                  }
                ?>
              </select>
            </div>
            <div class="mb-3">
              <label for="providerId" class="form-label">Udbyder:</label>
              <select id="providerId" name="providerId" class="form-select" required>
                <option value="">Vælg udbyder</option>
                <?php
                  $providers = $db->query('SELECT id, providerName FROM provideres;');
                  while ($row = $providers->fetchArray(SQLITE3_ASSOC)) {
                    echo '<option value="' . $row['id'] . '">' . $row['providerName'] . '</option>';
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
              <input type="number" step="0.01" id="kwh" name="kwh" class="form-control" required>
            </div>
            <div class="mb-3">
              <label for="pris" class="form-label">Pris:</label>
              <input type="number" step="0.01" id="pris" name="pris" class="form-control" required>
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
                  $vehicles = $db->query('SELECT * FROM vehicles');
                  while ($row = $vehicles->fetchArray(SQLITE3_ASSOC)) {
                    echo '<option value="' . $row['id'] . '">' . $row['vehicleName'] . '</option>';
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
                  $vehicles = $db->query('SELECT * FROM vehicles');
                  while ($row = $vehicles->fetchArray(SQLITE3_ASSOC)) {
                    echo '<option value="' . $row['id'] . '">' . $row['vehicleName'] . '</option>';
                  }
                ?>
              </select>
            </div>
            <div class="mb-3">
              <label for="externalProviderId" class="form-label">Udbyder:</label>
              <select id="externalProviderId" name="providerId" class="form-select" required>
                <option value="">Vælg udbyder</option>
                <?php
                  $providers = $db->query('SELECT id, providerName FROM provideres;');
                  while ($row = $providers->fetchArray(SQLITE3_ASSOC)) {
                    echo '<option value="' . $row['id'] . '">' . $row['providerName'] . '</option>';
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
              <input type="number" step="0.01" id="externalKwh" name="kwh" class="form-control" required>
            </div>
            <div class="mb-3">
              <label for="externalPris" class="form-label">Pris:</label>
              <input type="number" step="0.01" id="externalPris" name="pris" class="form-control" required>
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
	
	document.addEventListener("DOMContentLoaded", () => {
		Chart.register(ChartDataLabels);
		setupFlatpickr();
		setupEventListeners();
		fetchVehicles();
		fetchProviders();

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

    // Centraliseret fejlhåndtering for fetch
    const handleFetchResponse = async response => {
      if (!response.ok) {
        throw new Error("Netværksrespons var ikke ok");
      }
      return await response.json();
    };

    // Hent biler
    const fetchVehicles = async () => {
      try {
        const response = await fetch('getVehicles.php');
        const data = await handleFetchResponse(response);
        if (data.error) {
          console.error("Fejl ved hentning af biler:", data.error);
          return;
        }
        vehicles = data;
        populateFilter();
        fetchCharges();
      } catch (error) {
        console.error('Fejl ved hentning af biler:', error);
      }
    };

// Hent ladeudbuder
    const fetchProviders = async () => {
      try {
        const response = await fetch('getProvideres.php');
        const data = await handleFetchResponse(response);
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
      filterEl.innerHTML = '<option value="all">Alle</option>';
      vehicles.forEach(vehicle => {
        const option = document.createElement("option");
        option.value = vehicle.id;
        option.textContent = vehicle.vehicleName;
        filterEl.appendChild(option);
      });
    };

    // Hent ladninger (samler data fra både charges og ext_charges)
    const fetchCharges = async () => {
      const params = new URLSearchParams({
        filter: filterEl.value,
        showZeroKwh: showZeroKwhEl.checked,
        dateRange: dateRangeEl.value
      });

      try {
        const response = await fetch(`getCharges.php?${params.toString()}`);
        const data = await handleFetchResponse(response);
        renderCharges(data);
      } catch (error) {
        console.error('Fejl ved hentning af data:', error);
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

    // Bestem ikon for pris pr. kWh
	let priceIcon = "";
	if (pricePerKwh > avgPricePerKwh) {
		priceIcon = '<i class="fas fa-arrow-up" style="color: red;"></i>'; // Rød pil op
	} else if (pricePerKwh < avgPricePerKwh) {
		priceIcon = '<i class="fas fa-arrow-down" style="color: green;"></i>'; // Grøn pil ned
	}

    // Formater datoen pænere
    const formattedDate = new Date(charge.datetime).toLocaleString("da-DK", {
      day: "2-digit", month: "2-digit", year: "numeric",
      hour: "2-digit", minute: "2-digit"
    });

    // Opret række
    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${formattedDate}</td>
      <td>${kwh.toFixed(2)} kWh</td>
      <td>${pris.toFixed(2)} kr</td>
      <td>${(pricePerKwh).toFixed(2)} kr/kWh ${priceIcon}</td>
      <td>${vehicleName}</td>
      <td>${icon}</td>
      <td><button class="btn btn-sm btn-secondary" onclick="editCharge('${charge.id}', '${source}', '${charge.datetime}', ${kwh}, ${pris}, ${charge.vehicleId}${charge.providerId ? (", " + charge.providerId) : ''})">
              Rediger
          </button></td>
    `;
    chargeTableBodyEl.appendChild(row);
  });

 // Opdater summary med tabel og opdater piechartet med de akkumulerede værdier
  updateSummary(totals, overallKwh, overallPris, internalCount, externalCount, internalKwh, externalKwh, internalPrice, externalPrice);

  // Aktiver tooltips
  new bootstrap.Tooltip(document.body, {
    selector: '[data-bs-toggle="tooltip"]'
  });
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
      legend: { position: 'bottom' },
      title: { display: true, text: "Antal ladninger" },
      datalabels: {
        formatter: (value, context) => {
          const dataArr = context.chart.data.datasets[0].data;
          const sum = dataArr.reduce((a, b) => a + b, 0);
          const percentage = sum ? ((value / sum) * 100).toFixed(1) + "%" : "0%";
          return percentage;
        },
        color: '#fff',
        font: { weight: 'bold' }
      }
    }
  };

  if (pieChartCount) {
    pieChartCount.data = dataCount;
    pieChartCount.options = optionsCount;
    pieChartCount.update();
  } else {
    pieChartCount = new Chart(ctxCount, {
      type: 'pie',
      data: dataCount,
      options: optionsCount
    });
  }

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
      legend: { position: 'bottom' },
      title: { display: true, text: "Ladet KWH" },
      datalabels: {
        formatter: (value, context) => {
          const dataArr = context.chart.data.datasets[0].data;
          const sum = dataArr.reduce((a, b) => a + b, 0);
          const percentage = sum ? ((value / sum) * 100).toFixed(1) + "%" : "0%";
          return percentage;
        },
        color: '#fff',
        font: { weight: 'bold' }
      }
    }
  };

  if (pieChartKwh) {
    pieChartKwh.data = dataKwh;
    pieChartKwh.options = optionsKwh;
    pieChartKwh.update();
  } else {
    pieChartKwh = new Chart(ctxKwh, {
      type: 'pie',
      data: dataKwh,
      options: optionsKwh
    });
  }

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
      legend: { position: 'bottom' },
      title: { display: true, text: "Brugte kr" },
      datalabels: {
        formatter: (value, context) => {
          const dataArr = context.chart.data.datasets[0].data;
          const sum = dataArr.reduce((a, b) => a + b, 0);
          const percentage = sum ? ((value / sum) * 100).toFixed(1) + "%" : "0%";
          return percentage;
        },
        color: '#fff',
        font: { weight: 'bold' }
      }
    }
  };

  if (pieChartPrice) {
    pieChartPrice.data = dataPrice;
    pieChartPrice.options = optionsPrice;
    pieChartPrice.update();
  } else {
    pieChartPrice = new Chart(ctxPrice, {
      type: 'pie',
      data: dataPrice,
      options: optionsPrice
    });
  }
}
    
    const setupFlatpickr = () => {
      flatpickr(dateRangeEl, {
        mode: "range",
        dateFormat: "Y-m-d",
        locale: "da",
        onClose: fetchCharges
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
    };

    // Funktion, der kaldes når "Rediger" klikkes.
    // For interne ladninger redigeres kun vehicleId, for eksterne alle felter.
    function editCharge(id, source, datetime, kwh, pris, vehicleId, providerId = null) {
      if (source === 'internal') {
        document.getElementById('internalChargeId').value = id;
        document.getElementById('internalVehicleId').value = vehicleId;
        new bootstrap.Modal(document.getElementById('internalChargeModal')).show();
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
        new bootstrap.Modal(document.getElementById('externalChargeModal')).show();
      }
    }

    // Håndter formularindsendelse for opdatering af interne ladninger
    const internalChargeForm = document.getElementById('internalChargeForm');
    const internalChargeMsgEl = document.getElementById('internalChargeMsg');
    internalChargeForm.addEventListener('submit', async e => {
      e.preventDefault();
      const id = document.getElementById('internalChargeId').value;
      const vehicleId = document.getElementById('internalVehicleId').value;
      try {
        const response = await fetch('updateInternalCharge.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, vehicleId })
        });
        const result = await handleFetchResponse(response);
        if (result.success) {
          internalChargeMsgEl.innerHTML = '<div class="text-success">Intern ladning opdateret!</div>';
          bootstrap.Modal.getInstance(document.getElementById('internalChargeModal')).hide();
          fetchCharges();
        } else {
          internalChargeMsgEl.innerHTML = `<div class="text-danger">Fejl: ${result.error}</div>`;
        }
      } catch (error) {
        console.error('Fejl ved opdatering af intern ladning:', error);
      }
    });

    // Håndter formularindsendelse for opdatering af eksterne ladninger
    const externalChargeForm = document.getElementById('externalChargeForm');
    const externalChargeMsgEl = document.getElementById('externalChargeMsg');
    externalChargeForm.addEventListener('submit', async e => {
      e.preventDefault();
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
        const result = await handleFetchResponse(response);
        if (result.success) {
          externalChargeMsgEl.innerHTML = '<div class="text-success">Ekstern ladning opdateret!</div>';
          bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
          fetchCharges();
        } else {
          externalChargeMsgEl.innerHTML = `<div class="text-danger">Fejl: ${result.error}</div>`;
        }
      } catch (error) {
        console.error('Fejl ved opdatering af ekstern ladning:', error);
      }
    });

    // Håndter formular til oprettelse af ekstern ladning
    const exChargeForm = document.getElementById('exChargeForm');
    const exChargeMsgEl = document.getElementById('exChargeMsg');
  document.getElementById("exChargeForm").addEventListener("submit", function(event) {
    event.preventDefault();
    const formData = new FormData(this);
    console.log("Formularens data:", Object.fromEntries(formData.entries())); // Tjek at chargeDateTime sendes korrekt
    fetch("createExCharge.php", {
        method: "POST",
        body: formData
    }).then(response => response.json())
      .then(data => {
          console.log("Server respons:", data); // Se om datoen er korrekt på serveren
      }).catch(error => console.error("Fejl:", error));
});

// Hent slet-knappen
const deleteExternalChargeButton = document.getElementById('deleteExternalChargeButton');

deleteExternalChargeButton.addEventListener('click', async () => {
  // Hent id'et på den eksterne ladning, der skal slettes
  const id = document.getElementById('externalChargeId').value;
  
  // Vis en bekræftelsesdialog
  const bekræft = confirm("Er du sikker på, at du vil slette denne ladning?");
  if (!bekræft) {
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
    
    const result = await response.json();
    if (result.success) {
      // Vis en succesbesked og opdater oversigten
      document.getElementById('externalChargeMsg').innerHTML = '<div class="text-success">Ladningen er slettet!</div>';
      // Luk modal-vinduet
      bootstrap.Modal.getInstance(document.getElementById('externalChargeModal')).hide();
      // Opdater oversigten med ladninger
      fetchCharges();
    } else {
      document.getElementById('externalChargeMsg').innerHTML = `<div class="text-danger">Fejl: ${result.error}</div>`;
    }
  } catch (error) {
    console.error('Fejl ved sletning af ekstern ladning:', error);
    document.getElementById('externalChargeMsg').innerHTML = '<div class="text-danger">Der opstod en fejl under sletningen.</div>';
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
    .then(response => response.json())
    .then(data => {
      renderEfficiencyCharts(data);
    })
    .catch(error => console.error('Fejl ved hentning af effektivitetsstatistik:', error));
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
    
    // Mapper data til format: [timestamp (i ms), værdi]
    const kmData = vehicleEntries.map(entry => [entry.timestamp * 1000, entry.kmPerKwh]);
    const krData = vehicleEntries.map(entry => [entry.timestamp * 1000, entry.krPerKm]);

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


// Udvid event listeners – f.eks. kald både fetchCharges og fetchEfficiencyStats når datoen ændres
function setupEventListeners() {
  filterEl.addEventListener("change", () => {
    fetchCharges();
    fetchEfficiencyStats();
  });
  showZeroKwhEl.addEventListener("change", () => {
    fetchCharges();
    fetchEfficiencyStats();
  });
  quickFilterEl.addEventListener("change", () => {
    applyQuickFilter();
    fetchEfficiencyStats();
  });
  // Hvis dateRange ændres (du har allerede en flatpickr-instans)
  dateRangeEl.addEventListener("change", () => {
    fetchCharges();
    fetchEfficiencyStats();
  });
  
}

// Kald fetchEfficiencyStats, når DOM'en er klar
document.addEventListener("DOMContentLoaded", () => {
  // ... dine eksisterende initialiseringer
  fetchEfficiencyStats();
});


  </script>
</body>
</html>
