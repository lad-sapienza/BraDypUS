---
title: Cronologia
---

# Cronologia

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

### Nota

Il pannello viene mostrato automaticamente se la configurazione delle relazioni (`bdus_cfg_relations`) include almeno una FK verso una tabella con `fuzzy_date`. Non richiede configurazione aggiuntiva.
