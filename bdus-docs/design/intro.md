# Progettazione del database

La definizione della struttura della banca dati è di gran lunga il passaggio più importante e delicato
dell'intero processo.

Per questa demo useremo un esempio di tipo archeologico, ridotto alla massima semplicità, ma che introduce varie caratteristiche 
del sistema di gestione Bradypus.

La banca dati è composta da tre tabelle principali:

- `siti` raccoglie informazioni sui siti archeologici di un dato territorio
- `us` raccoglie informazioni sulle singole unità stratigrafiche scavate in un determinato sito.
Questi significa che `siti` e `us` sono collegati da una relazione `uno-a-molti`: un sito può avere nessuna, una o molte us
- `bibliografia` raccoglie dati bibliografici su siti e anche su us. Dunque, `bibliografia` e collegata in rapporto `molti-a-molti`
sia con i `siti` che con le `us`; ovvero un record bibliografico può trattare di zero, uno o più siti (o us) e viceversa
un sito (o us) può essere menzionata in zero, uno o più record bibliografici. Le relazioni `molti-a-molti` si realizzano di norma
attraverso l'utilizzo di una tabella intermedia di appoggio.

Inoltre prevederemo la possibilità di schedare anche possibili campionamenti delle unità stratigrafiche. Dal momento che per
ogni `us` è possibile avere nessuno, uno o più campioni, `us` e `campioni` si trovano in relazione `uno-a-molti`, ma a
differenza di quanto visto fino a qui i campioni non saranno un'entità separata, ma una appendice delle us.