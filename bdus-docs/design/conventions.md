---
title: "Qualche convenzione"
---

Bradypus è un [sistema relazionale](https://en.wikipedia.org/wiki/Relational_database) perciò la base della
sua costruzione solole tabelle.

Ogni tabella è composta da un numero specifico di campi, ognuno dei quali presenta regole precise di compilazione.

Bradypus è composta da alcune tabelle `di sistema`, la cui struttura non è possibile modificare e che servono
al funzinamento generale del sistema e da altre tabelle `di dati`, la cui struttura dipende da quanto si sta schedando.

Tra le tabelle di dati alcune sono di secondo ordine e vengono chiamate `plugin` e contengono dati aggiuntivi
relativamente ad una tabelle di dati principale.

Nel nostro caso, la tabella dei campioni è una tabella `plugin`.

## Nome dell'applicazione
Bradypus è un programma multi progetto, ovvero che può contenere entro la stessa installazione molti progetti
che vivono vite separate e indipendenti. Ogni progetto è identificato da un nome univoco, una stringa testuale 
che non contiene caratteri di interpunzione o spaziatura.

Nel caso concreto potremmo definire `test` il nome dell'applicazione che stiamo creando.

## Nomi delle tabelle
Il nome dell'applicazione, per convenzione, viene prefisso al nome di ciascuna tabella; prefisso e nome della tabella
vengono divisi da due linee basse: `___`.

Nel caso concreto le tabella che creremo si chiameranno
- `test__siti`
- `test__us`
- `test__bibliografia`

## Nomi delle tabelle plugin
Sempre per convenzione, le tabelle di plugin ricevono la stringa `m_` tra il prefisso e il nome della tabella.

Nel caso concreto la nostra tabella di plugin si chiamerà:
- `test__m_campioni`