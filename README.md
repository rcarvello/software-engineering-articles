# Software Engineering Articles

A collection of practical articles, patterns, notes, and guides about software engineering and the design and development of maintainable business applications.

The repository focuses on **practical software engineering**, with particular attention to the problems that developers encounter when designing and evolving real-world applications.

## Topics

The articles may cover topics such as:

* Software architecture
* Application design
* Database design and SQL
* Design patterns
* Web application development
* API design and integration
* Business applications and CRM
* Software development methodologies
* Code quality and maintainability
* Automation and AI-assisted development
* Performance and scalability
* Testing and reliability

The goal is not to collect theoretical material, but to extract **reusable concepts, patterns, and practical approaches** that can be applied to real software projects.

## Articles

### SQL

* **[SQL Patterns — A Practical Guide for Business Application Developers](sql/sql-patterns-en.md)**
  A practical guide to the most recurring SQL patterns in business applications: filtering, joins, aggregation, `EXISTS`, `NOT EXISTS`, CTEs, window functions, "last record per group", and translating business requirements into SQL queries.

* **[Essential Guide to Advanced SQL Patterns for Data Engineering](sql/Essential-Guide-to-Advanced-SQL-Patterns-for-Data-Engineering.md)**
  A practical reference to 10 core SQL patterns for data pipelines:   deduplication, gaps and islands, running totals/moving windows, pivot/unpivot, anti-joins, date spines, top-N per group, incremental upserts (MERGE), the QUALIFY clause, and log sessionization. Each pattern includes a working query, common pitfalls, and dialect notes (PostgreSQL, BigQuery, SQL Server, Snowflake, MySQL). Closes with an FAQ on ROW_NUMBER vs RANK vs DENSE_RANK and why ROWS and RANGE frames can produce different results in running totals.

## Structure

Articles are organised by topic:

```text
.
├── README.md
    ├── sql/
    │   └── sql-patterns.md
        └── sql-patterns-en.md
    ├── architecture/
    ├── design-patterns/
    ├── databases/
    ├── web-development/
    └── software-engineering/
```

The structure may evolve as the collection grows. Files are written in Italian and English

## Philosophy

Software engineering is not only about writing code.

A large part of the work consists of understanding a problem, modelling it correctly, choosing appropriate abstractions, and translating business requirements into software that is understandable, maintainable, and reliable.

These articles therefore focus on the reasoning behind technical solutions, rather than simply presenting isolated code examples.

> **Understand the problem first. Design the solution second. Write the code third.**

## License

Unless otherwise specified, the articles in this repository are provided for educational and reference purposes.

