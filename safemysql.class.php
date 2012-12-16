<?php

class SafeMySQL
{
	public	$lastquery;

	private $conn;
	private $emode;
	private $exname;

	private $defaults = array(
		'host'      => 'localhost',
		'user'      => 'root',
		'pass'      => '',
		'db'        => 'test',
		'port'      => NULL,
		'socket'    => NULL,
		'pconnect'  => FALSE,
		'charset'   => 'utf8',
		'errmode'   => 'error', //or exception
		'exception' => 'Exception', //Exception class name
	);

	const RESULT_ASSOC = MYSQLI_ASSOC;
	const RESULT_NUM   = MYSQLI_NUM;

	function __construct($opt = array())
	{
		$opt = array_merge($this->defaults,$opt);

		$this->emode  = $opt['errmode'];
		$this->exname = $opt['exception'];

		if ($opt['pconnect'])
		{
			$opt['host'] = "p:".$opt['host'];
		}

		@$this->conn = mysqli_connect($opt['host'], $opt['user'], $opt['pass'], $opt['db'], $opt['port'], $opt['socket']);
		if ( !$this->conn )
		{
			$this->error(mysqli_connect_errno()." ".mysqli_connect_error());
		}

		mysqli_set_charset($this->conn, $opt['charset']) or $this->error(mysqli_error($this->conn));
		unset($opt); // I am paranoid
	}

	public function query()
	{	
		return $this->rawQuery($this->prepareQuery(func_get_args()));
	}

	public function fetch($result,$mode=self::RESULT_ASSOC)
	{
		return mysqli_fetch_array($result, $mode);
	}

	public function affected_rows()
	{
		return mysqli_affected_rows ($this->conn);
	}

	public function insert_id()
	{
		return mysqli_insert_id($this->conn);
	}

	public function num_rows($result)
	{
		return mysqli_num_rows($result);
	}

	public function free($result)
	{
		mysqli_free_result($result);
	}

	public function getOne()
	{
		$query = $this->prepareQuery(func_get_args());
		if ($res = $this->rawQuery($query))
		{
			$row = $this->fetch($res);
			if (is_array($row)) {
				return reset($row);
			}
			$this->free($res);
		}
		return FALSE;
	}

	public function getRow()
	{
		$query = $this->prepareQuery(func_get_args());
		if ($res = $this->rawQuery($query)) {
			$ret = $this->fetch($res);
			$this->free($res);
			return $ret;
		}
		return FALSE;
	}

	public function getCol()
	{
		$ret   = array();
		$query = $this->prepareQuery(func_get_args());
		if ( $res = $this->rawQuery($query) )
		{
			while($row = $this->fetch($res))
			{
				$ret[] = reset($row);
			}
			$this->free($res);
		}
		return $ret;
	}

	public function getAll()
	{
		$ret   = array();
		$query = $this->prepareQuery(func_get_args());
		if ( $res = $this->rawQuery($query) )
		{
			while($row = $this->fetch($res))
			{
				$ret[] = $row;
			}
			$this->free($res);
		}
		return $ret;
	}

	public function getInd()
	{
		$args  = func_get_args();
		$index = array_shift($args);
		$query = $this->prepareQuery($args);

		$ret = array();
		if ( $res = $this->rawQuery($query) )
		{
			while($row = $this->fetch($res))
			{
				$ret[$row[$index]] = $row;
			}
			$this->free($res);
		}
		return $ret;
	}

	public function getIndCol()
	{
		$args  = func_get_args();
		$index = array_shift($args);
		$query = $this->prepareQuery($args);

		$ret = array();
		if ( $res = $this->rawQuery($query) )
		{
			while($row = $res->fetch($res))
			{
				$key = $row[$index];
				unset($row[$index]);
				$ret[$key] = reset($row);
			}
			$this->free($res);
		}
		return $ret;
	}

	public function parse()
	{
		return $this->prepareQuery(func_get_args());
	}

	public function whiteList($input,$allowed,$strict=FALSE)
	{
		$found = array_search($input,$allowed);
		if ($strict && ($found === FALSE))
		{
			return FALSE;
		} else {
			return $allowed[(int)$found];
		}
	}

	public function filterArray($input,$allowed)
	{
		foreach(array_keys($input) as $key )
		{
			if ( !in_array($key,$allowed) )
			{
				unset($input[$key]);
			}
		}
		return $input;
	}

	private function rawQuery($query)
	{
		$this->lastquery = $query;
		$res = mysqli_query($this->conn, $query) or $this->error(mysqli_error($this->conn).". Full query: [$query]");
		return $res;
	}

	private function prepareQuery($args)
	{
		$raw = $query = array_shift($args);
		preg_match_all('~(\?[a-z?])~',$query,$m,PREG_OFFSET_CAPTURE);
		$pholders = $m[1];
		$count = 0;
		foreach ($pholders as $i => $p)
		{
			if ($p[0] != '??')
			{
				 $count++;
			}
		}
		if ( $count != count($args) )
		{
			$this->error("Number of args (".count($args).") doesn't match number of placeholders ($count) in [$raw]");
		}
		$shift  = 0;
		$qmarks = 0;
		foreach ($pholders as $i => $p)
		{
			$pholder = $p[0];
			$offset  = $p[1] + $shift;
			if ($pholder != '??')
			{
				$value   = $args[$i-$qmarks];
			}
			switch ($pholder)
			{
				case '?n':
					$value = $this->escapeIdent($value);
					break;
				case '?s':
					$value = $this->escapeString($value);
					break;
				case '?i':
					$value = $this->escapeInt($value);
					break;
				case '?a':
					$value = $this->createIN($value);
					break;
				case '?u':
					$value = $this->createSET($value);
					break;
				case '??':
					$value = '?';
					$qmarks++;
					break;
				case '?p':
					break;
				default:
					$this->error("Unknown placeholder type ($pholder) in [$raw]");
			}
			$query = substr_replace($query,$value,$offset,2);
			$shift+= strlen($value) - strlen($pholder);
		}
		return $query;
	}

	private function escapeInt($value)
	{
		if (is_float($value))
		{
			return number_format($value, 0, '.', ''); // may lose precision on big numbers
		} 
		elseif(is_numeric($value))
		{
			return (string)$value;
		}
		else
		{
			$this->error("Invalid value for ?i (int) placeholder: [$value](".gettype($value).")");
		}
	}

	private function escapeString($value)
	{
		return	"'".mysqli_real_escape_string($this->conn,$value)."'";
	}

	private function escapeIdent($value)
	{
		if ($value)
		{
			return "`".str_replace("`","``",$value)."`";
		} else {
			$this->error("Empty value for ?n (identifier) placeholder.");
		}
	}

	private function createIN($data)
	{
		if (!is_array($data))
		{
			$this->error("Value for ?a (IN) placeholder should be array.");
			return;
		}
		if (!$data)
		{
			return 'NULL';
		}
		$query = $comma = '';
		foreach ($data as $key => $value)
		{
			$query .= $comma.$this->escapeString($value);
			$comma  = ",";
		}
		return $query;
	}

	private function createSET($data)
	{
		if (!is_array($data))
		{
			$this->error("Value for ?u (SET) placeholder should be an array. ".gettype($data)." passed instead.");
			return;
		}
		if (!$data)
		{
			$this->error("Empty array for ?u (SET) placeholder.");
			return;
		}
		$query = $comma = '';
		foreach ($data as $key => $value)
		{
			$query .= $comma.$this->escapeIdent($key).'='.$this->escapeString($value);
			$comma  = ",";
		}
		return $query;
	}

	private function error($err)
	{
		$err  = __CLASS__.": ".$err;

		if ( $this->emode == 'error' )
		{
			$err .= ". Error initiated in ".$this->caller().", thrown";
			trigger_error($err,E_USER_ERROR);
		} else {
			throw new $this->exname($err);
		}
	}

	private function caller()
	{
		$trace  = debug_backtrace();
		$caller = '';
		foreach ($trace as $t)
		{
			if ( isset($t['class']) && $t['class'] == __CLASS__ )
			{
				$caller = $t['file']." on line ".$t['line'];
			} else {
				break;
			}
		}
		return $caller;
	}
}
