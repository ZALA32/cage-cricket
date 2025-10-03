document.addEventListener('DOMContentLoaded', function () {
  // Rating Distribution (bar)
  const distEl = document.getElementById('distChart');
  if (distEl && window.rfDist && window.rfLabels) {
    const ctx = distEl.getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: window.rfLabels,
        datasets: [{
          label: 'Count',
          data: window.rfDist,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        plugins: { legend: { display: false } }
      }
    });
  }

  // Per Turf Average (horizontal bar)
  const perEl = document.getElementById('perTurfChart');
  if (perEl && window.rfPerTurf) {
    const labels = Object.keys(window.rfPerTurf);
    const values = labels.map(k => window.rfPerTurf[k].avg);
    const counts = labels.map(k => window.rfPerTurf[k].cnt);
    const ctx2 = perEl.getContext('2d');
    new Chart(ctx2, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Average Rating',
          data: values,
          borderWidth: 1
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        scales: {
          x: { suggestedMin: 0, suggestedMax: 5, beginAtZero: true, ticks: { stepSize: 1 } }
        },
        plugins: {
          tooltip: {
            callbacks: {
              afterLabel: (ctx) => ` (${counts[ctx.dataIndex]} review${counts[ctx.dataIndex]===1?'':'s'})`
            }
          },
          legend: { display: false }
        }
      }
    });
  }
});
