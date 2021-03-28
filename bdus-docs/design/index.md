---
title: Structure of the example database
---

The design of the database strucure is by far the 
the most delicate and important passage and depends
mainy on the data available and on the research aims.

For this tutorial a very simple schema of an archaeological 
database will be used. In its simplicity the schema is sufficiently
articilated to give a glimpse of the main fatures of BraDypUS.

The schema is made of three main tables:
- `sites` contains structured information on archaeological sites
- `su` contains structured information on stratigraphic units, or contexts.  
Each `su` is located in a certain and known site, thus `sites` and `su` 
are linked by a `one-to-many` relationship, ie. one site might have zero, one or more su.
- `bibliography` contains bibliographic records on sites and su.  
`bibliography` is linked in a `many-to-many` relationship with 
both `sites` and `su`. This means that a bibliographic record might contain information for
zero, one or many sites and su and vice-versa, a site might be described 
by zero, one, or meny bibliographic records.  
`many-to-many` relationships are obtained by use of a pivot table.

Finally, we will have the possibility of describing eventually
samples taken from su. Since each su can provide zero, one or many samples,
`su` and `samples` are thus joined in a `one-to-many` relationship.

Yet, samples will not be available as a table on itsown. It will be only
available as an appendix to `su`. This appencices tables are called
**plugins** in Bradypus.

<iframe width="100%" height="500" style="border:none" src='https://dbdiagram.io/embed/605201aaecb54e10c33be14a'> </iframe>

![screenshot](./../images/design/test-schema.svg "Visual schema") 
*Test database schema to be built ([open SVG](./../images/design/test-schema.svg))*

[Download test schema in Graphiz dot format](./test-schema.dot)

```dbml
Project bdus4Test {
  Note: 'Test database for Bradypus v4'
}
Table test__sites {
  id          int [pk, increment]
  creator     text
  name        text
  chronology  text
  capital     text
  description text
  lastmodified  text
  editor      text
}
Table tests__su {
  id          int [pk, increment]
  creator     int
  site        text
  su          text
  type        text
  description text
  note        text
}


Table tests__bibliography {
  id          int [pk, increment]
  creator     int
  short       text
  author      text
  title       text
  year        text
  publishedin text
}

Table tests__m_citations {
    id         int [pk, increment]
    table_link text
    id_link    text
    name       text
    pages      text
    notes      text
}

Table tests__m_samples {
  id            int [pk, increment]
  table_link    text
  id_link       int
  datetaken     text
  analysis      text
  reliability   text
  resultsvalues text
  notes         text
  info          text
}

Ref: "test__sites"."id" < "tests__m_citations"."id_link"

Ref: "tests__m_citations"."name" < "tests__bibliography"."id"

Ref: "tests__su"."id" < "tests__m_citations"."id_link"

Ref: "tests__su"."id" < "tests__m_samples"."id_link"
```