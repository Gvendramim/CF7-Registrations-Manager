/**
 * Renderiza os gráficos do Dashboard do plugin Music Club Registrations
 * utilizando Chart.js. Os dados são fornecidos pelo PHP através de
 * wp_localize_script (variável global MCRDashboardData).
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof window.Chart === 'undefined' || typeof window.MCRDashboardData === 'undefined' ) {
			return;
		}

		var data = window.MCRDashboardData;

		var timelineCanvas = document.getElementById( 'mcr-timeline-chart' );
		if ( timelineCanvas && data.timeline ) {
			new window.Chart( timelineCanvas.getContext( '2d' ), {
				type: 'line',
				data: {
					labels: data.timeline.labels,
					datasets: [
						{
							label: 'Registrations',
							data: data.timeline.values,
							borderColor: '#2271b1',
							backgroundColor: 'rgba(34, 113, 177, 0.12)',
							tension: 0.3,
							fill: true,
							pointRadius: 2,
						},
					],
				},
				options: {
					responsive: true,
					plugins: {
						legend: { display: false },
					},
					scales: {
						y: {
							beginAtZero: true,
							ticks: { precision: 0 },
						},
					},
				},
			} );
		}

		var statusCanvas = document.getElementById( 'mcr-status-chart' );
		if ( statusCanvas && data.statusChart ) {
			new window.Chart( statusCanvas.getContext( '2d' ), {
				type: 'doughnut',
				data: {
					labels: data.statusChart.labels,
					datasets: [
						{
							data: data.statusChart.values,
							backgroundColor: [ '#0a5dc2', '#9a6700', '#1a7f37', '#c62828' ],
						},
					],
				},
				options: {
					responsive: true,
					plugins: {
						legend: { position: 'bottom' },
					},
				},
			} );
		}
	} );
} )();
