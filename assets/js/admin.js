/* Smart Visitor Assistant – admin */
(function () {
	'use strict';
	var A = window.SVA_ADMIN;
	if (!A) { return; }

	function post(data) {
		var fd = new FormData();
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		fd.append('nonce', A.nonce);
		return fetch(A.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	/* ----- scan with progress bar ----- */
	var btn = document.getElementById('sva-scan');
	if (btn) {
		var box = document.getElementById('sva-progress');
		var bar = box.querySelector('.sva-bar span');
		var txt = box.querySelector('.sva-progress-text');
		function show(pct, msg) { box.hidden = false; bar.style.width = pct + '%'; txt.textContent = msg; }
		function fail(e) { show(100, 'خطا: ' + (e && e.message ? e.message : e)); btn.disabled = false; }
		function batch() {
			post({ action: 'sva_scan', step: 'batch' }).then(function (r) {
				if (!r.success) { return fail(r.data); }
				var d = r.data, pct = d.total ? Math.round(d.pos / d.total * 85) : 85;
				show(pct, 'بررسی محتوا: ' + d.pos + ' از ' + d.total);
				if (d.status === 'running') { return batch(); }
				show(90, 'تحلیل منوها، صفحه اصلی، نوع سایت و اطلاعات تماس…');
				return post({ action: 'sva_scan', step: 'finalize' }).then(function (f) {
					if (!f.success) { return fail(f.data); }
					show(100, 'تحلیل کامل شد ✔');
					setTimeout(function () { window.location.reload(); }, 700);
				});
			}).catch(fail);
		}
		btn.addEventListener('click', function () {
			btn.disabled = true;
			show(2, 'در حال آماده‌سازی…');
			post({ action: 'sva_scan', step: 'start' }).then(function (r) {
				if (!r.success) { return fail(r.data); }
				batch();
			}).catch(fail);
		});
	}

	/* ----- test chat ----- */
	var tb = document.getElementById('sva-test-btn');
	if (tb) {
		var q = document.getElementById('sva-test-q');
		var out = document.getElementById('sva-test-out');
		function run() {
			var m = q.value.trim();
			if (!m) { return; }
			out.textContent = '…';
			fetch(A.rest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': A.wpnon },
				body: JSON.stringify({ message: m })
			}).then(function (r) { return r.json(); }).then(function (r) {
				out.innerHTML = '';
				var t = document.createElement('div');
				t.className = 'sva-test-text';
				t.textContent = r.text || JSON.stringify(r);
				out.appendChild(t);
				var meta = document.createElement('div');
				meta.className = 'sva-muted';
				meta.textContent = 'نیت: ' + (r.intent || '—') + ' | حالت: ' + (r.mode || '—');
				out.appendChild(meta);
				(r.links || []).forEach(function (l) {
					var a = document.createElement('a');
					a.href = l.url; a.target = '_blank'; a.className = 'sva-tag'; a.textContent = l.title;
					out.appendChild(a);
				});
				(r.products || []).forEach(function (p) {
					var a = document.createElement('a');
					a.href = p.url; a.target = '_blank'; a.className = 'sva-tag'; a.textContent = '🛒 ' + p.title + (p.price ? ' — ' + p.price : '');
					out.appendChild(a);
				});
			}).catch(function (e) { out.textContent = 'خطا: ' + e; });
		}
		tb.addEventListener('click', run);
		q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
	}

	/* ----- AI connection test ----- */
	var ai = document.getElementById('sva-test-ai');
	if (ai) {
		ai.addEventListener('click', function () {
			var o = document.getElementById('sva-test-ai-out');
			o.textContent = '…';
			post({ action: 'sva_test_ai' }).then(function (r) {
				o.textContent = r.success ? '✔ ' + r.data : '✖ ' + r.data;
			}).catch(function (e) { o.textContent = '✖ ' + e; });
		});
	}

	/* ----- show API base only for OpenAI-compatible ----- */
	var prov = document.getElementById('sva-provider');
	if (prov) {
		var sync = function () {
			document.querySelectorAll('.sva-openai-only').forEach(function (r) { r.style.display = prov.value === 'openai' ? '' : 'none'; });
		};
		prov.addEventListener('change', sync);
		sync();
	}
})();
