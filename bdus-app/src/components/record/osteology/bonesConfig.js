/**
 * Osteological bone inventory configuration.
 * Each key is the bone ID used in the data model and SVG element IDs.
 *
 * bilateral: true  → has laterality (dx/sx pair)
 * side: 'dx'|'sx' → which side this element represents
 * pairId: string  → shared key for the bilateral pair (used in UI grouping)
 * category: string → anatomical region (for grouping in the legend/list)
 */
export const BONES = {
  // ── Cranio ───────────────────────────────────────────────────────────────
  cranio:    { label: 'Cranio',    bilateral: false, category: 'testa' },
  faccia:    { label: 'Faccia',    bilateral: false, category: 'testa' },
  mandibola: { label: 'Mandibola', bilateral: false, category: 'testa' },

  // ── Colonna vertebrale ────────────────────────────────────────────────────
  vertebre_cervicali:  { label: 'Vertebre cervicali',  bilateral: false, category: 'colonna' },
  vertebre_toraciche:  { label: 'Vertebre toraciche',  bilateral: false, category: 'colonna' },
  vertebre_lombari:    { label: 'Vertebre lombari',    bilateral: false, category: 'colonna' },
  sacro:               { label: 'Sacro',               bilateral: false, category: 'colonna' },
  coccige:             { label: 'Coccige',             bilateral: false, category: 'colonna' },

  // ── Torace ────────────────────────────────────────────────────────────────
  sterno:    { label: 'Sterno',     bilateral: false, category: 'torace' },
  coste_dx:  { label: 'Coste dx',   bilateral: true, side: 'dx', pairId: 'coste',  category: 'torace' },
  coste_sx:  { label: 'Coste sx',   bilateral: true, side: 'sx', pairId: 'coste',  category: 'torace' },

  // ── Cingolo scapolare ─────────────────────────────────────────────────────
  clavicola_dx: { label: 'Clavicola dx', bilateral: true, side: 'dx', pairId: 'clavicola', category: 'spalla' },
  clavicola_sx: { label: 'Clavicola sx', bilateral: true, side: 'sx', pairId: 'clavicola', category: 'spalla' },
  scapola_dx:   { label: 'Scapola dx',   bilateral: true, side: 'dx', pairId: 'scapola',   category: 'spalla' },
  scapola_sx:   { label: 'Scapola sx',   bilateral: true, side: 'sx', pairId: 'scapola',   category: 'spalla' },

  // ── Arto superiore destro ─────────────────────────────────────────────────
  omero_dx:        { label: 'Omero dx',         bilateral: true, side: 'dx', pairId: 'omero',        category: 'arto_sup' },
  radio_dx:        { label: 'Radio dx',          bilateral: true, side: 'dx', pairId: 'radio',        category: 'arto_sup' },
  ulna_dx:         { label: 'Ulna dx',           bilateral: true, side: 'dx', pairId: 'ulna',         category: 'arto_sup' },
  carpali_dx:      { label: 'Carpali dx',        bilateral: true, side: 'dx', pairId: 'carpali',      category: 'arto_sup' },
  metacarpali_dx:  { label: 'Metacarpali dx',    bilateral: true, side: 'dx', pairId: 'metacarpali',  category: 'arto_sup' },
  falangi_mano_dx: { label: 'Falangi mano dx',   bilateral: true, side: 'dx', pairId: 'falangi_mano', category: 'arto_sup' },

  // ── Arto superiore sinistro ───────────────────────────────────────────────
  omero_sx:        { label: 'Omero sx',          bilateral: true, side: 'sx', pairId: 'omero',        category: 'arto_sup' },
  radio_sx:        { label: 'Radio sx',           bilateral: true, side: 'sx', pairId: 'radio',        category: 'arto_sup' },
  ulna_sx:         { label: 'Ulna sx',            bilateral: true, side: 'sx', pairId: 'ulna',         category: 'arto_sup' },
  carpali_sx:      { label: 'Carpali sx',         bilateral: true, side: 'sx', pairId: 'carpali',      category: 'arto_sup' },
  metacarpali_sx:  { label: 'Metacarpali sx',     bilateral: true, side: 'sx', pairId: 'metacarpali',  category: 'arto_sup' },
  falangi_mano_sx: { label: 'Falangi mano sx',    bilateral: true, side: 'sx', pairId: 'falangi_mano', category: 'arto_sup' },

  // ── Pelvi ─────────────────────────────────────────────────────────────────
  ileo_dx:   { label: 'Ileo dx',   bilateral: true, side: 'dx', pairId: 'ileo',   category: 'pelvi' },
  ileo_sx:   { label: 'Ileo sx',   bilateral: true, side: 'sx', pairId: 'ileo',   category: 'pelvi' },
  ischio_dx: { label: 'Ischio dx', bilateral: true, side: 'dx', pairId: 'ischio', category: 'pelvi' },
  ischio_sx: { label: 'Ischio sx', bilateral: true, side: 'sx', pairId: 'ischio', category: 'pelvi' },
  pube_dx:   { label: 'Pube dx',   bilateral: true, side: 'dx', pairId: 'pube',   category: 'pelvi' },
  pube_sx:   { label: 'Pube sx',   bilateral: true, side: 'sx', pairId: 'pube',   category: 'pelvi' },

  // ── Arto inferiore destro ─────────────────────────────────────────────────
  femore_dx:        { label: 'Femore dx',         bilateral: true, side: 'dx', pairId: 'femore',        category: 'arto_inf' },
  patella_dx:       { label: 'Patella dx',         bilateral: true, side: 'dx', pairId: 'patella',       category: 'arto_inf' },
  tibia_dx:         { label: 'Tibia dx',           bilateral: true, side: 'dx', pairId: 'tibia',         category: 'arto_inf' },
  fibula_dx:        { label: 'Fibula dx',          bilateral: true, side: 'dx', pairId: 'fibula',        category: 'arto_inf' },
  astragalo_dx:     { label: 'Astragalo dx',       bilateral: true, side: 'dx', pairId: 'astragalo',     category: 'piede' },
  calcagno_dx:      { label: 'Calcagno dx',        bilateral: true, side: 'dx', pairId: 'calcagno',      category: 'piede' },
  tarsali_dx:       { label: 'Tarsali dx',         bilateral: true, side: 'dx', pairId: 'tarsali',       category: 'piede' },
  metatarsali_dx:   { label: 'Metatarsali dx',     bilateral: true, side: 'dx', pairId: 'metatarsali',   category: 'piede' },
  falangi_piede_dx: { label: 'Falangi piede dx',   bilateral: true, side: 'dx', pairId: 'falangi_piede', category: 'piede' },

  // ── Arto inferiore sinistro ───────────────────────────────────────────────
  femore_sx:        { label: 'Femore sx',          bilateral: true, side: 'sx', pairId: 'femore',        category: 'arto_inf' },
  patella_sx:       { label: 'Patella sx',          bilateral: true, side: 'sx', pairId: 'patella',       category: 'arto_inf' },
  tibia_sx:         { label: 'Tibia sx',            bilateral: true, side: 'sx', pairId: 'tibia',         category: 'arto_inf' },
  fibula_sx:        { label: 'Fibula sx',           bilateral: true, side: 'sx', pairId: 'fibula',        category: 'arto_inf' },
  astragalo_sx:     { label: 'Astragalo sx',        bilateral: true, side: 'sx', pairId: 'astragalo',     category: 'piede' },
  calcagno_sx:      { label: 'Calcagno sx',         bilateral: true, side: 'sx', pairId: 'calcagno',      category: 'piede' },
  tarsali_sx:       { label: 'Tarsali sx',          bilateral: true, side: 'sx', pairId: 'tarsali',       category: 'piede' },
  metatarsali_sx:   { label: 'Metatarsali sx',      bilateral: true, side: 'sx', pairId: 'metatarsali',   category: 'piede' },
  falangi_piede_sx: { label: 'Falangi piede sx',    bilateral: true, side: 'sx', pairId: 'falangi_piede', category: 'piede' },
}

export const CONSERVATION_OPTIONS = [
  { value: 'completo',      label: 'Completo' },
  { value: 'gt50',          label: '>50%' },
  { value: 'lt50',          label: '<50%' },
  { value: 'frammentario',  label: 'Frammentario' },
  { value: 'tracce',        label: 'Tracce' },
]

export const CERTAINTY_OPTIONS = [
  { value: 'certa',     label: 'Certa' },
  { value: 'probabile', label: 'Probabile' },
  { value: 'incerta',   label: 'Incerta' },
]

export const LATERALITY_OPTIONS = [
  { value: 'certa',   label: 'Certa' },
  { value: 'incerta', label: 'Incerta' },
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
