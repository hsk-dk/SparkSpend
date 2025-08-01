<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ladninger</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  
  <!-- Flatpickr CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <!-- Flatpickr JS -->
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/da.js"></script>
  
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- jQuery (kræves til datepicker) -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <!-- Bootstrap Datepicker CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker.min.css">
  <!-- Bootstrap JS (kræves for datepicker) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Bootstrap Datepicker JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>
  
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    header {
      background-color: #0047AB;
      color: white;
      padding: 15px;
      display: flex;
      align-items: center;
    }
    header h1 {
      margin-left: 15px;
    }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background-color: #f4f4f4; }
    select, button { padding: 5px; }
    #summaryBox { border: 1px solid #ddd; padding: 10px; margin-bottom: 20px; }

    /* Modal styling */
    .modal {
        display: none;
        position: fixed;
        z-index: 1;
        padding-top: 100px; /* Juster højde for centreret modal */
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.5); /* Semi-transparent baggrund */
        transition: background-color 0.3s ease; /* Glidende overgang */
    }

    .modal-content {
        background-color: #ffffff;
        margin: 0 auto;
        padding: 30px;
        border-radius: 8px;
        width: 50%; /* Gør modal boksen lidt bredere */
        max-width: 600px; /* Maksimal bredde */
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
    }

    .modal-content h3 {
        font-size: 24px;
        margin-bottom: 20px;
        color: #0047AB; /* Hovedfarve for overskriften */
    }

    .modal-content input, .modal-content button {
        width: 100%;
        padding: 10px;
        margin: 10px 0;
        border-radius: 5px;
        border: 1px solid #ddd;
    }

    .modal-content input {
        font-size: 16px;
    }

    .modal-content button {
        background-color: #4caf50; /* Grøn knap */
        color: white;
        border: none;
        font-size: 16px;
        cursor: pointer;
    }

    .modal-content button:hover {
        background-color: #45a049; /* Hover-effekt */
    }

    .close {
        color: #aaa;
        font-size: 28px;
        font-weight: bold;
        position: absolute;
        top: 10px;
        right: 15px;
    }

    .close:hover, .close:focus {
        color: black;
        text-decoration: none;
        cursor: pointer;
    }

    /* Justering af knapper i modal */
    .modal-content .btn-group {
        display: flex;
        justify-content: space-between;
        margin-top: 20px;
    }

    .modal-content .btn-group button {
        flex: 1;
        margin: 0 5px;
    }

    /* Responsivt design */
    @media screen and (max-width: 768px) {
        .modal-content {
            width: 80%; /* Modal breder sig på mindre skærme */
        }
    }
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

    <!-- Filter dropdown for cars -->
    <label for="filter">Filtrer efter bil:</label>
    <select id="filter" onchange="fetchCharges()">
        <option value="all">Alle</option>
    </select>

    <label>
        <input type="checkbox" id="showZeroKwh" onchange="fetchCharges()"> Vis ladninger med 0 kWh
    </label>

    <!-- Datovælger -->
    <label for="dateRange">Vælg dato interval:</label>
    <input type="text" id="dateRange">

    <!-- Hurtigvalg af periode -->
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
                <th>Kategori</th>
                <th>Handling</th>
            </tr>
        </thead>
        <tbody id="chargeTableBody">
            <tr><td colspan="6">Indlæser data...</td></tr>
        </tbody>
    </table>

    <script>
        let vehicles = []; // Liste over biler
        let selectedTimeFilter = 'all'; // Standard filter (alle)

        document.addEventListener("DOMContentLoaded", () => {
            fetchVehicles(); // Hent biler først, derefter ladninger
			setupFlatpickr();
            setupEventListeners();
        });

        function fetchVehicles() {
            fetch('getVehicles.php')
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        console.error("Fejl ved hentning af biler:", data.error);
                        return;
                    }
                    vehicles = data;
                    populateFilter();
                    fetchCharges();
                })
                .catch(error => console.error('Fejl ved hentning af biler:', error));
        }

        function populateFilter() {
            const filterSelect = document.getElementById("filter");
            filterSelect.innerHTML = '<option value="all">Alle</option>';
            vehicles.forEach(vehicle => {
                let option = document.createElement("option");
                option.value = vehicle.id;
                option.textContent = vehicle.vehicleName;
                filterSelect.appendChild(option);
            });
        }

		function fetchCharges() {
			const filter = document.getElementById("filter").value;
			const showZeroKwh = document.getElementById("showZeroKwh").checked;
			const dateRange = document.getElementById("dateRange").value;

			const params = new URLSearchParams({
				filter: filter,
				showZeroKwh: showZeroKwh,
				dateRange: dateRange
			});


			fetch(`getCharges.php?${params.toString()}`)
			.then(response => response.json())
			.then(data => {
			const tableBody = document.getElementById('chargeTableBody');
            tableBody.innerHTML = '';

            let totals = {};
            let overallKwh = 0, overallCost = 0;

            data.forEach(charge => {
                const vehicle = vehicles.find(v => v.id == charge.vehicleId);
                const vehicleName = vehicle ? vehicle.vehicleName : "Ukendt";
                const kwh = parseFloat(charge.consumedKwh) || 0;
                const cost = parseFloat(charge.cost) || 0;

                if (!totals[vehicleName]) {
                    totals[vehicleName] = { totalKwh: 0, totalCost: 0 };
                }
                totals[vehicleName].totalKwh += kwh;
                totals[vehicleName].totalCost += cost;

                overallKwh += kwh;
                overallCost += cost;

                let row = document.createElement('tr');
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
                tableBody.appendChild(row);
            });

            updateSummary(totals, overallKwh, overallCost);
			})
			.catch(error => console.error('Fejl ved hentning af data:', error));
			}


        function updateCategory(chargeId) {
            const selectedVehicleId = document.getElementById(`vehicle_${chargeId}`).value;

            fetch('updateCategory.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: chargeId, vehicleId: selectedVehicleId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Ladning opdateret:', data.message);
                    fetchCharges();
                } else {
                    console.error('Fejl:', data.error);
                }
            })
            .catch(error => console.error('Fejl ved opdatering:', error));
        }

        function updateSummary(totals, overallKwh, overallCost) {
            let summaryHtml = "<h3>Opsummering</h3><table><tr><th>Bil</th><th>Samlet kWh</th><th>Samlet Pris</th><th>Gns. Pris/kWh</th></tr>";

            for (const vehicle in totals) {
                const avgPrice = totals[vehicle].totalCost / totals[vehicle].totalKwh || 0;
                summaryHtml += `<tr>
                    <td>${vehicle}</td>
                    <td>${totals[vehicle].totalKwh.toFixed(2)} kWh</td>
                    <td>${totals[vehicle].totalCost.toFixed(2)} kr</td>
                    <td>${avgPrice.toFixed(2)} kr/kWh</td>
                </tr>`;
            }

            const overallAvgPrice = overallCost / overallKwh || 0;
            summaryHtml += `<tr style="font-weight:bold;">
                <td>Total</td>
                <td>${overallKwh.toFixed(2)} kWh</td>
                <td>${overallCost.toFixed(2)} kr</td>
                <td>${overallAvgPrice.toFixed(2)} kr/kWh</td>
            </tr>`;

            summaryHtml += "</table>";
            document.getElementById("summaryBox").innerHTML = summaryHtml;
        }





	function setupFlatpickr() {
            flatpickr("#dateRange", {
                mode: "range",
                dateFormat: "Y-m-d",
                onClose: fetchCharges
            });
        }

 function setupEventListeners() {
            document.getElementById("filter").addEventListener("change", fetchCharges);
            document.getElementById("showZeroKwh").addEventListener("change", fetchCharges);
            document.getElementById("quickFilter").addEventListener("change", applyQuickFilter);
        }
		       function applyQuickFilter() {
            const quickFilter = document.getElementById("quickFilter").value;
            const today = new Date();
            let startDate, endDate;

            switch (quickFilter) {
                case "today":
                    startDate = endDate = today;
                    break;
                case "week":
                    startDate = new Date(today);
                    startDate.setDate(today.getDate() - today.getDay());
                    endDate = new Date(today);
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

            const dateRangeInput = document.getElementById("dateRange");
            if (startDate && endDate) {
                dateRangeInput._flatpickr.setDate([startDate, endDate]);
            } else {
                dateRangeInput._flatpickr.clear();
            }

            fetchCharges();
        }
    </script>
	
  <form id="exChargeForm">
    <div>
      <label for="vehicleId">Bil:</label>
      <select id="vehicleId" name="vehicleId" required>
        <option value="">Vælg bil</option>
        <?php
          // Connect to SQLite database
          $db = new SQLite3('charging_data.db');

          // Fetch vehicles
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
          // Fetch providers
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
    // Listen for the form submission
    document.getElementById('exChargeForm').addEventListener('submit', function(e) {
      e.preventDefault(); // Prevent the default form submission
      
      // Gather form data
      const formData = new FormData(this);
      
      // Send the data via AJAX (using fetch)
      fetch('createExCharge.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(result => {
        // Display a success or error message
        const msgBox = document.getElementById('exChargeMsg');
        if(result.success) {
          msgBox.innerHTML = '<div style="color:green;">Session oprettet succesfuldt!</div>';
          // Optionally reset the form
          document.getElementById('exChargeForm').reset();
          // You might also refresh your table of sessions here, if applicable
        } else {
          msgBox.innerHTML = `<div style="color:red;">Fejl: ${result.error}</div>`;
        }
      })
      .catch(error => console.error('Fejl ved oprettelse af session:', error));
    });
  </script></body>
</html>
