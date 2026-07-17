import Alpine from 'alpinejs'
import * as echarts from 'echarts/core'
import { LineChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TitleComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'

echarts.use([LineChart, GridComponent, LegendComponent, TitleComponent, TooltipComponent, CanvasRenderer])

window.Alpine = Alpine

Alpine.data('flowBuilder', (opts) => ({
  filters: opts.initialFilters && opts.initialFilters.length ? opts.initialFilters : [],
  targetOffers: opts.initialTargetOffers || [],
  targetType: opts.initialTargetType || 'offers',
  schemaId: opts.initialSchemaId || 2,
  // Coerce to plain object: PHP empty associative array serializes as JS array []
  // and JSON.stringify drops non-numeric props, so {body: "..."} would be lost.
  schemaConfig: (opts.initialSchemaConfig && !Array.isArray(opts.initialSchemaConfig))
    ? { ...opts.initialSchemaConfig }
    : {},
  availableOffers: opts.availableOffers || [],

  init() {
    // When the user picks Offer(s) as target type, surface an empty offer-row
    // immediately so the dropdown is visible without an extra "+ add offer" click.
    this.$watch('targetType', (next) => {
      if (next === 'offers' && this.targetOffers.length === 0) {
        this.addTarget()
      }
    })
    // Same on initial mount when the form opens with target_type=offers and no rows.
    if (this.targetType === 'offers' && this.targetOffers.length === 0) {
      this.addTarget()
    }
  },

  get weightsSum() {
    return this.targetOffers.reduce((s, t) => s + (parseInt(t.weight, 10) || 0), 0)
  },

  addGroup() {
    this.filters.push([{ field: 'country', op: 'eq', value: '' }])
  },
  removeGroup(gi) {
    this.filters.splice(gi, 1)
  },
  addCondition(gi) {
    this.filters[gi].push({ field: 'country', op: 'eq', value: '' })
  },
  removeCondition(gi, ci) {
    this.filters[gi].splice(ci, 1)
    if (this.filters[gi].length === 0) {
      this.filters.splice(gi, 1)
    }
  },
  addTarget() {
    this.targetOffers.push({ offer_id: '', weight: 100 })
  },
  removeTarget(idx) {
    this.targetOffers.splice(idx, 1)
  },

  syncHidden() {
    this.$refs.filtersField.value = JSON.stringify(this.filters)
    this.$refs.targetOffersField.value = JSON.stringify(this.targetOffers)
    this.$refs.schemaConfigField.value = JSON.stringify(this.schemaConfig)
  },
}))

Alpine.data('statsChart', (data) => ({
  chart: null,
  init() {
    this.chart = echarts.init(this.$el)
    this.chart.setOption({
      grid: { left: 50, right: 20, top: 30, bottom: 30, containLabel: true },
      xAxis: { type: 'time', axisLabel: { color: '#6b6457' } },
      yAxis: { type: 'value', axisLabel: { color: '#6b6457' } },
      legend: { textStyle: { color: '#3f3a32' }, top: 0 },
      tooltip: { trigger: 'axis' },
      series: [
        { name: 'Clicks', type: 'line', smooth: true, data: data.points.map(p => [p.hour, p.clicks]), color: '#b54f17' },
        { name: 'Unique', type: 'line', smooth: true, data: data.points.map(p => [p.hour, p.uniq]),   color: '#0d6e6e' },
        { name: 'Bots',   type: 'line', smooth: true, data: data.points.map(p => [p.hour, p.bot]),    color: '#a8a399' },
      ],
    })
    window.addEventListener('resize', () => this.chart?.resize())
  },
}))

// Compact 48-hour timeline used at the top of /admin/clicks.
// Three series (clicks/unique/bots) over fixed 48 hourly buckets ending at
// the current hour. Soft area fill on the primary series so the chart reads
// as a "summary stripe" rather than a full analytics dashboard.
Alpine.data('clicksTimeline', (data) => ({
  chart: null,
  init() {
    this.chart = echarts.init(this.$el)
    this.chart.setOption({
      grid: { left: 8, right: 12, top: 28, bottom: 22, containLabel: true },
      xAxis: {
        type: 'time',
        axisLabel: { color: '#6b6457', fontSize: 10, hideOverlap: true },
        axisLine:  { lineStyle: { color: '#d6d2c8' } },
        axisTick:  { show: false },
        splitLine: { show: false },
      },
      yAxis: {
        type: 'value',
        axisLabel: { color: '#a8a399', fontSize: 10 },
        axisLine:  { show: false },
        axisTick:  { show: false },
        splitLine: { lineStyle: { color: '#ece8df', type: 'dashed' } },
      },
      legend: { textStyle: { color: '#6b6457', fontSize: 11 }, top: 0, right: 8, itemHeight: 8, itemWidth: 14 },
      tooltip: { trigger: 'axis', axisPointer: { type: 'line', lineStyle: { color: '#b54f17', width: 1, type: 'dashed' } } },
      series: [
        {
          name: 'Clicks', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.clicks]),
          lineStyle: { width: 2, color: '#b54f17' },
          itemStyle: { color: '#b54f17' },
          areaStyle: { color: 'rgba(181, 79, 23, 0.14)' },
        },
        {
          name: 'Unique', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.uniq]),
          lineStyle: { width: 1.5, color: '#0d6e6e', type: 'dashed' },
          itemStyle: { color: '#0d6e6e' },
        },
        {
          name: 'Bots', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.bot]),
          lineStyle: { width: 1, color: '#a8a399' },
          itemStyle: { color: '#a8a399' },
        },
      ],
    })
    window.addEventListener('resize', () => this.chart?.resize())
  },
}))

// Compact 48-hour timeline for /admin/pixel — events + unique visitors.
Alpine.data('pixelTimeline', (data) => ({
  chart: null,
  init() {
    this.chart = echarts.init(this.$el)
    this.chart.setOption({
      grid: { left: 8, right: 12, top: 28, bottom: 22, containLabel: true },
      xAxis: {
        type: 'time',
        axisLabel: { color: '#6b6457', fontSize: 10, hideOverlap: true },
        axisLine:  { lineStyle: { color: '#d6d2c8' } },
        axisTick:  { show: false },
        splitLine: { show: false },
      },
      yAxis: {
        type: 'value',
        axisLabel: { color: '#a8a399', fontSize: 10 },
        axisLine:  { show: false },
        axisTick:  { show: false },
        splitLine: { lineStyle: { color: '#ece8df', type: 'dashed' } },
      },
      legend: { textStyle: { color: '#6b6457', fontSize: 11 }, top: 0, right: 8, itemHeight: 8, itemWidth: 14 },
      tooltip: { trigger: 'axis', axisPointer: { type: 'line', lineStyle: { color: '#0d6e6e', width: 1, type: 'dashed' } } },
      series: [
        {
          name: 'Events', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.events]),
          lineStyle: { width: 2, color: '#0d6e6e' },
          itemStyle: { color: '#0d6e6e' },
          areaStyle: { color: 'rgba(13, 110, 110, 0.14)' },
        },
        {
          name: 'Unique', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.uniq]),
          lineStyle: { width: 1.5, color: '#b54f17', type: 'dashed' },
          itemStyle: { color: '#b54f17' },
        },
      ],
    })
    window.addEventListener('resize', () => this.chart?.resize())
  },
}))

Alpine.start()
