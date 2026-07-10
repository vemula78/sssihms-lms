/* SSSIHMS LMS — Module B quiz-taking UI. Vanilla JS, no framework.
 * Renders one page with all questions, shuffled options as served by the API
 * (never the correct answers), an optional countdown timer, and a result
 * panel after submit. Depends on window.SSLMS from assets/js/sslms.js. */
(function () {
	'use strict';

	function el(tag, attrs, children) {
		var e = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			if (k === 'text') {
				e.textContent = attrs[k];
			} else {
				e.setAttribute(k, attrs[k]);
			}
		});
		(children || []).forEach(function (c) { e.appendChild(c); });
		return e;
	}

	function fmtClock(totalSeconds) {
		var sec = Math.max(0, totalSeconds | 0);
		var m = Math.floor(sec / 60);
		var s = sec % 60;
		return m + ':' + (s < 10 ? '0' : '') + s;
	}

	function renderQuestion(q, index) {
		var wrap = el('fieldset', {});
		wrap.appendChild(el('legend', { text: (index + 1) + '. ' + q.prompt }));
		var inputType = q.qtype === 'multi' ? 'checkbox' : 'radio';
		q.options.forEach(function (opt) {
			var input = el('input', { type: inputType, name: 'q_' + q.id, value: String(opt.id) });
			var label = el('label', { class: 'sslms-quiz-option' }, [input, el('span', { text: opt.text })]);
			wrap.appendChild(label);
		});
		return wrap;
	}

	function collectAnswers(form, questions) {
		var answers = {};
		questions.forEach(function (q) {
			var checked = form.querySelectorAll('input[name="q_' + q.id + '"]:checked');
			answers[q.id] = Array.prototype.map.call(checked, function (i) { return parseInt(i.value, 10); });
		});
		return answers;
	}

	function renderResult(body, result) {
		body.innerHTML = '';
		var panel = el('div', { class: 'sslms-quiz-result' });
		panel.appendChild(el('p', { text: 'Score: ' + result.score_pct + '%' }));
		panel.appendChild(el('span', {
			class: 'sslms-badge ' + (result.passed ? 'sslms-badge--ok' : 'sslms-badge--bad'),
			text: result.passed ? 'Passed' : 'Not passed'
		}));
		if (result.attempts_left !== null && result.attempts_left !== undefined) {
			panel.appendChild(el('p', { text: 'Attempts left: ' + result.attempts_left }));
		}
		if (result.late) {
			panel.appendChild(el('p', { class: 'sslms-alert sslms-alert--error', text: 'This attempt was submitted after the time limit and was scored as unanswered.' }));
		}
		body.appendChild(panel);
	}

	function startQuiz(launcher) {
		var quizId = launcher.getAttribute('data-quiz-id');
		var body = launcher.querySelector('.sslms-quiz-body');
		var btn = launcher.querySelector('.sslms-quiz-start-btn');
		btn.disabled = true;

		SSLMS.api('quizzes/' + quizId + '/start', { method: 'POST' }).then(function (data) {
			btn.hidden = true;
			body.hidden = false;
			body.innerHTML = '';

			var form = el('form', { class: 'sslms-quiz-form' });
			var timerEl = null;
			var interval = null;

			if (data.remaining_seconds !== null && data.remaining_seconds !== undefined) {
				timerEl = el('p', { class: 'sslms-quiz-timer', text: 'Time remaining: ' + fmtClock(data.remaining_seconds) });
				form.appendChild(timerEl);
			}

			data.questions.forEach(function (q, i) { form.appendChild(renderQuestion(q, i)); });

			var submitBtn = el('button', { type: 'submit', class: 'sslms-btn' });
			submitBtn.textContent = 'Submit answers';
			form.appendChild(submitBtn);
			body.appendChild(form);

			var submitted = false;
			function doSubmit() {
				if (submitted) { return; }
				submitted = true;
				if (interval) { clearInterval(interval); }
				submitBtn.disabled = true;
				var answers = collectAnswers(form, data.questions);
				SSLMS.api('attempts/' + data.attempt_id + '/submit', { method: 'POST', body: { answers: answers } })
					.then(function (result) { renderResult(body, result); })
					.catch(function (e) {
						SSLMS.notice(launcher, e.message, true);
						submitBtn.disabled = false;
						submitted = false;
					});
			}

			form.addEventListener('submit', function (ev) { ev.preventDefault(); doSubmit(); });

			if (data.remaining_seconds !== null && data.remaining_seconds !== undefined) {
				var remaining = data.remaining_seconds;
				interval = setInterval(function () {
					remaining -= 1;
					if (timerEl) { timerEl.textContent = 'Time remaining: ' + fmtClock(remaining); }
					if (remaining <= 0) { doSubmit(); }
				}, 1000);
			}
		}).catch(function (e) {
			btn.disabled = false;
			SSLMS.notice(launcher, e.message, true);
		});
	}

	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest && ev.target.closest('.sslms-quiz-start-btn');
		if (!btn) { return; }
		var launcher = btn.closest('.sslms-quiz-launcher');
		if (launcher) { startQuiz(launcher); }
	});
})();
