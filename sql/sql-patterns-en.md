# SQL Patterns

## A practical guide for developers of business management software
by rosario.carvello@gmail.com

### Introduction

When developing a business management application, the problem is rarely:

> "How do I write a SQL query?"

The real problem is almost always:

> **"How do I turn a user's request into a relational problem that SQL can solve?"**

Examples:

* "Show me the customers who haven't purchased in the last 12 months."
* "What are the 10 best-selling products?"
* "Give me each customer's last order."
* "Which invoices are still unpaid?"
* "How much revenue have we made per sales agent?"
* "Which customers have purchased all the products in a given category?"
* "What's the latest status of each case?"
* "Which items are below stock threshold?"
* "Which orders are late compared to their expected delivery?"

These look like different problems.

In reality, a large part of them can be traced back to **a handful of recurring SQL patterns**.

The goal of this guide is to learn to recognize them.

---

# 1. SELECT + WHERE

## Finding the records that satisfy a condition

This is the fundamental pattern.

```sql
SELECT *
FROM customers
WHERE province = 'CZ';
```

The mental question is:

> **Which rows am I interested in?**

### Business-application example

"Give me the active customers."

```sql
SELECT *
FROM customers
WHERE active = 1;
```

"Give me the overdue invoices."

```sql
SELECT *
FROM invoices
WHERE due_date < CURRENT_DATE
  AND paid = 0;
```

"Give me this year's orders."

```sql
SELECT *
FROM orders
WHERE order_date >= '2026-01-01';
```

### Multiple conditions

```sql
SELECT *
FROM customers
WHERE province = 'CZ'
  AND active = 1;
```

Or:

```sql
SELECT *
FROM customers
WHERE province IN ('CZ', 'KR', 'CS');
```

### Mental pattern

```text
TABLE
   ↓
WHERE
   ↓
subset of rows
```

---

# 2. JOIN

## Reassembling information spread across multiple tables

This is probably **the most important pattern in business applications**.

A normalized database doesn't put everything in one table.

For example, we have:

```text
customers
   |
   +----< orders
             |
             +----< order_lines
                       |
                       +---- products
```

The request:

> "Show me the orders together with the customer's name."

becomes:

```sql
SELECT
    o.id,
    o.order_date,
    c.company_name
FROM orders o
JOIN customers c
    ON c.id = o.customer_id;
```

### Multiple JOINs

```sql
SELECT
    o.id AS order_id,
    c.company_name,
    p.description,
    r.quantity
FROM orders o
JOIN customers c
    ON c.id = o.customer_id
JOIN order_lines r
    ON r.order_id = o.id
JOIN products p
    ON p.id = r.product_id;
```

What matters isn't remembering the syntax.

It's thinking:

> **Which entities do I need to cross to reach the information I need?**

---

# 3. ORDER BY + LIMIT

## Finding the first, the last, or the best N

Many business requests are really:

> "Give me the top N..."

or:

> "Give me the latest..."

### The 10 customers with the highest revenue

Assuming revenue has already been calculated:

```sql
SELECT
    customer_id,
    SUM(total) AS revenue
FROM invoices
GROUP BY customer_id
ORDER BY revenue DESC
LIMIT 10;
```

### The last 20 orders

```sql
SELECT *
FROM orders
ORDER BY order_date DESC
LIMIT 20;
```

### The most recent customer

```sql
SELECT *
FROM customers
ORDER BY created_at DESC
LIMIT 1;
```

### Mental pattern

```text
dataset
      ↓
ORDER BY
      ↓
LIMIT
      ↓
top N
```

But be careful:

> **"the last record" doesn't necessarily mean `MAX(id)`.**

If the ID doesn't temporally represent creation order, you need to sort by a meaningful date instead.

---

# 4. GROUP BY + aggregations

## Turning rows into summary information

This is where SQL becomes particularly powerful.

The fundamental functions are:

```sql
COUNT()
SUM()
AVG()
MIN()
MAX()
```

### How many orders does each customer have?

```sql
SELECT
    customer_id,
    COUNT(*) AS order_count
FROM orders
GROUP BY customer_id;
```

### Revenue per customer

```sql
SELECT
    customer_id,
    SUM(total) AS revenue
FROM invoices
GROUP BY customer_id;
```

### Sales per product

```sql
SELECT
    product_id,
    SUM(quantity) AS quantity_sold
FROM order_lines
GROUP BY product_id;
```

### Monthly revenue

```sql
SELECT
    YEAR(invoice_date) AS year,
    MONTH(invoice_date) AS month,
    SUM(total) AS revenue
FROM invoices
GROUP BY
    YEAR(invoice_date),
    MONTH(invoice_date);
```

### The core concept

`GROUP BY` means:

> **Take many rows and turn them into one result per group.**

---

# 5. HAVING

## Filtering groups

This is where many developers make their first conceptual mistake.

`WHERE` filters **rows before aggregation**.

`HAVING` filters **groups after aggregation**.

### Request

> "Give me the customers who have placed at least 5 orders."

```sql
SELECT
    customer_id,
    COUNT(*) AS order_count
FROM orders
GROUP BY customer_id
HAVING COUNT(*) >= 5;
```

We cannot write:

```sql
WHERE COUNT(*) >= 5
```

because `COUNT()` is computed during aggregation.

### WHERE + HAVING

> "Give me the customers who placed at least 5 orders in 2026."

```sql
SELECT
    customer_id,
    COUNT(*) AS order_count
FROM orders
WHERE order_date >= '2026-01-01'
  AND order_date <  '2027-01-01'
GROUP BY customer_id
HAVING COUNT(*) >= 5;
```

The reasoning is:

```text
WHERE
↓
select the rows

GROUP BY
↓
build the groups

HAVING
↓
select the groups
```

---

# 6. EXISTS / NOT EXISTS

## Checking that something exists — or doesn't

This pattern is **fundamental in business applications**.

### Customers who have placed at least one order

```sql
SELECT c.*
FROM customers c
WHERE EXISTS (
    SELECT 1
    FROM orders o
    WHERE o.customer_id = c.id
);
```

The question isn't:

> "How many orders does he have?"

but:

> **"Does at least one order exist?"**

### Customers who have never placed an order

```sql
SELECT c.*
FROM customers c
WHERE NOT EXISTS (
    SELECT 1
    FROM orders o
    WHERE o.customer_id = c.id
);
```

This is an extremely useful pattern for:

* inactive customers;
* products never sold;
* documents with no attachments;
* cases with no activity;
* orders with no invoice;
* users with no logins;
* vehicles with no service history.

### Example

> "Products never sold."

```sql
SELECT p.*
FROM products p
WHERE NOT EXISTS (
    SELECT 1
    FROM order_lines r
    WHERE r.product_id = p.id
);
```

### Mental rule

When the request contains:

> **"at least one"**

think:

```sql
EXISTS
```

When it contains:

> **"none"**

think:

```sql
NOT EXISTS
```

---

# 7. Subqueries

## Using the result of one query inside another

A subquery lets you solve problems in multiple levels.

### Customers with revenue above average

```sql
SELECT
    customer_id,
    SUM(total) AS revenue
FROM invoices
GROUP BY customer_id
HAVING SUM(total) > (
    SELECT AVG(revenue)
    FROM (
        SELECT
            customer_id,
            SUM(total) AS revenue
        FROM invoices
        GROUP BY customer_id
    ) x
);
```

The reasoning is:

```text
calculate each customer's revenue
             ↓
calculate the average revenue
             ↓
compare each customer against the average
```

This is an important principle:

> **A complex query is often just a sequence of simpler problems.**

---

# 8. CTE — WITH

## Naming the intermediate steps

When subqueries become too convoluted, `WITH` makes the reasoning much more readable.

```sql
WITH customer_revenue AS (
    SELECT
        customer_id,
        SUM(total) AS revenue
    FROM invoices
    GROUP BY customer_id
)
SELECT *
FROM customer_revenue
WHERE revenue > 10000;
```

In practice:

```text
WITH
   ↓
build an intermediate result
   ↓
treat it as a table
   ↓
final query
```

This is especially useful when a query needs to represent a **logical sequence of transformations**.

---

# 9. Window Functions

## Comparing a row against other rows in the same group

This is one of the most powerful patterns in modern SQL.

Window functions let you perform aggregations **without losing the detail of individual rows**.

---

## Example: numbering each customer's orders

```sql
SELECT
    o.*,
    ROW_NUMBER() OVER (
        PARTITION BY customer_id
        ORDER BY order_date DESC
    ) AS position
FROM orders o;
```

We get:

```text
customer   order   position
--------   -----   --------
10         150     1
10         147     2
10         139     3
20         201     1
20         195     2
```

From here we can find each customer's most recent order.

With MySQL 8:

```sql
WITH numbered_orders AS (
    SELECT
        o.*,
        ROW_NUMBER() OVER (
            PARTITION BY customer_id
            ORDER BY order_date DESC
        ) AS rn
    FROM orders o
)
SELECT *
FROM numbered_orders
WHERE rn = 1;
```

This technique solves one of the most common problems in business applications:

> **"Give me the last record for each entity."**

---

# 10. CASE

## Turning data into application-level information

`CASE` is a small construct, but it's very important in business applications.

### Readable status

```sql
SELECT
    id,
    CASE
        WHEN paid = 1 THEN 'Paid'
        ELSE 'Unpaid'
    END AS status
FROM invoices;
```

### Classification

```sql
SELECT
    id,
    total,
    CASE
        WHEN total < 1000 THEN 'Small'
        WHEN total < 10000 THEN 'Medium'
        ELSE 'Large'
    END AS category
FROM invoices;
```

It can also be used inside aggregations.

### Paid vs. unpaid revenue

```sql
SELECT
    SUM(
        CASE
            WHEN paid = 1 THEN total
            ELSE 0
        END
    ) AS total_paid,

    SUM(
        CASE
            WHEN paid = 0 THEN total
            ELSE 0
        END
    ) AS total_unpaid

FROM invoices;
```

---

# 11. LEFT JOIN

## Finding what's missing

This is a variant of JOIN that deserves its own place in the toolbox.

Suppose we want to find:

> "Customers with no orders."

We can use `NOT EXISTS`, but also:

```sql
SELECT c.*
FROM customers c
LEFT JOIN orders o
    ON o.customer_id = c.id
WHERE o.id IS NULL;
```

The mechanism is:

```text
customers
   ↓ LEFT JOIN
orders
   ↓
customers with no match
   ↓
o.id IS NULL
```

This is a very useful pattern for spotting **anomalies or missing elements**.

---

# 12. DISTINCT

## Removing duplicates from the result

With a JOIN we might get:

```text
customer 10
customer 10
customer 10
customer 20
customer 20
```

If we only want the customers:

```sql
SELECT DISTINCT c.id
FROM customers c
JOIN orders o
    ON o.customer_id = c.id;
```

But be careful:

> `DISTINCT` shouldn't be used to "hide" a poorly designed JOIN.

If a query produces duplicates because the relationship is `1:N`, the problem usually needs to be understood at the logical level.

---

# 13. UNION

## Merging compatible results

Suppose we want a single list containing:

* customers;
* suppliers.

If they have compatible columns:

```sql
SELECT id, company_name, 'CUSTOMER' AS type
FROM customers

UNION ALL

SELECT id, company_name, 'SUPPLIER' AS type
FROM suppliers;
```

`UNION ALL` keeps all rows.

`UNION` removes duplicates.

In most cases, when we know the two sets are distinct, `UNION ALL` is the more natural choice.

---

# 14. "Last record per group"

## One of the most common problems in business applications

This deserves its own dedicated pattern.

Example:

> "Give me the latest status of each case."

Table:

```text
case_statuses

id
case_id
status
date
```

Solution with `ROW_NUMBER()`:

```sql
WITH x AS (
    SELECT
        cs.*,
        ROW_NUMBER() OVER (
            PARTITION BY case_id
            ORDER BY date DESC, id DESC
        ) AS rn
    FROM case_statuses cs
)
SELECT *
FROM x
WHERE rn = 1;
```

This pattern shows up constantly:

* latest case status;
* latest vehicle maintenance;
* latest customer contact;
* latest quote;
* latest price;
* latest transaction;
* latest meter reading;
* latest communication;
* latest login.

---

# 15. "All" is different from "at least one"

This is one of the most interesting SQL requests.

> "Customers who purchased **all** the products in category X."

`EXISTS` alone isn't enough.

You need to reason in terms of **sets**.

One technique is to compare counts:

```sql
SELECT o.customer_id
FROM order_lines r
JOIN orders o
    ON o.id = r.order_id
JOIN products p
    ON p.id = r.product_id
WHERE p.category_id = 10
GROUP BY o.customer_id
HAVING COUNT(DISTINCT p.id) = (
    SELECT COUNT(*)
    FROM products
    WHERE category_id = 10
);
```

The principle is:

```text
how many products exist in the category?
             ↓
how many did the customer buy?
             ↓
are they equal?
             ↓
ALL
```

This kind of reasoning is far more important than the specific syntax.

---

# 16. The real method: translating the request

When a request comes in from a client, **don't start writing SQL right away**.

Walk through this process first.

### Example

> "I want to see the customers who haven't placed any orders in the last 12 months."

### Step 1 — What's the main entity?

```text
CUSTOMERS
```

### Step 2 — What do I need to check?

```text
ORDERS
```

### Step 3 — Is the condition positive or negative?

```text
DOES NOT EXIST
```

So:

```text
NOT EXISTS
```

### Step 4 — What's the time window?

```sql
order_date >= ...
```

### Query

```sql
SELECT c.*
FROM customers c
WHERE NOT EXISTS (
    SELECT 1
    FROM orders o
    WHERE o.customer_id = c.id
      AND o.order_date >= DATE_SUB(
          CURRENT_DATE,
          INTERVAL 12 MONTH
      )
);
```

This approach is far more reliable than trying to "guess the query."

---

# 17. A mental map of the patterns

When you read a request, look for these words.

| In the request              | Pattern to consider         |
| ---------------------------- | --------------------------- |
| "that satisfy..."            | `WHERE`                     |
| "together with..."           | `JOIN`                      |
| "including those without..." | `LEFT JOIN` / `NOT EXISTS`  |
| "at least one"                | `EXISTS`                    |
| "none"                        | `NOT EXISTS`                |
| "how many"                    | `COUNT()`                   |
| "total"                       | `SUM()`                     |
| "average"                     | `AVG()`                     |
| "for each..."                 | `GROUP BY` / `PARTITION BY` |
| "at least N"                  | `HAVING`                    |
| "the top N"                   | `ORDER BY + LIMIT`          |
| "the latest for each..."      | `ROW_NUMBER()`               |
| "the most recent"             | `ORDER BY ... DESC`         |
| "above average"               | subquery / CTE              |
| "step 1, then step 2..."      | `CTE`                       |
| "ranking"                      | window functions            |
| "if... then..."               | `CASE`                      |
| "A or B"                       | `UNION`                     |
| "all"                          | set comparison               |

---

# 18. The mental SQL pipeline

A complex SQL query can almost always be broken down into this sequence:

```text
                 USER REQUEST
                        │
                        ▼
                  MAIN ENTITY
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
                     RESULT
```

Naturally, not every query uses every level.

The important skill is **understanding which ones are needed**.

---

# 19. A complete real-world example

Request:

> "Show me the top 10 customers of 2026, based on revenue from paid invoices, but only if they placed at least 3 orders."

Several patterns come into play here at once.

```sql
SELECT
    c.id,
    c.company_name,
    COUNT(DISTINCT o.id) AS order_count,
    SUM(f.total) AS revenue
FROM customers c

JOIN orders o
    ON o.customer_id = c.id

JOIN invoices f
    ON f.customer_id = c.id

WHERE o.order_date >= '2026-01-01'
  AND o.order_date <  '2027-01-01'

  AND f.invoice_date >= '2026-01-01'
  AND f.invoice_date <  '2027-01-01'

  AND f.paid = 1

GROUP BY
    c.id,
    c.company_name

HAVING COUNT(DISTINCT o.id) >= 3

ORDER BY revenue DESC

LIMIT 10;
```

But watch out: **this query might have a row-multiplication problem** if a customer has multiple orders and multiple invoices.

And this is exactly where the difference shows between:

> knowing SQL syntax

and

> **knowing how to design a correct query.**

We might therefore need to aggregate orders and invoices separately using CTEs:

```sql
WITH customer_orders AS (
    SELECT
        customer_id,
        COUNT(*) AS order_count
    FROM orders
    WHERE order_date >= '2026-01-01'
      AND order_date <  '2027-01-01'
    GROUP BY customer_id
),

customer_revenue AS (
    SELECT
        customer_id,
        SUM(total) AS revenue
    FROM invoices
    WHERE invoice_date >= '2026-01-01'
      AND invoice_date <  '2027-01-01'
      AND paid = 1
    GROUP BY customer_id
)

SELECT
    c.id,
    c.company_name,
    o.order_count,
    f.revenue
FROM customers c

JOIN customer_orders o
    ON o.customer_id = c.id

JOIN customer_revenue f
    ON f.customer_id = c.id

WHERE o.order_count >= 3

ORDER BY f.revenue DESC

LIMIT 10;
```

This second version is often **easier to reason about, verify, and maintain**.

---

# 20. SQL is not a collection of tricks

This is the most important lesson.

You don't need to memorize hundreds of queries.

You need to learn to recognize structures like:

```text
FILTER
    ↓
WHERE

CONNECT
    ↓
JOIN

AGGREGATE
    ↓
GROUP BY

FILTER THE GROUPS
    ↓
HAVING

CHECK EXISTENCE
    ↓
EXISTS

CHECK ABSENCE
    ↓
NOT EXISTS

TAKE THE FIRST ONES
    ↓
ORDER BY + LIMIT

FIND THE LAST PER GROUP
    ↓
ROW_NUMBER()

BREAK DOWN THE PROBLEM
    ↓
CTE

MAKE CONDITIONAL
    ↓
CASE

COMPARE ROWS
    ↓
WINDOW FUNCTIONS

MERGE SETS
    ↓
UNION
```

Once you recognize the pattern, the syntax becomes the easy part.

---

# 21. The developer's checklist

Before writing a complex query, ask yourself:

### 1. What's the main entity?

```text
customer?
order?
invoice?
product?
case?
vehicle?
```

### 2. Which tables contain the information I need?

### 3. Which relationships do I need to cross?

```text
JOIN
```

### 4. Am I filtering rows or groups?

```text
WHERE → rows
HAVING → groups
```

### 5. Do I need to know whether something exists?

```text
EXISTS
NOT EXISTS
```

### 6. Do I need to produce a summary?

```text
GROUP BY
```

### 7. Do I need to find one record per group?

```text
ROW_NUMBER()
```

### 8. Am I building a query that's too complex?

```text
CTE
```

### 9. Am I unintentionally duplicating rows?

Check the cardinalities carefully:

```text
1 : 1
1 : N
N : N
```

### 10. Is the result **semantically** correct?

This is the most important question.

A query can be:

* syntactically valid;
* very fast;
* elegant;

and at the same time **return the wrong data**.

---

## The final rule

For those developing business applications, learning SQL doesn't mean learning to write more queries.

It means learning to **translate a business requirement into operations on sets of data**.

The sequence to internalize is:

```text
REQUIREMENT
    ↓
DATA MODEL
    ↓
SETS
    ↓
RELATIONSHIPS
    ↓
FILTERS
    ↓
AGGREGATIONS
    ↓
COMPARISONS
    ↓
RESULT
```

And this mindset is far more transferable than memorizing individual queries.
