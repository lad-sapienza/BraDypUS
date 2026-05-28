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
        { text: 'Main app config',     link: '/guide/setup/main-app-config' },
        { text: 'Creating a table',    link: '/guide/setup/create-table-sites' },
        { text: 'Adding columns',      link: '/guide/setup/adding-columns' },
        { text: 'Vocabularies',        link: '/guide/setup/vocabularies' },
        { text: 'Other data tables',   link: '/guide/setup/other-data-tables' },
        { text: 'Table order',         link: '/guide/setup/changing-table-order' },
        { text: 'Finalizing',          link: '/guide/setup/finalizing-setup' },
        { text: 'Config preview',      link: '/guide/setup/preview-config' },
        { text: 'Advices & hacks',     link: '/guide/setup/advices-and-hacks' },
      ],
    },
    {
      text: 'Using the application',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/usage/' },
        { text: 'CRUD',                link: '/guide/usage/crud' },
        { text: 'Find & replace',      link: '/guide/usage/find-replace' },
        { text: 'Data export',         link: '/guide/usage/export' },
        { text: 'Database backup',     link: '/guide/usage/backup' },
        { text: 'System translation',  link: '/guide/usage/system-translation' },
        { text: 'User preferences',    link: '/guide/usage/user-preferences' },
      ],
    },
    {
      text: 'Template system',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/template-system/' },
        { text: 'Create a template',   link: '/guide/template-system/create-new-template' },
        { text: 'The print object',    link: '/guide/template-system/print-object' },
        { text: 'Template example',    link: '/guide/template-system/template-example' },
      ],
    },
    {
      text: 'System plugins',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/system-plugins/' },
        { text: 'Geodata & GeoFace',   link: '/guide/system-plugins/geodata' },
        { text: 'Stratigraphic RS',    link: '/guide/system-plugins/rs' },
      ],
    },
    {
      text: 'Deploy',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/deploy/' },
        { text: 'Infinityfree',        link: '/guide/deploy/infinityfree' },
      ],
    },
    {
      text: 'ShortSQL API',
      collapsed: true,
      items: [
        { text: 'Overview',            link: '/guide/api/' },
        { text: 'ShortSQL syntax',     link: '/guide/api/shortsql' },
        { text: 'Remarks',             link: '/guide/api/remarks-on-shortsql' },
        { text: 'Examples',            link: '/guide/api/shortsql-examples' },
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
        { text: 'Modules directory',       link: '/dev/modules' },
      ],
    },
    {
      text: 'Backend layers',
      items: [
        { text: 'Database layer',      link: '/dev/database' },
        { text: 'SQL layer (ShortSQL)', link: '/dev/sql-layer' },
        { text: 'Record lifecycle',    link: '/dev/record-lifecycle' },
        { text: 'Config & UAC',        link: '/dev/config' },
      ],
    },
    {
      text: 'Features',
      items: [
        { text: 'OAuth2 / SSO',        link: '/dev/oauth' },
        { text: 'Widget API',          link: '/dev/widget-api' },
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
