---
title: The print object
---

The `print` object is automatically injected in templates 
files by BraDypUS during rendering.

Its methods, explained in detail below, must be used
in template files load data a UI widges.

---

### print.cell(nr)
Utility method that outputs a Bootstrap `col-sm-nr` string
to be used to create grids. It was created during the migration
from Bootstrap v2 to Bootstrap v3 to facilitate future migrations.
- `nr`: a number ranging from 1 to 12

---

### print.permalink

Prints a html link to the full url of the current record

---

### print.links

Shows fieldset with both system and user links to the record

---

### print.geodata
Shows list of available geodata 
and a form to enter some more (WKT) if context is edit or add new

---

### print.rs

Prints Stratigraphic relations module

---

### print.fld(fieldname, formatting)

Writes a single single field, complete with label.

- `fieldname`, string, required. Field name to show
- `formatting`, string, optional, default: null. Inline CSS formatting options

Eg.:
```html
{% raw %}{{ print.fld( 'name', 'width:200') }}
{{ print.fld( 'name') }}{% endraw %}
```
---

### print.plg_fld(fieldname, formatting)
Writes a single single plugin field, complete with label.

- `fieldname`, string, required. Field name to show
- `formatting`, string, optional, default: null. Inline CSS formatting options

---

### print.value(fieldname, isplg)
Prints the nude value of a field

- `fieldname`, string, required. Column name
- `isplg`, bool, optional, default: false. True if it is a plugin column, false if it is a data-table column

---

### print.plg(plg)

Shows up the necessary frmatted HTML to display a plugin,
with all available rows, if any. If you want to customize the
plugin template you should create a specific template named
after the plugin.

- `plg`. string, required. Plugin name to show.

---

### print.showall

Print the default template, containing all columns,
plugins, images, links etc.
It does not make much sense to use this method in a cusàtom template.

---

### print.image_thumbs(max)

Well formatted HTML code containing linked files information with thumbnails and edit links.

- `max`, int\|'all', optional, default 2. Number of images to show or string `all` for all images