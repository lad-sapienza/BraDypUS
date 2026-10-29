---
title: "Welcome to BraDypUS"
---

# Welcome to BraDypUS

![BraDypUS](/images/bdus.svg "BraDypUS")

BraDypUS is a web-based relational database management system designed for humanities
and cultural heritage research. This guide covers installation, configuration, deployment,
and day-to-day usage.

Throughout this guide **BDUS** is used as a short form of **BraDypUS**; the terms are equivalent.

::: warning Work in progress
This documentation covers **BraDypUS v5**. Content is being actively updated.
:::

::: tip Version badges
A badge such as <Badge type="tip" text="v5.3.0" /> next to a heading marks the
BraDypUS version that first shipped that feature. Headings without a badge are
part of the v5.0.0 rewrite (or, in the Developer section, describe internals
that are not versioned this way). See the
[project changelog](https://github.com/lad-sapienza/BraDypUS/blob/v5/CHANGELOG.md)
for the full history.
:::

### Is BraDypUS right for your data?

If you are still deciding, or an assistant is helping you decide, start from
[Is BraDypUS right for your data?](/guide/evaluate). For assistants there is also an
[`llms.txt`](https://docs.bdus.lad-sapienza.it/llms.txt) index and a ready-made skill
that starts an application from existing data
([`skills/bradypus-bootstrap`](https://github.com/lad-sapienza/BraDypUS/tree/v5/skills/bradypus-bootstrap)).

### Target users

This guide is written for both expert users comfortable with web technologies and
for newcomers who want to set up an efficient web database service.

### Local test

Some information is provided on how to set up a local development environment,
but this is mainly left to the user. Only minimal requirements are introduced,
aiming at a basic but working local setup.
