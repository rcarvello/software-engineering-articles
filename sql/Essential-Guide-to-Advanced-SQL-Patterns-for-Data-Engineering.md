# Guida Essenziale ai Modelli SQL Avanzati per l'Ingegneria dei Dati

La progettazione di pipeline di dati efficienti richiede spesso una profonda conoscenza di SQL. Evitare linguaggi procedurali permette di mantenere le trasformazioni veloci, scalabili e integrate direttamente nel database.
Melty dei problemi quotidiani legati alla manipolazione dei dati possono essere risolti combinando le funzioni finestra (Window Functions) e specifiche tecniche di join. Di seguito vengono analizzati 10 costrutti fondamentali, i casi d'uso tipici e i potenziali errori logici da evitare in produzione.

------------------------------

## Tabella di Riferimento Rapido
| Problema Comune | Modello SQL Consigliato | Costrutto Principale |
|---|---|---|
| Rimozione di record duplicati mantenendo il più recente | Deduplicazione | `ROW_NUMBER()` |
| Analisi di intervalli consecutivi o periodi di inattività | Gaps and Islands | `ROW_NUMBER()` |
| Calcolo di somme cumulate o medie mobili | Totali Correnti | `SUM() OVER` |
| Conversione del formato delle tabelle (da righe a colonne e viceversa) | Pivot / Unpivot | `CASE` + Aggregazione / `UNION ALL` |
| Identificazione di chiavi mancanti tra tabelle differenti | Anti-Join | `LEFT JOIN` / `NOT EXISTS` |
| Integrazione di date vuote in un report | Date Spine (Generazione Calendario) | `LEFT JOIN` |
| Selezione dei migliori elementi all'interno di una categoria | Top-N per Gruppo | `ROW_NUMBER()` / `RANK()` |
| Allineamento incrementale di record nuovi o modificati | Upsert Incrementale | `MERGE` |
| Filtraggio directo basato sui risultati di funzioni finestra | QUALIFY | `QUALIFY` |
| Raggruppamento di eventi temporali in sessioni utente | Sessionizzazione | `LAG()` + Condizione |
------------------------------

# I 10 Modelli SQL da Conoscere

## 1. Deduplicazione dei Dati

Quando i sistemi di telemetria o di tracciamento (es. logistica dei corrieri) inviano aggiornamenti di stato ridondanti, è fondamentale isolare un'unica riga logica per entità (il pacco) mantenendo il record più aggiornato.
```sql
SELECT *
FROM (
  SELECT *,
         ROW_NUMBER() OVER (
           PARTITION BY tracking_id
           ORDER BY status_timestamp DESC, internal_log_id DESC
         ) AS rn
  FROM raw_shipment_logs
) t
WHERE rn = 1;
```

* **Attenzione ai dettagli:** È indispensabile inserire un criterio di ordinamento secondario deterministico (come `internal_log_id DESC`). Se due righe condividono lo stesso valore di `status_timestamp`, l'assenza di un discriminante univoco renderà instabile il risultato tra diverse esecuzioni. Per una rimozione di righe identiche in ogni singolo byte, preferire un semplice `SELECT DISTINCT` o `GROUP BY`.

## 2. Gaps and Islands 
(Intervalli e Isolati)Questo pattern serve a raggruppare sequenze di righe continue — ad esempio, i giorni consecutivi in cui un sensore IoT ha registrato anomalie — per sintetizzarle in record con una data di inizio e una di fine.
Sottraendo un indice incrementale (`ROW_NUMBER()`) dal valore della sequenza temporale, si ottiene un valore costante per ogni blocco continuo, utilizzabile come chiave di raggruppamento.
```sql
WITH numbered AS (
  SELECT device_id,
         reading_date,
         reading_date - (ROW_NUMBER() OVER (
           PARTITION BY device_id ORDER BY reading_date
         ) * INTERVAL '1 day') AS grp
  FROM sensor_anomalies
)
SELECT device_id,
       MIN(reading_date) AS alert_start,
       MAX(reading_date) AS alert_end,
       COUNT(*) AS consecutive_days
FROM numbered
GROUP BY device_id, grp
ORDER BY device_id, alert_start;
```

* **Nota sui dialetti:** La sintassi `INTERVAL '1 day'` è tipica di PostgreSQL. In BigQuery viene utilizzata la funzione `DATE_SUB(reading_date, INTERVAL rn DAY)`, mentre in SQL Server si ricorre a `DATEADD(day, -rn, reading_date)`.

## 3. Totali Correnti e Finestre Mobili

Utilizzato per monitorare l'andamento del saldo di un conto bancario attraverso le transazioni o per calcolare la media mobile della temperatura di un macchinario su un intervallo specifico.
```sql
SELECT transaction_date,
       amount_delta,
       SUM(amount_delta) OVER (
         ORDER BY transaction_date
         ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
       ) AS account_balance,
       AVG(amount_delta) OVER (
         ORDER BY transaction_date
         ROWS BETWEEN 6 PRECEDING AND CURRENT ROW
       ) AS rolling_7row_avg
FROM bank_transactions;
```

* **Attenzione ai dettagli:** Se si omette la clausola descrittiva del frame dopo `ORDER BY`, molti motori SQL applicano di default il comportamento `RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW`. Questo comporta che le transazioni eseguite nello stesso identico giorno (`transaction_date`) vengano sommate insieme nello stesso step, falsando l'accumulo riga per riga. Inoltre, un frame di tipo `ROWS` calcola le righe fisiche, non i giorni effettivi del calendario; se mancano date nel set originario, il calcolo temporale risulterà errato.

## 4. Pivot e Unpivot
Operazione utile per ristrutturare i dati di reportistica, passando da un formato lungo a uno largo (es. sensori che inviano metriche diverse su righe separate) o viceversa.
L'approccio più versatile e compatibile con ogni database sfrutta l'aggregazione condizionale:

```sql
-- Operazione di Pivot (Da Righe a Colonne)
SELECT device_id,
       AVG(CASE WHEN metric_type = 'TEMP' THEN metric_value END) AS avg_temperature,
       AVG(CASE WHEN metric_type = 'HUMID' THEN metric_value END) AS avg_humidity,
       AVG(CASE WHEN metric_type = 'PRESS' THEN metric_value END) AS avg_pressure
FROM device_readings
GROUP BY device_id;

-- Operazione di Unpivot (Da Colonne a Righe)
SELECT device_id, 'TEMP' AS metric_type, avg_temperature AS metric_value FROM consolidated_devices
UNION ALL
SELECT device_id, 'HUMID', avg_humidity FROM consolidated_devices
UNION ALL
SELECT device_id, 'PRESS', avg_pressure FROM consolidated_devices;
```

* **Nota sui dialetti:** Benché motori come SQL Server, Oracle, Snowflake o BigQuery includano comandi nativi come `PIVOT` o `UNPIVOT`, essi impongono la dichiarazione statica delle colonne. Se le colonne variano dinamicamente, è consigliabile eseguire la trasformazione logica nello strato applicativo o direttamente nello strumento di Business Intelligence.

## 5. Anti-Join per Record Mancanti

Questo schema individua discrepanze o assenze di relazioni tra due entità aziendali (es. identificare quali utenti registrati alla piattaforma non hanno mai completato la configurazione del profilo).
```sql
-- Variante con LEFT JOIN ed esclusione dei valori NULL
SELECT u.user_id
FROM accounts u
LEFT JOIN profiles p ON p.user_id = u.user_id
WHERE p.user_id IS NULL;

-- Variante con operatore NOT EXISTS
SELECT u.user_id
FROM accounts u
WHERE NOT EXISTS (
  SELECT 1 FROM profiles p WHERE p.user_id = u.user_id
);
```

* **Attenzione ai dettagli:** Evitare tassativamente l'uso dell'istruzione `NOT IN` combinata con una subquery se quest'ultima può generare valori `NULL`. La presenza di un solo elemento nullo invalida l'intero predicato logico, restituendo un set vuoto senza generare alcun messaggio d'errore.

## 6. Date Spine (Generazione di Calendari)

Utile per evitare che i report delle metriche aziendali omettano i giorni in cui non è stata registrata alcuna attività logistica o transazione. Consiste nel creare una sequenza ininterrotta di date su cui innestare le attività reali tramite una `LEFT JOIN`.
```sql
-- Esempio in ambiente Postgres
WITH calendar AS (
  SELECT generate_series(
    DATE '2026-09-01',
    DATE '2026-09-30',
    INTERVAL '1 day'
  )::date AS d
)
SELECT c.d AS log_date,
       COUNT(f.log_id) AS total_failures
FROM calendar c
LEFT JOIN system_failures f ON f.event_date = c.d
GROUP BY c.d
ORDER BY c.d;
```

* **Ottimizzazione per la produzione:** Negli ambienti Enterprise è preferibile memorizzare nel database una tabella fisica permanente dedicata al calendario (`dim_date`). Questo evita il calcolo dinamico continuo della colonna temporale e semplifica le aggregazioni basate su festività, fine settimana o trimestri fiscali.

## 7. Top-N per Gruppo

Questo costrutto estrae i migliori elementi (es. i 3 dipendenti con il punteggio di performance più alto) per ogni singolo dipartimento aziendale, differenziandosi da una clausola globale `LIMIT`.
```sql
SELECT department_id, employee_id, performance_score
FROM (
  SELECT department_id,
         employee_id,
         performance_score,
         ROW_NUMBER() OVER (
           PARTITION BY department_id ORDER BY performance_score DESC
         ) AS rn
  FROM staff_evaluations
) t
WHERE rn <= 3
ORDER BY department_id, rn;
```

* **Scelta della funzione di ranking:** `ROW_NUMBER()` assegna valori sequenziali unici ignorando i pari merito. Se si desiderano visualizzare tutti gli elementi che condividono la medesima posizione di classifica (estendendo potenzialmente il risultato a più di N righe), occorre adottare `RANK()` o `DENSE_RANK()`.

## 8. Upsert Incrementale mediante MERGE
Gestisce l'allineamento dei dati aggiornando i record dei prodotti esistenti nel catalogo e inserendo quelli inediti con un'unica istruzione di scrittura.
```sql
MERGE INTO warehouse_stock AS tgt
USING inventory_updates AS src
ON tgt.product_sku = src.product_sku
WHEN MATCHED THEN
  UPDATE SET
    quantity = src.quantity,
    last_received_at = src.update_timestamp
WHEN NOT MATCHED THEN
  INSERT (product_sku, quantity, last_received_at)
  VALUES (src.product_sku, src.quantity, src.update_timestamp);
```


* Attenzione ai dettagli: Se la sorgente (inventory_updates) ospita chiavi duplicate, il comando MERGE fallisce o genera comportamenti imprevedibili a seconda del database. È obbligatorio ripulire l'input a monte (usando ad esempio il pattern 1). In sistemi come PostgreSQL (versioni precedenti alla 15) si è soliti usare INSERT ... ON CONFLICT DO UPDATE, mentre in MySQL si ricorre a INSERT ... ON DUPLICATE KEY UPDATE.

## 9. Clausola QUALIFY
Evita l'annidamento di query superflue consentendo di filtrare i risultati 
delle funzioni finestra in modo analogo a quanto fa la clausola HAVING con le funzioni di aggregazione standard.
```sql
SELECT device_id, metric_value, recorded_at
FROM telemetry_stream
QUALIFY ROW_NUMBER() OVER (
  PARTITION BY device_id ORDER BY recorded_at DESC
) = 1;
```
* Nota di compatibilità: Questa estensione non appartiene sullo standard SQL nativo. Funziona correttamente su architetture cloud moderne quali Snowflake, BigQuery, Databricks e DuckDB, ma non è supportata in ambienti tradizionali come PostgreSQL, MySQL o SQL Server.

## 10. Sessionizzazione dei Log
Modello dell'analisi web e del comportamento d'uso che accorpa uno streaming di azioni in app in sessioni d'uso distinte, avviandone una nuova ogni volta che il tempo trascorso tra due azioni consecutive supera una soglia (es. 15 minuti).
```sql
WITH gaps AS (
  SELECT account_id,
         click_ts,
         CASE
           WHEN click_ts - LAG(click_ts) OVER (
             PARTITION BY account_id ORDER BY click_ts
           ) > INTERVAL '15 minutes'
           OR LAG(click_ts) OVER (
             PARTITION BY account_id ORDER BY click_ts
           ) IS NULL
           THEN 1 ELSE 0
         END AS is_new_session
  FROM app_clicks
)
SELECT account_id,
       click_ts,
       SUM(is_new_session) OVER (
         PARTITION BY account_id ORDER BY click_ts
         ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
       ) AS session_id
FROM gaps;
```

------------------------------

## Domande Frequenti (FAQ)

## Qual è la differenza strutturale tra ROW_NUMBER, RANK e DENSE_RANK?

* ROW_NUMBER(): Fornisce un numero progressivo univoco e sequenziale per ogni riga all'interno della partizione, indipendentemente dalla presenza di valori identici.
* RANK(): Attribuisce lo stesso valore ai record idonei allo stesso posizionamento, ma salta i numeri successivi nella progressione (es. 1, 1, 3).
* DENSE_RANK(): Gestisce i pari merito in modo analogo a RANK, senza però interrompere la continuità della numerazione sequenziale (es. 1, 1, 2).

## Perché ROWS e RANGE producono risultati differenti nei totali progressivi?
La clausola ROWS agisce sulla riga fisica specifica corrente. La clausola RANGE include invece tutte le righe che condividono lo stesso identico valore logico definito nell'ordinamento (ORDER BY). Di conseguenza, l'utilizzo inconsapevole di RANGE raggrupperà i dati con medesima data producendo un valore cumulativo non lineare.


---



