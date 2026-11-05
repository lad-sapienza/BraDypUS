# Piano — viste e tabelle esistenti come oggetti di prima classe (#53)

> Stato: **bozza per revisione**. Nessuna implementazione oltre al fix di #44 (già fatto, vedi §0).
> Decisioni prese: le viste sono **RW** (con probe di scrivibilità); `id` **obbligatoria**, `creator` obbligatoria se la vista
> è scrivibile (ereditate da una tabella di origine); la cronologia e i lock vanno **sotto il nome della vista** (nessun `view_of`);
> tabelle orfane e viste nello **stesso giro**; ciclo di vita a tre livelli (disattiva soft / disattiva hard / cancella), valido
> anche per le tabelle normali.

## 0. Prerequisito già fatto: #44

`Inspect::isView()`, `tableExists()` coerente (tabelle + viste) e `getAllTables()` solo tabelle su
SQLite/MySQL/PostgreSQL; `view_not_alterable` su add/rename/delete campo e rename tabella;
`delete_tb` su vista = solo de-registrazione. Test: `ConfigViewGuardTest`.
**Non verificato** su Postgres/MySQL reali (solo SQLite in-memory) → da fare prima del rilascio.

## 1. Obiettivo

Un super admin vede **tutto ciò che sta nel DB** (tabelle e viste) e può **attivare** ciò che non è ancora
in configurazione, senza SQL sulle `bdus_cfg_*`. Una vista attivata si configura (etichette, tipi di
campo, preview, permessi) ma non se ne altera la struttura. Una tabella orfana attivata è invece una
tabella normale, alterabile.

## 2. Modello

| Oggetto | in cfg | Stato in UI | Struttura | Record |
|---|---|---|---|---|
| tabella gestita | sì | normale | alterabile | RW |
| tabella orfana | no | "non configurata" → *Attiva* | alterabile dopo attivazione | RW |
| vista | no | "vista non configurata" → *Attiva* | **mai** alterabile | RW *(vedi §6.1)* |
| vista attivata | sì, `is_view=1` | badge "vista" | bloccata (`view_not_alterable`) | RW |

Non servono nuove tabelle di sistema: basta un flag `is_view` in `bdus_cfg_tables`
(nuove colonne `is_view`, `view_writable`, `active` → **migrazione M047**, minore, additiva; default 0 / `none` / 1). Il flag evita un round-trip
a `information_schema` a ogni richiesta, ma resta **derivato**: la verità è `Inspect::isView()`;
il flag cache e viene riallineato dal controllo di deriva (§5).

## 3. Backend

### 3.1 Introspezione unica
`Inspect::listObjects(): array` → `[{name, kind: table|view, columns:[{fld,type,pk}], has_id, sql?}]`
uguale sui tre motori. `sql` solo per le viste (`sqlite_master.sql`, `pg_get_viewdef`,
`information_schema.VIEWS.VIEW_DEFINITION`). Esclusi: `bdus_*`, tabelle di plugin già in cfg,
`sqlite_*`, tabelle FTS/shadow, tabelle di sistema Postgres/MySQL.

### 3.2 Endpoint (super_admin)
- `GET  /api/config/db-objects` → oggetti DB **non** in cfg + stato (`activatable`, `reason` se non lo è).
- `GET  /api/config/db-objects/{name}` → colonne dedotte + SQL (vista) per l'anteprima prima di attivare.
- `POST /api/config/db-objects/{name}/activate` → crea la config.
- `getTableList` / `getTableConfig` espongono `is_view` e, per le viste, `view_sql` (sola lettura).
- OpenAPI aggiornato (il parity test fallisce altrimenti).

### 3.3 Attivazione (`Config\Activate`, classe condivisa con l'import DBML come già `Config\Relations`)
1. Verifica `tableExists`, non già in cfg, nome valido.
2. **Gate `id`**: se manca la colonna `id` → rifiuto con `missing_id_column` (e per le tabelle orfane
   senza PK → proposta esplicita, mai silenziosa; vedi §6.2).
3. Deduzione campi: `tableColumns` → `db_type` + `type` BraDypUS (INTEGER→`text` con `db_type`, TEXT→`text`,
   `*DATE*`→`date`, ecc.), `id` `readonly`, `creator` se presente. Colonne FK reali (`PRAGMA foreign_key_list`,
   `information_schema`) → `bdus_cfg_relations` + `id_from_tb`: sono la parte più utile e meno ovvia.
4. Default layout: `id_field='id'`, `order='id'`, `preview=['id', primo campo testo]`.
5. Transazione unica: o tutta la config o niente.
6. Zero DDL: l'attivazione **non tocca mai** l'oggetto DB.

### 3.4 Blocchi per le viste (estensione di #44)
`view_not_alterable` anche per: `saveIndex`/`deleteIndex`, `saveRelation` con `from_tb` vista
(FK impossibile), `applyAllConstraints` (salta le viste), `activateFuzzyDate` & co. sulle viste,
`fix`/DbCfgAlign (una vista non ha colonne "da eliminare"). Una sola guardia `Config::rejectIfView`
già presente.

### 3.5 Ciclo di vita: tre livelli (vale per **tutte** le tabelle, non solo viste/orfane)

| Azione | Config | Tabella DB | Dopo |
|---|---|---|---|
| **Disattiva (soft)** — switch `active` sì/no | resta, ma ignorata | resta | riattivabile con lo stesso switch |
| **Disattiva (hard)** — "rimuovi dalla configurazione" | cancellata | resta | torna tra gli oggetti non configurati (§3.2), riattivabile con §3.3 |
| **Cancella** | cancellata | **DROP** | irreversibile; conferma digitando il nome |

- Oggi esiste solo "config + drop" (`delete_tb`) o nulla. Per **non rompere l'API**: `DELETE /api/config/table/{tb}` resta
  "cancella"; si aggiungono `PUT …/table/{tb}/active` `{active:bool}` (soft) e `DELETE …/table/{tb}?keep_data=1` (hard).
- **Viste**: soft e hard sì; "cancella" **no** (sarebbe `DROP VIEW`: non sapremmo ricrearla, la creazione è fuori scope).
- **Hard senza drop**: le FK vive nel DB **restano** (nessun `dropForeignKey`: i dati restano coerenti); le righe di
  `bdus_cfg_relations` vanno via come oggi. Alla riattivazione le FK reali vengono rilette (§3.3, punto 3).
- **Soft: è la parte rischiosa.** `Config\LoadFromDB` esclude le tabelle `active=0` da `tables` (e le espone a parte alla
  sola UI di configurazione). Tutto ciò che nomina quella tabella deve tollerare il bersaglio assente: relazioni e
  `id_from_tb` (infrastruttura "dangling" già esistente, `LoadFromDbDanglingRelationTest`), plugin del padre disattivato,
  backlink/PAThs, file e geodata collegati, grafici/query salvate/template/assemblage che la citano, regole `group_rules`
  e `user_table_privs`, ricerca globale, palette Ctrl+K, export/backup. Va fatto un censimento sistematico prima di scrivere codice
  (grep dei consumatori di `cfg->get('tables…')`).
- Poiché è indipendente da viste e orfane, conviene come **fase a sé** (§8), con i suoi test, e rilasciabile da sola.

### 3.6 DBML
Export: `bdus:view=1` + SQL nel `note`. Import: una tabella marcata vista **non** emette `CREATE TABLE`;
se la vista esiste nel DB la attiva, se manca → errore chiaro (la creazione di viste non è in scope).
Chiude anche una parte di #80.

## 4. Frontend (bdus-app)
- Pagina Configurazione → nuova tab/sezione **"Oggetti nel database"**: elenco filtrabile, badge
  `vista` / `non configurata`, pulsante *Attiva* con anteprima (colonne dedotte, FK trovate, SQL).
- Scheda tabella: per `is_view` nascondere *aggiungi/rinomina/elimina campo*, *rinomina tabella*,
  *indici*, *vincoli*; mostrare il SQL in un collapsible read-only; badge "vista".
- Palette Ctrl+K e liste tabelle: icona/badge per le viste.
- Nessun nuovo componente di editing: si riusa il pannello di configurazione campo esistente.
- i18n it/en; i codici errore nuovi (`view_not_alterable` già fatto, `missing_id_column`, `already_configured`).

## 5. Deriva (vista/tabella cambia dopo l'attivazione)
`getValidationReport` (DbCfgAlign) già confronta cfg↔DB: va esteso per
(a) viste scomparse o ricreate senza `id`; (b) colonne aggiunte/tolte dalla vista (la vista è
modificabile *fuori* da BraDypUS) con azione "riallinea" **solo di configurazione**; (c) `is_view`
incoerente con `isView()`. Mai un crash a runtime: un oggetto sparito deve dare errore leggibile,
non 500.

## 6. Rischi e punti aperti (da decidere/verificare prima di scrivere codice)

### 6.1 Viste RW (decisioni prese)
- Scrittura: `INSERT`/`UPDATE`/`DELETE` come per le tabelle; `view_writable` (`none|probe|trigger`) calcolato **all'attivazione**:
  PG `information_schema.views.is_updatable`, MySQL `IS_UPDATABLE`, SQLite `trigger` se in `sqlite_master` esistono
  trigger `INSTEAD OF` sulla vista, altrimenti `none`. Se `none` la scrittura è rifiutata con `view_read_only`
  invece di lasciar fallire il DB con un errore opaco. La UI mostra RO/RW; l'utente normale non nota la differenza se la vista è RW.
- **Cronologia, lock, permessi**: sotto il **nome della vista**, come per una tabella (`view_of` scartato).
  Conseguenza accettata: una modifica fatta sulla tabella base non compare nella cronologia della vista e un lock sulla
  vista non blocca la tabella base. Va scritto nelle guide.
- **`id` e `creator`** (obbligatori all'attivazione): `id` sempre (gate `missing_id_column`); `creator` se la vista è
  scrivibile (`missing_creator_column`), perché l'inserimento lo imposta e i livelli `self_writer` lo leggono. Devono
  provenire da una tabella di origine. Controllo possibile all'attivazione: `id` non nullo e unico
  (`COUNT(*) = COUNT(DISTINCT id)`, con tetto per tabelle grandi → avviso, non blocco). Che `id` sia davvero la PK
  della tabella base non è verificabile da SQL: è responsabilità di chi scrive la vista, e va documentato.
- `INSERT` in vista: `lastInsertId` spesso non affidabile → verificare per motore come `Record\Persist` ricava l'id nuovo;
  probabile fallback "rileggi con max(id)/RETURNING". **Spike obbligatorio** in fase 3.

### 6.2 Tabelle orfane senza `id`/PK
Il gate `id` vale anche per loro. Non aggiungo colonne a tabelle altrui in silenzio:
l'attivazione fallisce con motivo; un'azione esplicita separata ("aggiungi `id`") è fuori scope.

### 6.3 Altri
- **Nome**: viste/tabelle con maiuscole, spazi, parole riservate → validazione unica con `Config\Names`.
- **Tabelle di plugin**: orfane che *sembrano* plugin (`id_link`) → attivate come normali, mai come plugin.
- **Backup/restore**: includono le viste? Verificare `Backup`/`BigRestore`; un restore su DB vuoto
  non può ricreare la vista → avviso.
- **Export DBML/dump**: non devono tentare `DROP/CREATE TABLE` su una vista.
- **Permessi/gruppi (#78)**: `user_table_privs`, `group_rules` per nome tabella → funzionano
  uguale per le viste attivate; testare un blocco/permesso su vista.
- **Multi-engine**: ogni query di introspezione ×3 motori. Test SQLite in memoria **non bastano**
  (lezione #74): serve passare per `test.sh --db=pgsql|mysql`.
- **Mat. views PG**: escluse (`information_schema.tables` non le elenca); dichiarato in docs.

## 7. Fuori scope (esplicitamente)
- Creazione/modifica di viste da UI (né builder visuale né editor SQL): feature successiva sullo stesso
  backend (`CREATE VIEW` validato, solo super_admin, riuso del gate di #76).
- DDL su tabelle orfane oltre a quanto già permesso dopo l'attivazione.

## 8. Fasi proposte (ciascuna rilasciabile, test verdi su 3 motori)
1. **Introspezione** — `listObjects`, probe di scrivibilità, test ×3 motori. *(nessun cambiamento visibile)*
2. **Attivazione backend** — `Config\Activate`, endpoint, M047 (`is_view`, `view_writable`, `active`), hurl nuova fase.
3. **Viste: blocchi e scrittura** — §3.4, gate `view_read_only`, spike `lastInsertId`, gate `id`/`creator`.
4. **Ciclo di vita a tre livelli** (§3.5) — indipendente dalle viste; il censimento dei consumatori della config è il lavoro vero.
   Può slittare prima o dopo la 3 senza dipendenze.
5. **UI** — sezione "Oggetti nel database", badge, SQL collapsible, switch attiva, tre azioni di disattivazione/cancellazione.
6. **Deriva** + DBML + docs (guide, `llms.txt`, `evaluate.md`, skill `bradypus-bootstrap`) + OpenAPI.

Stima grezza: 3 e 4 sono il grosso del rischio; 1 e 5 sono lavoro lineare.

## 9. Decisioni confermate (2026-11-05)
1. Una tabella disattivata (soft) è visibile **solo** nella sezione Configurazione.
2. DROP di una tabella: richiede che sia già disattivata (soft) **e** la digitazione del nome.
3. Vista scrivibile senza `creator`: **attivazione rifiutata** (`missing_creator_column`).

Nessuna domanda aperta. Prossimo passo: aprire l'issue/le issue di implementazione per fase (§8) e partire dalla fase 1.
