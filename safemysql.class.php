<?php
/**
 * @author col.shrapnel@gmail.com
 * @link http://phpfaq.ru/safemysql
 * 
 * Safe and convenient vay to handle SQL queries utilizing type-hinted placeholders.
 * 
 * Key features
 * - set of helper functions to get the desired result right out of query, like in PEAR::DB
 * - conditional query building using parse() method to build queries of whatever comlexity, 
 *   while keeping extra safety of placeholders
 * - type-hinted placeholders
 * 
 *  Type-hinted placeholders are great because 
 * - safe, as any other [properly implemented] placeholders
 * - no need for manual escaping or binding, makes the code extra DRY
 * - allows support for non-standard types such as identifier or array, which saves A LOT of pain in the back.
 * 
 * Supported placeholders at the moment are:
 * 
 * ?s ("string")  - strings (also DATE, FLOAT and DECIMAL)
 * ?i ("integer") - the name says it all 
 * ?n ("name")    - identifiers (table and field names) 
 * ?a ("array")   - complex placeholder for IN() operator  (substituted with string of 'a','b','c' format, without parentesis)
 * ?u ("update")  - complex placeholder for SET operator (substituted with string of `field`='value',`field`='value' format)
 * and
 * ?p ("parsed") - special type placeholder, for inserting already parsed statements without any processing, to avoid double parsing.
 * 
 * Some examples:
 *
 * $db = new SafeMySQL(); // with default settings
 * 
 * $opts = array(
 *		'user'    => 'user',
 *		'pass'    => 'pass',
 *		'db'      => 'db',
 *		'charset' => 'latin1'
 * );
 * $db = new SafeMySQL($opts); // with some of the default settings overwritten
 * 
 * 
 * $name = $db->getOne('SELECT name FROM table WHERE id = ?i',$_GET['id']);
 * $data = $db->getInd('id','SELECT * FROM ?n WHERE id IN ?a','table', array(1,2));
 * $data = $db->getAll("SELECT * FROM ?n WHERE mod=?s LIMIT ?i",$table,$mod,$limit);
 *
 * $ids  = $db->getCol("SELECT id FROM tags WHERE tagname = ?s",$tag);
 * $data = $db->getAll("SELECT * FROM table WHERE category IN (?a)",$ids);
 * 
 * $data = array('offers_in' => $in, 'offers_out' => $out);
 * $sql  = "INSERT INTO stats SET pid=?i,dt=CURDATE(),?u ON DUPLICATE KEY UPDATE ?u";
 * $db->query($sql,$pid,$data,$data);
 * 
 * if ($var === NULL) {
 *     $sqlpart = "field is NULL";
 * } else {
 *     $sqlpart = $db->parse("field = ?s", $var);
 * }
 * $data = $db->getAll("SELECT * FROM table WHERE ?p", $bar, $sqlpart);
 * 
 */

class SafeMySQL
{
	private $conn;
	private $stats;
	private $emode;
	private $exname;
	private $charset;
	private $strs;
	private $sql_mode;
	private $quotes;
	private $pattern;
	private $esc_str;
	private $esc_len;
	
	private static final $defaults = array(
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

	private static final $encodings = array(		
		'ascii'   => 'ASCII',
		'big5'    => 'BIG-5',
		'cp1251'  => 'Windows-1251',
		'cp866'   => 'CP866',
		'cp932'   => 'CP932',
		'eucjpms' => 'eucJP-win',
		'euckr'   => 'EUC-KR',
		'greek'   => 'ISO-8859-7',
		'koi8r'   => 'KOI8-R',
		'latin1'  => 'Windows-1252',
		'latin2'  => 'ISO-8859-2',
		'latin5'  => 'ISO-8859-9',
		'latin7'  => 'ISO-8859-13',
		'sjis'    => 'SJIS',
		'ucs2'    => 'UCS-2',
		'ujis'    => 'EUC-JP',
		'utf8'    => 'UTF-8',
		'utf8mb4' => 'UTF-8',
		'utf16'   => 'UTF-16',
		'utf16le' => 'UTF-16LE',
		'utf32'   => 'UTF-32',
	);
	
	private static final $strs = array(
		'NULL',
		',',
		'=',
	);

	const RESULT_ASSOC = MYSQLI_ASSOC;
	const RESULT_NUM   = MYSQLI_NUM;
	const QUOTES_ASCII = '`\'"';
	const PARAMS_ASCII = 'nsiuap';
	define('PATTERN_ASCII', '[' . self::QUOTES_ASCII . ']|\\?[' . self::PARAMS_ASCII . '](:[^\\d\\s]\\S*)?\\b');

	function __construct($opt = array())
	{
		$opt = array_merge(self::$defaults,$opt);

		$this->emode   = $opt['errmode'];
		$this->exname  = $opt['exception'];
		$this->charset = self::$encodings[$opt['charset']];
		$this->pattern = mb_convert_encoding(self::PATTERN_ASCII, $this->charset, 'ASCII');
		$this->esc_str = mb_convert_encoding('\\', $this->charset, 'ASCII');
		$this->esc_len = strlen($this->esc_txenc_str);
		$this->strs    = array();

		foreach (self::$strs as $s)
		{
			$this->strs[$s] = mb_convert_encoding($s, $this->charset, 'ASCII');
		}

		if ($opt['pconnect'])
		{
			$opt['host'] = 'p:'.$opt['host'];
		}

		@$this->conn = mysqli_connect($opt['host'], $opt['user'], $opt['pass'], $opt['db'], $opt['port'], $opt['socket']);
		if ( !$this->conn )
		{
			$this->error(mysqli_connect_errno().' '.mysqli_connect_error());
		}

		mysqli_set_charset($this->conn, $opt['charset']) or $this->error(mysqli_error($this->conn));
		unset($opt); // I am paranoid

		$this->getSQLmode();
	}
	
	private function getSQLmode()
	{
		$this->sql_mode = array();
		$sql_modes = explode(',', $this->getOne('SHOW SESSION VARIABLES LIKE \'sql_mode\''));
		foreach ($sql_modes as $mode) $this->sql_mode[$mode] = true;
		
		$this->quotes = isset($this->sql_mode['ANSI_QUOTES']) ? '\'' : '\'"';
	}

	/**
	 * Conventional function to run a query with placeholders. A mysqli_query wrapper with placeholders support
	 * 
	 * Examples:
	 * $db->query("DELETE FROM table WHERE id=?i", $id);
	 *
	 * @param string $query - an SQL query with placeholders
	 * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
	 * @return resource|FALSE whatever mysqli_query returns
	 */
	public function query()
	{	
		return $this->rawQuery($this->prepareQuery(func_get_args()));
	}

	/**
	 * Conventional function to fetch single row. 
	 * 
	 * @param resource $result - myqli result
	 * @param int $mode - optional fetch mode, RESULT_ASSOC|RESULT_NUM, default RESULT_ASSOC
	 * @return array|FALSE whatever mysqli_fetch_array returns
	 */
	public function fetch($result,$mode=self::RESULT_ASSOC)
	{
		return mysqli_fetch_array($result, $mode);
	}

	/**
	 * Conventional function to get number of affected rows. 
	 * 
	 * @return int whatever mysqli_affected_rows returns
	 */
	public function affectedRows()
	{
		return mysqli_affected_rows ($this->conn);
	}

	/**
	 * Conventional function to get last insert id. 
	 * 
	 * @return int whatever mysqli_insert_id returns
	 */
	public function insertId()
	{
		return mysqli_insert_id($this->conn);
	}

	/**
	 * Conventional function to get number of rows in the resultset. 
	 * 
	 * @param resource $result - myqli result
	 * @return int whatever mysqli_num_rows returns
	 */
	public function numRows($result)
	{
		return mysqli_num_rows($result);
	}

	/**
	 * Conventional function to free the resultset. 
	 */
	public function free($result)
	{
		mysqli_free_result($result);
	}

	/**
	 * Helper function to get scalar value right out of query and optional arguments
	 * 
	 * Examples:
	 * $name = $db->getOne("SELECT name FROM table WHERE id=1");
	 * $name = $db->getOne("SELECT name FROM table WHERE id=?i", $id);
	 *
	 * @param string $query - an SQL query with placeholders
	 * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
	 * @return string|FALSE either first column of the first row of resultset or FALSE if none found
	 */
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

	/**
	 * Helper function to get single row right out of query and optional arguments
	 * 
	 * Examples:
	 * $data = $db->getRow("SELECT * FROM table WHERE id=1");
	 * $data = $db->getOne("SELECT * FROM table WHERE id=?i", $id);
	 *
	 * @param string $query - an SQL query with placeholders
	 * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
	 * @return array|FALSE either associative array contains first row of resultset or FALSE if none found
	 */
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

	/**
	 * Helper function to get single column right out of query and optional arguments
	 * 
	 * Examples:
	 * $ids = $db->getCol("SELECT id FROM table WHERE cat=1");
	 * $ids = $db->getCol("SELECT id FROM tags WHERE tagname = ?s", $tag);
	 *
	 * @param string $query - an SQL query with placeholders
	 * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
	 * @return array|FALSE either enumerated array of first fields of all rows of resultset or FALSE if none found
	 */
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

	/**
	 * Helper function to get all the rows of resultset right out of query and optional arguments
	 * 
	 * Examples:
	 * $data = $db->getAll("SELECT * FROM table");
	 * $data = $db->getAll("SELECT * FROM table LIMIT ?i,?i", $start, $rows);
	 *
	 * @param string $query - an SQL query with placeholders
	 * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
	 * @return array enumerated 2d array contains the resultset. Empty if no rows found. 
	 */
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

	/**
	 * Helper function to get all the rows of resultset into indexed array right out of query and optional arguments
	 * 
	 * Examples:
	 * $data = $db->getInd("id", "SELECT * FROM table");
	 * $data = $db->getInd("id", "SELECT * FROM table LIMIT ?i,?i", $start, $rows);
	 *
	 * @param string $index - name of the field which value is used to index resulting array
	 * @param string $query - an SQL query with placeholders
	 * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
	 * @return array - associative 2d array contains the resultset. Empty if no rows found. 
	 */
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

	/**
	 * Helper function to get a dictionary-style array right out of query and optional arguments
	 * 
	 * Examples:
	 * $data = $db->getIndCol("name", "SELECT name, id FROM cities");
	 *
	 * @param string $index - name of the field which value is used to index resulting array
	 * @param string $query - an SQL query with placeholders
	 * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
	 * @return array - associative array contains key=value pairs out of resultset. Empty if no rows found. 
	 */
	public function getIndCol()
	{
		$args  = func_get_args();
		$index = array_shift($args);
		$query = $this->prepareQuery($args);

		$ret = array();
		if ( $res = $this->rawQuery($query) )
		{
			while($row = $this->fetch($res))
			{
				$key = $row[$index];
				unset($row[$index]);
				$ret[$key] = reset($row);
			}
			$this->free($res);
		}
		return $ret;
	}

	/**
	 * Function to parse placeholders either in the full query or a query part
	 * unlike native prepared statements, allows ANY query part to be parsed
	 * 
	 * useful for debug
	 * and EXTREMELY useful for conditional query building
	 * like adding various query parts using loops, conditions, etc.
	 * already parsed parts have to be added via ?p placeholder
	 * 
	 * Examples:
	 * $query = $db->parse("SELECT * FROM table WHERE foo=?s AND bar=?s", $foo, $bar);
	 * echo $query;
	 * 
	 * if ($foo) {
	 *     $qpart = $db->parse(" AND foo=?s", $foo);
	 * }
	 * $data = $db->getAll("SELECT * FROM table WHERE bar=?s ?p", $bar, $qpart);
	 *
	 * @param string $query - whatever expression contains placeholders
	 * @param mixed  $arg,... unlimited number of arguments to match placeholders in the expression
	 * @return string - initial expression with placeholders substituted with data. 
	 */
	public function parse()
	{
		return $this->prepareQuery(func_get_args());
	}

	/**
	 * function to implement whitelisting feature
	 * sometimes we can't allow a non-validated user-supplied data to the query even through placeholder
	 * especially if it comes down to SQL OPERATORS
	 * 
	 * Example:
	 *
	 * $order = $db->whiteList($_GET['order'], array('name','price'));
	 * $dir   = $db->whiteList($_GET['dir'],   array('ASC','DESC'));
	 * if (!$order || !dir) {
	 *     throw new http404(); //non-expected values should cause 404 or similar response
	 * }
	 * $sql  = "SELECT * FROM table ORDER BY ?p ?p LIMIT ?i,?i"
	 * $data = $db->getArr($sql, $order, $dir, $start, $per_page);
	 * 
	 * @param string $iinput   - field name to test
	 * @param  array  $allowed - an array with allowed variants
	 * @param  string $default - optional variable to set if no match found. Default to false.
	 * @return string|FALSE    - either sanitized value or FALSE
	 */
	public function whiteList($input,$allowed,$default=FALSE)
	{
		$found = array_search($input,$allowed);
		return ($found === FALSE) ? $default : $allowed[$found];
	}

	/**
	 * function to filter out arrays, for the whitelisting purposes
	 * useful to pass entire superglobal to the INSERT or UPDATE query
	 * OUGHT to be used for this purpose, 
	 * as there could be fields to which user should have no access to.
	 * 
	 * Example:
	 * $allowed = array('title','url','body','rating','term','type');
	 * $data    = $db->filterArray($_POST,$allowed);
	 * $sql     = "INSERT INTO ?n SET ?u";
	 * $db->query($sql,$table,$data);
	 * 
	 * @param  array $input   - source array
	 * @param  array $allowed - an array with allowed field names
	 * @return array filtered out source array
	 */
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

	/**
	 * Function to get last executed query. 
	 * 
	 * @return string|NULL either last executed query or NULL if were none
	 */
	public function lastQuery()
	{
		$last = end($this->stats);
		return $last['query'];
	}

	/**
	 * Function to get all query statistics. 
	 * 
	 * @return array contains all executed queries with timings and errors
	 */
	public function getStats()
	{
		return $this->stats;
	}

	/**
	 * private function which actually runs a query against Mysql server.
	 * also logs some stats like profiling info and error message
	 * 
	 * @param string $query - a regular SQL query
	 * @return mysqli result resource or FALSE on error
	 */
	private function rawQuery($query)
	{
		$start = microtime(TRUE);
		$res   = mysqli_query($this->conn, $query);
		$timer = microtime(TRUE) - $start;

		$this->stats[] = array(
			'query' => $query,
			'start' => $start,
			'timer' => $timer,
		);
		if (!$res)
		{
			$error = mysqli_error($this->conn);
			
			end($this->stats);
			$key = key($this->stats);
			$this->stats[$key]['error'] = $error;
			$this->cutStats();
			
			$this->error("$error. Full query: [$query]");
		}
		$this->cutStats();
		return $res;
	}
	
	private function skipQuotedPart(final $raw, final $quote_raw) {
		final $len = strlen($quote_raw);
		final $quote_ascii = mb_convert_encoding($quote_raw, 'ASCII', $this->charset);
		
		// look for possible terminating quotes
		while ($qpos = mb_ereg_search_pos($quote_raw))
		{
			$start = $qpos[0] - $this->esc_len;
			$end   = $qpos[0] + $qpos[1];
			
			// if it's escaped...
			if (  !isset($this->sql_mode['NO_BACKSLASH_ESCAPES'])
			  and strpos($this->quotes, $quote_ascii) !== false
			  and $start >= 0
			  and substr($raw, $start, $this->esc_len) === $this->esc_str)
			{
				// keep looking
				continue;
			}
			
			// if it's doubled...
			elseif (substr($raw, $end, $len) === $quote_raw) {
				$end += $len;
				
				// ...and the double ends the statement, give up
				if ($end >= strlen($raw)) return false;

				// else skip the double and keep looking
				mb_ereg_search_setpos($end);
				continue;
			}
			
			// found terminating pair
			return true;
		}
		return false;
	}

	private function prepareQuery($args)
	{
		final $statement_str = array_shift($args);

		final $anon_args = array_filter(array_keys($args), 'is_int');
		final $name_args = array_diff_key($args, $anon_args);
		final $used_args = array();
		
		final $anum = count($anon_args);

		$statement_sql = '';
		$position_last = 0;
		
		mb_regex_encoding($this->charset) or $this->error('Unable to set encoding');
		mb_ereg_search_init($statement_str);
		
		while ($pos = mb_ereg_search_pos($this->pattern_txenc))
		{
			$match = mb_ereg_search_getregs()[0];
			
			switch (mb_strlen($match, $this->charset))
			{
				case 1: // found a quote character
					if (!$this->skipQuotedPart($statement_str, $match))
					{
						$this->error('Unterminated quote found: [' . substr($statement_str, $pos[0]) . ']');
					}
					
					// continue searching
					continue 2;
				
				case 2: // found an anonymous placeholder
					if (empty($anon_args))
					{
						$this->error("More anonymous placeholders than in [$raw] than provided integer-keyed args ($anum)");
					}
					
					// get the value
					$value = array_shift($anon_args);
					break;
				
				default: // found a named placeholder
					$key = mb_substr($match, 3, mb_strlen($match_len, $this->charset), $this->charset);
					if (!isset($name_args[$key]))
					{
						$this->error("Named placeholder ($key) not found amongst provided args");
					}
					
					// get the value, but don't unset
					// allows named placeholders to be used multiple times
					// instead, record that this argument has been used
					$value = $args[$key];
					$used_args[$key] = true;
					break;
			}

			$type = mb_convert_encoding(mb_substr($match, 0, 2, $this->charset), 'ASCII', $this->charset);
			switch ($type)
			{
				case '?n':
					$part = $this->escapeIdent($value);
					break;
				case '?s':
					$part = $this->escapeString($value);
					break;
				case '?i':
					$part = $this->escapeInt($value);
					break;
				case '?a':
					$part = $this->createIN($value);
					break;
				case '?u':
					$part = $this->createSET($value);
					break;
				case '?p':
					$part = $this->checkParsed($value);
					break;
				default:
					$this->error("Unhandled parameter type ($type)");
			}
			$statement_sql .= mb_strcut($statement_str, $position_last, $pos[0]-$position_last, $this->charset) . $part;
			$position_last  = $pos[0] + $pos[1];
		}
		$statement_sql .= mb_strcut($statement_str, $position_last, null, $this->charset);
		
		// check that all provided arguments have been used
		if (count($anon_args))
		{
			$pnum = $anum - count($anon_args);
			$this->error("$anum indexed values provided, but only $pnum used by anonymous placeholders");
		}
		
		$nnum = count($name_args);
		$unum = count($used_args);
		if ($nnum != $unum)
		{
			$this->error("$nnum associative values provided, but only $unum used by named placeholders");
		}
		
		return $statement_sql;
	}
	
	private function checkParsed($value) {
		if (!mb_check_encoding($value, $this->charset))
		{
			$this->error('Parsed (?p) placeholder not valid in '.$this->charset.' encoding');
			return;
		}
		return $value;
	}

	private function escapeInt($value)
	{
		if ($value === NULL)
		{
			return $this->strs['NULL'];
		}
		if(!is_numeric($value))
		{
			$this->error('Integer (?i) placeholder expects numeric value, '.gettype($value).' given');
			return FALSE;
		}
		if (is_float($value))
		{
			$value = number_format($value, 0, '.', ''); // may lose precision on big numbers
		} 
		return mb_convert_encoding($value,$this->charset,'ASCII');
	}

	private function escapeString($value)
	{
		if ($value === NULL)
		{
			return $this->strs['NULL'];
		}
		return	"'".mysqli_real_escape_string($this->conn,$value)."'";
	}

	private function escapeIdent($value)
	{
		if ($value)
		{
			mb_regex_encoding($this->charset) or $this->error('Unable to set encoding');
			return '`'.mb_ereg_replace('`','``',$value).'`';
		} else {
			$this->error('Empty value for identifier (?n) placeholder');
		}
	}

	private function createIN($data)
	{
		if (!is_array($data))
		{
			$this->error('Value for IN (?a) placeholder should be array');
			return;
		}
		if (!$data)
		{
			return $this->strs['NULL'];
		}
		$query = $comma = '';
		foreach ($data as $value)
		{
			$query .= $comma.$this->escapeString($value);
			$comma  = $this->strs[','];
		}
		return $query;
	}

	private function createSET($data)
	{
		if (!is_array($data))
		{
			$this->error('SET (?u) placeholder expects array, '.gettype($data).' given');
			return;
		}
		if (!$data)
		{
			$this->error('Empty array for SET (?u) placeholder');
			return;
		}
		$query = $comma = '';
		foreach ($data as $key => $value)
		{
			$query .= $comma.$this->escapeIdent($key).$this->strs['='].$this->escapeString($value);
			$comma  = $this->strs[','];
		}
		return $query;
	}

	private function error($err)
	{
		$err  = __CLASS__.': '.$err;

		if ( $this->emode == 'error' )
		{
			$err .= '. Error initiated in '.$this->caller().', thrown';
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
				$caller = $t['file'].' on line '.$t['line'];
			} else {
				break;
			}
		}
		return $caller;
	}

	/**
	 * On a long run we can eat up too much memory with mere statsistics
	 * Let's keep it at reasonable size, leaving only last 100 entries.
	 */
	private function cutStats()
	{
		if ( count($this->stats) > 100 )
		{
			reset($this->stats);
			$first = key($this->stats);
			unset($this->stats[$first]);
		}
	}
}
