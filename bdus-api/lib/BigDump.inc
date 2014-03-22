<?php
/**
 * BigDump ver. 0.32 from 2010-04-03
 * Staggered import of an large MySQL Dump (like phpMyAdmin 2.x Dump)
 * Even through the webservers with hard runtime limit and those in safe mode
 * Works fine with Internet Explorer 7.0 and Firefox 2.x
 * AJAX functionality removed (jbogdani) from this file.
 * $obj structure:
 * 		filename:	full path to file
 *		start: 		starting point (parseFile attribute, def=1)
 *		lines_this:	lines parsen in present session
 *		lines_done:	lines parsed il all sessions
 *		queries_this: queries executed in present session
 *		queries_done: queries executed in all sessions
 *		bytes_this: bytes parsed in present session
 *		bytes_done:	bytes parsed in all sessions
 *		status:		one of: completed, loading, error
 *		error:		verbose error text
 *		bytes_total: size of file (uncompressed if gzipped)
 *		bytes_percent: percentage of bytes processed in all sessions

 * 
 * @author		Alexey Ozerov (alexey at ozerov dot de)
 * @author		AJAX & CSV functionalities: Krzysiek Herod (kr81uni at wp dot pl)
 * @author		Changes to adapt to bradypus/sagid, Julian Bpogdani (jbogdani at gmail dot com)
 * @copyright	GPL (C) 2003-2010
 * @link		http://www.ozerov.de/bigdump.php
 * @uses		DB
 * @uses		tr
 */

// This program is free software; you can redistribute it and/or modify it under the
// terms of the GNU General Public License as published by the Free Software Foundation;
// either version 2 of the License, or (at your option) any later version.

// THIS SCRIPT IS PROVIDED AS IS, WITHOUT ANY WARRANTY OR GUARANTEE OF ANY KIND

// USAGE

// 1. Adjust the database configuration in this file
// 2. Remove the old tables on the target database if your dump doesn't contain "DROP TABLE"
// 3. Create the working directory (e.g. dump) on your web server
// 4. Upload bigdump.php and your dump files (.sql, .gz) via FTP to the working directory
// 5. Run the bigdump.php from your browser via URL like http://www.yourdomain.com/dump/bigdump.php
// 6. BigDump can start the next import session automatically if you enable the JavaScript
// 7. Wait for the script to finish, do not close the browser window
// 8. IMPORTANT: Remove bigdump.php and your dump files from the web server

// If Timeout errors still occure you may need to adjust the $linepersession setting in this file



class BigDump
{
	private $options, 
			$linespersession = 3000, 
			$comment = array("#", "--", "/*!"), 
			$maxquerylines = 200, 
			$data_chunk_length = 16384, 
			$obj;

	

	/**
	 * 
	 * Parses query file, executes queries ( from start to start + offset, not beyond $totalqueries)
	 * sets object with verbose result
	 * @param int $start
	 * @param int $offset
	 * @param int $totalqueries
	 */
	public function parseFile (DB $db, $filename, $start = false, $offset = 0, $totalqueries = 0)
	{
		try
		{
			@set_time_limit(0);
			
			$this->obj->filename = $this->checkFilename($filename);
			
			$this->obj->start = $start = $start ? floor($start) : 1;
	
			$this->obj->offset = $offset = $offset ? floor($offset) : 0;
	
			$isgzip = preg_match("/\.gz$/i", $filename);
			
			// open filename ($file)
			$file = $isgzip ? $file = @gzopen($filename, 'r') : @fopen($filename, 'r');
			
			if (!$file)
			{
				throw new myException(tr::sget('cannot_open_imported_file', $filename));
			}
			
	
			// Set file pointer to $offset
			if ( (!$isgzip && fseek($file, $offset)!=0) OR ($isgzip  && gzseek($file, $offset)!=0) )
			{
				throw new myException(tr::sget('cannot_point_to_offset', $offset));
			}
	
			
			// Start processing queries from $file
			$query = '';
			$queries = 0;
			$totalqueries = $totalqueries;
			$linenumber = $start;
			$querylines = 0;
			$inparents = false;
		
			// Stay processing as long as the $linespersession is not reached or the query is still incomplete
			while ($linenumber < ($start+$this->linespersession) OR $query!="")
			{
				// Read the whole next line
				$dumpline = "";
				while (!feof($file) && substr ($dumpline, -1) != "\n" && substr ($dumpline, -1) != "\r")
				{
					if (!$isgzip)
					$dumpline .= fgets($file, $this->data_chunk_length);
					else
					$dumpline .= gzgets($file, $this->data_chunk_length);
				}
				if ($dumpline==="")
				{
					break;
				}
				
				// Handle DOS and Mac encoded linebreaks (I don't know if it will work on Win32 or Mac Servers)
				$dumpline = str_replace("\r\n", "\n", $dumpline);
				$dumpline = str_replace("\r", "\n", $dumpline);
					
				// Skip comments and blank lines only if NOT in parents
				if (!$inparents)
				{
					$skipline=false;
					reset($this->comment);
					foreach ($this->comment as $comment_value)
					{
						if (!$inparents && (trim($dumpline)=="" || strpos ($dumpline, $comment_value) === 0))
						{
							$skipline=true;
							break;
						}
					}
					if ($skipline)
					{
						$linenumber++;
						continue;
					}
				}
					
				// Remove double back-slashes from the dumpline prior to count the quotes ('\\' can only be within strings)
				$dumpline_deslashed = str_replace ("\\\\","",$dumpline);
					
				// Count ' and \' in the dumpline to avoid query break within a text field ending by ;
				// Please don't use double quotes ('"')to surround strings, it wont work
				$parents=substr_count ($dumpline_deslashed, "'")-substr_count ($dumpline_deslashed, "\\'");
				if ($parents % 2 != 0)
				$inparents=!$inparents;
					
					
				// Add the line to query
				$query .= $dumpline;
	
				// Don't count the line if in parents (text fields may include unlimited linebreaks)
				if (!$inparents)
				$querylines++;
	
	
				// Execute query if end of query detected (; as last character) AND NOT in parents
				if ( preg_match('/;$/', trim($dumpline)) && !$inparents )
				{
					try
					{
						$db->query($query, false, 'boolean');
					}
					catch (myException $e)
					{
						$e->log();
						throw new myException(tr::get('error_in_query'));
					}
					$totalqueries++;
					$queries++;
					$query = '';
					$querylines = 0;
				}
				$linenumber++;
			}
			
			// Get the current file position
			$parsedoffset = $isgzip ? gztell($file) : ftell($file);
			
			if (!$parsedoffset)
			{
				throw new myException(tr::get('cannot_read_file_at_offset'));
			}
	
			// Print statistics
			$this->obj->lines_this		= $linenumber-$start;		//
			$this->obj->lines_done		= $linenumber-1;			// number of lines parsed
			$this->obj->queries_this	= $queries;				// number of queries created in this session
			$this->obj->queries_done	= $totalqueries;			// number of queries creted in all sessions up to the present
			$this->obj->bytes_this		= $parsedoffset-$offset;	// bytes parsed in this session
			$this->obj->bytes_done		= $parsedoffset;			// bytes parsed in all sessions up to now
			$this->obj->bytes_total		= $this->getSize($filename, $isgzip); 	// percentage of bytes elaborated in all sessions
			$this->obj->bytes_percent	= floor((int)$parsedoffset * 100 / $this->obj->bytes_total); 	// percentage of bytes elaborated in all sessions
	
	
			// Finish message and restart the script
			
			if ($linenumber < ($start+$this->linespersession))
			{
				$this->obj->status = 'completed';
			}
			else
			{
				$this->obj->status='loading';
			}
			
			// close file
			$end = $isgzip ? gzclose($file) : fclose($file);
		}
		catch (myException $e)
		{
			$e->log();
			$this->obj->status = 'error';
			$this->obj->error = $e->getMessage();
		}
		
		return $this->obj;
	}
	
	/**
	 * Returns total filesize
	 * @param string $file		valid path to file
	 * @param boolean $isgzip	is gzipped
	 */
	private function getSize($file, $isgzip = false)
	{
		if ($isgzip)
		{
			$fp = fopen($file, "rb");
			fseek($fp, -4, SEEK_END);
			$buf = fread($fp, 4);
			return end(unpack("V", $buf));
		}
		else
		{
			return filesize($file);
		}
	}
	
	/**
	 * Returns filename if wellformatted
	 * @param string $filename	filename
	 * @throws myException
	 * @return welformatted filename
	 */
	private function checkFilename($filename)
	{
		if ( !preg_match('/(\.(sql|gz))$/i' , $filename ) )
		{
			throw new myException(tr::get('wrong_filetype'));
		}
		
		return $filename;
	}


	/**
	 * 
	 * Sets $linepersession
	 * @param int $linepersession
	 */
	function setLinepersession($linepersession)
	{
		$this->linespersession = $linepersession;
	}

	/**
	 * 
	 * Adds comment to $comment array
	 * @param string $comment
	 */
	function setComment($comment)
	{
		$this->comment[count($this->comment)] = $comment;
	}

	/**
	 * 
	 * Sets $maxquerylines
	 * @param int $maxquerylines
	 */
	function setMaxquerylines($maxquerylines)
	{
		$this->maxquerylines=$maxquerylines;
	}

	/**
	 * 
	 * Sets $data_chunk_length
	 * @param int $data_chunk_length
	 */
	function setData_chunk_length($data_chunk_length)
	{
		$this->data_chunk_length = $data_chunk_length;
	}

	/**
	 * 
	 * Prints out the result of the class action in json format
	 * @author	Julian Bogdani
	 */
	function getJson()
	{
		return json_encode($this->obj);
	}


}
?>