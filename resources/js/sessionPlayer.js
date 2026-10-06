import { Replayer } from 'rrweb'

window.slimSessionPlayer = function (el, eventsUrl) {
  if (!el) return
  const labels = el.dataset
  const icon = (body) => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' + body + '</svg>'
  const playIcon = icon('<path d="m8 5 11 7-11 7z"/>')
  const pauseIcon = icon('<path d="M9 5v14M15 5v14"/>')
  const fmt = (ms) => {
    const s = Math.max(0, Math.floor(ms / 1000))
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0')
  }
  const message = (text) => {
    const node = document.createElement('div')
    node.className = 'session-player-message'
    node.setAttribute('role', 'status')
    node.textContent = text
    el.replaceChildren(node)
  }
  message(labels.loading)
  fetch(eventsUrl)
    .then((response) => {
      if (!response.ok) throw new Error('Session response ' + response.status)
      return response.json()
    })
    .then((data) => {
      const events = data?.events || []
      if (events.length < 2) { message(labels.emptyRecording); return }

      const address = document.createElement('div')
      address.className = 'session-player-address'
      address.innerHTML = icon('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a18 18 0 0 1 0 18 18 18 0 0 1 0-18"/>')
      const url = document.createElement('span')
      url.className = 'session-player-url'
      const badge = document.createElement('span')
      badge.className = 'badge badge-ghost'
      badge.textContent = labels.recording
      address.append(url, badge)
      const stage = document.createElement('div')
      stage.className = 'session-player-stage'
      const viewport = document.createElement('div')
      viewport.className = 'session-player-viewport'
      stage.append(viewport)
      const bar = document.createElement('div')
      bar.className = 'session-player-controls'
      const playBtn = document.createElement('button')
      playBtn.type = 'button'
      playBtn.className = 'btn session-player-toggle'
      const seek = document.createElement('input')
      seek.className = 'session-player-timeline'
      seek.type = 'range'; seek.min = '0'; seek.max = '1000'; seek.value = '0'; seek.step = '1'
      seek.setAttribute('aria-label', labels.timeline)
      const time = document.createElement('span')
      time.className = 'session-player-time'
      const speeds = document.createElement('div')
      speeds.className = 'session-player-speed'
      speeds.setAttribute('role', 'group')
      speeds.setAttribute('aria-label', labels.speed)
      const speedBtns = [1, 2, 4, 8].map((speed) => {
        const b = document.createElement('button')
        b.type = 'button'
        b.textContent = speed + '×'
        b.dataset.speed = String(speed)
        b.setAttribute('aria-pressed', speed === 1 ? 'true' : 'false')
        speeds.append(b)
        return b
      })
      bar.append(playBtn, seek, time, speeds)
      el.replaceChildren(address, stage, bar)

      let meta = events.find((event) => event.type === 4)
      const showUrl = (href) => { url.textContent = href || '—'; url.title = href || '' }
      showUrl(meta?.data?.href)
      const replayer = new Replayer(events, { root: viewport, speed: 1, skipInactive: false })
      const fit = () => {
        const wrap = viewport.querySelector('.replayer-wrapper')
        if (!wrap) return
        const rw = meta?.data?.width || 1024
        const rh = meta?.data?.height || 576
        const style = getComputedStyle(stage)
        const available = stage.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)
        const maxHeight = Math.max(220, Math.min(640, window.innerHeight * 0.65))
        const scale = Math.min(1, Math.max(1, available) / rw, maxHeight / rh)
        viewport.style.width = Math.round(rw * scale) + 'px'
        viewport.style.height = Math.round(rh * scale) + 'px'
        wrap.style.transform = 'scale(' + scale + ')'
        wrap.style.transformOrigin = 'top left'
      }
      replayer.on('event-cast', (event) => {
        if (event?.type === 4) { meta = event; showUrl(event.data?.href); fit() }
      })
      fit()
      const observer = new ResizeObserver(fit)
      observer.observe(stage)
      window.addEventListener('resize', fit)
      const total = replayer.getMetaData().totalTime || 1
      let playing = false
      let seeking = false
      const setPlaying = (value) => {
        playing = value
        playBtn.innerHTML = value ? pauseIcon : playIcon
        playBtn.append(document.createTextNode(value ? labels.pause : labels.play))
        playBtn.setAttribute('aria-label', value ? labels.pause : labels.play)
      }
      const setSpeed = (speed) => {
        replayer.setConfig({ speed })
        speedBtns.forEach((button) => button.setAttribute('aria-pressed', Number(button.dataset.speed) === speed ? 'true' : 'false'))
      }
      playBtn.onclick = () => {
        if (playing) { replayer.pause(); setPlaying(false) }
        else {
          const current = replayer.getCurrentTime()
          replayer.play(current >= total ? 0 : current)
          setPlaying(true)
        }
      }
      speedBtns.forEach((button) => { button.onclick = () => setSpeed(Number(button.dataset.speed)) })
      const updateTimeline = (t) => {
        seek.style.setProperty('--progress', String(t / total * 100) + '%')
        time.textContent = fmt(t) + ' / ' + fmt(total)
        seek.setAttribute('aria-valuetext', fmt(t) + ' / ' + fmt(total))
      }
      seek.addEventListener('input', () => {
        seeking = true
        updateTimeline(Number(seek.value) / 1000 * total)
      })
      seek.addEventListener('change', () => {
        const t = Number(seek.value) / 1000 * total
        if (playing) replayer.play(t)
        else replayer.pause(t)
        seeking = false
      })
      replayer.on('finish', () => setPlaying(false))
      const loop = () => {
        if (!el.isConnected) { observer.disconnect(); window.removeEventListener('resize', fit); return }
        if (!seeking) {
          const t = Math.max(0, Math.min(replayer.getCurrentTime(), total))
          seek.value = String(Math.round(t / total * 1000))
          updateTimeline(t)
        }
        requestAnimationFrame(loop)
      }
      updateTimeline(0)
      requestAnimationFrame(loop)
      setSpeed(1)
      replayer.play()
      setPlaying(true)
    })
    .catch((error) => {
      console.error('[slimSessionPlayer]', error)
      message(labels.loadError)
    })
}
