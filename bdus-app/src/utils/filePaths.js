// Where a file is read from. Pure (no imports) so it can be unit-tested with `node --test`.
//
// A file is addressed by its random `uuid`, never by its integer `id`. The integer id stays the
// internal key for everything else (PATCH/DELETE /api/file/{id}, links, sorting).
//
// Both addresses are API paths (the caller prefixes the application with apiUrl()):
//   original  GET /api/file/{uuid}                    — needs a signed-in principal who may read it;
//                                                       a browser tag authenticates with the session cookie
//   thumbnail GET /api/file/thumb/{uuid}.webp         — see Image\Presets for who may see which preset

// Extensions the server can derive thumbnails from (Image\Derivative::SOURCE_EXTENSIONS).
export const DERIVABLE = ['jpg', 'jpeg', 'png', 'gif', 'webp']

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

export function hasUuid(f) {
  return typeof f?.uuid === 'string' && UUID.test(f.uuid)
}

/** API path of the original binary (`/api/file/{uuid}`), or null when the file has no uuid. */
export function originalPath(f) {
  return hasUuid(f) ? `/api/file/${f.uuid}` : null
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
