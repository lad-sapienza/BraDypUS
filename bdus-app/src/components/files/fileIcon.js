import { FileExcelOutlined, FileOutlined, FilePdfOutlined, FileWordOutlined, FileZipOutlined, SoundOutlined, VideoCameraOutlined, EditOutlined } from '@ant-design/icons-vue'

/** The icon standing for a file that has no thumbnail. */
export function fileIcon(ext) {
  const e = (ext ?? '').toLowerCase()
  if (['pdf'].includes(e))                       return FilePdfOutlined
  if (['doc', 'docx', 'odt', 'rtf'].includes(e)) return FileWordOutlined
  if (['xls', 'xlsx', 'ods'].includes(e))        return FileExcelOutlined
  if (['mp3', 'wav', 'ogg', 'wma'].includes(e))  return SoundOutlined
  if (['mp4', 'mov', 'avi', 'mkv'].includes(e))  return VideoCameraOutlined
  if (['zip', 'rar', 'tar', 'gz'].includes(e))   return FileZipOutlined
  if (['svg', 'ai', 'eps'].includes(e))          return EditOutlined
  return FileOutlined
}
