(function () {
	'use strict';

	var COOKIE = 'wpat_attribution';
	var LEGACY_COOKIE = 'wpfv_attribution';
	var CARRIER = 'wpat_attribution';
	var classifier = window.WPATAttributionClassifier;
	var config = window.wpAttributionTracker || {};
	var DAYS = Number(config.cookieDays) || 90;
	if (!classifier) { return; }
	var ATTRIBUTE_KEYS = classifier.UTM_KEYS.concat(['normalized_source', 'utm_channel', 'attribution_method']);

	function cookieValue(name) {
		var escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
		var match = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
		if (!match) { return null; }
		try { return JSON.parse(decodeURIComponent(match[1])) || {}; } catch (error) { return null; }
	}

	function readAttribution() {
		return cookieValue(COOKIE) || cookieValue(LEGACY_COOKIE) || {};
	}

	function writeAttribution(value) {
		var expires = new Date(Date.now() + DAYS * 86400000).toUTCString();
		var secure = window.location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(value)) + '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
	}

	function resolveAttribution() {
		var data = readAttribution();
		var current = classifier.classify({
			search: window.location.search,
			referrer: document.referrer || '',
			currentHost: window.location.hostname,
			sources: config.sources || {}
		});
		if (!data.landing_url) {
			data.landing_url = window.location.href;
			data.landing_referrer = document.referrer || '';
		}
		data = classifier.merge(data, current, config.sources || {});
		writeAttribution(data);
		return data;
	}

	function payload(data) {
		return Object.assign({}, data, {
			submission_url: window.location.href,
			submission_title: document.title || ''
		});
	}

	function setInput(form, name, value, create) {
		var selector = 'input[name="' + name + '"]';
		var input = form.querySelector(selector);
		if (!input && create) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			form.appendChild(input);
		}
		if (input) { input.value = value == null ? '' : String(value); }
	}

	function fillConfiguredFields(form, data) {
		var last = data.last || {};
		var first = data.first || {};
		ATTRIBUTE_KEYS.forEach(function (key) {
			setInput(form, 'form_fields[' + key + ']', last[key], false);
			setInput(form, 'form_fields[first_' + key + ']', first[key], false);
			setInput(form, 'form_fields[last_' + key + ']', last[key], false);
		});
		classifier.CLICK_IDS.forEach(function (key) {
			setInput(form, 'form_fields[' + key + ']', last[key] || first[key], false);
		});
		['landing_url', 'landing_referrer'].forEach(function (key) {
			setInput(form, 'form_fields[' + key + ']', data[key], false);
		});
		setInput(form, 'form_fields[submission_url]', window.location.href, false);
		setInput(form, 'form_fields[submission_title]', document.title || '', false);
	}

	function inject(form, data) {
		if (!form || !form.querySelector) { return; }
		setInput(form, CARRIER, JSON.stringify(payload(data)), true);
		fillConfiguredFields(form, data);
	}

	function scan(data, root) {
		var scope = root && root.querySelectorAll ? root : document;
		if (scope.matches && scope.matches('form.elementor-form')) { inject(scope, data); }
		scope.querySelectorAll('form.elementor-form').forEach(function (form) { inject(form, data); });
	}

	function init() {
		var data = resolveAttribution();
		scan(data, document);

		document.addEventListener('submit', function (event) {
			if (event.target && event.target.matches && event.target.matches('form.elementor-form')) {
				inject(event.target, data);
			}
		}, true);

		if (window.MutationObserver && document.body) {
			new MutationObserver(function (mutations) {
				mutations.forEach(function (mutation) {
					mutation.addedNodes.forEach(function (node) {
						if (node.nodeType === 1) { scan(data, node); }
					});
				});
			}).observe(document.body, { childList: true, subtree: true });
		}

		window.dispatchEvent(new CustomEvent('wpat:ready', { detail: payload(data) }));
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
