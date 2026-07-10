/* SSSIHMS LMS — minimal portal JS: REST helper + form wiring. No frameworks. */
(function () {
	'use strict';

	window.SSLMS = {
		/**
		 * Call a sslms/v1 REST route. api('quizzes/3/start', {method:'POST', body:{...}})
		 * Resolves with response JSON's data; rejects with Error(message).
		 */
		api: function (path, opts) {
			opts = opts || {};
			var init = {
				method: opts.method || 'GET',
				headers: { 'X-WP-Nonce': SSLMS_CFG.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin'
			};
			if (opts.body instanceof FormData) {
				delete init.headers['Content-Type'];
				init.body = opts.body;
			} else if (opts.body) {
				init.body = JSON.stringify(opts.body);
			}
			return fetch(SSLMS_CFG.root + '/' + path, init).then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (j) {
					if (!r.ok) { throw new Error(j.message || 'Request failed (' + r.status + ')'); }
					return j.data !== undefined ? j.data : j;
				});
			});
		},

		notice: function (el, msg, isError) {
			var box = document.createElement('div');
			box.className = 'sslms-alert' + (isError ? ' sslms-alert--error' : '');
			box.setAttribute('role', 'alert');
			box.textContent = msg;
			el.insertBefore(box, el.firstChild);
			setTimeout(function () { box.remove(); }, 6000);
		}
	};

	/*
	 * Generic wiring: any <form data-sslms-endpoint="path" data-sslms-method="POST">
	 * inside .sslms is submitted via SSLMS.api as JSON (or FormData when it has
	 * enctype=multipart/form-data), then the page reloads unless
	 * data-sslms-noreload is present.
	 */
	document.addEventListener('submit', function (ev) {
		var form = ev.target;
		if (!form.matches('.sslms form[data-sslms-endpoint]')) { return; }
		ev.preventDefault();
		var btn = form.querySelector('[type=submit]');
		if (btn) { btn.disabled = true; }
		var body;
		if ((form.enctype || '').indexOf('multipart') !== -1) {
			body = new FormData(form);
		} else {
			body = {};
			new FormData(form).forEach(function (v, k) {
				if (k.slice(-2) === '[]') {
					k = k.slice(0, -2);
					(body[k] = body[k] || []).push(v);
				} else { body[k] = v; }
			});
		}
		SSLMS.api(form.getAttribute('data-sslms-endpoint'), {
			method: form.getAttribute('data-sslms-method') || 'POST',
			body: body
		}).then(function () {
			if (form.hasAttribute('data-sslms-noreload')) {
				SSLMS.notice(form.closest('.sslms'), 'Saved.');
				if (btn) { btn.disabled = false; }
			} else {
				window.location.reload();
			}
		}).catch(function (e) {
			SSLMS.notice(form.closest('.sslms'), e.message, true);
			if (btn) { btn.disabled = false; }
		});
	});
})();
