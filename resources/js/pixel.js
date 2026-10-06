import FingerprintJS from '@fingerprintjs/fingerprintjs'
import { record } from 'rrweb'

;(function () {
  if (typeof document === 'undefined') return

  // ── Campaign ID resolution ──────────────────────────────────────────────
  const script = document.currentScript
  let campaignId = null
  if (script) {
    campaignId = script.getAttribute('data-campaign') || null
    if (!campaignId) {
      try {
        const u = new URL(script.src)
        campaignId = u.searchParams.get('c') || null
      } catch (_) {}
    }
  }

  // ── Recording opt-out ───────────────────────────────────────────────────
  // Landers that only need entry-source/pageview tracking opt out of rrweb with
  // `<script src="/p.js?c=X&rec=0">` (or data-rec="0"). Fingerprint and pageview
  // still fire — this is a mode of the one pixel, not a second script.
  let recEnabled = true
  if (script) {
    let recAttr = script.getAttribute('data-rec')
    if (recAttr === null) {
      try {
        recAttr = new URL(script.src).searchParams.get('rec')
      } catch (_) {
        recAttr = null
      }
    }
    if (recAttr === '0' || recAttr === 'false' || recAttr === 'off') recEnabled = false
  }

  // ── Entry referer ───────────────────────────────────────────────────────
  // Captured once per tab and replayed on every later pageview. After the first
  // in-lander navigation document.referrer points at the lander itself, which is
  // exactly how genuine search traffic becomes indistinguishable from direct —
  // especially behind server-side go.php redirects. Only an EXTERNAL referer
  // counts as an entry source; a same-host one is stored as empty.
  let entryRef = ''
  try {
    const stored = sessionStorage.getItem('slim_ref')
    if (stored === null) {
      const r = document.referrer || ''
      let external = false
      if (r) {
        try {
          external = new URL(r).hostname.replace(/^www\./, '') !== location.hostname.replace(/^www\./, '')
        } catch (_) {}
      }
      entryRef = external ? r : ''
      sessionStorage.setItem('slim_ref', entryRef)
    } else {
      entryRef = stored
    }
  } catch (_) {
    entryRef = document.referrer || ''
  }

  // ── Test link ───────────────────────────────────────────────────────────
  // Carry signed test-link parameters from the lander into tracked clicks.
  var TEST_PARAMS = ['_t', '_geo', '_dbg']
  var testQs = ''
  try {
    var here = new URLSearchParams(location.search)
    if (here.get('_t')) {
      var q = new URLSearchParams()
      for (var ti = 0; ti < TEST_PARAMS.length; ti++) {
        var tv = here.get(TEST_PARAMS[ti])
        if (tv) q.set(TEST_PARAMS[ti], tv)
      }
      testQs = q.toString()
      sessionStorage.setItem('slim_test', testQs)
    } else {
      testQs = sessionStorage.getItem('slim_test') || ''
    }
  } catch (_) {}

  // The logged page URL must not carry the key into stats.pixel_events.
  function pageUrl() {
    if (!testQs) return location.href
    try {
      var u = new URL(location.href)
      for (var j = 0; j < TEST_PARAMS.length; j++) u.searchParams.delete(TEST_PARAMS[j])
      return u.href
    } catch (_) {
      return location.href
    }
  }

  // ── Endpoint URL ────────────────────────────────────────────────────────
  // Relative `p/event` (no leading slash) so the endpoint resolves next to
  // wherever p.js was served from. Two cases this handles:
  //   1. <script src="https://tds.example.com/p.js?c=X">      → POST https://tds.example.com/p/event
  //   2. <script src="/a/p.js?c=X"> (proxied via nginx)   → POST /a/p/event   (same-origin, no CORS)
  // Case 2 hides tds.example.com from the visible network footprint of the host site.
  const base = (script && script.src) ? script.src : location.href
  let eventEndpoint
  try {
    eventEndpoint = new URL('p/event', base).href
  } catch (_) {
    eventEndpoint = 'p/event'
  }

  // ── Test link: lander buttons ───────────────────────────────────────────
  // Lander buttons (/play/<button>/, or a direct link to the TDS) are how a
  // visitor reaches the click engine. Without the key such a click would
  // route as ordinary traffic, so in test mode the link is tagged at the
  // last moment — on the gesture, which also covers buttons rendered later.
  if (testQs) {
    var tdsOrigin = ''
    try { tdsOrigin = new URL(base).origin } catch (_) {}
    var tagLink = function (ev) {
      var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null
      if (!a) return
      try {
        var u = new URL(a.getAttribute('href'), location.href)
        var toPlay = u.origin === location.origin && u.pathname.indexOf('/play/') === 0
        // A proxied pixel (/a/p.js) shares the lander's origin — then only
        // /play/ counts, or every internal link would be tagged.
        var toTds = tdsOrigin !== location.origin && u.origin === tdsOrigin
        if (!(toPlay || toTds) || u.searchParams.get('_t')) return
        new URLSearchParams(testQs).forEach(function (v, k) { u.searchParams.set(k, v) })
        a.href = u.href
      } catch (_) {}
    }
    var TAG_EVENTS = ['mousedown', 'touchstart', 'keydown', 'click', 'auxclick']
    for (var te = 0; te < TAG_EVENTS.length; te++) {
      try { document.addEventListener(TAG_EVENTS[te], tagLink, true) } catch (_) {}
    }
  }

  // ── Send helper ─────────────────────────────────────────────────────────
  function send(payload) {
    const body = JSON.stringify(payload)
    if (typeof navigator.sendBeacon === 'function') {
      navigator.sendBeacon(eventEndpoint, new Blob([body], { type: 'application/json' }))
    } else {
      fetch(eventEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        keepalive: true,
      }).catch(function () {})
    }
  }

  // ── Core track function ─────────────────────────────────────────────────
  function track(eventName, props, fp) {
    var payload = {
      c: campaignId,
      url: pageUrl(),
      ref: document.referrer || null,
      eref: entryRef || null,
      ua: navigator.userAgent || null,
      lang: navigator.language || null,
      tz: Intl.DateTimeFormat
        ? Intl.DateTimeFormat().resolvedOptions().timeZone || null
        : null,
      sw: screen.width || null,
      sh: screen.height || null,
      fp: fp || null,
      t: Math.floor(Date.now() / 1000),
      event: eventName || 'pageview',
    }
    if (props && typeof props === 'object') {
      payload.props = props
    }
    send(payload)
  }

  // ── FingerprintJS resolver ──────────────────────────────────────────────
  // Wrap every FP step in its own try/catch + Promise so a synchronous
  // throw inside an FP probe (Firefox-only "can't access property" cases
  // we've seen in console) becomes a normal rejection caught by .catch().
  function resolveFp() {
    try {
      return Promise.resolve(FingerprintJS.load())
        .then(function (fp) {
          try { return fp.get() } catch (_) { return null }
        })
        .then(function (result) { return result && result.visitorId ? result.visitorId : null })
        .catch(function () { return null })
    } catch (_) {
      return Promise.resolve(null)
    }
  }

  // ── Public API ──────────────────────────────────────────────────────────
  window.slimTDS = window.slimTDS || {}
  window.slimTDS.track = function (eventName, props) {
    resolveFp().then(function (id) { track(eventName, props, id) })
  }

  // ── Auto-fire pageview on load ──────────────────────────────────────────
  resolveFp().then(function (id) { track('pageview', null, id) })

  // ── rrweb session recording (sampled, best-effort) ──────────────────────
  try {
    var cfg = window.__slim || {}
    var rate = typeof cfg.rate === 'number' ? cfg.rate : 100
    if (campaignId && recEnabled && rate > 0 && Math.random() * 100 < rate) {
      startRecording()
    }
  } catch (_) {}

  function startRecording() {
    var recEndpoint
    try { recEndpoint = new URL('p/rec', base).href } catch (_) { recEndpoint = 'p/rec' }

    // session_id must be a UUID (the server stores it in a uuid column). Use the
    // native generator in secure contexts, else a v4 fallback — never a non-uuid.
    function uuidv4() {
      return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        var r = (Math.random() * 16) | 0
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
      })
    }
    // Reuse one session id across same-tab navigations (sessionStorage) so a
    // whole visit — home → about → contact — is ONE replay, not one per page.
    function newSid() { return (typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : uuidv4() }
    var sid
    try {
      sid = sessionStorage.getItem('slim_sid')
      if (!sid) { sid = newSid(); sessionStorage.setItem('slim_sid', sid) }
    } catch (_) { sid = newSid() }
    var buffer = []
    var seq = 0
    // Entry referer comes from the shared capture above — one definition for
    // both the pageview payload and the recording, so they can never disagree.
    // Resolve the FingerprintJS id once; it links the session to clicks/pixel
    // events server-side. May be null on early chunks until it resolves.
    var fpId = null
    resolveFp().then(function (id) { fpId = id })

    try {
      record({ emit: function (event) {
        buffer.push(event)
        // Full Snapshot (type 2) is large — flush it immediately via plain fetch
        // (no keepalive 64 KB cap) instead of waiting for the 5s interval, so even
        // short visits deliver the snapshot + Meta as seq 0.
        if (event.type === 2) flush(false)
      } })
    } catch (_) { return }

    function flush(useBeacon) {
      if (!buffer.length) return
      var events = buffer
      buffer = []
      var body = JSON.stringify({ c: campaignId, sid: sid, seq: seq++, fp: fpId, ref: entryRef, events: events })
      try {
        if (useBeacon && typeof navigator.sendBeacon === 'function') {
          navigator.sendBeacon(recEndpoint, new Blob([body], { type: 'application/json' }))
        } else {
          // No keepalive: it caps the request body at ~64 KB, which silently
          // drops the Full Snapshot chunk. In-session flushes use a plain
          // (uncapped) fetch; only the unload path above uses sendBeacon.
          fetch(recEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: body,
          }).catch(function () {})
        }
      } catch (_) {}
    }

    // Flush often (2s) so at most ~2s of events are at risk if an unload event
    // never fires (common on mobile). The unload paths below use sendBeacon,
    // which — unlike a plain fetch — survives the page being torn down.
    setInterval(function () { flush(false) }, 2000)
    addEventListener('pagehide', function () { flush(true) })
    addEventListener('beforeunload', function () { flush(true) })
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') flush(true)
    })
  }
})()
