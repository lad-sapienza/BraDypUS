<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 16, 2012
 */


class Matrix
{
	
	private $dotfile, $dotcontent;
	
	public function __construct($tb, $where_q = false, DB $db)
	{
		$query = "SELECT * FROM `" . PREFIX . "__rs` WHERE `tb` = '" . $tb . "'";
		
		if ($where_q)
		{
			$where = base64_decode($where_q);
			$query .=  ' AND ('
				. '`first` IN (SELECT `id` FROM `' . $tb . '` WHERE '. $where . ')' 
				. ' OR ' 
				. '`second` IN (SELECT `id` FROM `' . $tb . '` WHERE '. $where . ')'
			.' )';
		}
		
		$where = $where ? $where : '1';
		
		$this->dotcontent = $this->createDotContent($tb, $where, $db);
		
	}
	
	private function createDotContent($tb, $where = false, DB $db)
	{
	
		// Formulate query
		$query = "SELECT * FROM `" . PREFIX . "__rs` WHERE `tb` = '" . $tb . "'";
		
		if ($where){
			$query .=  ' AND ('
				. '`first` IN (SELECT `' . cfg::tbEl($tb, 'rs') . '` FROM `' . $tb . '` WHERE '. $where . ')' 
				. ' OR ' 
				. '`second` IN (SELECT `' . cfg::tbEl($tb, 'rs') . '` FROM `' . $tb . '` WHERE '. $where . ')'
			.' )';
		}
		
		// run query and get results
		$rsResult = $db->query($query);
		
		if (!is_array($rsResult))
		{
			throw new myException('query_produced_no_result');
		}
		
		// create array of values to retrieve id from database
		foreach ($rsResult as $arr)
		{
			$id_arr[$arr['first']] = true;
			$id_arr[$arr['second']] = true;
		}
		
		
		$id_arr = array_keys($id_arr);
		
		// ru secondary query to get id from database
		$mainTbResult = $db->query('SELECT `id`, `' . cfg::tbEl($tb, 'rs') . '` FROM `' . $tb . '` WHERE `' . cfg::tbEl($tb, 'rs') . "` IN ('" . implode("','", $id_arr). "') ", false, false, true);
		
		if (!is_array($mainTbResult))
		{
			throw new myException('query_produced_no_result');
		}
		
		// Create map array with pair of id: rs_filds
		foreach ($mainTbResult as $res)
		{
			$map[$res[1]] = $res[0];
		}
		
		// Create empty arrays for nodes and edges
		$container = array('nodes' => array(), $edges = array());
		
		/*
		 * Foreach result of first query extract nodes and edges
		 */
		foreach ($rsResult as $arr)
		{
			/*
			 * Set first_id, second_id, first_label and second label
			 * 
			 * Check if FIRST is in map
			 * if found map id will be used as first_id
			 * otherwize 'dont' + label will be used
			 * Do the same for second
			 */
			$first['id'] = $map[$arr['first']] ? $map[$arr['first']] : 'dont' . $arr['first'];
			
			$first['label'] = $arr['first'];
			
			
			$second['id'] = $map[$arr['second']] ? $map[$arr['second']] : 'dont' . $arr['second'];
			
			$second['label'] = $arr['second'];
			
			$container = $this->addRel($container, $first, $second, $arr['relation']);
			
			
		}// end of loop foreach main result. Nodes and edges are all set now!

		
		if (is_array($container['nodes']) && is_array($container['edges']))
		{
			$dot = "digraph G {\n" .
				"    concentrate=true;\n";
			
			foreach ($container['nodes'] as $id =>$label)
			{
				 $dot .= '   ' . $id . '[label="' . implode('=', $label) . '"];' . "\n";
			}
			
			foreach($container['edges'] as $arr)
			{
				$dot .= '  "' . $arr['upper'] . '"->"' . $arr['lower'] . '";' . "\n";
			}
				
			
			$dot .=  "}";
			
			return $dot;
		}
		
		return false;
		
	}
	
	private function addRel($container, $first, $second, $relation)
	{
		// $container[nodes]
		// $container[edges]
		//$first[id]
		////$first[label]
		//$second[id]
		//$second[label]
		
		// check if first is already present in nodes
		foreach($container['nodes'] as $id => &$labels_array)
		{
			// Test for first: node already set
			if (in_array($first['label'], $labels_array))
			{
				// change first id to match the array index. One id for multiple equals
				$first['id'] = $id;
				
				// if relation is equal, add sendond label to array;
				if ($relation == 9 || $relation == 10)
				{
					array_push($labels_array, $second['label']);
					$second_found = true;
				}
				$first_found = true;
				break;
			}
			
			// Test for second: node already set
			if (in_array($second['label'], $labels_array))
			{
				// change first id to match the array index. One id for multiple equals
				$second['id'] = $id;
				
				// if relation is equal, add sendond label to array;
				if ($relation == 9 || $relation == 10)
				{
					array_push($labels_array, $first['label']);
					$first_found = true;
				}
				$econd_found = true;
				break;
			}
		} // end of nodes loop
		
		
		// Add first node, if not found in nodes
		if (!$first_found)
		{
			$container['nodes'][$first['id']] = array($first['label']);
		}
		
		// Add first node, if not found in nodes
		if (!$second_found)
		{
			$container['nodes'][$second['id']] = array($second['label']);
		}
		
		
		// set edges
		// Set Edges array
		if ($relation > 0 && $relation < 5)
		{
			$container['edges'][] = array('upper' => (string)$second['id'], 'lower' => (string)$first['id']);
			//$edges[$second_id] = $first_id;
		}
		else if ($relation > 4 AND $relation < 9)
		{
			$container['edges'][] = array('upper' => (string)$first['id'], 'lower' => (string)$second['id']);
			//$edges[$first_id] = $second_id;
		}
		
		
		return $container;
	}
	
	
	
	public function write_dot($dotfile)
	{
		$this->dotfile = $dotfile;
		
		if (utils::write_in_file($this->dotfile, $this->dotcontent))
		{
			return $this;
		}
		else
		{
			throw new myException("cannot_write_in_tmp_file");
		}
	}
	
	
	private function clear_dot_file()
	{
		if (!file_exists($this->dotfile))
		{
			throw new myException('dotfile_doesnot_exist');
		}
        
		$command = cfg::main('dot_path') . 'tred ' . escapeshellarg($this->dotfile);
		
		exec($command, $msg, $return_val);
		
		
		if(is_array($msg))
		{
			if($msg[(count($msg)-2)]=="warning: G has cycle(s), transitive reduction not unique")
			{
				error_log(preg_replace("/cycle involves edge /",null , $msg[(count($msg)-1)]));
				throw new myException('cicle_problem');
			}
		}

		$text = '';

		foreach($msg as $line)
		{
			$text .= $line . "\n";
		}

		if (utils::write_in_file($this->dotfile, $text))
		{
			return $this;
		}
		else
		{
			throw new myException('cannot_write_in_tmp_file');
		}
	}
	
	
	public function getFromWeb()
	{
		//return "http://public.research.att.com/~north/cgi-bin/webdot/webdot.cgi/http://" . $_SERVER['HTTP_HOST'] . "/" . $this->dotfile . ".dot.png";
		//return 'https://chart.googleapis.com/chart?cht=gv&chl=' . urlencode(file_get_contents($this->dotfile));
		return "http://server.bradypus.net/apiDot/?dot_url=http://" . $_SERVER['HTTP_HOST'] . "/" . $this->dotfile . "";
	}
	
	public function getDotContent()
	{
		return $this->dotcontent;
	}
	
	
	public function renderDotFile($format = 'png', $dont_clear = false)
	{
		$outputfile = PROJ_TMP_DIR . uniqid('matrix') . '.' . $format;
		
		if (!file_exists($this->dotfile))
		{
			throw new myException('dotfile_doesnot_exist');
		}
		
		if (!$dont_clear)
		{
			$this->clear_dot_file($this->dotfile);
		}
		
		$command = cfg::main('dot_path') . 'dot'
			. ' -T' . escapeshellarg($format)
			. ' -o' . escapeshellarg($outputfile)
			. ' ' . escapeshellarg($this->dotfile)
			. ' 2>&1';
		
		exec($command, $msg, $return_val);
		
		
		if ($msg AND !file_exists($outputfile))
		{
			throw new myException('error_dot_cmd');
		}
		
		return $outputfile;

        clearstatcache();
    }
}

 
?>