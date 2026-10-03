// assets/js/reports.js
// Initializes Chart.js charts on the reports/dashboard pages if present.
document.addEventListener("DOMContentLoaded", function () {
  const revenueCanvas = document.getElementById("revenueChart");
  if (revenueCanvas && window.revenueChartData) {
    new Chart(revenueCanvas, {
      type: "line",
      data: {
        labels: window.revenueChartData.labels,
        datasets: [
          {
            label: "Revenue",
            data: window.revenueChartData.values,
            borderColor: "#2563eb",
            backgroundColor: "rgba(37,99,235,0.1)",
            tension: 0.3,
            fill: true,
          },
        ],
      },
      options: { responsive: true, plugins: { legend: { display: false } } },
    });
  }
});
