---
title: Deploy application
---

Once te application has been created, the last step is to
publish it online so that it can be shared with collaborators
and colleagues.

Bradypus can be installed in  **shared hosting** services,
**virtual private servers** (VPS) od **cloud solutions**. 
You just need PHP with PDO and a database server, if you are not going 
to use the default SQLite database.

The choise of the platform depends on the network velocity you want
to grant to your users, the performance, the number of connections,
security. Ie. **budget**.

If you are using a SQLite database you just need to upload your files 
to the remote server and your local application will be immediately 
available to your collaborators. If you are using MySQL or Postgresql,
then you need to manually setup the server, upload the backup and update
application configuration to use the new database service.

Manu hosting companies still offer FTP access to the remote server.
This is very easy to use but [highly insecure](https://security.stackexchange.com/questions/191900/how-insecure-is-ftp).
You should use 
[SFTP](https://en.wikipedia.org/wiki/SSH_File_Transfer_Protocol),
[FTPS](https://en.wikipedia.org/wiki/FTPS) or 
[SSH](https://en.wikipedia.org/wiki/Secure_Shell) to safely
upload and download files from the server.