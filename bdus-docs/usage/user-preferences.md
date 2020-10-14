---
title: User preferences
---

Each user can customize itsproper experience with Bradypus and these features are collected in the 
**User preferences** module located in the main page.


User preferences can be session related or can be saved to the database and loaded each time the 
user logs in and thus make them permanent.

Once a preference is changed, the **Save** button of the **Save** panel will save the 
change in the database and make it persistent through work sessions. If a change is not
saved in the database it will be "forgotten" and resetted on logout.

![screenshot](../../images/usage/user-preferences.png "Main view of user preferences")
*Main view of user preferences*


## Result pagination
The listing of a query results will never show by default all the available found, since
these can be sereval thousands or millions and it would be highly inefficient to show all items.

Bradyus paginates results, by splitting them in groups of 30 items. You can change this behaviour
and activate Infinite scrolling. Results will still be divided in pages, but new pages will
be automatically loaded in results table as the user scrolls down (or up) the results.

![screenshot](../../images/usage/pagination.png "Pagination")
*Pagination*


## User language
Each user chan choose the language to use in te main interface.
At present, English and Italian are available, but more translations can be 
easily added using the [System translation module](/usage/system-translation).

![screenshot](../../images/usage/language.png "Language")
*Language*


## Template manager
This module permits each user to select, for each data-table, what template
to use, allowing to use different templates in read and edit/add new contexts.
The drop-down menu will be automatically filled with available templates.

For more information on templates, please read the [relative page](/template-system/).

![screenshot](../../images/usage/tmpl-manager.png "Template manager")
*Template manager*


## User data
Non administrators can use this module to update their data, such as:
- name
- email addres
- password.

![screenshot](../../images/usage/user-data.png "User data")
*User data*

## Preview fields
The list of columns visualized in search results table is defined in 
[configuration files](/setup/finalizing-setup#table-settings) and this 
setting is available by default to all users.

Each user, can customize thi setting by access **Preview fields** module.

Foreach table check and save the columns to use in records preview, and 
rememeber to save in the database the preference, if you want to make it persistent
in future sessions.

![screenshot](../../images/usage/preview-fields.png "Preview fields")
*Preview fields*
