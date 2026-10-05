'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const classifier = require('../public/js/attribution-classifier.js');
const sources = JSON.parse(fs.readFileSync(path.join(__dirname, '../public/data/attribution-sources.json'), 'utf8'));

function classify(search, referrer = '', currentHost = 'cliente.test') {
	return classifier.classify({ search, referrer, currentHost, sources });
}

test('normalizes aliases without matching arbitrary substrings', () => {
	assert.equal(classifier.sourceMatch('ig', sources).source, 'instagram');
	assert.equal(classifier.sourceMatch('instagram_feed', sources).source, 'instagram');
	assert.equal(classifier.sourceMatch('instragram_stories', sources).source, 'instagram');
	assert.equal(classifier.sourceMatch('facebook-mobile-feed', sources).source, 'facebook');
	assert.equal(classifier.sourceMatch('digital', sources), null);
	const custom = { parceiro: { family: 'referral', aliases: ['portal parceiro'] } };
	assert.equal(classifier.sourceMatch('portal-parceiro-conteudo', custom).source, 'parceiro');
});

test('preserves explicit UTMs and exposes the canonical source', () => {
	const result = classify('?utm_source=ig&utm_medium=social&utm_content=link_in_bio&fbclid=PAZX');
	assert.equal(result.utm_source, 'ig');
	assert.equal(result.normalized_source, 'instagram');
	assert.equal(result.utm_medium, 'social');
	assert.equal(result.utm_channel, 'Social');
	assert.equal(result.utm_content, 'link_in_bio');
	assert.equal(result.fbclid, 'PAZX');
	assert.equal(result.attribution_method, 'utm');
});

test('uses RD-like channel rules and completes partial UTMs from aliases', () => {
	assert.deepEqual(
		Object.fromEntries(Object.entries(classify('?utm_source=instagram_feed&utm_medium=cpc')).filter(([key]) => ['utm_source', 'normalized_source', 'utm_medium', 'utm_channel'].includes(key))),
		{ utm_source: 'instagram_feed', normalized_source: 'instagram', utm_medium: 'cpc', utm_channel: 'Paid Search' }
	);
	assert.equal(classify('?utm_source=instragram').utm_medium, 'social');
	assert.equal(classify('?utm_source=facebook_mobile_feed').utm_channel, 'Social');
	assert.equal(classify('?utm_source=instagram&utm_medium=paid_social').utm_channel, 'Social');
	assert.equal(classify('?utm_source=google').utm_medium, 'organic');
	assert.equal(classify('?utm_source=fonte_desconhecida').utm_channel, 'Unknown');
});

test('infers search, social and referral traffic from the referrer', () => {
	const google = classify('', 'https://www.google.com/search?q=formulario');
	assert.equal(google.utm_source, 'google');
	assert.equal(google.utm_medium, 'organic');
	assert.equal(google.utm_channel, 'Organic Search');
	assert.equal(google.attribution_method, 'referrer_search');

	const bing = classify('', 'https://www.bing.com/search?q=formulario');
	assert.equal(bing.utm_source, 'bing');
	assert.equal(bing.utm_channel, 'Organic Search');

	const instagram = classify('', 'https://l.instagram.com/');
	assert.equal(instagram.utm_source, 'instagram');
	assert.equal(instagram.utm_channel, 'Social');
	assert.equal(instagram.attribution_method, 'referrer_social');

	const referral = classify('', 'https://parceiro.example/artigo');
	assert.equal(referral.utm_source, 'parceiro.example');
	assert.equal(referral.utm_medium, 'referral');
	assert.equal(referral.utm_channel, 'Referral');
});

test('infers paid click identifiers and handles fbclid conservatively', () => {
	assert.equal(classify('?gclid=1').utm_source, 'google');
	assert.equal(classify('?gbraid=1').utm_channel, 'Paid Search');
	assert.equal(classify('?wbraid=1').utm_channel, 'Paid Search');
	assert.equal(classify('?msclkid=1').utm_source, 'bing');
	assert.equal(classify('?ttclid=1').utm_source, 'tiktok');

	const meta = classify('?fbclid=1');
	assert.equal(meta.utm_source, 'meta');
	assert.equal(meta.utm_channel, 'Social');

	const instagram = classify('?fbclid=1', 'https://l.instagram.com/');
	assert.equal(instagram.utm_source, 'instagram');
	assert.equal(instagram.utm_medium, 'social');
});

test('records direct traffic and ignores internal or invalid referrers', () => {
	for (const referrer of ['', 'https://www.cliente.test/pagina', 'not a url']) {
		const result = classify('', referrer);
		assert.equal(result.utm_source, '(direct)');
		assert.equal(result.utm_medium, '(none)');
		assert.equal(result.utm_channel, 'Direct');
		assert.equal(result.attribution_method, 'direct_no_referrer');
	}
});

test('keeps the last non-direct attribution across direct returns', () => {
	const campaign = classify('?utm_source=ig&utm_medium=social');
	const firstVisit = classifier.merge({}, campaign, sources);
	const returned = classifier.merge(firstVisit, classify(''), sources);
	assert.equal(returned.last.utm_source, 'ig');
	assert.equal(returned.last.utm_channel, 'Social');

	const directFirst = classifier.merge({}, classify(''), sources);
	const laterOrganic = classifier.merge(directFirst, classify('', 'https://google.com/'), sources);
	assert.equal(laterOrganic.first.utm_source, '(direct)');
	assert.equal(laterOrganic.last.utm_source, 'google');
});

test('enriches legacy cookies without discarding their values', () => {
	const legacy = { first: { utm_source: 'instagram_feed' }, last: { gclid: 'old-click' } };
	const merged = classifier.merge(legacy, classify(''), sources);
	assert.equal(merged.first.utm_source, 'instagram_feed');
	assert.equal(merged.first.normalized_source, 'instagram');
	assert.equal(merged.first.utm_medium, 'social');
	assert.equal(merged.first.attribution_method, 'legacy_utm');
	assert.equal(merged.last.utm_source, 'google');
	assert.equal(merged.last.utm_channel, 'Paid Search');
});
