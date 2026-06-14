---
title: Analisi Assemblaggio
---

# Analisi Assemblaggio

Il modulo **Analisi Assemblaggio** permette di costruire tabelle pivot e grafici a barre per analizzare la composizione di assemblaggi di materiale (es. distribuzione tipologica per unità stratigrafica, quantità per classe ceramica per strato, ecc.).

## Come aprire il modulo

Dalla **DataView** (lista record di qualsiasi tabella), clicca il pulsante **Griglia** nella barra degli strumenti. Si apre il pannello Analisi Assemblaggio.

---

## Il wizard di configurazione

Il modulo usa un wizard a passaggi per definire l'analisi:

### 1. Tabella sorgente

Scegli la tabella che contiene i dati da analizzare (es. `reperti`). Sono disponibili sia le tabelle principali sia le tabelle plugin (indicate con il nome del loro parent).

### 2. Percorso (group path)

Se il campo di raggruppamento si trova in una tabella collegata via FK, puoi attraversare uno o più hop di relazioni per raggiungerla. Lascia vuoto se il campo di raggruppamento è nella tabella sorgente.

### 3. Campo di raggruppamento

Scegli il campo usato sull'**asse verticale** della pivot (righe). Se il campo è una FK verso un'altra tabella, l'asse verticale mostrerà le etichette leggibili dei record referenziati (non gli id numerici) con link diretto alla scheda del record.

### 4. Campo categoria

Scegli il campo usato sull'**asse orizzontale** della pivot (colonne). Tipicamente un campo vocabolario (es. `classe`, `tipo`, `materiale`).

### 5. Misura

Scegli come aggregare i valori:

| Misura | Descrizione |
|---|---|
| `count` | Conteggio dei record |
| `sum` | Somma di un campo numerico |
| `count_distinct` | Conteggio dei valori distinti |

### 6. Filtri

Puoi aggiungere filtri JSON per restringere i record considerati nell'analisi.

---

## La pivot table

Una volta eseguita la configurazione, la vista mostra:

- Una **tabella pivot** con gruppi sulle righe e categorie sulle colonne.
- Le righe relative ai gruppi FK mostrano l'**etichetta leggibile** (campo preview della tabella referenziata) e un **link** diretto alla scheda del record.
- Le celle mostrano il valore della misura (0 se assente).
- La colonna **Totale** somma i valori per riga.

---

## Il grafico

La scheda **Grafico** mostra un grafico a barre raggruppate con le stesse categorie e gruppi della pivot. Utile per confrontare visivamente le distribuzioni.

---

## Salvare e condividere un'analisi

Le analisi possono essere **salvate** con un titolo e richiamate in futuro dal menu a sinistra del pannello. Ogni analisi salvata può essere:

- **Privata** (default) — visibile solo all'utente che l'ha creata.
- **Condivisa** — visibile a tutti gli utenti dell'applicazione (in sola lettura per gli altri).

Per salvare: compila il campo **Titolo** e clicca **Salva**. Per condividere/disattivare la condivisione usa il pulsante apposito nella lista delle analisi salvate.

---

## Esportare i dati

Nella vista pivot è disponibile il pulsante **Esporta CSV** che scarica la tabella corrente come file `.csv`, con le etichette leggibili al posto degli id numerici.
