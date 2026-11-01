/* Smart Visitor Assistant – chat widget (vanilla JS, no dependencies) */
(function () {
	'use strict';
	var C = window.SVA_CONFIG;
	if (!C || document.getElementById('sva-root')) { return; }
	var S = C.strings || {};
	var KEY = 'sva_chat_v1';
	var state = { open: false, busy: false, history: [], session: '' };

	function store(get, val) {
		try {
			if (get) { return JSON.parse(window.sessionStorage.getItem(KEY) || 'null'); }
			window.sessionStorage.setItem(KEY, JSON.stringify(val));
		} catch (e) { return null; }
	}
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text != null) { e.textContent = text; }
		return e;
	}
	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function safeUrl(u) { return /^https?:\/\//i.test(u) ? u : ''; }
	/* Minimal, safe formatting: **bold**, [text](url), bare URLs, line breaks, bullet lines. */
	function format(text) {
		var h = esc(text);
		h = h.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function (m, t, u) {
			return '<a href="' + u + '" target="_blank" rel="noopener">' + t + '</a>';
		});
		h = h.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, function (m, pre, u) {
			return pre + '<a href="' + u + '" target="_blank" rel="noopener">' + u + '</a>';
		});
		h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		h = h.replace(/^\s*[-*]\s+/gm, '• ');
		return h.replace(/\n/g, '<br>');
	}

	/* ---------- build UI ---------- */
	var root = el('div', 'sva-root sva-pos-' + C.position);
	root.id = 'sva-root';
	root.setAttribute('dir', C.rtl ? 'rtl' : 'ltr');
	root.style.setProperty('--sva-color', C.color);

	var btn = el('button', 'sva-launcher');
	btn.type = 'button';
	btn.setAttribute('aria-label', S.open || 'Chat');
	btn.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path fill="currentColor" d="M12 3C6.5 3 2 6.9 2 11.7c0 2.4 1.1 4.6 3 6.2L4.3 21l3.6-1.8c1.3.4 2.7.7 4.1.7 5.5 0 10-3.9 10-8.7S17.5 3 12 3zm-4 9.9a1.2 1.2 0 110-2.4 1.2 1.2 0 010 2.4zm4 0a1.2 1.2 0 110-2.4 1.2 1.2 0 010 2.4zm4 0a1.2 1.2 0 110-2.4 1.2 1.2 0 010 2.4z"/></svg>';

	var panel = el('div', 'sva-panel');
	panel.setAttribute('role', 'dialog');
	panel.setAttribute('aria-label', C.name);

	var head = el('div', 'sva-head');
	var avatar = el('div', 'sva-avatar');
	avatar.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 2a2 2 0 012 2c0 .7-.4 1.4-1 1.7V7h5a3 3 0 013 3v8a3 3 0 01-3 3H6a3 3 0 01-3-3v-8a3 3 0 013-3h5V5.7A2 2 0 0112 2zM8.5 12a1.5 1.5 0 100 3 1.5 1.5 0 000-3zm7 0a1.5 1.5 0 100 3 1.5 1.5 0 000-3z"/></svg>';
	var titles = el('div', 'sva-titles');
	titles.appendChild(el('div', 'sva-name', C.name));
	var status = el('div', 'sva-status', S.online || '');
	titles.appendChild(status);
	var resetBtn = el('button', 'sva-icon-btn');
	resetBtn.type = 'button';
	resetBtn.title = S.reset || '';
	resetBtn.setAttribute('aria-label', S.reset || 'Reset');
	resetBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 5V2L7 6l5 4V7a5 5 0 11-5 5H5a7 7 0 107-7z"/></svg>';
	var closeBtn = el('button', 'sva-icon-btn');
	closeBtn.type = 'button';
	closeBtn.setAttribute('aria-label', S.close || 'Close');
	closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M18.3 5.7L12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3l6.3 6.3 6.3-6.3z"/></svg>';
	head.appendChild(avatar);
	head.appendChild(titles);
	head.appendChild(resetBtn);
	head.appendChild(closeBtn);

	var body = el('div', 'sva-body');
	body.setAttribute('aria-live', 'polite');
	var quick = el('div', 'sva-quick');
	var form = el('form', 'sva-form');
	var input = el('textarea', 'sva-input');
	input.rows = 1;
	input.maxLength = 500;
	input.placeholder = S.placeholder || '';
	input.setAttribute('aria-label', S.placeholder || 'Message');
	var send = el('button', 'sva-send');
	send.type = 'submit';
	send.setAttribute('aria-label', S.send || 'Send');
	send.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3.4 20.4l17.4-7.5c.8-.4.8-1.5 0-1.8L3.4 3.6c-.7-.3-1.4.2-1.4.9L2 9.1c0 .5.4.9.9 1L16 12 2.9 13.9c-.5.1-.9.5-.9 1l.1 4.6c0 .7.7 1.2 1.3.9z"/></svg>';
	form.appendChild(input);
	form.appendChild(send);

	panel.appendChild(head);
	panel.appendChild(body);
	panel.appendChild(quick);
	panel.appendChild(form);
	root.appendChild(panel);
	root.appendChild(btn);
	document.body.appendChild(root);

	/* ---------- rendering ---------- */
	function scroll() { body.scrollTop = body.scrollHeight; }

	function renderBot(r) {
		var wrap = el('div', 'sva-msg sva-bot');
		var bubble = el('div', 'sva-bubble');
		bubble.innerHTML = format(r.text || '');
		wrap.appendChild(bubble);

		if (r.links && r.links.length) {
			var lw = el('div', 'sva-links');
			r.links.forEach(function (l) {
				var u = safeUrl(l.url);
				if (!u) { return; }
				var a = el('a', 'sva-link', l.title || u);
				a.href = u;
				lw.appendChild(a);
			});
			wrap.appendChild(lw);
		}
		if (r.products && r.products.length) {
			var pw = el('div', 'sva-products');
			r.products.forEach(function (p) {
				var u = safeUrl(p.url);
				if (!u) { return; }
				var card = el('a', 'sva-card');
				card.href = u;
				if (p.image && safeUrl(p.image)) {
					var img = el('img');
					img.src = p.image;
					img.alt = p.title || '';
					img.loading = 'lazy';
					card.appendChild(img);
				}
				card.appendChild(el('div', 'sva-card-title', p.title));
				if (p.price) { card.appendChild(el('div', 'sva-card-price', p.price)); }
				card.appendChild(el('div', 'sva-card-stock ' + (p.in_stock ? 'is-in' : 'is-out'), p.stock || ''));
				pw.appendChild(card);
			});
			wrap.appendChild(pw);
		}
		body.appendChild(wrap);
		if (r.quick && r.quick.length) { renderQuick(r.quick); }
		scroll();
	}

	function renderUser(text) {
		var wrap = el('div', 'sva-msg sva-user');
		wrap.appendChild(el('div', 'sva-bubble', text));
		body.appendChild(wrap);
		scroll();
	}

	function renderQuick(list) {
		quick.innerHTML = '';
		(list || []).forEach(function (q) {
			var b = el('button', 'sva-chip', q.label);
			b.type = 'button';
			b.addEventListener('click', function () { ask(q.message || q.label); });
			quick.appendChild(b);
		});
	}

	var typing;
	function setBusy(on) {
		state.busy = on;
		send.disabled = on;
		if (on) {
			typing = el('div', 'sva-msg sva-bot sva-typing');
			var b = el('div', 'sva-bubble');
			b.innerHTML = '<span></span><span></span><span></span>';
			b.setAttribute('aria-label', S.typing || '');
			typing.appendChild(b);
			body.appendChild(typing);
			scroll();
		} else if (typing && typing.parentNode) {
			typing.parentNode.removeChild(typing);
		}
	}

	function save() {
		store(false, { history: state.history.slice(-30), session: state.session });
	}

	/* ---------- networking ---------- */
	function ask(text) {
		text = String(text || '').trim();
		if (!text || state.busy) { return; }
		quick.innerHTML = '';
		renderUser(text);
		var hist = state.history.slice(-6).map(function (m) {
			return { role: m.role, text: m.role === 'assistant' ? m.r.text : m.text };
		});
		state.history.push({ role: 'user', text: text });
		save();
		setBusy(true);
		fetch(C.rest, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify({ message: text, history: hist, session: state.session })
		}).then(function (res) {
			return res.json().catch(function () { return {}; });
		}).then(function (r) {
			setBusy(false);
			if (!r || typeof r.text !== 'string') { r = { text: S.error || 'Error' }; }
			renderBot(r);
			state.history.push({ role: 'assistant', r: { text: r.text, links: r.links || [], products: r.products || [] } });
			save();
		}).catch(function () {
			setBusy(false);
			renderBot({ text: S.error || 'Error' });
		});
	}

	function welcome() {
		renderBot({ text: C.welcome, quick: C.quick });
	}

	function restore() {
		var saved = store(true);
		state.session = (saved && saved.session) || (Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
		state.history = (saved && saved.history) || [];
		welcome();
		if (state.history.length) {
			quick.innerHTML = '';
			state.history.forEach(function (m) {
				if (m.role === 'user') { renderUser(m.text); } else { renderBot(m.r); }
			});
		}
	}

	function toggle(open) {
		state.open = open;
		root.classList.toggle('sva-open', open);
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open) { setTimeout(function () { input.focus(); scroll(); }, 60); }
	}

	btn.addEventListener('click', function () { toggle(!state.open); });
	closeBtn.addEventListener('click', function () { toggle(false); btn.focus(); });
	resetBtn.addEventListener('click', function () {
		state.history = [];
		body.innerHTML = '';
		save();
		welcome();
	});
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var t = input.value;
		input.value = '';
		input.style.height = '';
		ask(t);
	});
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			form.dispatchEvent(new Event('submit', { cancelable: true }));
		}
	});
	input.addEventListener('input', function () {
		input.style.height = 'auto';
		input.style.height = Math.min(input.scrollHeight, 110) + 'px';
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && state.open) { toggle(false); }
	});

	restore();
})();
