/**
 * Osteological bone inventory configuration.
 * Keys are standard anatomical English terms — used in the data model and SVG element IDs.
 * Labels are user-facing (Italian) and can be moved to i18n in the future.
 *
 * bilateral: true  → has laterality (right/left pair)
 * side: 'right'|'left' → which side this element represents
 * pairId: string  → shared key for the bilateral pair (used in UI grouping)
 * category: string → anatomical region (for grouping in the legend/list)
 */
export const BONES = {
  // ── Head ─────────────────────────────────────────────────────────────────
  cranium:  { label: 'Cranio',    bilateral: false, category: 'head' },
  face:     { label: 'Faccia',    bilateral: false, category: 'head' },
  mandible: { label: 'Mandibola', bilateral: false, category: 'head' },

  // ── Spine ─────────────────────────────────────────────────────────────────
  cervical_vertebrae: { label: 'Vertebre cervicali',  bilateral: false, category: 'spine' },
  thoracic_vertebrae: { label: 'Vertebre toraciche',  bilateral: false, category: 'spine' },
  lumbar_vertebrae:   { label: 'Vertebre lombari',    bilateral: false, category: 'spine' },
  sacrum:             { label: 'Sacro',               bilateral: false, category: 'spine' },
  coccyx:             { label: 'Coccige',             bilateral: false, category: 'spine' },

  // ── Thorax ────────────────────────────────────────────────────────────────
  sternum:    { label: 'Sterno',   bilateral: false, category: 'thorax' },
  ribs_right: { label: 'Coste dx', bilateral: true, side: 'right', pairId: 'ribs', category: 'thorax' },
  ribs_left:  { label: 'Coste sx', bilateral: true, side: 'left',  pairId: 'ribs', category: 'thorax' },

  // ── Shoulder girdle ───────────────────────────────────────────────────────
  clavicle_right: { label: 'Clavicola dx', bilateral: true, side: 'right', pairId: 'clavicle', category: 'shoulder' },
  clavicle_left:  { label: 'Clavicola sx', bilateral: true, side: 'left',  pairId: 'clavicle', category: 'shoulder' },
  scapula_right:  { label: 'Scapola dx',   bilateral: true, side: 'right', pairId: 'scapula',  category: 'shoulder' },
  scapula_left:   { label: 'Scapola sx',   bilateral: true, side: 'left',  pairId: 'scapula',  category: 'shoulder' },

  // ── Upper limb right ──────────────────────────────────────────────────────
  humerus_right:        { label: 'Omero dx',        bilateral: true, side: 'right', pairId: 'humerus',        category: 'upper_limb' },
  radius_right:         { label: 'Radio dx',         bilateral: true, side: 'right', pairId: 'radius',         category: 'upper_limb' },
  ulna_right:           { label: 'Ulna dx',          bilateral: true, side: 'right', pairId: 'ulna',           category: 'upper_limb' },
  carpals_right:        { label: 'Carpali dx',       bilateral: true, side: 'right', pairId: 'carpals',        category: 'upper_limb' },
  metacarpals_right:    { label: 'Metacarpali dx',   bilateral: true, side: 'right', pairId: 'metacarpals',    category: 'upper_limb' },
  hand_phalanges_right: { label: 'Falangi mano dx',  bilateral: true, side: 'right', pairId: 'hand_phalanges', category: 'upper_limb' },

  // ── Upper limb left ───────────────────────────────────────────────────────
  humerus_left:        { label: 'Omero sx',       bilateral: true, side: 'left', pairId: 'humerus',        category: 'upper_limb' },
  radius_left:         { label: 'Radio sx',        bilateral: true, side: 'left', pairId: 'radius',         category: 'upper_limb' },
  ulna_left:           { label: 'Ulna sx',         bilateral: true, side: 'left', pairId: 'ulna',           category: 'upper_limb' },
  carpals_left:        { label: 'Carpali sx',      bilateral: true, side: 'left', pairId: 'carpals',        category: 'upper_limb' },
  metacarpals_left:    { label: 'Metacarpali sx',  bilateral: true, side: 'left', pairId: 'metacarpals',    category: 'upper_limb' },
  hand_phalanges_left: { label: 'Falangi mano sx', bilateral: true, side: 'left', pairId: 'hand_phalanges', category: 'upper_limb' },

  // ── Pelvis ────────────────────────────────────────────────────────────────
  ilium_right:   { label: 'Ileo dx',   bilateral: true, side: 'right', pairId: 'ilium',   category: 'pelvis' },
  ilium_left:    { label: 'Ileo sx',   bilateral: true, side: 'left',  pairId: 'ilium',   category: 'pelvis' },
  ischium_right: { label: 'Ischio dx', bilateral: true, side: 'right', pairId: 'ischium', category: 'pelvis' },
  ischium_left:  { label: 'Ischio sx', bilateral: true, side: 'left',  pairId: 'ischium', category: 'pelvis' },
  pubis_right:   { label: 'Pube dx',   bilateral: true, side: 'right', pairId: 'pubis',   category: 'pelvis' },
  pubis_left:    { label: 'Pube sx',   bilateral: true, side: 'left',  pairId: 'pubis',   category: 'pelvis' },

  // ── Lower limb right ──────────────────────────────────────────────────────
  femur_right:          { label: 'Femore dx',       bilateral: true, side: 'right', pairId: 'femur',          category: 'lower_limb' },
  patella_right:        { label: 'Patella dx',       bilateral: true, side: 'right', pairId: 'patella',        category: 'lower_limb' },
  tibia_right:          { label: 'Tibia dx',         bilateral: true, side: 'right', pairId: 'tibia',          category: 'lower_limb' },
  fibula_right:         { label: 'Fibula dx',        bilateral: true, side: 'right', pairId: 'fibula',         category: 'lower_limb' },
  talus_right:          { label: 'Astragalo dx',     bilateral: true, side: 'right', pairId: 'talus',          category: 'foot' },
  calcaneus_right:      { label: 'Calcagno dx',      bilateral: true, side: 'right', pairId: 'calcaneus',      category: 'foot' },
  tarsals_right:        { label: 'Tarsali dx',       bilateral: true, side: 'right', pairId: 'tarsals',        category: 'foot' },
  metatarsals_right:    { label: 'Metatarsali dx',   bilateral: true, side: 'right', pairId: 'metatarsals',    category: 'foot' },
  foot_phalanges_right: { label: 'Falangi piede dx', bilateral: true, side: 'right', pairId: 'foot_phalanges', category: 'foot' },

  // ── Lower limb left ───────────────────────────────────────────────────────
  femur_left:          { label: 'Femore sx',       bilateral: true, side: 'left', pairId: 'femur',          category: 'lower_limb' },
  patella_left:        { label: 'Patella sx',       bilateral: true, side: 'left', pairId: 'patella',        category: 'lower_limb' },
  tibia_left:          { label: 'Tibia sx',         bilateral: true, side: 'left', pairId: 'tibia',          category: 'lower_limb' },
  fibula_left:         { label: 'Fibula sx',        bilateral: true, side: 'left', pairId: 'fibula',         category: 'lower_limb' },
  talus_left:          { label: 'Astragalo sx',     bilateral: true, side: 'left', pairId: 'talus',          category: 'foot' },
  calcaneus_left:      { label: 'Calcagno sx',      bilateral: true, side: 'left', pairId: 'calcaneus',      category: 'foot' },
  tarsals_left:        { label: 'Tarsali sx',       bilateral: true, side: 'left', pairId: 'tarsals',        category: 'foot' },
  metatarsals_left:    { label: 'Metatarsali sx',   bilateral: true, side: 'left', pairId: 'metatarsals',    category: 'foot' },
  foot_phalanges_left: { label: 'Falangi piede sx', bilateral: true, side: 'left', pairId: 'foot_phalanges', category: 'foot' },
}

export const CONSERVATION_OPTIONS = [
  { value: 'complete',    label: 'Completo' },
  { value: 'gt50',        label: '>50%' },
  { value: 'lt50',        label: '<50%' },
  { value: 'fragmentary', label: 'Frammentario' },
  { value: 'traces',      label: 'Tracce' },
]

export const CERTAINTY_OPTIONS = [
  { value: 'certain',   label: 'Certa' },
  { value: 'probable',  label: 'Probabile' },
  { value: 'uncertain', label: 'Incerta' },
]

export const LATERALITY_OPTIONS = [
  { value: 'certain',   label: 'Certa' },
  { value: 'uncertain', label: 'Incerta' },
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

/** Creates a fresh individual with empty bones. */
export function newIndividual(id) {
  return { id, label: '', notes: '', bones: {} }
}
