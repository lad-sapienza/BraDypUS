---
title: Manual download and installation
---

To install Bradypus maually you need:
- a connection to the Internet
- a web browser

1. Go to official repository on GitHub [https://github.com/bdus-db/BraDypUS](https://github.com/bdus-db/BraDypUS)
2. Click on **Code** and then on **Download ZIP**
3. Extract the ZIP Archive in a directory served by the web server if available
4. Create the folder `projects` inside `BraDypUS` directory
5. If no web server is available, you need to use the terminal:

    5.1. Change directory to the extracted ZIP path: `cd path/to/th/downloaded/and/unzipped/folder/BraDypUS`
    5.2.  Start PHP's web server: `php -S localhost:8000`  
    To stop the PHP's web server type `CRL+C`.  

Now you are ready to create your first web database application. 
Open the browser and go to: [http://localhost:8000/](http://localhost:8000/) 
if you are using PHP's web server or to your localhost address
if you are using a locally installed web browser.