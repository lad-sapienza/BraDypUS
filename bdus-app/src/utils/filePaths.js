// Where a file lives. Pure (no imports) so it can be unit-tested with `node --test`.
//
// A file is addressed by its random `uuid`, never by its integer `id`: the
// uuid is the name of the binary on disk and the key of every derived (resized)
// version. The integer id stays the internal key for everything else
// (PATCH/DELETE /api/file/{id}, links, sorting).

// Extensions the server can derive thumbnails from (Image\Derivative::SOURCE_EXTENSIONS).
export const DERIVABLE = ['jpg', 'jpeg', 'png', 'gif', 'webp']

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

export function hasUuid(f) {
  return typeof f?.uuid === 'string' && UUID.test(f.uuid)
}

/**
 * Path of the original binary, relative to the site root
 * (`projects/{app}/files/{uuid}.{ext}`), or null when the file has no uuid.
 */
export function originalPath(app, f) {
  if (!hasUuid(f) || !app) return null
  const ext = String(f.ext ?? '').toLowerCase()
  return `projects/${app}/files/${f.uuid}${ext ? '.' + ext : ''}`
}

/**
 * API path of the system `thumb` version (`/api/file/thumb/{uuid}.webp`), or
 * null when it cannot exist: the file has no uuid, or its type cannot be derived.
 */
export function thumbPath(f) {
  if (!hasUuid(f)) return null
  const ext = String(f.ext ?? '').toLowerCase()
  if (!DERIVABLE.includes(ext)) return null
  return `/api/file/thumb/${f.uuid}.webp`
}
