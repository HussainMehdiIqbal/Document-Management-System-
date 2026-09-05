// ============================================================
// DHA DMS — Chart.js Initializations (charts.js)
// ============================================================

const DHA_GREEN  = '#1e4db7';
const DHA_DARK   = '#0f1f3d';
const DHA_LIGHT  = '#3b82f6';
const DHA_GOLD   = '#60a5fa';

const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark';

// Common grid color
const gridColor = () => isDark() ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
const textColor  = () => isDark() ? '#aaa' : '#555';

// ── Bar Chart: Daily Uploads ──────────────────────────────────
const barCtx = document.getElementById('barChart');
if (barCtx) {
  new Chart(barCtx, {
    type: 'bar',
    data: {
      labels: trendLabels,
      datasets: [{
        label: 'Documents Uploaded',
        data: trendData,
        backgroundColor: trendData.map((v, i) => i === trendData.length - 1 ? DHA_DARK : DHA_GREEN + 'cc'),
        borderRadius: 8,
        borderSkipped: false,
        hoverBackgroundColor: DHA_DARK,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0f1f3d',
          titleColor: '#fff',
          bodyColor: '#e0eaff',
          cornerRadius: 8,
          callbacks: {
            label: ctx => ` ${ctx.raw} document${ctx.raw !== 1 ? 's' : ''}`
          }
        }
      },
      scales: {
        x: {
          grid: { color: gridColor() },
          ticks: { color: textColor(), font: { family: 'Inter', size: 11 } }
        },
        y: {
          beginAtZero: true,
          grid: { color: gridColor() },
          ticks: { color: textColor(), stepSize: 1, font: { family: 'Inter', size: 11 } }
        }
      }
    }
  });
}

// ── Pie Chart: Status Distribution ───────────────────────────
const pieCtx = document.getElementById('pieChart');
if (pieCtx) {
  new Chart(pieCtx, {
    type: 'doughnut',
    data: {
      labels: ['Approved', 'Pending', 'Corrected'],
      datasets: [{
        data: [pieData.approved, pieData.pending, pieData.changed],
        backgroundColor: ['#2563eb', '#f59e0b', '#ef4444'],
        hoverBackgroundColor: ['#1d4ed8', '#d97706', '#dc2626'],
        borderWidth: 0,
        hoverOffset: 8,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '65%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            color: textColor(),
            font: { family: 'Inter', size: 11 },
            padding: 14,
            boxWidth: 12,
            borderRadius: 4,
          }
        },
        tooltip: {
          backgroundColor: '#0f1f3d',
          titleColor: '#fff',
          bodyColor: '#e0eaff',
          cornerRadius: 8,
        }
      }
    }
  });
}

// ── Line Chart: Monthly Trend ─────────────────────────────────
const lineCtx = document.getElementById('lineChart');
if (lineCtx) {
  new Chart(lineCtx, {
    type: 'line',
    data: {
      labels: monthLabels,
      datasets: [{
        label: 'Documents',
        data: monthData,
        borderColor: DHA_GREEN,
        backgroundColor: DHA_GREEN + '22',
        borderWidth: 2.5,
        pointBackgroundColor: DHA_DARK,
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        pointRadius: 5,
        pointHoverRadius: 8,
        fill: true,
        tension: 0.4,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0f1f3d',
          titleColor: '#fff',
          bodyColor: '#e0eaff',
          cornerRadius: 8,
        }
      },
      scales: {
        x: {
          grid: { color: gridColor() },
          ticks: { color: textColor(), font: { family: 'Inter', size: 10 }, maxRotation: 30 }
        },
        y: {
          beginAtZero: true,
          grid: { color: gridColor() },
          ticks: { color: textColor(), stepSize: 1, font: { family: 'Inter', size: 11 } }
        }
      }
    }
  });
}
