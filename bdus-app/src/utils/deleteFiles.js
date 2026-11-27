// What to do with the files only a record uses when that record is deleted. Pure, so it can be
// unit-tested with `node --test`.
//
// The server lists them (GET /api/record/{tb}/files-before-delete) and takes the answer as
// `files: { "<fileId>": "delete" | "keep" }` on DELETE /api/record/{tb}/{id}. Anything that is not
// "delete" means keep (the file becomes an orphan): a file is never lost without being asked.

export const KEEP = 'keep'
export const DELETE = 'delete'

/** One choice per file, all the same (the dialog starts on "keep"). */
export function choicesFor(files, value = KEEP) {
  const v = value === DELETE ? DELETE : KEEP
  return Object.fromEntries((files ?? []).map(f => [f.id, v]))
}

/** Sets every file to the same choice (the "all" buttons). */
export function setAll(choices, value) {
  const v = value === DELETE ? DELETE : KEEP
  for (const id of Object.keys(choices ?? {})) choices[id] = v
  return choices
}

/** The `files` request parameter: exactly the listed files, anything but "delete" as "keep". */
export function filesParam(files, choices) {
  const out = {}
  for (const f of files ?? []) out[f.id] = choices?.[f.id] === DELETE ? DELETE : KEEP
  return out
}

/** How many will go and how many will stay, for the confirm button and the dialog text. */
export function summary(files, choices) {
  const list = files ?? []
  const del  = list.filter(f => choices?.[f.id] === DELETE).length
  return { delete: del, keep: list.length - del, total: list.length }
}
