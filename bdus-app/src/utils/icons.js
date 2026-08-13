// resolveIcon — looks up the Ant Design Vue icon component for a short name
// (e.g. 'database', 'pi-database' or 'pi pi-database', for compatibility with
// data that still carries the old PrimeIcons-style token). Used wherever an
// icon is chosen dynamically from a string — nav items, command palette
// entries, confirm-dialog icons — rather than written directly in a template.
import {
  ApartmentOutlined,
  AppstoreOutlined,
  BgColorsOutlined,
  BookOutlined,
  BulbFilled,
  BulbOutlined,
  ClockCircleOutlined,
  CloudServerOutlined,
  CodeOutlined,
  CompassOutlined,
  DatabaseOutlined,
  DeleteOutlined,
  DownloadOutlined,
  FileImageOutlined,
  GoogleOutlined,
  HistoryOutlined,
  HomeOutlined,
  IdcardOutlined,
  InfoCircleOutlined,
  LogoutOutlined,
  PlusOutlined,
  SaveOutlined,
  SearchOutlined,
  SettingOutlined,
  StopOutlined,
  TableOutlined,
  TeamOutlined,
  TranslationOutlined,
  UnorderedListOutlined,
  UserOutlined,
  WarningOutlined,
} from '@ant-design/icons-vue'

const ICONS = {
  ban: StopOutlined,
  book: BookOutlined,
  clock: ClockCircleOutlined,
  cog: SettingOutlined,
  database: DatabaseOutlined,
  download: DownloadOutlined,
  'exclamation-triangle': WarningOutlined,
  google: GoogleOutlined,
  history: HistoryOutlined,
  home: HomeOutlined,
  'id-card': IdcardOutlined,
  images: FileImageOutlined,
  'info-circle': InfoCircleOutlined,
  language: TranslationOutlined,
  list: UnorderedListOutlined,
  map: CompassOutlined,
  moon: BulbOutlined,
  palette: BgColorsOutlined,
  plus: PlusOutlined,
  save: SaveOutlined,
  search: SearchOutlined,
  'search-plus': SearchOutlined,
  server: CloudServerOutlined,
  'sign-out': LogoutOutlined,
  sitemap: ApartmentOutlined,
  sun: BulbFilled,
  table: TableOutlined,
  'th-large': AppstoreOutlined,
  code: CodeOutlined,
  trash: DeleteOutlined,
  'user-edit': UserOutlined,
  users: TeamOutlined,
}

export function resolveIcon(name) {
  if (!name) return null
  const key = name.replace(/^pi\s+pi-/, '').replace(/^pi-/, '')
  return ICONS[key] || null
}
