document.addEventListener("DOMContentLoaded", function() {
    const heatpumpFilter = document.getElementById("heatpumpFilter");
    const ctx = document.getElementById("heatpumpChart").getContext("2d");
    let heatpumpChart;

    function fetchHeatpumpData() {
        const mode = heatpumpFilter.value;
        let url = `getHeatpumpData.php?mode=${mode}`;

        if (mode === "daily") {
            const month = new Date().toISOString().slice(0, 7); // YYYY-MM
            url += `&month=${month}`;
        } else if (mode === "monthly") {
            const year = new Date().getFullYear(); // YYYY
            url += `&year=${year}`;
        }

        fetch(url)
            .then(response => response.json())
            .then(data => {
                updateChart(data, mode);
            })
            .catch(error => console.error("Fejl ved hentning af data:", error));
    }

    function updateChart(data, mode) {
        if (heatpumpChart) {
            heatpumpChart.destroy(); // Fjern gammel graf
        }

        let labels = [];
        let values = [];

        if (mode === "daily") {
            labels = data.map(item => item.day);
            values = data.map(item => item.total_kwh);
        } else if (mode === "monthly") {
            labels = data.map(item => item.month);
            values = data.map(item => item.total_kwh);
        } else if (mode === "compare") {
            labels = data.map(item => `${item.year}-${item.month}`);
            values = data.map(item => item.total_kwh);
        }

        heatpumpChart = new Chart(ctx, {
            type: "bar",
            data: {
                labels: labels,
                datasets: [{
                    label: "Forbrug (kWh)",
                    data: values,
                    backgroundColor: "rgba(75, 192, 192, 0.6)"
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }

    heatpumpFilter.addEventListener("change", fetchHeatpumpData);
    fetchHeatpumpData(); // Hent data ved indlæsning
});
document.addEventListener("DOMContentLoaded", function() {
    const tabs = document.querySelectorAll(".tab-button");
    const contents = document.querySelectorAll(".tab-content");

    tabs.forEach(tab => {
        tab.addEventListener("click", function() {
            tabs.forEach(t => t.classList.remove("active"));
            contents.forEach(c => c.style.display = "none");

            this.classList.add("active");
            document.getElementById(this.dataset.target).style.display = "block";
        });
    });
});
