<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><i class="pi pi-map" /> {{ t('geoface') }}</h2>
      <Button :label="t('save')" icon="pi pi-save" size="small" :loading="saving" @click="save" />
    </div>

    <div v-if="loading" class="cfg-loading-center">
      <i class="pi pi-spin pi-spinner" />
    </div>

    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>

    <div v-if="!loading" class="cfg-geoface-body">

      <!-- ─── Layers table ──────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-header">
          <span>{{ t('layers') }}</span>
          <Button :label="t('add_layer')" icon="pi pi-plus" size="small" outlined @click="addLayer" />
        </div>

        <div v-if="layers.length === 0" class="cfg-empty-msg-sm">{{ t('no_layers') }}</div>

        <div v-for="(layer, idx) in layers" :key="idx" class="cfg-layer-card">
          <div class="cfg-layer-row">
            <div class="cfg-field-group">
              <label>{{ t('label') }}</label>
              <InputText v-model="layer.label" size="small" />
            </div>
            <div class="cfg-field-group">
              <label>{{ t('layer_type') }}</label>
              <Select
                v-model="layer.type"
                :options="dataTypes"
                size="small"
              />
            </div>
            <div class="cfg-field-group">
              <label>{{ t('map_layer_role') }}</label>
              <Select
                v-model="layer.layertype"
                :options="layerRoles"
                size="small"
              />
            </div>
            <Button
              icon="pi pi-trash"
              severity="danger"
              size="small"
              text
              :title="t('delete')"
              @click="removeLayer(idx)"
            />
          </div>

          <!-- path / local file / wms layers -->
          <div class="cfg-layer-row" v-if="layer.type === 'local'">
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('local_geo_file') }}</label>
              <Select
                v-model="layer.path"
                :options="localFiles"
                :placeholder="t('select_file')"
                size="small"
              />
            </div>
          </div>
          <div class="cfg-layer-row" v-else-if="layer.type === 'wms'">
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('wms_url') }}</label>
              <InputText v-model="layer.path" size="small" placeholder="https://..." />
            </div>
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('wms_layers') }}</label>
              <InputText v-model="layer.wmslayers" size="small" placeholder="layer1,layer2" />
            </div>
          </div>
          <div class="cfg-layer-row" v-else-if="layer.type === 'tiles'">
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('tiles_url') }}</label>
              <InputText v-model="layer.path" size="small" placeholder="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
            </div>
          </div>
          <div class="cfg-layer-row" v-else-if="layer.type === 'maplibre_style'">
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('maplibre_style_url') }}</label>
              <InputText v-model="layer.path" size="small" placeholder="https://..." />
            </div>
          </div>
        </div>
      </section>

      <!-- ─── Local files ───────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-header">
          <span>{{ t('local_geo_files') }}</span>
        </div>

        <div v-if="localFiles.length === 0" class="cfg-empty-msg-sm">{{ t('no_local_files') }}</div>
        <div v-else class="cfg-file-list">
          <div v-for="file in localFiles" :key="file" class="cfg-file-row">
            <i class="pi pi-file" />
            <span>{{ file }}</span>
            <Button
              icon="pi pi-trash"
              severity="danger"
              size="small"
              text
              :title="t('delete')"
              :loading="deletingFile === file"
              @click="deleteFile(file)"
            />
          </div>
        </div>

        <!-- Upload -->
        <div class="cfg-upload-row">
          <input ref="fileInput" type="file" accept=".json,.geojson,.kml,.gpx" style="display:none" @change="uploadFile" />
          <Button
            :label="t('upload_geo_file')"
            icon="pi pi-upload"
            size="small"
            outlined
            :loading="uploading"
            @click="fileInput?.click()"
            type="button"
          />
        </div>
      </section>

    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import Button    from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select    from 'primevue/select'
import Message   from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import { useI18n }  from '@/i18n'
import { api }      from '@/api'

const { t }  = useI18n()
const toast  = useToast()

const loading      = ref(false)
const saving       = ref(false)
const uploading    = ref(false)
const deletingFile = ref(null)
const error        = ref(null)

const layers     = ref([])
const localFiles = ref([])
const fileInput  = ref(null)

const dataTypes  = ['wms', 'tiles', 'local', 'maplibre_style']
const layerRoles = ['overlay', 'base']

function addLayer() {
  layers.value.push({ label: '', type: 'tiles', path: '', wmslayers: '', layertype: 'overlay' })
}

function removeLayer(idx) {
  layers.value.splice(idx, 1)
}

async function load() {
  loading.value = true
  error.value   = null
  try {
    const res = await api.get('config_ctrl', 'getGeoFaceConfig')
    if (res.status === 'error') throw new Error(t(res.code))
    layers.value     = res.layers      ?? []
    localFiles.value = res.local_files ?? []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const res = await api.post('config_ctrl', 'save_geoface_properties', layers.value)
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    saving.value = false
  }
}

async function deleteFile(filename) {
  deletingFile.value = filename
  try {
    const res = await api.get('config_ctrl', 'delete_local_geofile', { file: filename })
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })
    if (res.status === 'success') {
      localFiles.value = localFiles.value.filter(f => f !== filename)
      layers.value.forEach(l => { if (l.path === filename) l.path = '' })
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    deletingFile.value = null
  }
}

async function uploadFile(event) {
  const file = event.target.files?.[0]
  if (!file) return
  uploading.value = true
  try {
    const res = await api.upload('config_ctrl', 'uploadGeoFile', file, 'file')
    toast.add({
      severity: res.status === 'success' ? 'success' : 'error',
      summary:  t('saved'),
      detail:   api.responseMessage(res, t),
      life: 4000
    })
    if (res.status === 'success' && res.filename) {
      if (!localFiles.value.includes(res.filename)) {
        localFiles.value = [...localFiles.value, res.filename]
      }
    }
  } catch (e) {
    toast.add({ severity: 'error', summary: e.message, life: 4000 })
  } finally {
    uploading.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

onMounted(load)
</script>

<style scoped>
.cfg-panel {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.cfg-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color);
  flex-shrink: 0;
}
.cfg-panel-header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.cfg-loading-center {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  color: var(--p-text-muted-color);
}
.cfg-geoface-body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.cfg-section {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.cfg-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  border-bottom: 1px solid var(--p-content-border-color);
  padding-bottom: 0.4rem;
}
.cfg-layer-card {
  border: 1px solid var(--p-content-border-color);
  border-radius: 6px;
  padding: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  background: var(--bdus-bg);
}
.cfg-layer-row {
  display: flex;
  align-items: flex-end;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.cfg-field-group {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 140px;
}
.cfg-field-group label {
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--p-text-muted-color);
}
.cfg-file-list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.cfg-file-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.3rem 0.5rem;
  border-radius: 4px;
  font-size: 0.875rem;
}
.cfg-file-row:hover { background: var(--p-content-hover-background); }
.cfg-file-row span { flex: 1; }
.cfg-upload-row { margin-top: 0.5rem; }
.cfg-empty-msg-sm {
  font-size: 0.85rem;
  color: var(--p-text-muted-color);
  padding: 0.5rem 0;
}
</style>
