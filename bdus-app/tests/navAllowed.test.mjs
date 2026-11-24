import test from 'node:test'
import assert from 'node:assert/strict'
import { navAllowed, PRIVILEGE } from '../src/commands/privilege.js'

const data   = { minPrivilege: PRIVILEGE.READER, groupAction: 'read' }
const imp    = { minPrivilege: PRIVILEGE.WRITER, groupAction: ['create', 'update'] }
const admin  = { minPrivilege: PRIVILEGE.ADMIN }
const tbRead = [{ name: 'a', can: { read: true, create: false, update: false, delete: false } }]
const tbRw   = [{ name: 'a', can: { read: true, create: true, update: true, delete: true } }]

test('level alone still decides', () => {
  assert.equal(navAllowed(data, 30), true)
  assert.equal(navAllowed(admin, 20), false)
})
test('Enter + group: data screens follow the table abilities', () => {
  assert.equal(navAllowed(data, 39, []), false)
  assert.equal(navAllowed(data, 39, tbRead), true)
  assert.equal(navAllowed(imp, 39, tbRead), false)
  assert.equal(navAllowed(imp, 39, tbRw), true)
})
test('groups never open admin screens, nor Waiting users', () => {
  assert.equal(navAllowed(admin, 39, tbRw), false)
  assert.equal(navAllowed(data, 40, tbRw), false)
})
