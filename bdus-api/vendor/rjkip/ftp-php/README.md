FTP for PHP (c) David Grudl, 2008 (http://davidgrudl.com)

Introduction
------------
FTP for PHP is a very small and easy-to-use library for accessing FTP servers.

David Grudl's project at GoogleCode: http://ftp-php.googlecode.com  
David Grudl's PHP blog: http://phpfashion.com

Requirements
------------
 * PHP 5.3+

Installation
------------
Install `FtpPhp` through [Composer](http://getcomposer.org/doc/00-intro.md).
Just specify `rjkip/ftp-php` as a dependency.

Usage
-----
Opens an FTP connection to the specified host:

```php
<?php
require "vendor/autoload.php";

use FtpPhp\FtpClient;
use FtpPhp\FtpException;

$ftp = new FtpClient;
$ftp->connect($host);
```

Login with username and password
```php
<?php
$ftp->login($username, $password);
```

You can also pass a URI to the constructor, as such:
```php
<?php
$ftp = new FtpClient("ftp://user:password@host/path");
```

```php
<?php
$arr = $ftp->nlist();
foreach ($arr as $value) {
    echo $value.PHP_EOL;
}
```

Upload the file
```php
<?php
$ftp->put($destination_file, $source_file, FtpClient::BINARY);
```

Close the FTP stream
```php
<?php
# Connection is also closed when `$ftp` goes out of scope.
$ftp->close();
```

Ftp throws exception if operation failed. So you can simply do following:
```php
<?php
try {
	$ftp = new FtpClient;
	$ftp->connect($host);
	$ftp->login($username, $password);
	$ftp->put($destination_file, $source_file, FtpClient::BINARY);

} catch (FtpException $e) {
	echo 'Error: ', $e->getMessage();
}
```

On the other hand, if you'd like the possible exception quietly catch, call methods with the prefix 'try':
```php
<?php
$ftp->tryDelete($destination_file);
```

When the connection is accidentally interrupted, you can re-establish it using method `$ftp->reconnect()`.

Changelog
---------

### v1.1.0 - 2014-01-13
 * Introducing a base exception class for all FtpPhp exceptions. This shouldn't break any of your exception handling, unless you rely on `FtpException` directly extending `\Exception`.
 * All classes comply largely to PSR-2.
 * Updated docblocks to satisfy PhpStorm.
