/* SSSIHMS LMS — Phase 3d dashboard self-refresh + TV/board mode. Vanilla JS,
 * built on the existing SSLMS.api() helper (assets/js/sslms.js). No
 * framework, no websockets: polls /sslms/v1/dashboard/kpis every 30-60s and
 * patches card values, Chart.js instances (window.SSLMS_CHARTS, populated by
 * the inline bootstrap in class-sslms-admin-dashboard.php) and the alert
 * banner in place. */
(function () {
	'use strict';

	if (typeof SSLMS_DASH_CFG === 'undefined' || typeof SSLMS === 'undefined') {
		return;
	}

	function fetchKpis(department) {
		var path = 'dashboard/kpis?department=' + encodeURIComponent(department || '');
		return SSLMS.api(path, { method: 'GET' });
	}

	function applyCards(cards) {
		(cards || []).forEach(function (card) {
			var el = document.querySelector('[data-kpi-value="' + card.key + '"]');
			if (!el) { return; }
			el.textContent = String(card.value);
			el.className = 'sslms-big' + (card.badge ? ' sslms-badge sslms-badge--' + card.badge : '');
		});
	}

	function applyCharts(charts) {
		if (!window.SSLMS_CHARTS || !charts) { return; }
		Object.keys(charts).forEach(function (id) {
			var chart = window.SSLMS_CHARTS[id];
			var cfg = charts[id];
			if (!chart || !cfg) { return; }
			chart.data.labels = cfg.labels;
			cfg.datasets.forEach(function (ds, i) {
				if (chart.data.datasets[i]) {
					chart.data.datasets[i].data = ds.data;
					chart.data.datasets[i].label = ds.label;
				} else {
					chart.data.datasets[i] = ds;
				}
			});
			chart.data.datasets.length = cfg.datasets.length;
			chart.update();
		});
	}

	function applyAlerts(alerts) {
		var box = document.getElementById('sslms-kpi-alerts');
		if (!box) { return; }
		box.textContent = '';
		if (!alerts || !alerts.length) { return; }
		var banner = document.createElement('div');
		banner.className = 'sslms-badge--bad';
		banner.style.display = 'block';
		banner.style.borderRadius = '8px';
		banner.style.padding = '12px 16px';
		banner.style.margin = '12px 0';
		banner.style.fontWeight = '700';
		var lead = document.createTextNode('KPI threshold alerts: ');
		banner.appendChild(lead);
		var parts = alerts.map(function (a) {
			var dir = a.direction === 'below' ? '<' : '>';
			return a.label + ': ' + round1(a.value) + ' ' + dir + ' ' + round1(a.threshold);
		});
		banner.appendChild(document.createTextNode(parts.join('   •   ')));
		box.appendChild(banner);
	}

	function round1(n) {
		return Math.round(n * 10) / 10;
	}

	function applyTvHeading(department) {
		var el = document.getElementById('sslms-tv-department');
		if (el) {
			el.textContent = department || SSLMS_DASH_CFG.allLabel || 'All departments';
		}
	}

	function refresh(department) {
		fetchKpis(department).then(function (data) {
			applyCards(data.cards);
			applyCharts(data.charts);
			applyAlerts(data.alerts);
			if (SSLMS_DASH_CFG.tv) {
				applyTvHeading(department);
			}
		}).catch(function () {
			/* silent — next poll retries; a transient failure shouldn't disrupt the board */
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (SSLMS_DASH_CFG.tv) {
			var rotation = [''].concat(SSLMS_DASH_CFG.departments || []);
			var idx = 0;
			applyTvHeading('');
			refresh('');
			if (rotation.length > 1) {
				setInterval(function () {
					idx = (idx + 1) % rotation.length;
					refresh(rotation[idx]);
				}, SSLMS_DASH_CFG.tvRotateMs || 15000);
			}
			// Still refresh the current station's own numbers between rotations.
			setInterval(function () { refresh(rotation[idx]); }, SSLMS_DASH_CFG.pollMs || 45000);
		} else {
			setInterval(function () { refresh(SSLMS_DASH_CFG.department); }, SSLMS_DASH_CFG.pollMs || 45000);
		}
	});
})();
