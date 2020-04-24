---
title: File di configurazione delle tabelle di dati
---


Come nel caso della compilazione del [file di configurazione generale delle tabelle](/config/tables) 
anche in quello delle tabelle di dati si darà qui una spiegazione completa delle varie opzioni,
ma si consiglia di compilare manualmente solo lo stretto necessario e usare la funzionalità
interna a Bradypus, corredata da interfaccia grafica, per una più puntuale e corretta configurazione.

## Struttura: campi obbligatori
La struttura delle tabelle è libera, ma perogni tabella è importante fornire i seguenti campi obbligatori
che permetto un corretto funzionamento dell'intero sistema:
- `id`: la chiave primaria di ogni tabella.
- `creator`: una campo che il sistema riempirà in automatico con l'identificativo del creatore del record.
Questo campo è fondamentale per garantire il corretto funzionamento dell'utenza di data-entry, 
overo utenti che possono inserire dati, ma che possono modificare solamente i record da loro stessi inseriti.

## Campi speciali delle tabelle di plugin
[**TODO**: da fare]

## Struttura della descrizione di ogni campo

Per ogni campo presente nel file di configurazione sono disponibili le seguenti chiavi:

### name
**obbligatorio**
Il nome identificativo del campo. La stringa deve contenere caratteri minuscoli, 
solo lettere, niente numeri o caratteri di interpunzione, spazi, caratteri accentati e altri diacritici.

#### label
**obbligatorio**
L'etichetta che verrà mostrata agli utenti associata al campo

#### type
**obligatorio**
Tipologia del campo che detemina come questo campo appare agli utenti. I seguenti valori sono disponibili:
- **text**: campo testuale di una sole linea
- **date**: campo testuale di una sole linea associato ad un _widget_ per la selezione della data
- **long_text**: campo testuale di più linee
- **select**: un menu a tendina.
È necessario fornire una fonte di dati per i valor della tendina, 
compilando una delle seguenti chiavi: `vocabulary_set`, `get_values_from_tb`, `id_from_tb`
- **combo_select**: un menu a tendina con possibilità di inserire manualmente valori non presenti nella lista fornita.
È necessario fornire una fonte di dati per i valor della tendina, 
compilando una delle seguenti chiavi: `vocabulary_set`, `get_values_from_tb`, `id_from_tb`
- **multi_select**: un menu a tendina del quale possono essere usati più valori in contemporanea (es. tag) 
e che permete inoltre di inserire anche manualmente valori non presenti nella tendina.
È necessario fornire una fonte di dati per i valor della tendina, 
compilando una delle seguenti chiavi: `vocabulary_set`, `get_values_from_tb`, `id_from_tb`
- **boolean**: menu a tendina precompilato con due possibili valori Si e No (nella banca dati vengono inseriti i valori 1 o 0)
- **slider**: un cursore a trascinamento per inserire un valore numerico compreso tra due estremità
che devono essere definite in `min` e `max`.

#### vocabulary_set
Da compilare se il `type` è select, combo_select o multi_select.

Bradypus permette di definire da interfaccia grafica una serie di vocabolari i cui valori possono esere usati
nei campi con valori predefiniti o suggeriti. inserire qui i nome del vocabolario al quale fare riferimento.

#### get_values_from_tb
Da compilare se il `type` è select, combo_select o multi_select.

I valori predefiniti possono essere presi anche dai valori unici già utilizzati in un determinato
campo di una daterminata tabella. In tal caso inserire in questo campo il nome della tabella, compreso di prefisso, seguito da due punti e dal nome del campo di riferimento. Per esempio il campo sito della tabella us può essere definiti come menu a tendina
i cui valori sono costituit dai valori unici del capo nome della tabella siti (test__siti:nome). Questo espediente
fa sì che non si possano inserite nella tabella us riferimenti a siti inesistenti.

#### id_from_tb
Da compilare se il `type` è select.

Come il precedente, sia nella funzione che nella sintassi, solo che invece di inserire nel campo corrente
il valore letterale del campo esterno di riferimento, inserisce l'id del record riferito.
Dal punto di vista dell'utente non ci sono diferenze, perché il sistema farà comunque vedere il valore letterale.
Questo sistema realzza una integrità refernziale di più alto livello rispetto alla soluzione precedente.

#### check
Controlli di validazione dei dati da operare al momento di inserimento. Si tratta di una lista che può accettare anche
più controlli tra i seguenti. In caso di violazione la scheda non potrà essere salvata.
- **int**: il valore inserito deve essere un numero intero o decimale
- **email**: il valore inserito deve essere un indirizzo email formalmente valido (non viene controllato se l'indirizzo esiste o meno)
- **no_dupl**: il valore inserito non deve esere g'à stato usato in questo campo predecentemente (solo valori unici)
- **not_empty**: il campo non può essere lasciato vuoto
- **range**: il valore inserito deve essere compreso in una forchetta predefinita. 
Per definire la forchetta le chiavi `min` e `max` devono essere valorizzate.
- **regex**: il campo deve rispettare una [espressione regolare](https://en.wikipedia.org/wiki/Regular_expression) predefinita.
Per definire l'espressione regolare di riferimento la chiave `pattern` deve essere valorizzata.

#### active_link
Da valorizzare **0** / **1**
Se 1, in modalità lettura il valore del campo
riportare un link che rimanderà ad una ricerca
automatica di tutti i records simili (ovvero che hanno in questo campo valoro simili, SQL: LIKE).

#### readonly
Da valorizzare **0** / **1**
Se 1, il campo verrà visualizzato,
il suo contenuto verrà inviato al database al momento dell'invio
ma il valore non potrà essere modificato dall'utente

#### disabled
Da valorizzare **0** / **1**
Se 1, il campo verrà visualizzato,
il suo contenuto **non** verrà inviato al database al momento dell'invio (a differenza di `readonly`)
e il valore non potrà essere modificato dall'utente

#### hide
Da valorizzare **0** / **1**
Se 1, il campo seppur presente nel modulo non verrà visualizzato e sarà nascosto
e il suo contenuto verrà inviato al database al momento dell'invio.

#### def_value
Se configurato, al momento della compilazione di una scheda nuova, 
il campo sarà già vaorizzato con la stringa fornita.
È anche possibile usare le seguenti variabili:
- `%today%`: inserirà la data del momento della creazine della scheda nuova
- `%current_user%`: inserirà l'id dell'utente logato che sta creando la scheda

#### force_default
Da valorizzare **0** / **1**
Se 1, il valore inserito in `def_value` verrà inserito ad ogni modifica della scheda
e non solo al momento della sua prima compilazione.
Viene usato, per esempio, per sovrascrivere ad ogni salvataggio la data di più recente modifica
o l'utente che per ultimo ha modificato la scheda.

#### max_length
È possibile inserire un limite di caratteri che un campo può accettare

#### db_type
Questa chiave registra la tipologia del campo nella banca dati.
Le seguanti tipologie sono disponibili:
- `TEXT`
- `INTEGER`
- `DATETIME`

#### min
Da usare in combinazione al `type` **slider** o al `check` **range** per definire un valore minimo

#### max
Da usare in combinazione al `type` **slider** o al `check` **range** per definire un valore massimo

#### pattern
Da usare in combinazione al `check` **regex** per definire il _pattern_ dell'espressione regolare

#### help
Un testo, accessibile all'utente attraverso un bottone informativo associato al campo, 
che può fornire informazioni utili alla compilazione.