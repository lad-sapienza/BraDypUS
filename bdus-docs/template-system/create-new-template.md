---
title: Creating a new template
---

BraDypUS uses [Twig](https://twig.symfony.com/) as a PHP template
engine system-wide. Twig is also used to write templates.

But you really do not need a deep knowledge of PHP or Twig
to write a template file; you really do not need *any*
knowledge of PHP or Twig to create a template.

What you really need is:
- (very) good knowledge of [HTML](https://en.wikipedia.org/wiki/HTML5)
- (very) good knowledge of [Boostrap](https://getbootstrap.com/)  
The usage of Bootstrap is not mandatory, but it can really help,
and Bootstrap is already available in the core of BraDypUS.
- a very good knowledge of the `print` object made available by 
BraDypUS.

## File name and path

Template files **must** have `.twig` extension
and must be placed in the template folder of the 
project, in the test case: `projects/test/templates/`

{: .callout-block .callout-block-warning }
There **exists no GUI feature** to create and edit template files.
You must use a code editor of your own and then upload the file 
in the server directory.

You can name your template files whatever you like, but it is 
recommended to choose a significatve name, possibly containing 
also a reference to te data-table they refer.

{: .callout-block .callout-block-info }
Templates named exactly after the the referenced data-table
without prefix, eg. `sites.twig` or `su.twig` will be
**automatically** loaded by the system for these tables.
The same can be said for context-related template names,
such as `sites_edit.twig` or `sites_read.twig`.