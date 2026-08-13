<template>
  <div class="cfg-panel">
    <div class="cfg-panel-header">
      <h2><CompassOutlined /> {{ t('geoface') }}</h2>
      <AButton type="primary" size="small" :loading="saving" @click="save">
        <template #icon><SaveOutlined /></template>
        {{ t('save') }}
      </AButton>
    </div>

    <div v-if="loading" class="cfg-loading-center">
      <LoadingOutlined spin />
    </div>

    <AAlert v-if="error" type="error" :message="error" :closable="false" show-icon />

    <div v-if="!loading" class="cfg-geoface-body">

      <!-- ─── Layers table ──────────────────────────────────────── -->
      <section class="cfg-section">
        <div class="cfg-section-header">
          <span>{{ t('layers') }}</span>
          <AButton size="small" @click="addLayer">
            <template #icon><PlusOutlined /></template>
            {{ t('add_layer') }}
          </AButton>
        </div>

        <div v-if="layers.length === 0" class="cfg-empty-msg-sm">{{ t('no_layers') }}</div>

        <div v-for="(layer, idx) in layers" :key="idx" class="cfg-layer-card">
          <div class="cfg-layer-row">
            <div class="cfg-field-group">
              <label>{{ t('label') }}</label>
              <AInput v-model:value="layer.label" size="small" />
            </div>
            <div class="cfg-field-group">
              <label>{{ t('layer_type') }}</label>
              <ASelect
                v-model:value="layer.type"
                :options="dataTypeOptions"
                size="small"
              />
            </div>
            <div class="cfg-field-group">
              <label>{{ t('map_layer_role') }}</label>
              <ASelect
                v-model:value="layer.layertype"
                :options="layerRoleOptions"
                size="small"
              />
            </div>
            <AButton type="text" danger size="small" :title="t('delete')" @click="removeLayer(idx)">
              <template #icon><DeleteOutlined /></template>
            </AButton>
          </div>

          <!-- path / local file / wms layers -->
          <div class="cfg-layer-row" v-if="layer.type === 'local'">
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('local_geo_file') }}</label>
              <ASelect
                v-model:value="layer.path"
                :options="localFileOptions"
                :placeholder="t('select_file')"
                size="small"
              />
            </div>
          </div>
          <div class="cfg-layer-row" v-else-if="layer.type === 'wms'">
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('wms_url') }}</label>
              <AInput v-model:value="layer.path" size="small" placeholder="https://..." />
            </div>
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('wms_layers') }}</label>
              <AInput v-model:value="layer.wmslayers" size="small" placeholder="layer1,layer2" />
            </div>
          </div>
          <div class="cfg-layer-row" v-else-if="layer.type === 'tiles'">
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('tiles_url') }}</label>
              <AInput v-model:value="layer.path" size="small" placeholder="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
            </div>
          </div>
          <div class="cfg-layer-row" v-else-if="layer.type === 'maplibre_style'">
            <div class="cfg-field-group" style="flex:1">
              <label>{{ t('maplibre_style_url') }}</label>
              <AInput v-model:value="layer.path" size="small" placeholder="https://..." />
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
            <FileOutlined />
            <span>{{ file }}</span>
            <AButton
              type="text"
              danger
              size="small"
              :title="t('delete')"
              :loading="deletingFile === file"
              @click="deleteFile(file)"
            ><template #icon><DeleteOutlined /></template></AButton>
          </div>
        </div>

        <!-- Upload -->
        <div class="cfg-upload-row">
          <input ref="fileInput" type="file" accept=".json,.geojson,.kml,.gpx" style="display:none" @change="uploadFile" />
          <AButton size="small" :loading="uploading" @click="fileInput?.click()">
            <template #icon><UploadOutlined /></template>
            {{ t('upload_geo_file') }}
          </AButton>
        </div>
      </section>

    </div>
  </div>
</template>

<script setup>
import { CompassOutlined, DeleteOutlined, FileOutlined, LoadingOutlined, PlusOutlined, SaveOutlined, UploadOutlined } from '@ant-design/icons-vue'
import { ref, computed, onMounted } from 'vue'
import { Button as AButton, Input as AInput, Select as ASelect, Alert as AAlert } from 'ant-design-vue'
import { useToast } from '@/composables/useNotify'
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
const dataTypeOptions  = dataTypes.map(v => ({ value: v, label: v }))
const layerRoleOptions = layerRoles.map(v => ({ value: v, label: v }))
const localFileOptions = computed(() => localFiles.value.map(v => ({ value: v, label: v })))

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
    const res = await api.get('/api/config/geoface')
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
    const res = await api.put('/api/config/geoface', layers.value)
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
    const res = await api.delete('/api/config/geofile', { file: filename })
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
    const res = await api.upload('/api/config/geofile', file, 'file')
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
