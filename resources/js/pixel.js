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

  // ── Endpoint URL ────────────────────────────────────────────────────────
  // Relative `p/event` (no leading slash) so the endpoint resolves next to
  // wherever p.js was served from. Two cases this handles:
  //   1. <script src="https://example.com/p.js?c=X">      → POST https://example.com/p/event
  //   2. <script src="/a/p.js?c=X"> (proxied via nginx)   → POST /a/p/event   (same-origin, no CORS)
  // Case 2 hides example.com from the visible network footprint of the host site.
  const base = (script && script.src) ? script.src : location.href
  let eventEndpoint
  try {
    eventEndpoint = new URL('p/event', base).href
  } catch (_) {
    eventEndpoint = 'p/event'
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
      url: location.href,
      ref: document.referrer || null,
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
    // Entry referer of the visit — captured once and reused across same-tab
    // navigations (the entry page's document.referrer carries the external
    // source; later in-visit pages reference the lander itself).
    var entryRef
    try {
      entryRef = sessionStorage.getItem('slim_ref')
      if (entryRef === null) { entryRef = document.referrer || ''; sessionStorage.setItem('slim_ref', entryRef) }
    } catch (_) { entryRef = document.referrer || '' }
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
