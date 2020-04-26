# Pubblicare tutto online

Una volta che abiamo creato e testato la nostra applicazione,
l'ultimo passaggio è quello di pubblicarlo online
in modo che sia raggiungibile non solo da noi ma anche da eventuali altru utenti.

Gli scenari possibili per la pubblicazione sono davvero molti e non è
possibile qui affrontarli tutti. Le scelte più semplici ed economiche 
possono essere quelle di acquistare un servizio di hosting web, che nella
stragrande maggioranza (o meglio nella pressoché totalità) dei casi 
prevedono la disponibilità di Apache e PHP con PDO.

È anche possibile usare servizi VPS o servizi cloud di varia natura.
Bradypus è una soluzione software estremamente addattabile,
non richiedendo insfrastrutture particolari, e quindi come guida nella 
scelta del servizio migliore è sufficiente considerare il budget a disposizione
e le conoscenze tecniche di gestione di servizi di hosting/vps/cloud.

Al fine di questa esercitazione useremo un servizio assolutamente gratuito
che permete di pubblicare una applicazione PHP senza nessun costo di avviamento:
[https://infinityfree.net/](https://infinityfree.net/).

## Attenzione
> La scelta di Infinityfree ai fini di questa esercitazione
è esclusivamente casuale e frutto di una ricerca non approfondita
su servizi che fossereo gratuiti e di semplice e immediato utilizzo.
Esistono sicuramente altri portali equivalenti e/o probabilmente
migliori che garantiscono lo stesso servizio.  
Inoltre, si specifica che Infinityfree è addatto alla realizzazione
di questa dimostrazione, ma probabilmente il piano gratuito non è addatto
per una installazione di lavoro. La scelta del servizio web di riferimento
è una questione delicata che deve essere valutata insieme a professionisti
del settore al fine di garantire scaliabilità, velocità e sorattutto
**sicurezza**.

> Nel tutorial si farà riferimento al protocollo FTP per il trasferimento
dei file, ma è importante sapere che FTP non è il protocollo più sicuro
in assoluto e all'interno di FTP esisteno vari livelli di sicurezza per 
la trasmissione dei dati. In assoluto, e nel lavoro di tutti i giorni è 
meglio affidarsi a protocolli alternativi, es. 
[FTPS](https://en.wikipedia.org/wiki/FTPS),
[SSH](https://en.wikipedia.org/wiki/Secure_Shell) e a utility 
per il trasferimento di file che usano questi protocolli, come 
[scp](https://en.wikipedia.org/wiki/Secure_copy),
[sftp](https://en.wikipedia.org/wiki/SSH_File_Transfer_Protocol) o
[rsync](https://en.wikipedia.org/wiki/Rsync).
