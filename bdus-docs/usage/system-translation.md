---
title: System translation
---


{: .callout-block .callout-block-warning }
This feature is available only for super-administrators


BraDypUS database system is available in Italian and English, 
but other locales can be added easily. Translations are located in
JSON files in the `/locale/` directory. The files are named with the
two-digits code of the language, eg. `en.json` or `it.json`.

The JSON structure is very simple:

```JSON
{
    "choose_db": "Select a database to continue",
    "error_in_module": "Error. Something went wrong in loading module %s",
    "generic_error": "Error. Please check the error log for more details",

    "app_name": "Application name",
    "definition": "Application description",
    ...
}
```

The key is the string used in the system, while the value is the translation 
(in English in this example). A new translation can be added by adding a new 
locale file following the same structure and implementing the same keys, of by 
using the GUI feature.

![screenshot](../../images/usage/sys-translate-main.png "System translate")
*System translate*

English is the primary language, and Italian is considered to be a translation.
A key availabe in the primary language (English) will be automatically available
for translation in the secondary languages. The **Show incomplete translations**
button will hide translated strings and make it easier to find the missing translations.

Adding a new language is as easy as clicking in the **Add** button and digit the code
of the new language. After that the newly createf file will be available for translation.