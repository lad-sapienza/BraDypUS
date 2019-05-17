<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 19, 2012
 */

class Charts
{
	private $db, $out;

	public function __construct(DB $db)
	{
		$this->db = $db;
	}

	/**
	 * Deletes record from charts table
	 * @param int $id
	 */
	public function erase($id)
	{
		return $this->db->query('DELETE FROM `' . PREFIX . 'charts` WHERE `id` = :id', array(':id'=>$id), 'boolean');
	}

	/**
	 * Addes new chart query text to chart table
	 * @param string $name
	 * @param string $query_text
	 */
	public function save($name, $query_text)
	{
		$query = 'INSERT INTO `' . PREFIX . 'charts`'
		. '(`user_id`, `name`, `query`, `date`)'
		. ' VALUES '
		. '(:user_id, :name, :query, :date)';

		$values = array(
				':user_id'=> $_SESSION['user']['id'],
				':name'=> $name,
				':query'=> base64_decode($query_text),
				':date'=> date('Y-m-d G:i:s')
		);

		return $this->db->query($query, $values, 'boolean');
	}

	/**
	 * Returns JSON results using query ID or queru text
	 * @param int $id
	 * @param string $query_text
	 * @throws myException
	 */
	public function formatResult($id = false, $query_text = false)
	{
		if (!$id AND !$query_text)
		{
			throw new myException('Chart ID or Query text are required');
		}

		if ($id)
		{
			$res = $this->db->query('SELECT * FROM `' . PREFIX . 'charts` WHERE `id` = ' . $id, false, 'read');

			if (!$res)
			{
				throw new myException('Chart ID ' . $id . ' does not exist');
			}
			$query_text = $res[0]['query'];
		}

		if (!$query_text)
		{
			throw new myException('No query text!');
		}

		$out['query_text'] = $query_text;

		$data = $this->db->query($query_text, false, 'read');

		if (!$data)
		{
			throw new myException('Chart query text produced no result (' . $query_text . ')');
		}

		$row = 0;
		foreach($data as $id=>$one_series)
		{
			if ($one_series['series_name'] == '')
			{
				$one_series['series_name'] = tr::get('no_value');
			}

			$out['opt_series'][$row] = array('label'=>$one_series['series_name']);

			unset($one_series['series_name']);

			$column = 0;
			foreach ($one_series as $label=>$value)
			{
				$out['data'][$row][$column] = (int)$value;

				$out['ticks'][$column] = $label;

				$column++;
			}

			$row++;
		}

		$this->out = $out;

		return $this;

		//return json_encode($out, SON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
	}

	public function getData()
	{
		return array(
				'encoded_query'	=> base64_encode($this->out['query_text']),
				'json_data'		=> json_encode($this->out['data']),
				'json_series'	=> json_encode($this->out['opt_series']),
				'json_ticks'	=> json_encode($this->out['ticks'])
				);
	}


	public function showChart()
	{
		if (!$this->out)
		{
			throw new myException('Database info not formatted.');
		}

		$uid = uniqid('rand');

		$html = '<input type="hidden" name="query_text" value="' . base64_encode($this->out['query_text']) . '" class="query_text" />'
		. '<div id="' . $uid . '"></div>'
		. '<script>'
		. 'var plot = $.jqplot(\'' . $uid. '\', ' . json_encode($this->out['data']) . ', {'
			. 'seriesDefaults:{'
				. 'renderer:$.jqplot.BarRenderer,'
				. 'pointLabels: { show: true },'
				. 'rendererOptions: {fillToZero: true}'
			. '},'

			. 'legend: {'
				. 'show: true,'
				//. "location: 'ne',"
				//. "xoffset: -115"
				. "placement: 'outsideGrid'"
			. '},'

			. 'series:' . json_encode($this->out['opt_series']) . ','

			. 'axes: {'
				. 'xaxis: {'
					. 'renderer: $.jqplot.CategoryAxisRenderer,'
					. 'ticks: ' . json_encode($this->out['ticks'])
				. '},'
			.' yaxis: {pad: 1.05}}'

		. '});'
		. '</script>';

		return $html;
	}

	/**
	 * Return array of all available charts or false
	 * @param int $id
	 */
	public function getCharts($id = false)
	{
		return $this->db->query('SELECT * FROM `' . PREFIX . 'charts` WHERE ' . ( $id ? ' `id`=' . $id : ' 1 ') , false, 'read');
	}

	public function update($id, $name = false, $query = false)
	{
		if (!$name && $query)
		{
			throw new myException('Nothing to update');
		}
		if ($name)
		{
			$part[] = ' `name`=:name ';
			$val[':name'] = $name;
		}

		if($query)
		{
			$part[] = ' `query`=:query ';
			$val[':query'] = Query::makeSafeStatement($query);
		}

		return $this->db->query('UPDATE `' . PREFIX . 'charts` SET ' . implode(', ', $part) . ' WHERE `id` = ' . $id, $val, 'boolean');
	}
}
