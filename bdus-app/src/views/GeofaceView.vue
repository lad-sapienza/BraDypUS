<template>
  <div class="geoface-view">
    <!-- Toolbar -->
    <div class="geoface-toolbar">
      <Button icon="pi pi-arrow-left" text @click="router.back()" />
      <span class="geoface-title">{{ meta.tb_label ?? route.params.tb }}</span>
      <span class="geoface-count">{{ featureCount }} {{ t('geometries') }}</span>
    </div>

    <!-- Loading / error states -->
    <div v-if="loading" class="geoface-status">
      <i class="pi pi-spin pi-spinner" style="font-size:2rem" />
    </div>
    <div v-else-if="loadError" class="geoface-status">
      <Message severity="error" :closable="false">{{ loadError }}</Message>
    </div>

    <!-- Map container — always rendered so mapEl ref is available -->
    <div ref="mapEl" class="geoface-map" :style="{ visibility: loading || loadError ? 'hidden' : 'visible' }" />

    <!-- Link geometry dialog (shown after drawing a new geometry) -->
    <Dialog
      v-model:visible="linkDialogVisible"
      modal
      :header="t('link_geometry_to_record')"
      style="width: 420px"
    >
      <div class="field" style="padding: 0.75rem 0">
        <AutoComplete
          v-model="linkSearch"
          :suggestions="linkSuggestions"
          optionLabel="label"
          :placeholder="t('type_to_search')"
          @complete="onLinkSearch"
          @item-select="onLinkSelect"
          fluid
        />
      </div>
      <template #footer>
        <Button :label="t('cancel')" text @click="cancelLink" />
      </template>
    </Dialog>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useRoute, useRouter }  from 'vue-router'
import { useToast }             from 'primevue/usetoast'
import maplibregl               from 'maplibre-gl'
import MapboxDraw               from 'maplibre-gl-draw'
import 'maplibre-gl/dist/maplibre-gl.css'
import 'maplibre-gl-draw/dist/maplibre-gl-draw.css'
import { api }                  from '@/api'
import { useI18n }              from '@/i18n'
import Button                   from 'primevue/button'
import Dialog                   from 'primevue/dialog'
import AutoComplete             from 'primevue/autocomplete'
import Message                  from 'primevue/message'

const { t }    = useI18n()
const route    = useRoute()
const router   = useRouter()
const toast    = useToast()

// ── State ─────────────────────────────────────────────────────────────────
const mapEl   = ref(null)
const loading = ref(true)
const loadError = ref(null)

const geojson = ref(null)
const meta    = ref({})

let map  = null
let draw = null

const featureCount = computed(() => geojson.value?.features?.length ?? 0)

// ── Link dialog state ─────────────────────────────────────────────────────
const linkDialogVisible = ref(false)
const pendingGeometry   = ref(null)
const pendingDrawId     = ref(null)
const linkSearch        = ref('')
const linkSuggestions   = ref([])

// ── Build filter params from route.query ───────────────────────────────────
function buildFilterParams() {
  const tb = route.params.tb
  const q  = route.query
  const params = { tb }

  if (q.search_type) params.search_type = q.search_type
  if (q.where)       params.where       = q.where
  if (q.querytext)   params.querytext   = q.querytext
  if (q.adv)         params.adv         = q.adv

  return params
}

// ── Load GeoJSON from server ───────────────────────────────────────────────
async function loadGeoJson() {
  loading.value   = true
  loadError.value = null
  try {
    const res = await api.get('geoface_ctrl', 'getGeoJson', buildFilterParams())
    if (res.status === 'error') throw new Error(t(res.code ?? 'generic_error'))
    geojson.value = res.geojson
    meta.value    = res.meta ?? {}
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

// ── Map initialisation ─────────────────────────────────────────────────────
async function initMap() {
  // Determine base style — prefer a maplibre_style base layer from config
  const maplibreStyleLayer = (meta.value.layers ?? []).find(
    l => l.type === 'maplibre_style' && l.layertype === 'base'
  )

  const style = maplibreStyleLayer
    ? maplibreStyleLayer.path
    : {
        version: 8,
        sources: {
          osm: {
            type: 'raster',
            tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
            tileSize: 256,
            attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>'
          }
        },
        layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
        glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf'
      }

  map = new maplibregl.Map({
    container: mapEl.value,
    style,
    center: [12.5, 42],
    zoom: 6
  })

  map.addControl(new maplibregl.NavigationControl())
  map.addControl(new maplibregl.ScaleControl())

  map.on('load', () => {
    addCustomLayers()
    addRecordLayer()
    if (meta.value.canUserEdit) addDrawControl()
    fitToData()
  })
}

// ── Custom overlay layers (XYZ, WMS, local GeoJSON) ───────────────────────
function addCustomLayers() {
  const overlayLayers = (meta.value.layers ?? []).filter(l => l.type !== 'maplibre_style')

  for (const layer of overlayLayers) {
    const id = 'custom-' + (layer.label ?? layer.path ?? String(Math.random())).replace(/\s/g, '-')

    if (layer.type === 'tiles') {
      map.addSource(id, { type: 'raster', tiles: [layer.path], tileSize: 256 })
      map.addLayer({ id, type: 'raster', source: id, layout: { visibility: 'none' } })

    } else if (layer.type === 'wms') {
      const wmsUrl = `${layer.path}?SERVICE=WMS&VERSION=1.1.1&REQUEST=GetMap`
        + `&LAYERS=${encodeURIComponent(layer.wmslayers ?? '')}`
        + `&FORMAT=image/png&TRANSPARENT=true&SRS=EPSG:3857&WIDTH=256&HEIGHT=256`
        + `&BBOX={bbox-epsg-3857}`
      map.addSource(id, { type: 'raster', tiles: [wmsUrl], tileSize: 256 })
      map.addLayer({ id, type: 'raster', source: id, layout: { visibility: 'none' } })

    } else if (layer.type === 'local') {
      // Files live in the project's geodata/ directory which is web-accessible.
      // The layer.path stores the filename; serve it relative to the app root.
      // Full URL: same origin + /projects/{app}/geodata/{filename}
      // We fall back to using the raw path if it is already absolute.
      const fileUrl = layer.path.startsWith('http')
        ? layer.path
        : layer.path   // relative path as configured — works when served from the same origin

      fetch(fileUrl)
        .then(r => r.json())
        .then(data => {
          map.addSource(id, { type: 'geojson', data })
          map.addLayer({
            id: id + '-fill', type: 'fill', source: id,
            paint: { 'fill-color': '#3388ff', 'fill-opacity': 0.2 },
            layout: { visibility: 'none' }
          })
          map.addLayer({
            id: id + '-line', type: 'line', source: id,
            paint: { 'line-color': '#3388ff', 'line-width': 2 },
            layout: { visibility: 'none' }
          })
        })
        .catch(() => {
          // Silently ignore unavailable local files
        })
    }
  }
}

// ── Record GeoJSON layer ───────────────────────────────────────────────────
function addRecordLayer() {
  if (!geojson.value?.features?.length) return

  map.addSource('records', { type: 'geojson', data: geojson.value })

  // Points
  map.addLayer({
    id: 'records-circle',
    type: 'circle',
    source: 'records',
    filter: ['==', '$type', 'Point'],
    paint: {
      'circle-radius': 7,
      'circle-color': '#e74c3c',
      'circle-stroke-width': 2,
      'circle-stroke-color': '#fff'
    }
  })

  // Lines
  map.addLayer({
    id: 'records-line',
    type: 'line',
    source: 'records',
    filter: ['in', '$type', 'LineString', 'MultiLineString'],
    paint: { 'line-color': '#e74c3c', 'line-width': 2 }
  })

  // Polygons — fill + outline
  map.addLayer({
    id: 'records-fill',
    type: 'fill',
    source: 'records',
    filter: ['in', '$type', 'Polygon', 'MultiPolygon'],
    paint: { 'fill-color': '#e74c3c', 'fill-opacity': 0.25 }
  })
  map.addLayer({
    id: 'records-outline',
    type: 'line',
    source: 'records',
    filter: ['in', '$type', 'Polygon', 'MultiPolygon'],
    paint: { 'line-color': '#e74c3c', 'line-width': 2 }
  })

  // Click popups
  ;['records-circle', 'records-fill'].forEach(layerId => {
    map.on('click', layerId, e => {
      const props  = e.features[0].properties
      const fields = meta.value.preview_fields ?? []
      const content = fields.length
        ? fields.map(f => `<div><strong>${f}</strong>: ${props[f] ?? ''}</div>`).join('')
        : Object.entries(props)
            .filter(([k]) => k !== 'geo_id')
            .map(([k, v]) => `<div><strong>${k}</strong>: ${v}</div>`)
            .join('')

      new maplibregl.Popup()
        .setLngLat(e.lngLat)
        .setHTML(`<div class="geo-popup">${content}</div>`)
        .addTo(map)
    })
    map.on('mouseenter', layerId, () => { map.getCanvas().style.cursor = 'pointer' })
    map.on('mouseleave', layerId, () => { map.getCanvas().style.cursor = '' })
  })
}

// ── Fit map to data bounds ─────────────────────────────────────────────────
function fitToData() {
  const features = geojson.value?.features ?? []
  if (!features.length) return

  let minLng = 180, maxLng = -180, minLat = 90, maxLat = -90

  function processCoords(coords) {
    if (typeof coords[0] === 'number') {
      minLng = Math.min(minLng, coords[0]); maxLng = Math.max(maxLng, coords[0])
      minLat = Math.min(minLat, coords[1]); maxLat = Math.max(maxLat, coords[1])
    } else {
      coords.forEach(processCoords)
    }
  }

  features.forEach(f => f.geometry?.coordinates && processCoords(f.geometry.coordinates))

  if (minLng < maxLng && minLat < maxLat) {
    map.fitBounds([[minLng, minLat], [maxLng, maxLat]], { padding: 60 })
  }
}

// ── Draw control (edit mode) ───────────────────────────────────────────────
function addDrawControl() {
  draw = new MapboxDraw({
    displayControlsDefault: false,
    controls: { polygon: true, line_string: true, point: true, trash: true }
  })
  map.addControl(draw)

  map.on('draw.create', onDrawCreate)
  map.on('draw.update', onDrawUpdate)
  map.on('draw.delete', onDrawDelete)
}

// ── Draw event handlers ────────────────────────────────────────────────────
function onDrawCreate(e) {
  pendingGeometry.value = e.features[0].geometry
  pendingDrawId.value   = e.features[0].id
  linkSearch.value      = ''
  linkSuggestions.value = []
  linkDialogVisible.value = true
}

async function onLinkSearch(event) {
  const tb = route.params.tb
  try {
    const res = await api.get('record_ctrl', 'searchLinkCandidates', { tb, q: event.query })
    linkSuggestions.value = (res.data ?? []).map(r => ({
      label: String(r.label ?? r.id),
      id:    r.id
    }))
  } catch {
    linkSuggestions.value = []
  }
}

async function onLinkSelect(event) {
  const recordId = event.value.id
  try {
    const res = await api.post('geoface_ctrl', 'saveNew', {
      tb:       route.params.tb,
      id:       recordId,
      geometry: pendingGeometry.value
    })
    if (res.status === 'error') {
      draw?.delete(pendingDrawId.value)
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code ?? 'generic_error'), life: 4000 })
    } else {
      toast.add({ severity: 'success', summary: t('ok_insert_geodata'), life: 3000 })
      await reloadGeoJson()
    }
  } catch {
    draw?.delete(pendingDrawId.value)
  }
  linkDialogVisible.value = false
  pendingGeometry.value   = null
  draw?.deleteAll()
}

function cancelLink() {
  draw?.delete(pendingDrawId.value)
  linkDialogVisible.value = false
  pendingGeometry.value   = null
}

async function onDrawUpdate(e) {
  const geodata = e.features
    .filter(f => f.properties?.geo_id)
    .map(f => ({ id: f.properties.geo_id, geometry: f.geometry }))
  if (!geodata.length) return

  try {
    const res = await api.post('geoface_ctrl', 'updateGeometry', { geodata })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code ?? 'generic_error'), life: 4000 })
    } else {
      toast.add({ severity: 'success', summary: t('ok_update_geometry'), life: 3000 })
      await reloadGeoJson()
      draw?.deleteAll()
    }
  } catch (err) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: String(err), life: 4000 })
  }
}

async function onDrawDelete(e) {
  const ids = e.features
    .filter(f => f.properties?.geo_id)
    .map(f => f.properties.geo_id)
  if (!ids.length) return

  try {
    const res = await api.post('geoface_ctrl', 'eraseGeometry', { ids })
    if (res.status === 'error') {
      toast.add({ severity: 'error', summary: t('generic_error'), detail: t(res.code ?? 'generic_error'), life: 4000 })
    } else {
      toast.add({ severity: 'success', summary: t('ok_delete_geodata'), life: 3000 })
      await reloadGeoJson()
    }
  } catch (err) {
    toast.add({ severity: 'error', summary: t('generic_error'), detail: String(err), life: 4000 })
  }
}

async function reloadGeoJson() {
  try {
    const res = await api.get('geoface_ctrl', 'getGeoJson', buildFilterParams())
    if (res.status !== 'error') {
      geojson.value = res.geojson
      const src = map?.getSource('records')
      if (src) src.setData(geojson.value)
    }
  } catch { /* silently ignore reload errors */ }
}

// ── Lifecycle ──────────────────────────────────────────────────────────────
onMounted(async () => {
  await loadGeoJson()
  if (!loadError.value) {
    await initMap()
  }
})

onUnmounted(() => {
  map?.remove()
  map  = null
  draw = null
})
</script>

<style scoped>
.geoface-view {
  display: flex;
  flex-direction: column;
  height: 100vh;
  overflow: hidden;
}

.geoface-toolbar {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.5rem 1rem;
  background: var(--bdus-surface, var(--p-surface-0));
  border-bottom: 1px solid var(--p-surface-200);
  flex-shrink: 0;
  z-index: 10;
}

.geoface-title {
  font-weight: 600;
  font-size: 1rem;
}

.geoface-count {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
  margin-left: auto;
}

.geoface-map {
  flex: 1;
  min-height: 0;
}

.geoface-status {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
}

/* MapLibre popup */
:global(.geo-popup) {
  font-size: 0.875rem;
  max-width: 220px;
  line-height: 1.5;
}

:global(.geo-popup div) {
  border-bottom: 1px solid rgba(0,0,0,0.06);
  padding: 0.15rem 0;
}

:global(.geo-popup div:last-child) {
  border-bottom: none;
}
</style>
