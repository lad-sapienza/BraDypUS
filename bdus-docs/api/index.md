---
title: API
---

Bradypus is shipped with a read-only JSON Rest API that makes it very easy to 
query and retrieve informations programmatically.

The API is versioned, and the current version is v2. Both v1 and v2 are
available, but if you are planning to create applications that make use
of this feature, please consider seriously to use v2, since the previous
version be deprecated very soon.

The API endpoint is available at the `/api/` relative URL, eg.:
`https://bdus.cloud/db/api/`.

{: .callout-block .callout-block-warning}
The API function must be activated in the main app configuration file in order for the API to work. The API should run as a specific user of the database

Foreach API call the **application name**  and a set of **parameters** should be provided in the URL in the form: `https://{base-url}/api/v2/{app-name}/?parameters`, eg.: `https://bdus.cloud/db/api/test/?parameters`

### Available parameters
- `pretty` [bool, optional, default: 0]: if seto to `1` the returned JSON will be indented for easier reading

- `debug` [bool, optional, default: 0]: if seto to `1` the debug will be turned on and detailed information on errorwill be returned

- `verb` [string, **required**]  
The verb tells the API what to do. One of the following strings ca be used:

  - `read` returns all available information about a record. Table namea an recordsis ust be provided
    - `tb` [string, **required**]: table name with no prefix
    - `id` [int, **required**]: row id  
    Example: [http://db.localhost/api/v2/test/?verb=read&tb=sites&id=1](http://db.localhost/api/v2/test/?verb=read&tb=sites&id=1)

  - `search` performs a search in the databases and returnes results
    - `shortsql` [string, **required**]: string with [ShortSQL](/api/shortsql) filter
    - `total_rows` [integer, optional, default: 0]: if provided, the database will not be queried fot total number of rows
    - `page` [integer, optional, default: 1]: page to retrieve. Search resultsa are pagineated, for efficiency
    - `records_per_page`  [integer, optional, default: 30]: number of records per page, default: 30
    - `full_records` [boolean, optional, default: 0]: if true for each returned record full data will be returned, otherwize only preview fields (faster) will be returned  
    - `geojson` [boolean, optional, default: 0]: if true and if geogata ara available for table, valid geojson will be returned
    Example #1: [http://db.localhost/api/v2/test/?verb=search&shortsql=@sites](http://db.localhost/api/v2/test/?verb=search&shortsql=@sites)  
    Example #2: [http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&total_rows=1](http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&total_rows=1)  
    Example #3: [http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&total_rows=1&page=1](http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&total_rows=1&page=1)  
    Example #4: [http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&total_rows=1&page=1&records_per_page=10](http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&total_rows=1&page=1&records_per_page=10)  
    Example #5: [http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&total_rows=1&page=1&records_per_page=10&full_records=1](http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&total_rows=1&page=1&records_per_page=10&full_records=1)  
    Example #6: [http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&geojson=1&records_per_page=1000](http://db.localhost/api/v2/test/?verb=search&shortsql=@sites&geojson=1&records_per_page=1000)

  - `inspect`: returns information on configuration  
  Example: [http://db.localhost/api/v2/test/?verb=inspect](http://db.localhost/api/v2/test/?verb=inspect)
    - `tb` [string, optional]: if present only data for indicated table will be returned  
    Example: [http://db.localhost/api/v2/test/?verb=inspect&tb=sites]()http://db.localhost/api/v2/test/?verb=inspect&tb=sites
  
  - `getChart`: returns data for specific chart
    - `id` [string, **required**]: id of the chart to output  
    Example: [http://db.localhost/api/v2/test/?verb=getChart&id=1](http://db.localhost/api/v2/test/?verb=getChart&id=1)
  
  - `getUniqueVal`: returns list of unique values used in a specific table and field
    - `tb` [string, **required**]: name of the table, without prefix
    - `fld` [string, **required**]: name of the field
    - `s` [string, optional]: filter sub-string. If present only values containing the provided substrings will be returned
    - `w` [string, optional]: string with [ShortSQL](/api/shortsql) filter to use to limit search  
    Example #1: [http://db.localhost/api/v2/test/?verb=getUniqueVal&tb=sites&fld=typology](http://db.localhost/api/v2/test/?verb=getUniqueVal&tb=sites&fld=typology)  
    Example #2: [http://db.localhost/api/v2/test/?verb=getUniqueVal&tb=sites&fld=typology&s=large](http://db.localhost/api/v2/test/?verb=getUniqueVal&tb=sites&fld=typology&s=large)  
    Example #3: [http://db.localhost/api/v2/test/?verb=getUniqueVal&tb=sites&fld=typology&w=chronology|like|%prehistoric%](http://db.localhost/api/v2/test/?verb=getUniqueVal&tb=sites&fld=typology&w=chronology|like|%prehistoric%)  
    Example #4: [http://db.localhost/api/v2/test/?verb=getUniqueVal&tb=sites&fld=typology&s=large&w=chronology|like|%prehistoric%](http://db.localhost/api/v2/test/?verb=getUniqueVal&tb=sites&fld=typology&s=large&w=chronology|like|%prehistoric%)  
  
  - `getVocabulary`: returns list of vocabulary items for specific vocabulary
    - `voc` [string, **required**]: vobaulary name  
    Example: [http://db.localhost/api/v2/test/?verb=getVocabulary&voc=site_typology](http://db.localhost/api/v2/test/?verb=getVocabulary&voc=site_typology)

