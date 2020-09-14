<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			08/jan/2013
 *  
 * $resp
 * 	filename	filename
 * 	filesize
 * 	start
 * 	offset
 * 	lines_this
 * 	lines_done
 * 	queries_this
 * 	queries_done
 * 	bytes_this
 * 	bytes_done
 *  percent_done
 * 	status		completed|loading|error
 * 	error		true
 *  
 */
use DB\DBInterface;

class bigRestore
{
	private $db;
	private $filename;
	private $resp;
	private $linespersession = 3000;
	private $comment = [ '#', '--', '/*!' ];
	private $maxquerylines = 200;
	private $data_chunk_length = 16384;
	
	
	public function __construct(DBInterface $db)
	{
		$this->db = $db;
	}

	public function runImport(string $filename, int $start = 1, int $offset = 0, $totalqueries = 0)
	{
		@set_time_limit(0);

		$this->validateFile($filename);
		
		$this->filename = $filename;
		$this->resp['filename'] = $filename;
		
		$start = floor($start);
		$resp['start'] = $start;
		
		$offset = floor($offset);
		$this->rest['offset'] = $offset;
		
		$linenumber = $start;
		
		$isgzip = preg_match("/\.gz$/i",$this->filename);
		
		$this->resp['filesize'] = $isgzip ? $this->gzfilesize($filename) : filesize($filename);
		
		$file = $isgzip ? @gzopen($this->filename,"r") : @fopen($this->filename,"r");
		
		if(!$file) {
			throw new \Exception("Can't open " . $this->filename . " for import");
		}
		
		if ((!$isgzip && fseek($file, $offset) !== 0) || ($isgzip  && gzseek($file, $offset) !== 0)) {
			throw new \Exception("Can't set file pointer to offset: " . $offset);
		}
		
		
		$query = '';
		$queries = 0;
		
		$querylines = 0;
		$inparents = false;
		
		while ( $linenumber < $start+$this->linespersession || !empty($query) ) {
			// Read the whole next line
			$dumpline = '';
			
			while (!feof($file) && substr ($dumpline, -1) !== "\n" && substr ($dumpline, -1) !== "\r") {
				$dumpline .= $isgzip ? gzgets($file, $this->data_chunk_length) : fgets($file, $this->data_chunk_length);
			}
			
			if (empty($dumpline)) {
				break;
			}
		
			$dumpline = str_replace("\r\n", "\n", $dumpline);
			$dumpline = str_replace("\r", "\n", $dumpline);
				
			// Skip comments and blank lines only if NOT in parents
		
			if (!$inparents) {
				$skipline = false;
				reset($this->comment);
				
				foreach ($this->comment as $comment_value) {
					if (!$inparents && (trim($dumpline) === '' || strpos ($dumpline, $comment_value) === 0)){
						$skipline = true;
						break;
					}
				}
				
				if ($skipline) {
					$linenumber++;
					continue;
				}
			}
				
			// Remove double back-slashes from the dumpline prior to count the quotes ('\\' can only be within strings)
				
			$dumpline_deslashed = str_replace ("\\\\", '', $dumpline);
				
			// Count ' and \' in the dumpline to avoid query break within a text field ending by ;
			// Please don't use double quotes ('"')to surround strings, it wont work
				
			$parents = substr_count($dumpline_deslashed, "'") - substr_count ($dumpline_deslashed, "\\'");
			
			if ($parents % 2 !== 0) {
				$inparents = !$inparents;
			}
				
			// Add the line to query
			$query .= $dumpline;
		
			// Don't count the line if in parents (text fields may include unlimited linebreaks)
			if (!$inparents) {
				$querylines++;
			}
		
			// Execute query if end of query detected (; as last character) AND NOT in parents
			if (preg_match("/;$/",trim($dumpline)) && !$inparents) {
				if(!$this->db->exec($query)) {
					throw new \Exception("Error in query " . $query);
				}
				$totalqueries++;
				$queries++;
				$query = '';
				$querylines =0;
			}
			$linenumber++;
		}
		
		$parsedoffset = $isgzip ? gztell($file) : ftell($file);
		
		if (!$parsedoffset) {
			throw new \Exception("Error. Can't read the file pointer offset");
		}
		
		$this->resp['lines_this']   = $linenumber-$start;	//
		$this->resp['lines_done']   = $linenumber-1;		// number of lines parsed
		$this->resp['queries_this'] = $queries;				// number of queries created in this session
		$this->resp['queries_done'] = ($totalqueries ? $totalqueries : '1');		// number of queries creted in all sessions up to the present
		
		$this->resp['bytes_this ']  = $parsedoffset - $offset;// bytes parsed in this session
		$this->resp['bytes_done']   = $parsedoffset;		// bytes parsed in all sessions up to now
		$this->resp['percent_done']  = (int)$parsedoffset*100/$this->resp['filesize'];
		
		if ($linenumber < $start+$this->linespersession ) {
			$this->resp['status'] = "completed";
		} else {
			$this->resp['status']	= 'loading';
		}
		
		$isgzip ? gzclose($file) : fclose($file);
		
	}
	
	// https://www.php.net/manual/en/function.gzread.php
	private function gzfilesize($filename)
	{
		$gzfs = FALSE;
		if(($zp = fopen($filename, 'r')) !== false) {
			if(@fread($zp, 2) === "\x1F\x8B") { // this is a gzip'd file
				fseek($zp, -4, SEEK_END);
				if(strlen($datum = @fread($zp, 4))==4)
					extract(unpack('Vgzfs', $datum));
			} else  {
			// not a gzip'd file, revert to regular filesize function
				$gzfs = filesize($filename);
			}
			fclose($zp);
		}
		return($gzfs);
	}

	private function validateFile( string $filename ): bool
	{
		if(!preg_match("/\.sql(\.gz)?$/i", $filename)) {
			throw new \Exception("Invalid file type for input file `{$filename}`. Only *.sql o *.sql.gz files can be parsed");
		}
		if (!file_exists($filename)){
			throw new \Exception("File `{$filename}` not found");
		}
		return true;
	}
	
	
	
	public function setLinepersession($linepersession)
	{
		$this->linespersession=$linepersession;
	}
	
	public function setComment($comment)
	{
		array_push( $this->comment, $comment);
	}
	
	public function setMaxquerylines($maxquerylines)
	{
		$this->maxquerylines = $maxquerylines;
	}
	
	public function setData_chunk_length($data_chunk_length)
	{
		$this->data_chunk_length = $data_chunk_length;
	}
	
	public function getResponse()
	{
		return $this->resp;
	}	
}
?>