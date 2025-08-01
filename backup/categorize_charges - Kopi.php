<!DOCTYPE html>
<html lang="da">
<head>
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

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ladninger</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f4f4f4; }
        select, button { padding: 5px; }
        #summaryBox { border: 1px solid #ddd; padding: 10px; margin-bottom: 20px; }
        /* Modal styling */
        .modal { display: none; position: fixed; z-index: 1; padding-top: 60px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgb(0,0,0); background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; }
        .close:hover, .close:focus { color: black; text-decoration: none; cursor: pointer; }
    </style>
</head>
<body>
    <h2>Ladninger</h2>

    <div id="summaryBox"></div>

    <!-- Filter dropdown for cars -->
    <label for="filter">Filtrer efter bil:</label>
    <select id="filter" onchange="fetchCharges()">
        <option value="all">Alle</option>
    </select>

    <label>
        <input type="checkbox" id="showZeroKwh" onchange="fetchCharges()"> Vis ladninger med 0 kWh
    </label>

    <!-- Filter button for time range -->
    <button id="filterTimeBtn" onclick="openTimeFilterModal()">Filtrer efter tid</button>

	<!-- Modal for Time Filter -->
	<div id="timeFilterModal" class="modal">
		<div class="modal-content">
			<span class="close" onclick="closeTimeFilterModal()">&times;</span>
			<h3>Vælg Tidsfilter</h3>

			<label for="dateRange">Vælg dato-interval:</label>
			<input type="text" id="dateRange" class="form-control">

			<div class="mt-2">
				<button class="btn btn-primary" onclick="setQuickRange('week')">Denne uge</button>
				<button class="btn btn-secondary" onclick="setQuickRange('month')">Denne måned</button>
				<button class="btn btn-success" onclick="setQuickRange('year')">Dette år</button>
			</div>

			<br>
			<button onclick="applyDateFilter()" class="btn btn-primary">Anvend filter</button>
		</div>
	</div>

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

    fetch('getCharges.php?filter=' + filter)
        .then(response => response.json())
        .then(data => {
            const tableBody = document.getElementById('chargeTableBody');
            tableBody.innerHTML = '';

            let totals = {};
            let overallKwh = 0, overallCost = 0;

            // Konverter udvalgt tidsfilter fra Flatpickr
            let startDate = null, endDate = null;
            if (selectedTimeFilter && selectedTimeFilter !== 'all') {
                let dates = selectedTimeFilter.split(" til ");
                if (dates.length === 2) {
                    startDate = new Date(dates[0] + "T00:00:00"); // Startdato
                    endDate = new Date(dates[1] + "T23:59:59");   // Slutdato
                }
            }

            // Filtrér data baseret på valgt tidsfilter
            let filteredData = data.filter(charge => {
                let chargeDate = new Date(charge.createdAt); // Konverter dato fra API'et

                if (startDate && endDate) {
                    return chargeDate >= startDate && chargeDate <= endDate;
                }
                return true; // Hvis intet filter er valgt, vis alt
            });

            filteredData.forEach(charge => {
                const vehicle = vehicles.find(v => v.id == charge.vehicleId);
                const vehicleName = vehicle ? vehicle.vehicleName : "Ukendt";
                const kwh = parseFloat(charge.consumedKwh) || 0;
                const cost = parseFloat(charge.cost) || 0;

                if (!showZeroKwh && kwh === 0) return;

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
                    </td>
                `;
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

        // Open and close time filter modal
        function openTimeFilterModal() {
            document.getElementById("timeFilterModal").style.display = "block";
        }

        function closeTimeFilterModal() {
            document.getElementById("timeFilterModal").style.display = "none";
        }

        // Apply the selected time filter
        function applyDateFilter() {
			const dateRange = document.getElementById("dateRange").value;

			if (dateRange) {
				selectedTimeFilter = dateRange; // Gem dato-intervallet
				fetchCharges(); // Opdater tabellen med filtrering
			}    
			closeTimeFilterModal();
		}


document.addEventListener("DOMContentLoaded", function () {
    flatpickr("#dateRange", {
        mode: "range",
        dateFormat: "Y-m-d",
        locale: "da"
    });
});

// Funktionen til at vælge en hurtig dato-range
function setQuickRange(type) {
    const today = new Date();
    let startDate, endDate;

    switch (type) {
        case 'week': // Denne uge (mandag - søndag)
            startDate = new Date(today.setDate(today.getDate() - today.getDay() + 1));
            endDate = new Date(today.setDate(today.getDate() + 6));
            break;
        case 'month': // Denne måned
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'year': // Dette år
            startDate = new Date(today.getFullYear(), 0, 1);
            endDate = new Date(today.getFullYear(), 11, 31);
            break;
    }

    flatpickr("#dateRange").setDate([startDate, endDate]);
}

// Funktion til at anvende dato-filteret
function applyDateFilter() {
    const dateRange = document.getElementById("dateRange").value;
    
    if (dateRange) {
        selectedTimeFilter = dateRange; // Gem datoen i dit filter
        fetchCharges(); // Opdater dataene
    }
    
    closeTimeFilterModal();
}

    </script>
</body>
</html>
