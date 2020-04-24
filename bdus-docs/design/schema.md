---
title: Schema della anca dei dati
---

L'elenco delle tabelle e dei campi per ciascuno tabella che a definire sarà:

## test__siti
- nome
- tipologia
- cronologia
- descrizione

## test__us
- sito
- nome
- tipo
- descrizione

## test__bibliografia
- breve
- autori
- anno
- descrizione

## test__m_campioni
- dataprelievo
- tipoanalisi
- note

## test__m_biblio
- breve
- pp


## Nota
La tabella `test__m_biblio` è la tabella intermedia di appoggio utile per la creazione della relazione molti-a-molti
tra la tabella bibliografia e la tabella siti (e us).

---

Questi dati sono tutto quanto ci serve per costruire il nostro database. Da questi dati dobbiamo estrarre le informazioni pr creare le tabelle e le informazioni necessarie per gestire in maniere corretta l'interfaccia di **immissione** dei dati e la loro **validazione**.