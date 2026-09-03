(function () {
	'use strict';

	var COOKIE = 'wpfv_attribution';
	var DAYS = 90;
	var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

	function readCookie() {
		var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
		if (!match) { return {}; }
		try { return JSON.parse(decodeURIComponent(match[1])) || {}; } catch (e) { return {}; }
	}

	function writeCookie(value) {
		var expires = new Date(Date.now() + DAYS * 86400000).toUTCString();
		document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(value)) + '; expires=' + expires + '; path=/; SameSite=Lax';
	}

	function currentCampaign() {
		var params = new URLSearchParams(window.location.search);
		var campaign = {};
		var found = false;
		UTM_KEYS.forEach(function (key) {
			if (params.get(key)) { campaign[key] = params.get(key); found = true; }
		});
		['gclid', 'fbclid'].forEach(function (key) {
			if (params.get(key)) { campaign[key] = params.get(key); found = true; }
		});
		return found ? campaign : null;
	}

	function resolveAttribution() {
		var data = readCookie();
		var campaign = currentCampaign();
		if (!data.landing_url) {
			data.landing_url = window.location.href;
			data.landing_referrer = document.referrer || '';
		}
		if (campaign) {
			if (!data.first) { data.first = campaign; }
			data.last = campaign;
		}
		writeCookie(data);
		return data;
	}

	function hidden(form, key, value) {
		var name = 'wpfv_attribution[' + key + ']';
		var input = form.querySelector('input[name="' + name + '"]');
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			form.appendChild(input);
		}
		input.value = value || '';
	}

	function plainHidden(form, name, value) {
		var input = form.querySelector('input[name="' + name + '"]');
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			form.appendChild(input);
		}
		input.value = value || '';
	}

	function recaptchaToken(form) {
		var formId = form.getAttribute('data-wpfv-form');
		var config = window.wpFormVault.recaptcha;
		var formConfig = config && config.forms && config.forms[formId];
		if (!formConfig) { return Promise.resolve(''); }
		if (!window.grecaptcha || !window.grecaptcha.enterprise) { return Promise.resolve(''); }

		return new Promise(function (resolve) {
			window.grecaptcha.enterprise.ready(function () {
				window.grecaptcha.enterprise.execute(config.siteKey, { action: formConfig.action })
					.then(resolve)
					.catch(function () { resolve(''); });
			});
		});
	}

	function inject(form, data) {
		UTM_KEYS.forEach(function (key) {
			hidden(form, 'first_' + key, data.first && data.first[key]);
			hidden(form, 'last_' + key, data.last && data.last[key]);
		});
		hidden(form, 'gclid', (data.last && data.last.gclid) || (data.first && data.first.gclid));
		hidden(form, 'fbclid', (data.last && data.last.fbclid) || (data.first && data.first.fbclid));
		hidden(form, 'landing_url', data.landing_url);
		hidden(form, 'landing_referrer', data.landing_referrer);
		hidden(form, 'submission_url', window.location.href);
		hidden(form, 'submission_title', document.title);
	}

	function scan(data, root) {
		(root || document).querySelectorAll('form[data-wpfv-form]').forEach(function (form) {
			inject(form, data);
			bindAjax(form, data);
		});
	}

	function noticeFor(form) {
		var notice = form.previousElementSibling;
		if (!notice || !notice.classList.contains('wpfv-form-notice')) {
			notice = document.createElement('div');
			notice.className = 'wpfv-form-notice';
			notice.setAttribute('role', 'status');
			notice.setAttribute('aria-live', 'polite');
			form.parentNode.insertBefore(notice, form);
		}
		return notice;
	}

	function showNotice(form, message, success) {
		var notice = noticeFor(form);
		notice.textContent = message;
		notice.classList.toggle('is-success', success);
		notice.classList.toggle('is-error', !success);
		notice.hidden = false;
		notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	function bindAjax(form, data) {
		if (form.dataset.wpfvAjaxBound === '1' || !window.fetch || !window.FormData || !window.wpFormVault) { return; }
		form.dataset.wpfvAjaxBound = '1';
		setupValidation(form);
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (form.dataset.wpfvSubmitting === '1') { return; }

			inject(form, data);
			form.dataset.wpfvSubmitting = '1';
			form.setAttribute('aria-busy', 'true');
			var button = form.querySelector('[type="submit"]');
			var originalLabel = button ? button.textContent : '';
			if (button) {
				button.disabled = true;
				button.textContent = window.wpFormVault.sendingLabel;
			}

			recaptchaToken(form).then(function (token) {
				plainHidden(form, 'wpfv_recaptcha_token', token);
				return fetch(window.wpFormVault.ajaxUrl, {
					method: 'POST',
					body: new FormData(form),
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' }
				});
			}).then(function (response) {
				return response.json().catch(function () { throw new Error('invalid_json'); });
			}).then(function (payload) {
				var responseData = payload && payload.data ? payload.data : {};
				if (!payload.success) {
					showNotice(form, responseData.message || window.wpFormVault.networkError, false);
					return;
				}
				showNotice(form, responseData.message, true);
				form.reset();
				plainHidden(form, 'wpfv_recaptcha_token', '');
				inject(form, data);
				form.dispatchEvent(new CustomEvent('wpfv:success', { detail: responseData }));
			}).catch(function () {
				showNotice(form, window.wpFormVault.networkError, false);
			}).finally(function () {
				form.dataset.wpfvSubmitting = '0';
				form.removeAttribute('aria-busy');
				if (button) {
					button.disabled = false;
					button.textContent = originalLabel;
				}
			});
		});
	}

	function errorFor(input, message) {
		var node = input.parentNode.querySelector('.wpfv-field-error');
		if (!node) { node = document.createElement('span'); node.className = 'wpfv-field-error'; input.parentNode.appendChild(node); }
		node.textContent = message || ''; node.hidden = !message; input.setAttribute('aria-invalid', message ? 'true' : 'false');
	}

	function emailBlock(email, cfg) {
		if (!email || email.indexOf('@') < 0) { return ''; }
		var domain = email.split('@').pop().toLowerCase();
		if ((cfg.email_block_domains || []).map(String).map(function(v){return v.toLowerCase();}).indexOf(domain) >= 0) { return cfg.email_msg_domains; }
		var suffixes = cfg.email_block_suffixes || []; for (var i=0;i<suffixes.length;i++) { if (suffixes[i] && domain.slice(-suffixes[i].length) === String(suffixes[i]).toLowerCase()) { return cfg.email_msg_suffixes; } }
		var words = cfg.email_block_words || []; for (var j=0;j<words.length;j++) { if (words[j] && domain.indexOf(String(words[j]).toLowerCase()) >= 0) { return cfg.email_msg_words; } }
		return '';
	}

	function phoneMask(value) { var d=value.replace(/\D/g,'').slice(0,11); if(d.length<=2)return d?'('+d:''; if(d.length<=6)return '('+d.slice(0,2)+') '+d.slice(2); if(d.length<=10)return '('+d.slice(0,2)+') '+d.slice(2,6)+'-'+d.slice(6); return '('+d.slice(0,2)+') '+d.slice(2,7)+'-'+d.slice(7); }

	function setupValidation(form) {
		var formId = form.getAttribute('data-wpfv-form'); var cfg = window.wpFormVault.validations && window.wpFormVault.validations[formId]; if (!cfg) return;
		if (cfg.phone_validation) { form.querySelectorAll('input[type="tel"]').forEach(function(input){ input.addEventListener('input',function(){input.value=phoneMask(input.value); errorFor(input,'');}); }); }
		form.addEventListener('submit', function(event){ var valid=true;
			if(cfg.phone_validation){form.querySelectorAll('input[type="tel"]').forEach(function(input){if(input.value && input.value.replace(/\D/g,'').length<10){errorFor(input,cfg.phone_message);valid=false;}else{errorFor(input,'');}});}
			if(cfg.email_block_enabled){form.querySelectorAll('input[type="email"]').forEach(function(input){var msg=emailBlock(input.value,cfg);errorFor(input,msg);if(msg)valid=false;});}
			if(!valid){event.preventDefault();event.stopImmediatePropagation();}
		}, true);
	}

	function init() {
		var data = resolveAttribution();
		scan(data);
		if (window.MutationObserver) {
			new MutationObserver(function (mutations) {
				mutations.forEach(function (mutation) {
					mutation.addedNodes.forEach(function (node) {
						if (node.nodeType === 1) {
							if (node.matches && node.matches('form[data-wpfv-form]')) { inject(node, data); bindAjax(node, data); }
							scan(data, node);
						}
					});
				});
			}).observe(document.body, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
