;(function () {
  if (typeof document === 'undefined') return

  var script = document.currentScript
  if (!script) return

  var campaign = script.getAttribute('data-campaign')
  var endpoint
  try {
    var scriptUrl = new URL(script.src)
    campaign = campaign || scriptUrl.searchParams.get('c')
    endpoint = new URL('/p/event', scriptUrl).href
  } catch (_) {
    return
  }
  if (!campaign) return

  var ref = document.referrer || ''
  try {
    var key = 'slim_entry_ref'
    var currentHost = location.hostname.replace(/^www\./, '')
    var refHost = ref ? new URL(ref).hostname.replace(/^www\./, '') : ''
    var isExternal = refHost && refHost !== currentHost
    if (isExternal) sessionStorage.setItem(key, ref)
    else ref = sessionStorage.getItem(key) || ref
  } catch (_) {}

  var payload = JSON.stringify({
    c: campaign,
    url: location.href,
    ref: ref || null,
    ua: navigator.userAgent || null,
    lang: navigator.language || null,
    tz: Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone || null : null,
    sw: screen.width || null,
    sh: screen.height || null,
    t: Math.floor(Date.now() / 1000),
    event: 'pageview',
  })

  try {
    if (typeof navigator.sendBeacon === 'function') {
      navigator.sendBeacon(endpoint, new Blob([payload], { type: 'application/json' }))
    } else {
      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload,
        credentials: 'include',
        keepalive: true,
      }).catch(function () {})
    }
  } catch (_) {}
})()
