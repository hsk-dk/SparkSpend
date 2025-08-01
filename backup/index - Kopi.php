<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ladninger</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  
  <!-- Flatpickr CSS og JS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/da.js"></script>
  
  <!-- Bootstrap CSS og JS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
  
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    header {
      background-color: #0047AB;
      color: white;
      padding: 15px;
      display: flex;
      align-items: center;
    }
    header h1 { margin-left: 15px; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background-color: #f4f4f4; }
    select, button { padding: 5px; }
    #summaryBox { border: 1px solid #ddd; padding: 10px; margin-bottom: 20px; }
  </style>
</head>
<body>
  <header>
    <!-- Embedded SVG logo -->
    <div style="width: 64px; height: 64px;">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
        <circle cx="32" cy="32" r="30" fill="#0047AB" />
        <g fill="#4caf50">
          <rect x="12" y="40" width="4" height="12" />
          <rect x="20" y="35" width="4" height="17" />
          <rect x="28" y="30" width="4" height="22" />
          <rect x="36" y="38" width="4" height="14" />
        </g>
        <path 
          d="M28 12 L18 36 L32 36 L24 52 L44 28 L30 28 Z"
          fill="none"
          stroke="#FFD700"
          stroke-width="2"
          stroke-linejoin="round"
        />
      </svg>
    </div>
    <h1>SparkSpend</h1>
  </header>

  <div id="summaryBox"></div>
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
        <th>ID</th>
        <th>Oprettet</th>
        <th>Forbrugt kWh</th>
        <th>Pris</th>
        <th>Bil</th>
        <th>Handling</th>
      </tr>
    </thead>
    <tbody id="chargeTableBody">
      <tr><td colspan="6">Indlæser data...</td></tr>
    </tbody>
  </table>

  <form id="exChargeForm">
    <div>
      <label for="vehicleId">Bil:</label>
      <select id="vehicleId" name="vehicleId" required>
        <option value="">Vælg bil</option>
        <?php
          // Connect til SQLite-database og hent biler
          $db = new SQLite3('charging_data.db');
          $vehicles = $db->query('SELECT * FROM vehicles');
          while ($row = $vehicles->fetchArray(SQLITE3_ASSOC)) {
            echo '<option value="' . $row['id'] . '">' . $row['vehicleName'] . '</option>';
          }
        ?>
      </select>
    </div>
    <div>
      <label for="providerId">Udbyder:</label>
      <select id="providerId" name="providerId" required>
        <option value="">Vælg udbyder</option>
        <?php
          // Hent udbydere
          $providers = $db->query('SELECT id, providerName FROM provideres;');
          while ($row = $providers->fetchArray(SQLITE3_ASSOC)) {
            echo '<option value="' . $row['id'] . '">' . $row['providerName'] . '</option>';
          }
        ?>
      </select>
    </div>
    <button type="submit" class="btn btn-accent">Opret session</button>
  </form>

  <div id="exChargeMsg"></div>

  <script>
    // Cache DOM-elementer
    const filterEl = document.getElementById("filter");
    const showZeroKwhEl = document.getElementById("showZeroKwh");
    const dateRangeEl = document.getElementById("dateRange");
    const quickFilterEl = document.getElementById("quickFilter");
    const chargeTableBodyEl = document.getElementById("chargeTableBody");
    const summaryBoxEl = document.getElementById("summaryBox");
    
    let vehicles = []; // Liste over biler

    document.addEventListener("DOMContentLoaded", () => {
      setupFlatpickr();
      setupEventListeners();
      fetchVehicles();
    });

    // Centraliseret fejlhåndtering for fetch
    const handleFetchResponse = async response => {
      if (!response.ok) {
        throw new Error("Netværksrespons var ikke ok");
      }
      return await response.json();
    };

    // Hent biler via async/await
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

    const populateFilter = () => {
      filterEl.innerHTML = '<option value="all">Alle</option>';
      vehicles.forEach(vehicle => {
        const option = document.createElement("option");
        option.value = vehicle.id;
        option.textContent = vehicle.vehicleName;
        filterEl.appendChild(option);
      });
    };

    // Hent ladninger via async/await
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
      let overallKwh = 0, overallCost = 0;

      data.forEach(charge => {
        const vehicle = vehicles.find(v => v.id == charge.vehicleId);
        const vehicleName = vehicle ? vehicle.vehicleName : "Ukendt";
        const kwh = parseFloat(charge.consumedKwh) || 0;
        const cost = parseFloat(charge.cost) || 0;

        // Saml totaler per bil
        if (!totals[vehicleName]) {
          totals[vehicleName] = { totalKwh: 0, totalCost: 0 };
        }
        totals[vehicleName].totalKwh += kwh;
        totals[vehicleName].totalCost += cost;

        overallKwh += kwh;
        overallCost += cost;

        // Opret række vha. template literals
        const row = document.createElement('tr');
        row.innerHTML = `
          <td>${charge.id}</td>
          <td>${charge.createdAt}</td>
          <td>${kwh.toFixed(2)} kWh</td>
          <td>${cost.toFixed(2)} kr</td>
          <td>
            <select id="vehicle_${charge.id}">
              ${vehicles.map(v => 
                `<option value="${v.id}" ${charge.vehicleId == v.id ? "selected" : ""}>${v.vehicleName}</option>`
              ).join('')}
            </select>
          </td>
          <td>
            <button onclick="updateCategory('${charge.id}')">Gem</button>
          </td>`;
        chargeTableBodyEl.appendChild(row);
      });
      updateSummary(totals, overallKwh, overallCost);
    };

    // Opdater kategori med async/await
    const updateCategory = async chargeId => {
      const selectedVehicleId = document.getElementById(`vehicle_${chargeId}`).value;
      try {
        const response = await fetch('updateCategory.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: chargeId, vehicleId: selectedVehicleId })
        });
        const data = await handleFetchResponse(response);
        if (data.success) {
          console.log('Ladning opdateret:', data.message);
          fetchCharges();
        } else {
          console.error('Fejl:', data.error);
        }
      } catch (error) {
        console.error('Fejl ved opdatering:', error);
      }
    };

    const updateSummary = (totals, overallKwh, overallCost) => {
      let summaryHtml = `<h3>Opsummering</h3>
      <table>
        <tr>
          <th>Bil</th>
          <th>Samlet kWh</th>
          <th>Samlet Pris</th>
          <th>Gns. Pris/kWh</th>
        </tr>`;

      Object.keys(totals).forEach(vehicle => {
        const avgPrice = totals[vehicle].totalCost / totals[vehicle].totalKwh || 0;
        summaryHtml += `
        <tr>
          <td>${vehicle}</td>
          <td>${totals[vehicle].totalKwh.toFixed(2)} kWh</td>
          <td>${totals[vehicle].totalCost.toFixed(2)} kr</td>
          <td>${avgPrice.toFixed(2)} kr/kWh</td>
        </tr>`;
      });

      const overallAvgPrice = overallCost / overallKwh || 0;
      summaryHtml += `
        <tr style="font-weight:bold;">
          <td>Total</td>
          <td>${overallKwh.toFixed(2)} kWh</td>
          <td>${overallCost.toFixed(2)} kr</td>
          <td>${overallAvgPrice.toFixed(2)} kr/kWh</td>
        </tr>
      </table>`;
      summaryBoxEl.innerHTML = summaryHtml;
    };

    const setupFlatpickr = () => {
      flatpickr(dateRangeEl, {
        mode: "range",
        dateFormat: "Y-m-d",
        onClose: fetchCharges
      });
    };

    const setupEventListeners = () => {
      filterEl.addEventListener("change", fetchCharges);
      showZeroKwhEl.addEventListener("change", fetchCharges);
      quickFilterEl.addEventListener("change", applyQuickFilter);
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

    // Formular til oprettelse af session
    const exChargeForm = document.getElementById('exChargeForm');
    const exChargeMsgEl = document.getElementById('exChargeMsg');

    exChargeForm.addEventListener('submit', async e => {
      e.preventDefault();
      const formData = new FormData(exChargeForm);
      try {
        const response = await fetch('createExCharge.php', {
          method: 'POST',
          body: formData
        });
        const result = await handleFetchResponse(response);
        if (result.success) {
          exChargeMsgEl.innerHTML = '<div style="color:green;">Session oprettet succesfuldt!</div>';
          exChargeForm.reset();
        } else {
          exChargeMsgEl.innerHTML = `<div style="color:red;">Fejl: ${result.error}</div>`;
        }
      } catch (error) {
        console.error('Fejl ved oprettelse af session:', error);
      }
    });
  </script>
</body>
</html>
