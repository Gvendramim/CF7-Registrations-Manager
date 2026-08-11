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

		var monthlyCanvas = document.getElementById( 'mcr-monthly-chart' );
		if ( monthlyCanvas && data.monthly ) {
			new window.Chart( monthlyCanvas.getContext( '2d' ), {
				type: 'bar',
				data: {
					labels: data.monthly.labels,
					datasets: [
						{
							label: 'Registrations',
							data: data.monthly.values,
							backgroundColor: '#2271b1',
							borderRadius: 4,
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

		var classCanvas = document.getElementById( 'mcr-class-chart' );
		if ( classCanvas && data.byClass ) {
			new window.Chart( classCanvas.getContext( '2d' ), {
				type: 'bar',
				data: {
					labels: data.byClass.labels,
					datasets: [
						{
							label: 'Registrations',
							data: data.byClass.values,
							backgroundColor: '#9a6700',
							borderRadius: 4,
						},
					],
				},
				options: {
					indexAxis: 'y',
					responsive: true,
					plugins: {
						legend: { display: false },
					},
					scales: {
						x: {
							beginAtZero: true,
							ticks: { precision: 0 },
						},
					},
				},
			} );
		}

		var interestCanvas = document.getElementById( 'mcr-interest-chart' );
		if ( interestCanvas && data.byInterest ) {
			new window.Chart( interestCanvas.getContext( '2d' ), {
				type: 'bar',
				data: {
					labels: data.byInterest.labels,
					datasets: [
						{
							label: 'Registrations',
							data: data.byInterest.values,
							backgroundColor: '#1a7f37',
							borderRadius: 4,
						},
					],
				},
				options: {
					indexAxis: 'y',
					responsive: true,
					plugins: {
						legend: { display: false },
					},
					scales: {
						x: {
							beginAtZero: true,
							ticks: { precision: 0 },
						},
					},
				},
			} );
		}
	} );
} )();
