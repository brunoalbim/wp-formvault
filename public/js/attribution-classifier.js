(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) { module.exports = api; }
	if (root) { root.WPFVAttributionClassifier = api; }
}(typeof window !== 'undefined' ? window : null, function () {
	'use strict';

	var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
	var CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'fbclid'];

	function normalize(value) {
		return String(value || '')
			.toLowerCase()
			.trim()
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '')
			.replace(/_+/g, '_');
	}

	function cleanHost(host) {
		return String(host || '').toLowerCase().replace(/^www\./, '').replace(/:\d+$/, '');
	}

	function sourceMatch(value, sources) {
		var candidate = normalize(value);
		var found = null;
		Object.keys(sources || {}).some(function (canonical) {
			var definition = sources[canonical] || {};
			var aliases = Array.isArray(definition.aliases) ? definition.aliases : [];
			return aliases.some(function (alias) {
				alias = normalize(alias);
				if (alias && (candidate === alias || candidate.indexOf(alias + '_') === 0)) {
					found = { source: canonical, family: definition.family || 'referral' };
					return true;
				}
				return false;
			});
		});
		return found;
	}

	function hostSource(host, sources) {
		var normalizedHost = cleanHost(host);
		var match = sourceMatch(normalizedHost, sources);
		if (match) { return match; }
		normalizedHost.split('.').some(function (label) {
			match = sourceMatch(label, sources);
			return !!match;
		});
		return match;
	}

	function parseReferrer(referrer, currentHost, sources) {
		if (!referrer) { return null; }
		try {
			var parsed = new URL(referrer);
			var host = cleanHost(parsed.hostname);
			if (!host || host === cleanHost(currentHost)) { return null; }
			var match = hostSource(host, sources);
			if (match) {
				return {
					source: match.source,
					family: match.family,
					host: host
				};
			}
			return { source: host, family: 'referral', host: host };
		} catch (e) {
			return null;
		}
	}

	function familyMedium(family) {
		if (family === 'search') { return 'organic'; }
		if (family === 'social') { return 'social'; }
		if (family === 'email') { return 'email'; }
		if (family === 'referral') { return 'referral'; }
		return 'unknown';
	}

	function channelFor(medium, source) {
		var value = normalize(medium);
		if (value === 'cpc' || value === 'ppc' || value === 'paidsearch' || value === 'paid_search') { return 'Paid Search'; }
		if (value === 'organic') { return 'Organic Search'; }
		if (['social', 'social_network', 'social_media', 'sm', 'paid_social', 'social_paid'].indexOf(value) >= 0) { return 'Social'; }
		if (value === 'referral') { return 'Referral'; }
		if (value === 'email') { return 'Email'; }
		if (['display', 'cpm', 'banner'].indexOf(value) >= 0) { return 'Display'; }
		if ((value === 'none' || value === '') && source === '(direct)') { return 'Direct'; }
		return 'Unknown';
	}

	function paramsFrom(search) {
		return new URLSearchParams(String(search || '').replace(/^\?/, ''));
	}

	function valuesFromParams(params, keys) {
		var values = {};
		keys.forEach(function (key) {
			var value = params.get(key);
			if (value) { values[key] = value; }
		});
		return values;
	}

	function clickInference(clickIds, referrer) {
		if (clickIds.gclid || clickIds.gbraid || clickIds.wbraid) { return { source: 'google', family: 'search', medium: 'cpc' }; }
		if (clickIds.msclkid) { return { source: 'bing', family: 'search', medium: 'cpc' }; }
		if (clickIds.ttclid) { return { source: 'tiktok', family: 'social', medium: 'cpc' }; }
		if (clickIds.fbclid) {
			if (referrer && referrer.family === 'social' && ['facebook', 'instagram'].indexOf(referrer.source) >= 0) {
				return { source: referrer.source, family: 'social', medium: 'social' };
			}
			return { source: 'meta', family: 'social', medium: 'social' };
		}
		return null;
	}

	function classify(input) {
		input = input || {};
		var sources = input.sources || {};
		var params = paramsFrom(input.search);
		var utms = valuesFromParams(params, UTM_KEYS);
		var clickIds = valuesFromParams(params, CLICK_IDS);
		var hasUtm = Object.keys(utms).length > 0;
		var click = clickInference(clickIds, parseReferrer(input.referrer, input.currentHost, sources));
		var referrer = parseReferrer(input.referrer, input.currentHost, sources);
		var result = {};
		var match;

		Object.keys(utms).forEach(function (key) { result[key] = utms[key]; });
		Object.keys(clickIds).forEach(function (key) { result[key] = clickIds[key]; });

		if (hasUtm) {
			if (!result.utm_source) {
				result.utm_source = click ? click.source : (referrer ? referrer.source : 'unknown');
			}
			match = sourceMatch(result.utm_source, sources);
			result.normalized_source = match ? match.source : (click && !utms.utm_source ? click.source : normalize(result.utm_source));
			if (!result.utm_medium) {
				result.utm_medium = click ? click.medium : familyMedium(match ? match.family : (referrer ? referrer.family : ''));
			}
			result.utm_channel = channelFor(result.utm_medium, result.utm_source);
			result.attribution_method = 'utm';
			result.non_direct = true;
			return result;
		}

		if (click) {
			result.utm_source = click.source;
			result.normalized_source = click.source;
			result.utm_medium = click.medium;
			result.utm_channel = channelFor(click.medium, click.source);
			result.attribution_method = 'click_id';
			result.non_direct = true;
			return result;
		}

		if (referrer) {
			result.utm_source = referrer.source;
			result.normalized_source = referrer.source;
			result.utm_medium = familyMedium(referrer.family);
			result.utm_channel = channelFor(result.utm_medium, result.utm_source);
			result.attribution_method = referrer.family === 'search' ? 'referrer_search' : (referrer.family === 'social' ? 'referrer_social' : 'referral');
			result.non_direct = true;
			return result;
		}

		result.utm_source = '(direct)';
		result.normalized_source = 'direct';
		result.utm_medium = '(none)';
		result.utm_channel = 'Direct';
		result.attribution_method = 'direct_no_referrer';
		result.non_direct = false;
		return result;
	}

	function enrichLegacy(campaign, sources) {
		if (!campaign || typeof campaign !== 'object') { return campaign; }
		var enriched = Object.assign({}, campaign);
		var match = sourceMatch(enriched.utm_source, sources || {});
		var click = clickInference(enriched, null);
		if (!enriched.utm_source && click) { enriched.utm_source = click.source; }
		if (!match && enriched.utm_source) { match = sourceMatch(enriched.utm_source, sources || {}); }
		if (!enriched.normalized_source) { enriched.normalized_source = match ? match.source : (click ? click.source : normalize(enriched.utm_source)); }
		if (!enriched.utm_medium) { enriched.utm_medium = click ? click.medium : familyMedium(match ? match.family : ''); }
		if (!enriched.utm_channel) { enriched.utm_channel = channelFor(enriched.utm_medium, enriched.utm_source); }
		if (!enriched.attribution_method) { enriched.attribution_method = 'legacy_utm'; }
		if (typeof enriched.non_direct !== 'boolean') { enriched.non_direct = enriched.utm_source !== '(direct)'; }
		return enriched;
	}

	function merge(data, current, sources) {
		data = data && typeof data === 'object' ? Object.assign({}, data) : {};
		data.first = enrichLegacy(data.first, sources);
		data.last = enrichLegacy(data.last, sources);
		if (!data.first) { data.first = current; }
		if (!data.last) { data.last = data.first || current; }
		if (current.non_direct) { data.last = current; }
		return data;
	}

	return {
		CLICK_IDS: CLICK_IDS,
		UTM_KEYS: UTM_KEYS,
		channelFor: channelFor,
		classify: classify,
		enrichLegacy: enrichLegacy,
		merge: merge,
		normalize: normalize,
		parseReferrer: parseReferrer,
		sourceMatch: sourceMatch
	};
}));
