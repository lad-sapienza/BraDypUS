import { apiUrl } from '@/api'

// Extensions the server can derive thumbnails from (Image\Derivative::SOURCE_EXTENSIONS).
const DERIVABLE = ['jpg', 'jpeg', 'png', 'gif', 'webp']

/**
 * URL of the system `thumb` version of an image file (generated on first
 * request, cached server-side). Other image types (svg, avif, …) cannot be
 * derived: for those the caller keeps using the original.
 */
export function thumbUrl(f, fallback) {
  const ext = String(f.ext ?? '').toLowerCase()
  if (!DERIVABLE.includes(ext)) return fallback
  return apiUrl(`/api/file/thumb/${f.id}.webp`)
}
