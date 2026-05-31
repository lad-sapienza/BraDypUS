import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'BraDypUS',
  description: 'Web database system for humanities research',
  lang: 'en-US',

  head: [
    ['link', { rel: 'icon', href: '/favicon.ico' }],
    ['meta', { name: 'theme-color', content: '#1C2128' }],
  ],

  // Ignore: API reference (Scalar embed), localhost dev links
  ignoreDeadLinks: [
    /\/api-reference/,
    /localhost/,
  ],

  themeConfig: {
    logo: '/images/bdus.svg',
    siteTitle: 'BraDypUS',

    nav: [
      { text: 'Guide',         link: '/guide/',           activeMatch: '/guide/' },
      { text: 'Developer',     link: '/dev/',             activeMatch: '/dev/' },
      { text: 'API Reference', link: '/api-reference/',   activeMatch: '/api-reference/' },
    ],

    sidebar: {
      '/guide/': guideSidebar(),
      '/dev/':   devSidebar(),
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/lad-sapienza/bdus-api' },
    ],

    editLink: {
      pattern: 'https://github.com/lad-sapienza/bdus-docs/edit/v5/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the <a href="https://www.gnu.org/licenses/agpl-3.0.html">AGPL-3.0 License</a>.',
      copyright: 'Copyright © 2007-present <a href="https://github.com/jbogdani">Julian Bogdani</a> — LAD, Sapienza University of Rome',
    },

    search: {
      provider: 'local',
    },
  },
})

// ── Guide sidebar ─────────────────────────────────────────────────────────────

function guideSidebar() {
  return [
    {
      text: 'Introduction',
      items: [
        { text: 'Overview',            link: '/guide/' },
        { text: 'Conventions',         link: '/guide/conventions' },
        { text: 'App tree structure',  link: '/guide/tree-structure' },
        { text: 'Changelog',           link: '/guide/changelog' },
      ],
    },
    {
      text: 'System requirements',
      collapsed: true,
      items: [
        { text: 'Requirements',                    link: '/guide/environment/system-requirements' },
        { text: 'Setup on Windows',                link: '/guide/environment/setup-windows' },
        { text: 'Setup on macOS / Linux',          link: '/guide/environment/setup-nix' },
        { text: 'Knowledge base',                  link: '/guide/environment/knowledge-base' },
      ],
    },
    {
      text: 'Installation',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/install/' },
        { text: 'Manual download',     link: '/guide/install/manual-download' },
        { text: 'Via terminal / git',  link: '/guide/install/terminal' },
        { text: 'Updating',            link: '/guide/install/update' },
        { text: 'Uninstalling',        link: '/guide/install/uninstall' },
      ],
    },
    {
      text: 'Creating an application',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/create-app/' },
        { text: 'App anatomy',         link: '/guide/create-app/new-app-anatomy' },
        { text: 'Troubleshooting',     link: '/guide/create-app/troubleshooting' },
      ],
    },
    {
      text: 'Setup',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/setup/' },
        { text: 'App settings',        link: '/guide/setup/main-app-config' },
        { text: 'Tables',              link: '/guide/setup/create-table-sites' },
        { text: 'Fields',              link: '/guide/setup/adding-columns' },
        { text: 'Field preview',       link: '/guide/setup/preview-config' },
        { text: 'Table relations',     link: '/guide/setup/relations' },
        { text: 'Vocabularies',        link: '/guide/setup/vocabularies' },
        { text: 'Users & privileges',  link: '/guide/setup/users' },
        { text: 'Validation',          link: '/guide/setup/finalizing-setup' },
        { text: 'Advices & hacks',     link: '/guide/setup/advices-and-hacks' },
      ],
    },
    {
      text: 'Using the application',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/usage/' },
        { text: 'Records (CRUD)',       link: '/guide/usage/crud' },
        { text: 'Search & filter',     link: '/guide/usage/search' },
        { text: 'Data export',         link: '/guide/usage/export' },
        { text: 'Find & replace',      link: '/guide/usage/find-replace' },
        { text: 'Import data',         link: '/guide/usage/import' },
        { text: 'Database backup',     link: '/guide/usage/backup' },
        { text: 'Version history',     link: '/guide/usage/versions' },
        { text: 'Deleted records',     link: '/guide/usage/deleted-records' },
        { text: 'Charts',              link: '/guide/usage/charts' },
        { text: 'Bibliography (Zotero)', link: '/guide/usage/zotero' },
      ],
    },
    {
      text: 'System plugins',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/system-plugins/' },
        { text: 'Geodata & GeoFace',   link: '/guide/system-plugins/geodata' },
        { text: 'Harris Matrix (RS)',   link: '/guide/system-plugins/rs' },
        { text: 'Zotero',              link: '/guide/system-plugins/zotero' },
        { text: 'Fuzzy date',          link: '/guide/system-plugins/fuzzy-date' },
      ],
    },
    {
      text: 'Print templates',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/template-system/' },
        { text: 'Create a template',   link: '/guide/template-system/create-new-template' },
        { text: 'Template example',    link: '/guide/template-system/template-example' },
      ],
    },
    {
      text: 'Deploy',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/deploy/' },
      ],
    },
    {
      text: 'REST API',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/api/' },
      ],
    },
    {
      text: 'Reference',
      items: [
        { text: 'Vocabulary',          link: '/guide/vocabulary' },
      ],
    },
  ]
}

// ── Developer sidebar ─────────────────────────────────────────────────────────

function devSidebar() {
  return [
    {
      text: 'Overview',
      items: [
        { text: 'Developer guide',     link: '/dev/' },
      ],
    },
    {
      text: 'Architecture',
      items: [
        { text: 'Bootstrapping & routing', link: '/dev/architecture' },
        { text: 'lib/ namespace map',      link: '/dev/lib-map' },
        { text: 'Controllers directory',   link: '/dev/modules' },
      ],
    },
    {
      text: 'Backend layers',
      items: [
        { text: 'Database layer',       link: '/dev/database' },
        { text: 'SQL & filter layer',   link: '/dev/sql-layer' },
        { text: 'Record lifecycle',     link: '/dev/record-lifecycle' },
        { text: 'Config & UAC',         link: '/dev/config' },
      ],
    },
    {
      text: 'Features',
      items: [
        { text: 'OAuth2 / SSO',        link: '/dev/oauth' },
        { text: 'Widget API',          link: '/dev/widget-api' },
        { text: 'Zotero integration',  link: '/dev/zotero' },
      ],
    },
    {
      text: 'Frontend',
      items: [
        { text: 'Vue 3 app',           link: '/dev/frontend' },
      ],
    },
    {
      text: 'Contributing',
      items: [
        { text: 'Testing',             link: '/dev/testing' },
        { text: 'DB migrations',       link: '/dev/migrations' },
      ],
    },
  ]
}
