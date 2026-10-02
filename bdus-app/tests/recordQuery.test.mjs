import test from 'node:test'
import assert from 'node:assert/strict'
import { SEARCH, searchParams, listBody, geofaceQuery } from '../src/utils/recordQuery.js'

const base = { fastSearch: '', expertQuery: '', activeFilter: null }

test('searchParams: one shape per search mode', () => {
  assert.deepEqual(searchParams({ ...base, activeSearch: null }), { search_type: 'all', search: '' })
  assert.deepEqual(searchParams({ ...base, activeSearch: SEARCH.FAST, fastSearch: 'US01' }), { search_type: 'fast', search: 'US01' })
  assert.deepEqual(
    searchParams({ ...base, activeSearch: SEARCH.EXPERT, expertQuery: "tipo = 'Taglio'" }),
    { search_type: 'sqlExpert', querytext: "tipo = 'Taglio'", join: '' }
  )
  const filter = { tipo: { _eq: 'Strato' } }
  for (const mode of [SEARCH.ADVANCED, SEARCH.FILTER]) {
    assert.deepEqual(searchParams({ ...base, activeSearch: mode, activeFilter: filter }), { filter })
  }
})

test('searchParams ignores state that belongs to another mode', () => {
  // a stale text box / filter must not leak into a different mode's request
  assert.deepEqual(searchParams({ activeSearch: SEARCH.EXPERT, fastSearch: 'x', expertQuery: 'a=1', activeFilter: { a: 1 } }),
    { search_type: 'sqlExpert', querytext: 'a=1', join: '' })
  assert.deepEqual(searchParams({ activeSearch: null, fastSearch: 'x', expertQuery: 'a=1', activeFilter: { a: 1 } }),
    { search_type: 'all', search: '' })
})

test('listBody: paging + sort + search, columns only when chosen', () => {
  const search = { search_type: 'all', search: '' }
  assert.deepEqual(listBody({ page: 2, perPage: 30, sortField: null, sortDir: 'asc', search, columns: [] }), {
    page: 2, per_page: 30, sort_field: '', sort_dir: 'asc', search_type: 'all', search: '',
  })
  assert.deepEqual(listBody({ page: 1, perPage: 15, sortField: 'sigla', sortDir: 'desc', search, columns: ['sigla', 'tipo'] }), {
    page: 1, per_page: 15, sort_field: 'sigla', sort_dir: 'desc', search_type: 'all', search: '', columns: ['sigla', 'tipo'],
  })
})

test('geofaceQuery forwards what narrows the list', () => {
  const filter = { tipo: { _eq: 'Strato' } }
  assert.deepEqual(geofaceQuery({ ...base, activeSearch: SEARCH.ADVANCED, activeFilter: filter }), { filter: JSON.stringify(filter) })
  assert.deepEqual(geofaceQuery({ ...base, activeSearch: SEARCH.EXPERT, expertQuery: 'a=1' }), { search_type: 'sqlExpert', querytext: 'a=1' })
  assert.deepEqual(geofaceQuery({ ...base, activeSearch: SEARCH.FAST, fastSearch: 'x' }), {})
  assert.deepEqual(geofaceQuery({ ...base, activeSearch: SEARCH.ADVANCED, activeFilter: null }), {})
})
