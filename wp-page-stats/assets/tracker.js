/* First-party pageview collector. No dependencies. */
(function () {
  'use strict';
  var c = window.WPSConfig;
  if (!c) return;
  var sent = false, consent = !c.consentRequired, active = true, visitorId = '', navigation = '', converted = {};
  var key = c.cookieName, consentKey = key + '_consent';
  function cookie(name) {
    var prefix = name + '=';
    return document.cookie.split(';').map(function (s) { return s.trim(); }).filter(function (s) { return s.indexOf(prefix) === 0; }).map(function (s) { return s.slice(prefix.length); })[0] || '';
  }
  function save(name, value, age) {
    document.cookie = name + '=' + value + '; Path=/; Max-Age=' + age + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }
  function uuid() {
    if (!window.crypto || !window.crypto.getRandomValues) return '';
    var bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function blocked() { return c.respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true); }
  function visitor() {
    if (visitorId) return visitorId;
    var id = cookie(key);
    if (/^[a-f0-9]{32}$/.test(id)) { visitorId = id; return id; }
    id = uuid();
    if (id) save(key, id, 31536000);
    visitorId = id; return id;
  }
  var attributionKey = key + '_campaign', entryKey = key + '_google_entry', acquisition;
  function clearAttribution() {
    acquisition = null;
    try { sessionStorage.removeItem(attributionKey); sessionStorage.removeItem(entryKey); } catch (_) {}
  }
  function attribution(storageKey, fresh) {
    try {
      var now = Date.now();
      if (fresh) sessionStorage.setItem(storageKey, JSON.stringify({at: now, tags: fresh}));
      else {
        var saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        if (saved && saved.tags && saved.at <= now && now - saved.at < 1800000) return saved.tags;
        sessionStorage.removeItem(storageKey);
      }
    } catch (_) {}
    return fresh || {};
  }
  function acquisitionContext() {
    var tags = {}, params = new URLSearchParams(location.search || ''), referrer;
    ['utm_source','utm_medium','utm_campaign'].forEach(function (name) { tags[name] = (params.get(name) || '').slice(0,80); });
    var tagged = Boolean(tags.utm_source || tags.utm_medium || tags.utm_campaign);
    var fromAd = ['gclid','gbraid','wbraid'].some(function (name) { return (params.get(name) || '').trim() !== ''; });
    try {
      var url = new URL(document.referrer);
      if (url.protocol === 'http:' || url.protocol === 'https:') referrer = url;
    } catch (_) {}
    function normalize(host) { return (host || '').toLowerCase().replace(/^www\./, ''); }
    var external = referrer && normalize(referrer.hostname) !== normalize(location.hostname);
    // A new external landing replaces the channel. Internal pages inherit the same entry.
    var entry = (fromAd || tagged || external) ? {
      ad: fromAd, source: tags.utm_source, medium: tags.utm_medium,
      origin: external ? referrer.origin : '',
      search: Boolean(external && /^\/search\/?$/.test(referrer.pathname))
    } : null;
    if (entry || !referrer) {
      // Start both attribution records from the same landing.
      clearAttribution();
    }
    return {
      tags: attribution(attributionKey, tagged ? tags : null),
      entry: attribution(entryKey, entry),
      referrer: referrer ? referrer.origin : ''
    };
  }
  function sendEvent(type, goal) {
    var id = visitor();
    if (!id || !navigation) return;
    var context = acquisition || (acquisition = acquisitionContext());
    var tags = context.tags, entry = context.entry;
    var body = new URLSearchParams({action: 'wps_track', post_id: String(c.postId), path: c.path, title: c.title,
      signature: c.signature, visitor: id, event: navigation, event_type: type, goal: goal || '',
      referrer: context.referrer, consent: consent ? '1' : '0',
      entry_referrer: entry.origin || '', entry_search: entry.search ? '1' : '0', google_click_tag: entry.ad ? '1' : '0',
      entry_utm_source: entry.source || '', entry_utm_medium: entry.medium || '',
      utm_source: tags.utm_source || '', utm_medium: tags.utm_medium || '', utm_campaign: tags.utm_campaign || ''});
    function send(retry) {
      if (!active || !consent || blocked()) return;
      fetch(c.endpoint, {method: 'POST', credentials: 'same-origin', referrerPolicy: 'origin', body: body, keepalive: true})
        .then(function (r) { if (retry && r.status >= 500) setTimeout(function () { send(false); }, 1800); })
        .catch(function () { if (retry) setTimeout(function () { send(false); }, 1800); });
    }
    send(true);
  }
  function convert(id) {
    if (!sent || !consent || !active || blocked() || converted[id]) return;
    var goal = (c.goals || []).filter(function (g) { return g.id === id; })[0];
    if (!goal) return;
    function path(value) { try { return decodeURIComponent(value).replace(/\/+$/, ''); } catch (_) { return value.replace(/\/+$/, ''); } }
    if (goal.type === 'destination' && (goal.post_id ? String(goal.post_id) !== String(c.postId) : path(goal.path) !== path(c.path))) return;
    converted[id] = true; sendEvent('conversion', id);
  }
  function track() {
    if (sent || !active || !consent || blocked() || document.visibilityState === 'hidden' || document.prerendering) return;
    navigation = uuid();
    if (!visitor() || !navigation) return;
    acquisition = null;
    sent = true;
    sendEvent('pageview');
    (c.goals || []).filter(function (g) { return g.type === 'destination'; }).forEach(function (g) { convert(g.id); });
  }
  var stored = cookie(consentKey);
  if (stored === '0') consent = false;
  if (stored === '1') consent = true;
  window.WPPageStats = {
    consent: function (allowed) {
      consent = !!allowed;
      save(consentKey, consent ? '1' : '0', 31536000);
      if (!consent) { save(key, '', 0); visitorId = ''; clearAttribution(); }
      if (consent) track();
    },
    optOut: function () { this.consent(false); },
    convert: convert,
    forget: function () { active = false; consent = false; visitorId = ''; clearAttribution(); save(key, '', 0); save(consentKey, '0', 31536000); }
  };
  document.addEventListener('wps:consent', function (e) { window.WPPageStats.consent(e.detail === true); });
  document.addEventListener('wps:conversion', function (e) { if (typeof e.detail === 'string') convert(e.detail); });
  document.addEventListener('visibilitychange', track);
  document.addEventListener('prerenderingchange', track);
  window.addEventListener('pageshow', function (e) { if (e.persisted) { sent = false; converted = {}; track(); } });
  track();
}());
