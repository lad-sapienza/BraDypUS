# Vocabolary

### Geoface
**TODO**

---

### Plugin
A group of fields, gathered in a table, that add information to a main table.
A plugin is not accessible on its own as an indipendent table.

Normally a plugin in linked in a one-to-many relationship to the main table.
The same plugin table might also be used by more than one main table; that is why
plugins have two mandatory PK fields, named `table_link` and `id_link`.

Finally, plugins name follow the pattern {prefix}m_{pluginname}. 
The `m_` part stands for (one-to-)m(any).

---

### Template
Una maschera personalizzata perl'inserimento dei dati per una data tabella o parti di tabella (plugin).
I template salvati nella cartella `projects/{nome-progetto}/templates/`

---