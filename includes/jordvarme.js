/**
 * SparkSpend Jordvarme Module
 *
 * Lazy-initialised via window.jordvarmeApp.init() called by SparkNav
 * on the first visit to the Jordvarme tab.
 */

const jordvarmeApp = (() => {
    let initialized = false;

    function init() {
        if (initialized) return;
        initialized = true;

        // State
        let currentMode = "daily";
        let currentMonth = new Date().toISOString().slice(0, 7); // YYYY-MM
        let currentYear = String(new Date().getFullYear());       // YYYY
        let heatpumpChart = null;

        // DOM refs
        const canvas = document.getElementById("heatpumpChart");
        const noDataEl = document.getElementById("heatpumpNoData");
        const errorEl = document.getElementById("heatpumpError");
        const periodNav = document.getElementById("heatpumpPeriodNav");
        const periodLabel = document.getElementById("heatpumpPeriodLabel");
        const prevBtn = document.getElementById("heatpumpPrev");
        const nextBtn = document.getElementById("heatpumpNext");
        const statsContent = document.getElementById("heatpumpStatsContent");
        const syncStatusEl = document.getElementById("heatpumpSyncStatus");

        if (!canvas || !prevBtn || !nextBtn || !periodNav || !statsContent) return;

        const ctx = canvas.getContext("2d");

        // Mode radio buttons
        document.querySelectorAll('input[name="heatpumpMode"]').forEach(radio => {
            radio.addEventListener("change", function () {
                currentMode = this.value;
                periodNav.style.display = currentMode === "compare" ? "none" : "";
                fetchAndRender();
            });
        });

        // Period navigation
        prevBtn.addEventListener("click", () => shiftPeriod(-1));
        nextBtn.addEventListener("click", () => shiftPeriod(1));

        function shiftPeriod(direction) {
            if (currentMode === "daily") {
                const [y, m] = currentMonth.split("-").map(Number);
                const d = new Date(y, m - 1 + direction, 1);
                currentMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
            } else if (currentMode === "monthly") {
                currentYear = String(Number(currentYear) + direction);
            }
            fetchAndRender();
        }

        function updatePeriodLabel() {
            if (currentMode === "daily") {
                const [y, m] = currentMonth.split("-").map(Number);
                const name = new Date(y, m - 1, 1).toLocaleString("da-DK", { month: "long", year: "numeric" });
                periodLabel.textContent = name.charAt(0).toUpperCase() + name.slice(1);
            } else if (currentMode === "monthly") {
                periodLabel.textContent = currentYear;
            } else {
                periodLabel.textContent = "";
            }
        }

        function buildUrl() {
            if (currentMode === "daily") return `getHeatpumpData.php?mode=daily&month=${currentMonth}`;
            if (currentMode === "monthly") return `getHeatpumpData.php?mode=monthly&year=${currentYear}`;
            return "getHeatpumpData.php?mode=compare";
        }

        function setChartVisible(visible) {
            canvas.style.display = visible ? "" : "none";
        }

        function fetchAndRender() {
            updatePeriodLabel();

            noDataEl.style.display = "none";
            errorEl.style.display = "none";
            setChartVisible(false);
            statsContent.innerHTML = "<p>Indlæser data...</p>";

            fetch(buildUrl())
                .then(r => {
                    if (!r.ok) throw new Error("HTTP " + r.status);
                    return r.json();
                })
                .then(data => {
                    if (!Array.isArray(data) || data.length === 0) {
                        noDataEl.style.display = "";
                        renderStats([]);
                        return;
                    }
                    renderChart(data);
                    renderStats(data);
                    setChartVisible(true);
                })
                .catch(() => {
                    errorEl.style.display = "";
                    statsContent.innerHTML = "<p class='text-muted'>Kunne ikke hente data.</p>";
                });
        }

        function renderChart(data) {
            if (heatpumpChart) {
                heatpumpChart.destroy();
                heatpumpChart = null;
            }

            let labels, values;

            if (currentMode === "daily") {
                labels = data.map(d => d.day.slice(8));
                values = data.map(d => parseFloat(d.total_kwh));
            } else if (currentMode === "monthly") {
                const monthNames = ["Jan", "Feb", "Mar", "Apr", "Maj", "Jun", "Jul", "Aug", "Sep", "Okt", "Nov", "Dec"];
                labels = data.map(d => monthNames[parseInt(d.month, 10) - 1]);
                values = data.map(d => parseFloat(d.total_kwh));
            } else {
                const monthNames = ["Jan", "Feb", "Mar", "Apr", "Maj", "Jun", "Jul", "Aug", "Sep", "Okt", "Nov", "Dec"];
                labels = data.map(d => `${monthNames[parseInt(d.month, 10) - 1]} ${d.year}`);
                values = data.map(d => parseFloat(d.total_kwh));
            }

            heatpumpChart = new Chart(ctx, {
                type: "bar",
                data: {
                    labels: labels,
                    datasets: [{
                        label: "Forbrug (kWh)",
                        data: values,
                        backgroundColor: "rgba(34, 197, 94, 0.65)",
                        borderColor: "rgba(34, 197, 94, 1)",
                        borderWidth: 1,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => `${ctx.parsed.y.toFixed(2)} kWh`
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: "kWh" }
                        }
                    }
                }
            });
        }

        function renderStats(data) {
            if (!data || data.length === 0) {
                statsContent.innerHTML = "<p class='text-muted'>Ingen data.</p>";
                return;
            }
            const values = data.map(d => parseFloat(d.total_kwh));
            const total = values.reduce((a, b) => a + b, 0);
            const avg = total / values.length;
            const max = Math.max(...values);
            const maxIndex = values.indexOf(max);

            let maxLabel = "";
            if (currentMode === "daily") {
                maxLabel = data[maxIndex].day.slice(8) + ". " + new Date(data[maxIndex].day).toLocaleString("da-DK", { month: "short" });
            } else if (currentMode === "monthly") {
                const monthNames = ["Jan", "Feb", "Mar", "Apr", "Maj", "Jun", "Jul", "Aug", "Sep", "Okt", "Nov", "Dec"];
                maxLabel = monthNames[parseInt(data[maxIndex].month, 10) - 1];
            } else {
                const monthNames = ["Jan", "Feb", "Mar", "Apr", "Maj", "Jun", "Jul", "Aug", "Sep", "Okt", "Nov", "Dec"];
                maxLabel = `${monthNames[parseInt(data[maxIndex].month, 10) - 1]} ${data[maxIndex].year}`;
            }

            statsContent.innerHTML = `
                <table class="table table-sm mb-0">
                    <tr><td>Total</td><td class="text-end fw-bold">${total.toFixed(1)} kWh</td></tr>
                    <tr><td>Gennemsnit</td><td class="text-end">${avg.toFixed(1)} kWh</td></tr>
                    <tr><td>Højeste</td><td class="text-end">${max.toFixed(1)} kWh (${maxLabel})</td></tr>
                    <tr><td>Perioder</td><td class="text-end">${data.length}</td></tr>
                </table>
            `;
        }

        function fetchSyncStatus() {
            fetch("getHeatpumpData.php?mode=sync_status")
                .then(r => r.json())
                .then(data => {
                    if (data && data.updated_at) {
                        const ts = new Date(data.updated_at.replace(" ", "T"));
                        syncStatusEl.textContent = "Sidst synkroniseret: " + ts.toLocaleString("da-DK");
                    }
                })
                .catch(() => {});
        }

        // Initial load
        periodNav.style.display = "";
        fetchAndRender();
        fetchSyncStatus();
    }

    return { init };
})();

window.jordvarmeApp = jordvarmeApp;
