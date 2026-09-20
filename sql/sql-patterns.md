# SQL Patterns

## Guida pratica per sviluppatori di gestionali
di rosario.carvello@gmail.com

### Introduzione

Quando si sviluppa un gestionale, il problema raramente è:

> "Come faccio a scrivere una query SQL?"

Il problema vero è quasi sempre:

> **"Come trasformo una richiesta dell'utente in un problema relazionale che SQL può risolvere?"**

Esempi:

* "Mostrami i clienti che non hanno acquistato negli ultimi 12 mesi."
* "Quali sono i 10 prodotti più venduti?"
* "Dammi l'ultimo ordine di ogni cliente."
* "Quali fatture sono ancora da pagare?"
* "Quanto abbiamo fatturato per agente?"
* "Quali clienti hanno acquistato tutti i prodotti di una determinata categoria?"
* "Qual è l'ultimo stato di ogni pratica?"
* "Quali articoli sono sotto scorta?"
* "Quali ordini sono in ritardo rispetto alla consegna prevista?"

Sono problemi apparentemente diversi.

In realtà, una grande parte di essi può essere ricondotta a **pochi pattern SQL ricorrenti**.

L'obiettivo di questa guida è imparare a riconoscerli.

---

# 1. SELECT + WHERE

## Cercare i record che soddisfano una condizione

È il pattern fondamentale.

```sql
SELECT *
FROM clienti
WHERE provincia = 'CZ';
```

La domanda mentale è:

> **Quali righe mi interessano?**

### Esempio gestionale

"Dammi i clienti attivi."

```sql
SELECT *
FROM clienti
WHERE attivo = 1;
```

"Dammi le fatture scadute."

```sql
SELECT *
FROM fatture
WHERE data_scadenza < CURRENT_DATE
  AND pagata = 0;
```

"Dammi gli ordini dell'anno corrente."

```sql
SELECT *
FROM ordini
WHERE data_ordine >= '2026-01-01';
```

### Più condizioni

```sql
SELECT *
FROM clienti
WHERE provincia = 'CZ'
  AND attivo = 1;
```

Oppure:

```sql
SELECT *
FROM clienti
WHERE provincia IN ('CZ', 'KR', 'CS');
```

### Pattern mentale

```text
TABELLA
   ↓
WHERE
   ↓
sottoinsieme di righe
```

---

# 2. JOIN

## Ricostruire l'informazione distribuita tra più tabelle

Questo è probabilmente **il pattern più importante nei gestionali**.

Un database normalizzato non mette tutto in una tabella.

Abbiamo ad esempio:

```text
clienti
   |
   +----< ordini
             |
             +----< righe_ordine
                       |
                       +---- prodotti
```

La richiesta:

> "Mostrami gli ordini con il nome del cliente."

diventa:

```sql
SELECT
    o.id,
    o.data_ordine,
    c.ragione_sociale
FROM ordini o
JOIN clienti c
    ON c.id = o.cliente_id;
```

### Più JOIN

```sql
SELECT
    o.id AS ordine,
    c.ragione_sociale,
    p.descrizione,
    r.quantita
FROM ordini o
JOIN clienti c
    ON c.id = o.cliente_id
JOIN righe_ordine r
    ON r.ordine_id = o.id
JOIN prodotti p
    ON p.id = r.prodotto_id;
```

La cosa importante non è ricordare la sintassi.

È pensare:

> **Quali entità devo attraversare per arrivare all'informazione che mi serve?**

---

# 3. ORDER BY + LIMIT

## Trovare i primi, gli ultimi o i migliori N

Molte richieste aziendali sono in realtà:

> "Dammi i primi N..."

oppure:

> "Dammi l'ultimo..."

### I 10 clienti con il fatturato maggiore

Supponiamo di avere già il fatturato calcolato:

```sql
SELECT
    cliente_id,
    SUM(totale) AS fatturato
FROM fatture
GROUP BY cliente_id
ORDER BY fatturato DESC
LIMIT 10;
```

### Gli ultimi 20 ordini

```sql
SELECT *
FROM ordini
ORDER BY data_ordine DESC
LIMIT 20;
```

### Il cliente più recente

```sql
SELECT *
FROM clienti
ORDER BY created_at DESC
LIMIT 1;
```

### Pattern mentale

```text
insieme di dati
      ↓
ORDER BY
      ↓
LIMIT
      ↓
primi N
```

Ma attenzione:

> **"l'ultimo record" non significa necessariamente `MAX(id)`.**

Se l'ID non rappresenta temporalmente la creazione, bisogna ordinare per una data significativa.

---

# 4. GROUP BY + aggregazioni

## Trasformare righe in informazioni riepilogative

Qui SQL diventa particolarmente potente.

Le funzioni fondamentali sono:

```sql
COUNT()
SUM()
AVG()
MIN()
MAX()
```

### Quanti ordini ha ogni cliente?

```sql
SELECT
    cliente_id,
    COUNT(*) AS numero_ordini
FROM ordini
GROUP BY cliente_id;
```

### Fatturato per cliente

```sql
SELECT
    cliente_id,
    SUM(totale) AS fatturato
FROM fatture
GROUP BY cliente_id;
```

### Vendite per prodotto

```sql
SELECT
    prodotto_id,
    SUM(quantita) AS quantita_venduta
FROM righe_ordine
GROUP BY prodotto_id;
```

### Fatturato mensile

```sql
SELECT
    YEAR(data_fattura) AS anno,
    MONTH(data_fattura) AS mese,
    SUM(totale) AS fatturato
FROM fatture
GROUP BY
    YEAR(data_fattura),
    MONTH(data_fattura);
```

### Il concetto fondamentale

`GROUP BY` significa:

> **Prendi molte righe e trasformale in un risultato per gruppo.**

---

# 5. HAVING

## Filtrare i gruppi

Qui molti sviluppatori commettono il primo errore concettuale.

`WHERE` filtra **le righe prima dell'aggregazione**.

`HAVING` filtra **i gruppi dopo l'aggregazione**.

### Richiesta

> "Dammi i clienti che hanno effettuato almeno 5 ordini."

```sql
SELECT
    cliente_id,
    COUNT(*) AS numero_ordini
FROM ordini
GROUP BY cliente_id
HAVING COUNT(*) >= 5;
```

Non possiamo scrivere:

```sql
WHERE COUNT(*) >= 5
```

perché `COUNT()` viene calcolato durante l'aggregazione.

### WHERE + HAVING

> "Dammi i clienti che nel 2026 hanno fatto almeno 5 ordini."

```sql
SELECT
    cliente_id,
    COUNT(*) AS numero_ordini
FROM ordini
WHERE data_ordine >= '2026-01-01'
  AND data_ordine <  '2027-01-01'
GROUP BY cliente_id
HAVING COUNT(*) >= 5;
```

Il ragionamento è:

```text
WHERE
↓
seleziono le righe

GROUP BY
↓
creo i gruppi

HAVING
↓
seleziono i gruppi
```

---

# 6. EXISTS / NOT EXISTS

## Verificare che qualcosa esista — o non esista

Questo pattern è **fondamentale nei gestionali**.

### Clienti che hanno effettuato almeno un ordine

```sql
SELECT c.*
FROM clienti c
WHERE EXISTS (
    SELECT 1
    FROM ordini o
    WHERE o.cliente_id = c.id
);
```

La domanda non è:

> "Quanti ordini ha?"

ma:

> **"Esiste almeno un ordine?"**

### Clienti che non hanno mai effettuato ordini

```sql
SELECT c.*
FROM clienti c
WHERE NOT EXISTS (
    SELECT 1
    FROM ordini o
    WHERE o.cliente_id = c.id
);
```

Questo è un pattern estremamente utile per:

* clienti inattivi;
* prodotti mai venduti;
* documenti senza allegati;
* pratiche senza attività;
* ordini senza fattura;
* utenti senza accessi;
* veicoli senza interventi.

### Esempio

> "Prodotti mai venduti."

```sql
SELECT p.*
FROM prodotti p
WHERE NOT EXISTS (
    SELECT 1
    FROM righe_ordine r
    WHERE r.prodotto_id = p.id
);
```

### Regola mentale

Quando nella richiesta compare:

> **"almeno uno"**

pensa a:

```sql
EXISTS
```

Quando compare:

> **"nessuno"**

pensa a:

```sql
NOT EXISTS
```

---

# 7. Subquery

## Usare il risultato di una query dentro un'altra

Una subquery permette di risolvere problemi in più livelli.

### Clienti con fatturato superiore alla media

```sql
SELECT
    cliente_id,
    SUM(totale) AS fatturato
FROM fatture
GROUP BY cliente_id
HAVING SUM(totale) > (
    SELECT AVG(fatturato)
    FROM (
        SELECT
            cliente_id,
            SUM(totale) AS fatturato
        FROM fatture
        GROUP BY cliente_id
    ) x
);
```

Il ragionamento è:

```text
calcolo il fatturato di ogni cliente
             ↓
calcolo la media dei fatturati
             ↓
confronto ogni cliente con la media
```

Questo è un principio importante:

> **Una query complessa spesso è semplicemente una sequenza di problemi più semplici.**

---

# 8. CTE — WITH

## Dare un nome ai passaggi intermedi

Quando le subquery diventano troppo complicate, `WITH` rende il ragionamento molto più leggibile.

```sql
WITH fatturato_clienti AS (
    SELECT
        cliente_id,
        SUM(totale) AS fatturato
    FROM fatture
    GROUP BY cliente_id
)
SELECT *
FROM fatturato_clienti
WHERE fatturato > 10000;
```

In pratica:

```text
WITH
   ↓
costruisco un risultato intermedio
   ↓
lo tratto come una tabella
   ↓
query finale
```

Questo è particolarmente utile quando una query deve rappresentare una **sequenza logica di trasformazioni**.

---

# 9. Window Functions

## Confrontare una riga con le altre righe dello stesso gruppo

Questo è uno dei pattern più potenti di SQL moderno.

Le window functions permettono di fare aggregazioni **senza perdere il dettaglio delle singole righe**.

---

## Esempio: numerare gli ordini di ogni cliente

```sql
SELECT
    o.*,
    ROW_NUMBER() OVER (
        PARTITION BY cliente_id
        ORDER BY data_ordine DESC
    ) AS posizione
FROM ordini o;
```

Otteniamo:

```text
cliente   ordine   posizione
-------   ------   ---------
10        150      1
10        147      2
10        139      3
20        201      1
20        195      2
```

A questo punto possiamo trovare l'ultimo ordine di ogni cliente.

Con MySQL 8:

```sql
WITH ordini_numerati AS (
    SELECT
        o.*,
        ROW_NUMBER() OVER (
            PARTITION BY cliente_id
            ORDER BY data_ordine DESC
        ) AS rn
    FROM ordini o
)
SELECT *
FROM ordini_numerati
WHERE rn = 1;
```

Questa tecnica risolve uno dei problemi più frequenti nei gestionali:

> **"Dammi l'ultimo record per ogni entità."**

---

# 10. CASE

## Trasformare dati in informazioni applicative

`CASE` è un piccolo costrutto, ma nei gestionali è molto importante.

### Stato leggibile

```sql
SELECT
    id,
    CASE
        WHEN pagata = 1 THEN 'Pagata'
        ELSE 'Da pagare'
    END AS stato
FROM fatture;
```

### Classificazione

```sql
SELECT
    id,
    totale,
    CASE
        WHEN totale < 1000 THEN 'Piccola'
        WHEN totale < 10000 THEN 'Media'
        ELSE 'Grande'
    END AS categoria
FROM fatture;
```

Può essere usato anche dentro aggregazioni.

### Fatturato pagato/non pagato

```sql
SELECT
    SUM(
        CASE
            WHEN pagata = 1 THEN totale
            ELSE 0
        END
    ) AS totale_pagato,

    SUM(
        CASE
            WHEN pagata = 0 THEN totale
            ELSE 0
        END
    ) AS totale_da_pagare

FROM fatture;
```

---

# 11. LEFT JOIN

## Trovare ciò che manca

Questa è una variante del JOIN che merita un posto autonomo nella cassetta degli attrezzi.

Supponiamo di voler trovare:

> "Clienti che non hanno ordini."

Possiamo utilizzare `NOT EXISTS`, ma anche:

```sql
SELECT c.*
FROM clienti c
LEFT JOIN ordini o
    ON o.cliente_id = c.id
WHERE o.id IS NULL;
```

Il meccanismo è:

```text
clienti
   ↓ LEFT JOIN
ordini
   ↓
clienti senza corrispondenza
   ↓
o.id IS NULL
```

È un pattern molto utile per individuare **anomalie o elementi mancanti**.

---

# 12. DISTINCT

## Eliminare duplicazioni nel risultato

Con un JOIN potremmo ottenere:

```text
cliente 10
cliente 10
cliente 10
cliente 20
cliente 20
```

Se vogliamo soltanto i clienti:

```sql
SELECT DISTINCT c.id
FROM clienti c
JOIN ordini o
    ON o.cliente_id = c.id;
```

Ma attenzione:

> `DISTINCT` non dovrebbe essere usato per "nascondere" un JOIN progettato male.

Se una query produce duplicati perché la relazione è `1:N`, spesso il problema va capito a livello logico.

---

# 13. UNION

## Unire risultati compatibili

Supponiamo di voler ottenere in un'unica lista:

* clienti;
* fornitori.

Se hanno colonne compatibili:

```sql
SELECT id, ragione_sociale, 'CLIENTE' AS tipo
FROM clienti

UNION ALL

SELECT id, ragione_sociale, 'FORNITORE' AS tipo
FROM fornitori;
```

`UNION ALL` mantiene tutte le righe.

`UNION` elimina i duplicati.

Nella maggior parte dei casi, quando sappiamo che i due insiemi sono distinti, `UNION ALL` è la scelta più naturale.

---

# 14. "Ultimo record per gruppo"

## Uno dei problemi più frequenti nei gestionali

Questo merita un pattern specifico.

Esempio:

> "Dammi l'ultimo stato di ogni pratica."

Tabella:

```text
pratica_stati

id
pratica_id
stato
data
```

Soluzione con `ROW_NUMBER()`:

```sql
WITH x AS (
    SELECT
        ps.*,
        ROW_NUMBER() OVER (
            PARTITION BY pratica_id
            ORDER BY data DESC, id DESC
        ) AS rn
    FROM pratica_stati ps
)
SELECT *
FROM x
WHERE rn = 1;
```

Questo pattern appare continuamente:

* ultimo stato pratica;
* ultima manutenzione veicolo;
* ultimo contatto cliente;
* ultima quotazione;
* ultimo prezzo;
* ultimo movimento;
* ultima lettura contatore;
* ultima comunicazione;
* ultimo login.

---

# 15. "Tutti" è diverso da "almeno uno"

Questa è una delle richieste SQL più interessanti.

> "Clienti che hanno acquistato **tutti** i prodotti della categoria X."

Non basta `EXISTS`.

Bisogna ragionare in termini di **insiemi**.

Una tecnica consiste nel confrontare i conteggi:

```sql
SELECT o.cliente_id
FROM righe_ordine r
JOIN ordini o
    ON o.id = r.ordine_id
JOIN prodotti p
    ON p.id = r.prodotto_id
WHERE p.categoria_id = 10
GROUP BY o.cliente_id
HAVING COUNT(DISTINCT p.id) = (
    SELECT COUNT(*)
    FROM prodotti
    WHERE categoria_id = 10
);
```

Il principio è:

```text
quanti prodotti esistono nella categoria?
             ↓
quanti ne ha acquistati il cliente?
             ↓
sono uguali?
             ↓
ALL
```

Questo tipo di ragionamento è molto più importante della sintassi specifica.

---

# 16. Il vero metodo: tradurre la richiesta

Quando arriva una richiesta del cliente, **non iniziare subito a scrivere SQL**.

Fai prima questo percorso.

### Esempio

> "Voglio vedere i clienti che non hanno effettuato ordini negli ultimi 12 mesi."

### Passo 1 — Qual è l'entità principale?

```text
CLIENTI
```

### Passo 2 — Cosa devo verificare?

```text
ORDINI
```

### Passo 3 — La condizione è positiva o negativa?

```text
NON ESISTE
```

Quindi:

```text
NOT EXISTS
```

### Passo 4 — Qual è la finestra temporale?

```sql
data_ordine >= ...
```

### Query

```sql
SELECT c.*
FROM clienti c
WHERE NOT EXISTS (
    SELECT 1
    FROM ordini o
    WHERE o.cliente_id = c.id
      AND o.data_ordine >= DATE_SUB(
          CURRENT_DATE,
          INTERVAL 12 MONTH
      )
);
```

Questo approccio è molto più affidabile del tentativo di "indovinare la query".

---

# 17. Una mappa mentale dei pattern

Quando leggi una richiesta, cerca queste parole.

| Nella richiesta           | Pattern da considerare      |
| ------------------------- | --------------------------- |
| "che soddisfano..."       | `WHERE`                     |
| "insieme a..."            | `JOIN`                      |
| "anche quelli senza..."   | `LEFT JOIN` / `NOT EXISTS`  |
| "almeno uno"              | `EXISTS`                    |
| "nessuno"                 | `NOT EXISTS`                |
| "quanti"                  | `COUNT()`                   |
| "totale"                  | `SUM()`                     |
| "media"                   | `AVG()`                     |
| "per ogni..."             | `GROUP BY` / `PARTITION BY` |
| "almeno N"                | `HAVING`                    |
| "i primi N"               | `ORDER BY + LIMIT`          |
| "l'ultimo per ogni..."    | `ROW_NUMBER()`              |
| "il più recente"          | `ORDER BY ... DESC`         |
| "sopra la media"          | subquery / CTE              |
| "passo 1, poi passo 2..." | `CTE`                       |
| "classifica"              | window functions            |
| "se... allora..."         | `CASE`                      |
| "A oppure B"              | `UNION`                     |
| "tutti"                   | confronto tra insiemi       |

---

# 18. La pipeline mentale SQL

Una query SQL complessa può quasi sempre essere scomposta in questa sequenza:

```text
                 RICHIESTA UTENTE
                        │
                        ▼
                ENTITÀ PRINCIPALE
                        │
                        ▼
                     JOIN
                        │
                        ▼
                     WHERE
                        │
                        ▼
                   GROUP BY
                        │
                        ▼
                    HAVING
                        │
                        ▼
                  WINDOW / CTE
                        │
                        ▼
                   ORDER BY
                        │
                        ▼
                     LIMIT
                        │
                        ▼
                    RISULTATO
```

Naturalmente non tutte le query utilizzano tutti i livelli.

La capacità importante è **capire quali servono**.

---

# 19. Un esempio completo da gestionale

Richiesta:

> "Mostrami i 10 migliori clienti del 2026, considerando il fatturato delle fatture pagate, ma solo se hanno effettuato almeno 3 ordini."

Qui ci sono diversi pattern contemporaneamente.

```sql
SELECT
    c.id,
    c.ragione_sociale,
    COUNT(DISTINCT o.id) AS numero_ordini,
    SUM(f.totale) AS fatturato
FROM clienti c

JOIN ordini o
    ON o.cliente_id = c.id

JOIN fatture f
    ON f.cliente_id = c.id

WHERE o.data_ordine >= '2026-01-01'
  AND o.data_ordine <  '2027-01-01'

  AND f.data_fattura >= '2026-01-01'
  AND f.data_fattura <  '2027-01-01'

  AND f.pagata = 1

GROUP BY
    c.id,
    c.ragione_sociale

HAVING COUNT(DISTINCT o.id) >= 3

ORDER BY fatturato DESC

LIMIT 10;
```

Ma attenzione: **questa query potrebbe avere un problema di moltiplicazione delle righe** se un cliente ha più ordini e più fatture.

Ed è proprio qui che si vede la differenza tra:

> conoscere la sintassi SQL

e

> **saper progettare una query corretta.**

Potremmo quindi dover aggregare separatamente ordini e fatture tramite CTE:

```sql
WITH ordini_cliente AS (
    SELECT
        cliente_id,
        COUNT(*) AS numero_ordini
    FROM ordini
    WHERE data_ordine >= '2026-01-01'
      AND data_ordine <  '2027-01-01'
    GROUP BY cliente_id
),

fatturato_cliente AS (
    SELECT
        cliente_id,
        SUM(totale) AS fatturato
    FROM fatture
    WHERE data_fattura >= '2026-01-01'
      AND data_fattura <  '2027-01-01'
      AND pagata = 1
    GROUP BY cliente_id
)

SELECT
    c.id,
    c.ragione_sociale,
    o.numero_ordini,
    f.fatturato
FROM clienti c

JOIN ordini_cliente o
    ON o.cliente_id = c.id

JOIN fatturato_cliente f
    ON f.cliente_id = c.id

WHERE o.numero_ordini >= 3

ORDER BY f.fatturato DESC

LIMIT 10;
```

Questa seconda versione è spesso **più facile da ragionare, verificare e mantenere**.

---

# 20. SQL non è una collezione di trucchi

La lezione più importante è questa.

Non serve memorizzare centinaia di query.

Serve imparare a riconoscere strutture come:

```text
FILTRARE
    ↓
WHERE

COLLEGARE
    ↓
JOIN

AGGREGARE
    ↓
GROUP BY

FILTRARE I GRUPPI
    ↓
HAVING

VERIFICARE ESISTENZA
    ↓
EXISTS

VERIFICARE ASSENZA
    ↓
NOT EXISTS

PRENDERE I PRIMI
    ↓
ORDER BY + LIMIT

TROVARE L'ULTIMO PER GRUPPO
    ↓
ROW_NUMBER()

DIVIDERE IL PROBLEMA
    ↓
CTE

CONDIZIONARE
    ↓
CASE

CONFRONTARE RIGHE
    ↓
WINDOW FUNCTIONS

UNIRE INSIEMI
    ↓
UNION
```

Una volta riconosciuto il pattern, la sintassi diventa la parte più semplice.

---

# 21. La checklist dello sviluppatore

Prima di scrivere una query complessa, chiediti:

### 1. Qual è l'entità principale?

```text
cliente?
ordine?
fattura?
prodotto?
pratica?
veicolo?
```

### 2. Quali tabelle contengono le informazioni necessarie?

### 3. Quali relazioni devo attraversare?

```text
JOIN
```

### 4. Sto filtrando righe o gruppi?

```text
WHERE → righe
HAVING → gruppi
```

### 5. Devo sapere se qualcosa esiste?

```text
EXISTS
NOT EXISTS
```

### 6. Devo produrre un riepilogo?

```text
GROUP BY
```

### 7. Devo trovare un record per ogni gruppo?

```text
ROW_NUMBER()
```

### 8. Sto costruendo una query troppo complessa?

```text
CTE
```

### 9. Sto duplicando righe involontariamente?

Controlla attentamente le cardinalità:

```text
1 : 1
1 : N
N : N
```

### 10. Il risultato è corretto **semanticamente**?

Questa è la domanda più importante.

Una query può essere:

* sintatticamente valida;
* velocissima;
* elegante;

e contemporaneamente **restituire dati sbagliati**.

---

## La regola finale

Per chi sviluppa gestionali, imparare SQL non significa imparare a scrivere più query.

Significa imparare a **tradurre un requisito aziendale in operazioni sugli insiemi di dati**.

La sequenza da interiorizzare è:

```text
REQUISITO
    ↓
MODELLO DATI
    ↓
INSIEMI
    ↓
RELAZIONI
    ↓
FILTRI
    ↓
AGGREGAZIONI
    ↓
CONFRONTI
    ↓
RISULTATO
```

E questa mentalità è molto più trasferibile della memorizzazione di singole query.

