---
title: Cronologia
---

# Cronologia <Badge type="tip" text="v5.1.0" />

BraDypUS offre due strumenti di visualizzazione cronologica per le tabelle che usano il plugin **`fuzzy_date`** (date sfumate con intervallo `from/to`, certezza e periodo).

## Plugin fuzzy_date

Per abilitare la cronologia su una tabella, aggiungi il plugin `fuzzy_date` nella configurazione. Ogni record acquisirà cinque campi:

| Campo | Descrizione |
|---|---|
| `chrono_from` | Anno di inizio (numero intero, negativo = a.C.) |
| `chrono_to` | Anno di fine |
| `chrono_label` | Etichetta libera inserita dall'utente (es. "IV–III sec. a.C.") |
| `chrono_certainty` | Livello di certezza (`certain`, `probable`, `possible`) |
| `chrono_period` | Nome del periodo di riferimento |

---

## Timeline cronologica comparata

La **Timeline cronologica** è una vista a pagina intera che sovrappone sullo stesso asse temporale tutti i record con dati `fuzzy_date` delle tabelle selezionate.

### Come aprirla

Dalla **DataView** (lista record di qualsiasi tabella), clicca il pulsante **Calendario** nella barra degli strumenti. La vista si apre a pagina intera.

### Lettura della vista

- Ogni **riga** corrisponde a una tabella con il plugin `fuzzy_date` attivato.
- Ogni **segmento colorato** rappresenta un record: si estende dall'anno `chrono_from` all'anno `chrono_to`.
- Il colore codifica la certezza:
  - Verde → `certain`
  - Arancio → `probable`
  - Rosso/rosa → `possible`
- Passando il mouse su un segmento compare un **tooltip** con il titolo del record, l'intervallo e il livello di certezza.

### Filtri disponibili

Nella barra in cima alla vista puoi:

- **Selezionare le tabelle** da includere (selezione multipla).
- **Definire un intervallo temporale** (`da` / `a` in anni) per restringere i segmenti visualizzati.

---

## Distribuzione cronologica derivata

Il pannello **Distribuzione cronologica** compare nel corpo della scheda record quando la tabella ha relazioni FK con altre tabelle che hanno il plugin `fuzzy_date` abilitato.

### Lettura del pannello

Per ogni tabella relata viene mostrato un **istogramma a 60 bin** della densità cronologica dei record collegati al record corrente:

- L'asse orizzontale è l'asse temporale.
- L'altezza di ogni barra indica quanti record correlati hanno la loro finestra cronologica (`chrono_from`–`chrono_to`) che include quel bin.
- Il **picco** (bin con il maggior numero di record) è evidenziato con le etichette `from` e `to`.
- Ogni barra è un **link** che apre la lista dei record correlati filtrata per quell'intervallo temporale.

### Comportamento automatico (default)

Senza alcuna configurazione aggiuntiva, il pannello mostra un solo hop automatico: le tabelle **figlie dirette** (relazione FK in `bdus_cfg_relations`) che hanno il plugin `fuzzy_date` abilitato. Se nessuna figlia diretta ha `fuzzy_date`, il pannello resta vuoto — anche se un discendente più lontano (es. un nipote) ne è dotato.

### Percorso configurabile (per raggiungere un discendente più lontano) <Badge type="tip" text="v5.4.0" />

Per casi come "da un Sito, mostra i Reperti collegati tramite le Unità stratigrafiche" (un nipote, non un figlio diretto), ogni tabella può avere un **percorso di distribuzione cronologica** configurato in Config → Tabelle, sezione "Chronological distribution path":

1. Ogni passo della cascata elenca le tabelle **figlie dirette** della precedente (via `bdus_cfg_relations`), senza filtrare per `fuzzy_date` — le tabelle intermedie possono fare da semplice ponte (es. le Unità stratigrafiche non hanno necessariamente una propria cronologia).
2. Solo l'**ultima** tabella del percorso deve avere `fuzzy_date` attivo: un badge nel selettore la segnala a colpo d'occhio, e il salvataggio viene bloccato (sia lato client sia lato server) se la condizione non è soddisfatta.
3. Se in un punto della cascata non compare più nessuna tabella selezionabile, significa che manca una relazione FK — vai su Config → Relazioni per aggiungerla.
4. Un percorso vuoto equivale al comportamento automatico descritto sopra (nessuna migrazione o effetto collaterale su app esistenti).

Quando è configurato un percorso, il pannello mostra **solo** l'ultima tabella della catena (non più i figli diretti), e il link di ogni barra filtra sull'intero insieme di record raggiungibili lungo il percorso — non solo quelli con dati cronologici, come nel comportamento automatico a un hop.
