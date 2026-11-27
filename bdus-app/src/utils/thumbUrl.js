import { apiUrl } from '@/api'
import { thumbPath } from '@/utils/filePaths'

/**
 * URL of the system `thumb` version of an image file (generated on first
 * request, cached server-side). Other image types (svg, avif, …) cannot be
 * derived: for those the caller keeps using the original.
 */
export function thumbUrl(f, fallback) {
  const path = thumbPath(f)
  return path ? apiUrl(path) : fallback
}
