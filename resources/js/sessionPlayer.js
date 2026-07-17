import { Replayer } from 'rrweb'

// Session replay UI driven by rrweb's Replayer directly.
//
// We do NOT use the `rrweb-player` Svelte wrapper: rrweb-player 2.0.1 mounts
// only an empty `.rr-player__frame` (its Frame onMount never builds the
// Replayer), while rrweb's own Replayer renders correctly. So we build our own
// controller around it: play/pause, a seek timeline, speed options and a time
// readout.
window.slimSessionPlayer = function (el, eventsUrl) {
  if (!el) return

  function mkBtn(txt) {
    var b = document.createElement('button')
    b.type = 'button'
    b.className = 'btn'
    b.textContent = txt
    b.style.cssText = 'cursor:pointer;padding:4px 12px;font:inherit;white-space:nowrap'
    return b
  }
  function fmt(ms) {
    var s = Math.max(0, Math.floor(ms / 1000))
    return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2)
  }

  fetch(eventsUrl)
    .then(function (r) { return r.json() })
    .then(function (data) {
      var events = (data && data.events) || []
      if (events.length < 2) {
        el.textContent = 'Not enough events to replay.'
        return
      }

      var bar = document.createElement('div')
      bar.style.cssText = 'display:flex;gap:10px;align-items:center;padding:8px 0;flex-wrap:wrap;border-top:1px solid var(--line,#e3e7ee);margin-top:8px'
      var playBtn = mkBtn('❚❚ Pause')
      var seek = document.createElement('input')
      seek.type = 'range'; seek.min = '0'; seek.max = '1000'; seek.value = '0'; seek.step = '1'
      seek.style.cssText = 'flex:1;min-width:220px;cursor:pointer;accent-color:var(--accent,#b54f17)'
      var timeLabel = document.createElement('span')
      timeLabel.style.cssText = 'font-variant-numeric:tabular-nums;font-size:12px;color:var(--muted,#6b6457);min-width:96px;text-align:right'
      var speedWrap = document.createElement('span')
      speedWrap.style.cssText = 'display:inline-flex;gap:4px'
      var speedBtns = [1, 2, 4, 8].map(function (s) { var b = mkBtn(s + '×'); b.dataset.speed = String(s); speedWrap.appendChild(b); return b })

      bar.appendChild(playBtn)
      bar.appendChild(seek)
      bar.appendChild(timeLabel)
      bar.appendChild(speedWrap)

      // Current page URL, updated as the replay crosses page navigations.
      var urlLine = document.createElement('div')
      urlLine.style.cssText = 'font:12px/1.4 ui-monospace,monospace;color:var(--muted,#6b6457);padding:4px 0;border-bottom:1px solid var(--line,#e3e7ee);margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis'
      function showUrl(href) { urlLine.textContent = href || '—' }
      for (var mi = 0; mi < events.length; mi++) {
        if (events[mi].type === 4 && events[mi].data && events[mi].data.href) { showUrl(events[mi].data.href); break }
      }

      var stage = document.createElement('div')
      el.appendChild(urlLine)
      el.appendChild(stage)
      el.appendChild(bar)

      var replayer
      try {
        replayer = new Replayer(events, { root: stage, speed: 1, skipInactive: false })
      } catch (e) {
        el.textContent = 'Player error: ' + (e && e.message ? e.message : e)
        return
      }
      // rrweb casts each event during playback; Meta (type 4) marks a new page.
      replayer.on('event-cast', function (ev) {
        if (ev && ev.type === 4 && ev.data && ev.data.href) showUrl(ev.data.href)
      })

      // Fit the recorded viewport to the container width WITHOUT upscaling:
      // wide desktop recordings shrink to fit, but narrow (mobile) recordings
      // render at their real captured size instead of being blown up.
      function fit() {
        var wrap = stage.querySelector('.replayer-wrapper')
        if (!wrap) return
        var meta = null
        for (var i = 0; i < events.length; i++) { if (events[i].type === 4) { meta = events[i]; break } }
        var rw = (meta && meta.data && meta.data.width) || 1024
        var rh = (meta && meta.data && meta.data.height) || 576
        var availW = el.clientWidth || stage.clientWidth || 900
        var scale = Math.min(1, availW / rw)
        wrap.style.transform = 'scale(' + scale + ')'
        wrap.style.transformOrigin = 'top left'
        stage.style.height = Math.round(rh * scale) + 'px'
        stage.style.overflow = 'hidden'
      }
      fit()
      addEventListener('resize', fit)

      var total = replayer.getMetaData().totalTime || 1
      var playing = false
      var seeking = false

      function setPlaying(p) { playing = p; playBtn.textContent = p ? '❚❚ Pause' : '▶ Play' }
      function setSpeed(s) {
        replayer.setConfig({ speed: s })
        speedBtns.forEach(function (b) { b.style.fontWeight = (Number(b.dataset.speed) === s) ? '700' : '400' })
      }

      playBtn.onclick = function () {
        if (playing) { replayer.pause(); setPlaying(false) }
        else { replayer.play(replayer.getCurrentTime()); setPlaying(true) }
      }
      speedBtns.forEach(function (b) { b.onclick = function () { setSpeed(Number(b.dataset.speed)) } })

      seek.addEventListener('input', function () {
        seeking = true
        var t = (Number(seek.value) / 1000) * total
        timeLabel.textContent = fmt(t) + ' / ' + fmt(total)
      })
      seek.addEventListener('change', function () {
        var t = (Number(seek.value) / 1000) * total
        if (playing) { replayer.play(t) } else { replayer.pause(t) }
        seeking = false
      })

      replayer.on('finish', function () { setPlaying(false) })

      function loop() {
        if (!seeking) {
          var t = Math.min(replayer.getCurrentTime(), total)
          seek.value = String(Math.round((t / total) * 1000))
          timeLabel.textContent = fmt(t) + ' / ' + fmt(total)
        }
        requestAnimationFrame(loop)
      }
      requestAnimationFrame(loop)

      setSpeed(1)
      replayer.play()
      setPlaying(true)
    })
    .catch(function (e) {
      console.error('[slimSessionPlayer]', e)
      el.textContent = 'Failed to load session.'
    })
}
