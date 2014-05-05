<?php

require_once "vendor/autoload.php";

use FtpPhp\FtpClient;
use FtpPhp\FtpException;

try {
	$ftp = new FtpClient;

	// Opens an FTP connection to the specified host
	$ftp->connect("ftp.ed.ac.uk");

	// Login with username and password
	$ftp->login("anonymous", "example@example.com");

	// Download file "README" to local temporary file
	$temp = tmpfile();
	$ftp->fget($temp, "README", FtpClient::ASCII);

	// echo file
	echo "<pre>";
	fseek($temp, 0);
	fpassthru($temp);

} catch (FtpException $e) {
	echo "Error: ", $e->getMessage();
}
