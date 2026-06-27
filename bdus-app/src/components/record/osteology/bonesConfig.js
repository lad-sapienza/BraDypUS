/**
 * Osteological bone inventory configuration.
 * Keys are standard anatomical English terms — used in the data model and SVG element IDs.
 * labelKey / categoryKey → resolved via i18n t() in components.
 *
 * bilateral: true  → has laterality (right/left pair)
 * side: 'right'|'left' → which side this element represents
 * pairId: string  → shared key for the bilateral pair (used in UI grouping)
 * category: string → anatomical region (for grouping in the legend/list)
 */
export const BONES = {
  // ── Head ─────────────────────────────────────────────────────────────────
  cranium:  { labelKey: 'osteo_bone_cranium',    bilateral: false, category: 'head' },
  face:     { labelKey: 'osteo_bone_face',        bilateral: false, category: 'head' },
  mandible: { labelKey: 'osteo_bone_mandible',    bilateral: false, category: 'head' },

  // ── Spine ─────────────────────────────────────────────────────────────────
  cervical_vertebrae: { labelKey: 'osteo_bone_cervical_vertebrae',  bilateral: false, category: 'spine' },
  thoracic_vertebrae: { labelKey: 'osteo_bone_thoracic_vertebrae',  bilateral: false, category: 'spine' },
  lumbar_vertebrae:   { labelKey: 'osteo_bone_lumbar_vertebrae',    bilateral: false, category: 'spine' },
  sacrum:             { labelKey: 'osteo_bone_sacrum',               bilateral: false, category: 'spine' },
  coccyx:             { labelKey: 'osteo_bone_coccyx',               bilateral: false, category: 'spine' },

  // ── Thorax ────────────────────────────────────────────────────────────────
  sternum:    { labelKey: 'osteo_bone_sternum', bilateral: false, category: 'thorax' },
  ribs_right: { labelKey: 'osteo_bone_ribs',    bilateral: true, side: 'right', pairId: 'ribs', category: 'thorax' },
  ribs_left:  { labelKey: 'osteo_bone_ribs',    bilateral: true, side: 'left',  pairId: 'ribs', category: 'thorax' },

  // ── Shoulder girdle ───────────────────────────────────────────────────────
  clavicle_right: { labelKey: 'osteo_bone_clavicle', bilateral: true, side: 'right', pairId: 'clavicle', category: 'shoulder' },
  clavicle_left:  { labelKey: 'osteo_bone_clavicle', bilateral: true, side: 'left',  pairId: 'clavicle', category: 'shoulder' },
  scapula_right:  { labelKey: 'osteo_bone_scapula',  bilateral: true, side: 'right', pairId: 'scapula',  category: 'shoulder' },
  scapula_left:   { labelKey: 'osteo_bone_scapula',  bilateral: true, side: 'left',  pairId: 'scapula',  category: 'shoulder' },

  // ── Upper limb right ──────────────────────────────────────────────────────
  humerus_right:        { labelKey: 'osteo_bone_humerus',        bilateral: true, side: 'right', pairId: 'humerus',        category: 'upper_limb' },
  radius_right:         { labelKey: 'osteo_bone_radius',          bilateral: true, side: 'right', pairId: 'radius',          category: 'upper_limb' },
  ulna_right:           { labelKey: 'osteo_bone_ulna',            bilateral: true, side: 'right', pairId: 'ulna',            category: 'upper_limb' },
  carpals_right:        { labelKey: 'osteo_bone_carpals',         bilateral: true, side: 'right', pairId: 'carpals',         category: 'upper_limb' },
  metacarpals_right:    { labelKey: 'osteo_bone_metacarpals',     bilateral: true, side: 'right', pairId: 'metacarpals',     category: 'upper_limb' },
  hand_phalanges_right: { labelKey: 'osteo_bone_hand_phalanges',  bilateral: true, side: 'right', pairId: 'hand_phalanges',  category: 'upper_limb' },

  // ── Upper limb left ───────────────────────────────────────────────────────
  humerus_left:        { labelKey: 'osteo_bone_humerus',        bilateral: true, side: 'left', pairId: 'humerus',        category: 'upper_limb' },
  radius_left:         { labelKey: 'osteo_bone_radius',          bilateral: true, side: 'left', pairId: 'radius',          category: 'upper_limb' },
  ulna_left:           { labelKey: 'osteo_bone_ulna',            bilateral: true, side: 'left', pairId: 'ulna',            category: 'upper_limb' },
  carpals_left:        { labelKey: 'osteo_bone_carpals',         bilateral: true, side: 'left', pairId: 'carpals',         category: 'upper_limb' },
  metacarpals_left:    { labelKey: 'osteo_bone_metacarpals',     bilateral: true, side: 'left', pairId: 'metacarpals',     category: 'upper_limb' },
  hand_phalanges_left: { labelKey: 'osteo_bone_hand_phalanges',  bilateral: true, side: 'left', pairId: 'hand_phalanges',  category: 'upper_limb' },

  // ── Pelvis ────────────────────────────────────────────────────────────────
  ilium_right:   { labelKey: 'osteo_bone_ilium',   bilateral: true, side: 'right', pairId: 'ilium',   category: 'pelvis' },
  ilium_left:    { labelKey: 'osteo_bone_ilium',   bilateral: true, side: 'left',  pairId: 'ilium',   category: 'pelvis' },
  ischium_right: { labelKey: 'osteo_bone_ischium', bilateral: true, side: 'right', pairId: 'ischium', category: 'pelvis' },
  ischium_left:  { labelKey: 'osteo_bone_ischium', bilateral: true, side: 'left',  pairId: 'ischium', category: 'pelvis' },
  pubis_right:   { labelKey: 'osteo_bone_pubis',   bilateral: true, side: 'right', pairId: 'pubis',   category: 'pelvis' },
  pubis_left:    { labelKey: 'osteo_bone_pubis',   bilateral: true, side: 'left',  pairId: 'pubis',   category: 'pelvis' },

  // ── Lower limb right ──────────────────────────────────────────────────────
  femur_right:          { labelKey: 'osteo_bone_femur',          bilateral: true, side: 'right', pairId: 'femur',          category: 'lower_limb' },
  patella_right:        { labelKey: 'osteo_bone_patella',        bilateral: true, side: 'right', pairId: 'patella',        category: 'lower_limb' },
  tibia_right:          { labelKey: 'osteo_bone_tibia',          bilateral: true, side: 'right', pairId: 'tibia',          category: 'lower_limb' },
  fibula_right:         { labelKey: 'osteo_bone_fibula',         bilateral: true, side: 'right', pairId: 'fibula',         category: 'lower_limb' },
  talus_right:          { labelKey: 'osteo_bone_talus',          bilateral: true, side: 'right', pairId: 'talus',          category: 'foot' },
  calcaneus_right:      { labelKey: 'osteo_bone_calcaneus',      bilateral: true, side: 'right', pairId: 'calcaneus',      category: 'foot' },
  tarsals_right:        { labelKey: 'osteo_bone_tarsals',        bilateral: true, side: 'right', pairId: 'tarsals',        category: 'foot' },
  metatarsals_right:    { labelKey: 'osteo_bone_metatarsals',    bilateral: true, side: 'right', pairId: 'metatarsals',    category: 'foot' },
  foot_phalanges_right: { labelKey: 'osteo_bone_foot_phalanges', bilateral: true, side: 'right', pairId: 'foot_phalanges', category: 'foot' },

  // ── Lower limb left ───────────────────────────────────────────────────────
  femur_left:          { labelKey: 'osteo_bone_femur',          bilateral: true, side: 'left', pairId: 'femur',          category: 'lower_limb' },
  patella_left:        { labelKey: 'osteo_bone_patella',        bilateral: true, side: 'left', pairId: 'patella',        category: 'lower_limb' },
  tibia_left:          { labelKey: 'osteo_bone_tibia',          bilateral: true, side: 'left', pairId: 'tibia',          category: 'lower_limb' },
  fibula_left:         { labelKey: 'osteo_bone_fibula',         bilateral: true, side: 'left', pairId: 'fibula',         category: 'lower_limb' },
  talus_left:          { labelKey: 'osteo_bone_talus',          bilateral: true, side: 'left', pairId: 'talus',          category: 'foot' },
  calcaneus_left:      { labelKey: 'osteo_bone_calcaneus',      bilateral: true, side: 'left', pairId: 'calcaneus',      category: 'foot' },
  tarsals_left:        { labelKey: 'osteo_bone_tarsals',        bilateral: true, side: 'left', pairId: 'tarsals',        category: 'foot' },
  metatarsals_left:    { labelKey: 'osteo_bone_metatarsals',    bilateral: true, side: 'left', pairId: 'metatarsals',    category: 'foot' },
  foot_phalanges_left: { labelKey: 'osteo_bone_foot_phalanges', bilateral: true, side: 'left', pairId: 'foot_phalanges', category: 'foot' },
}

export const BONE_CATEGORIES = [
  { id: 'head',       key: 'osteo_cat_head' },
  { id: 'spine',      key: 'osteo_cat_spine' },
  { id: 'thorax',     key: 'osteo_cat_thorax' },
  { id: 'shoulder',   key: 'osteo_cat_shoulder' },
  { id: 'upper_limb', key: 'osteo_cat_upper_limb' },
  { id: 'pelvis',     key: 'osteo_cat_pelvis' },
  { id: 'lower_limb', key: 'osteo_cat_lower_limb' },
  { id: 'foot',       key: 'osteo_cat_foot' },
]

export const CONSERVATION_OPTIONS = [
  { value: 'complete',    labelKey: 'osteo_cons_complete' },
  { value: 'gt50',        labelKey: 'osteo_cons_gt50' },
  { value: 'lt50',        labelKey: 'osteo_cons_lt50' },
  { value: 'fragmentary', labelKey: 'osteo_cons_fragmentary' },
  { value: 'traces',      labelKey: 'osteo_cons_traces' },
]

export const CERTAINTY_OPTIONS = [
  { value: 'certain',   labelKey: 'osteo_cert_certain' },
  { value: 'probable',  labelKey: 'osteo_cert_probable' },
  { value: 'uncertain', labelKey: 'osteo_cert_uncertain' },
]

export const LATERALITY_OPTIONS = [
  { value: 'certain',   labelKey: 'osteo_cert_certain' },
  { value: 'uncertain', labelKey: 'osteo_cert_uncertain' },
]

/** Returns the CSS class for a bone element based on its data. */
export function boneStateClass(boneData) {
  if (!boneData || boneData.present === undefined) return 'bone-undocumented'
  if (!boneData.present) return 'bone-absent'
  return `bone-${boneData.conservation ?? 'undocumented'}`
}

/** Creates an empty bone record (not-documented state). */
export function emptyBoneData() {
  return {}
}

/** Creates a new individual skeleton record. */
export function newIndividual(id) {
  return { id, label: '', notes: '', bones: {} }
}
