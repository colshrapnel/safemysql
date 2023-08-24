<?php

declare(strict_types=1);

/**
 * @author col.shrapnel@gmail.com
 * @link http://phpfaq.ru/safemysql
 * 
 * Safe and convenient way to handle SQL queries utilizing type-hinted placeholders.
 * 
 * Key features:
 * - Set of helper functions to get the desired result right out of a query, similar to PEAR::DB.
 * - Conditional query building using the parse() method to build queries of any complexity while keeping extra safety with placeholders.
 * - Type-hinted placeholders.
 * 
 * Type-hinted placeholders are great because:
 * - They are safe, just like any other properly implemented placeholders.
 * - They eliminate the need for manual escaping or binding, making the code more DRY (Don't Repeat Yourself).
 * - They allow support for non-standard types such as identifiers or arrays, saving a lot of effort.
 * 
 * Supported placeholders at the moment are:
 * - ?s ("string")  - strings (also DATE, FLOAT, and DECIMAL).
 * - ?i ("integer") - integers.
 * - ?n ("name")    - identifiers (table and field names).
 * - ?a ("array")   - a complex placeholder for IN() operator (substituted with a string in 'a','b','c' format, without parentheses).
 * - ?u ("update")  - a complex placeholder for SET operator (substituted with a string in `field`='value',`field`='value' format).
 * - ?p ("parsed")  - a special type placeholder for inserting already parsed statements without any processing, to avoid double parsing.
 * 
 * Connection:
 * 
 * $db = new SafeMySQL(); // with default settings.
 * 
 * $opts = [
 *     'user'    => 'user',
 *     'pass'    => 'pass',
 *     'db'      => 'db',
 *     'charset' => 'latin1'
 * ];
 * $db = new SafeMySQL($opts); // with some of the default settings overwritten.
 * 
 * Alternatively, you can just pass an existing mysqli instance that will be used to run queries 
 * instead of creating a new connection. An excellent choice for migration!
 * 
 * $db = new SafeMySQL(['mysqli' => $mysqli]);
 * 
 * Some examples:
 * 
 * $name = $db->getOne('SELECT name FROM table WHERE id = ?i', $_GET['id']);
 * $data = $db->getInd('id', 'SELECT * FROM ?n WHERE id IN ?a', 'table', [1, 2]);
 * $data = $db->getAll("SELECT * FROM ?n WHERE mod=?s LIMIT ?i", $table, $mod, $limit);
 * 
 * $ids  = $db->getCol("SELECT id FROM tags WHERE tagname = ?s", $tag);
 * $data = $db->getAll("SELECT * FROM table WHERE category IN (?a)", $ids);
 * 
 * $data = ['offers_in' => $in, 'offers_out' => $out];
 * $sql  = "INSERT INTO stats SET pid=?i, dt=CURDATE(), ?u ON DUPLICATE KEY UPDATE ?u";
 * $db->query($sql, $pid, $data, $data);
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

	/**
	 * @var mysqli|null The mysqli connection.
	 */
	protected $conn;

	/**
	 * @var array Query statistics.
	 */
	protected $stats;

	/**
	 * @var string The error mode ('exception' or 'error').
	 */
	protected $emode;

	/**
	 * @var string The exception class name.
	 */
	protected $exname;

	/**
	 * Default settings.
	 * 
	 * @var array
	 */
	protected $defaults = [
		'host'      => 'localhost',
		'user'      => 'root',
		'pass'      => '',
		'db'        => 'test',
		'port'      => null,
		'socket'    => null,
		'pconnect'  => false,
		'charset'   => 'utf8',
		'errmode'   => 'exception', // or 'error'
		'exception' => 'Exception', // Exception class name
	];

	/**
	 * Result format constants.
	 */
	const RESULT_ASSOC = MYSQLI_ASSOC;
	const RESULT_NUM   = MYSQLI_NUM;

	/**
	 * Constructs a new instance of the class.
	 *
	 * @param array $opt An array of options to configure the instance.
	 *     - $opt['errmode']: The error mode to use.
	 *     - $opt['exception']: The exception class to throw.
	 *     - $opt['mysqli']: An optional mysqli object to use for the connection.
	 *     - $opt['pconnect']: Whether to use a persistent connection.
	 *     - $opt['host']: The hostname of the database server.
	 *     - $opt['user']: The username to use for the connection.
	 *     - $opt['pass']: The password to use for the connection.
	 *     - $opt['db']: The name of the database to use.
	 *     - $opt['port']: The port number to use for the connection.
	 *     - $opt['socket']: The socket to use for the connection.
	 *     - $opt['charset']: The character set to use for the connection.
	 * @return void
	 */
	function __construct(array $opt = [])
	{
		$opt = array_merge($this->defaults, $opt);

		$this->emode = $opt['errmode'];
		$this->exname = $opt['exception'];

		if (isset($opt['mysqli'])) {
			if ($opt['mysqli'] instanceof mysqli) {
				$this->conn = $opt['mysqli'];
				return;
			} else {
				$this->error("mysqli option must be valid instance of mysqli class");
			}
		}

		if ($opt['pconnect']) {
			$opt['host'] = "p:" . $opt['host'];
		}

		@$this->conn = mysqli_connect(
			$opt['host'],
			$opt['user'],
			$opt['pass'],
			$opt['db'],
			$opt['port'],
			$opt['socket']
		);

		if (!$this->conn) {
			$this->error(mysqli_connect_errno() . " " . mysqli_connect_error());
		}

		mysqli_set_charset($this->conn, $opt['charset']) or $this->error(mysqli_error($this->conn));
		unset($opt); // I am paranoid
	}

	/**
	 * Conventional function to run a query with placeholders. A mysqli_query wrapper with placeholders support.
	 *
	 * @param string $query - An SQL query with placeholders.
	 * @param mixed  ...$args - Unlimited number of arguments to match placeholders in the query.
	 *
	 * @return mysqli|bool The result of the query or false if an error occurred.
	 *
	 * @example $result = $db->query("DELETE FROM table WHERE id=?i", $id);
	 */
	public function query(string $query, ...$args): mysqli|bool
	{
		return $this->rawQuery($this->prepareQuery($query, ...$args));
	}

	/**
	 * Fetch a row from a result set as an associative array or a numeric array.
	 *
	 * @param mysqli_result|null $result The result set from which to fetch the row.
	 * @param int $mode The type of array to return (RESULT_ASSOC for associative array, or RESULT_NUM for numeric array). Defaults to RESULT_ASSOC.
	 * @return array|null The fetched row as an associative array or a numeric array, or null if no more rows are available.
	 */
	public function fetch(mysqli_result|null $result, int $mode = self::RESULT_ASSOC): array|null
	{
		return $result === null ? null : mysqli_fetch_array($result, $mode);
	}

	/**
	 * Retrieve the number of rows affected by the last database operation.
	 *
	 * @return int The number of affected rows.
	 */
	public function affectedRows(): int
	{
		return mysqli_affected_rows($this->conn);
	}

	/**
	 * Retrieves the last inserted ID from the database connection.
	 *
	 * @return int The ID of the last inserted row.
	 */
	public function insertId(): int
	{
		return mysqli_insert_id($this->conn);
	}

	/**
	 * Returns the number of rows in the resultset.
	 * 
	 * @param mysqli_result $result - The mysqli result object.
	 * @return int - The number of rows in the resultset.
	 */
	public function numRows(mysqli_result $result): int
	{
		return mysqli_num_rows($result);
	}

	/**
	 * Frees the memory associated with a result.
	 *
	 * @param mixed $result The result to free.
	 * @return void
	 */
	public function free($result): void
	{
		mysqli_free_result($result);
	}

	/**
	 * Helper function to get a scalar value directly from a query and optional arguments.
	 *
	 * This function retrieves a scalar value from a query result and returns it as a string.
	 * You can use placeholders in the SQL query and provide optional arguments to match those placeholders.
	 *
	 * @param string $query - An SQL query with placeholders.
	 * @param mixed  ...$args - An unlimited number of arguments to match placeholders in the query.
	 *
	 * @return string|false - The retrieved result as a string, or false if no result is found.
	 *
	 * @example $name = $db->getOne("SELECT name FROM table WHERE id=1");
	 * @example $name = $db->getOne("SELECT name FROM table WHERE id=?i", $id);
	 */
	public function getOne(string $query, ...$args): string|false
	{
		$query = $this->prepareQuery($query, ...$args);
		if ($res = $this->rawQuery($query)) {
			$row = $this->fetch($res);
			if (is_array($row)) {
				return reset($row);
			}
			$this->free($res);
		}
		return false;
	}

	/**
	 * Retrieve a single row from a query result along with optional arguments.
	 *
	 * This function retrieves a single row from a query result and returns it as an associative array.
	 * You can use placeholders in the SQL query and provide optional arguments to match those placeholders.
	 *
	 * @param string $query - An SQL query with placeholders.
	 * @param mixed  ...$args - Unlimited number of arguments to match placeholders in the query.
	 *
	 * @return array|null - An associative array containing the fetched row from the database,
	 *                     or null if the query execution fails or no rows are found.
	 *
	 * @example $data = $db->getRow("SELECT * FROM table WHERE id=1");
	 * @example $data = $db->getRow("SELECT * FROM table WHERE id=?i", $id);
	 */
	public function getRow(string $query, ...$args): array|null
	{
		$query = $this->prepareQuery($query, ...$args);
		if ($res = $this->rawQuery($query)) {
			$ret = $this->fetch($res);
			$this->free($res);
			return $ret;
		}
		return null;
	}

	/**
	 * Retrieve a single column from a query result along with optional arguments.
	 *
	 * This function retrieves a single column from a query result and returns it as an enumerated array.
	 * You can use placeholders in the SQL query and provide optional arguments to match those placeholders.
	 *
	 * @param string $query - An SQL query with placeholders.
	 * @param mixed  ...$args - Unlimited number of arguments to match placeholders in the query.
	 *
	 * @return array - An enumerated array containing the values of the first field of all rows in the resultset.
	 *                Returns an empty array if no rows are found.
	 *
	 * @example $ids = $db->getCol("SELECT id FROM table WHERE cat=1");
	 * @example $ids = $db->getCol("SELECT id FROM tags WHERE tagname = ?s", $tag);
	 */
	public function getCol(string $query, ...$args): array
	{
		$ret   = array();
		$query = $this->prepareQuery($query, ...$args);
		if ($res = $this->rawQuery($query)) {
			while ($row = $this->fetch($res)) {
				$ret[] = reset($row);
			}
			$this->free($res);
		}
		return $ret;
	}

	/**
	 * Retrieve all rows from a query result along with optional arguments.
	 *
	 * This function retrieves all rows from a query result and structures them into an enumerated 2D array.
	 * You can use placeholders in the SQL query and provide optional arguments to match those placeholders.
	 *
	 * @param string $query - An SQL query with placeholders.
	 * @param mixed  ...$args - Unlimited number of arguments to match placeholders in the query.
	 *
	 * @return array - An enumerated 2D array containing the resultset. Returns an empty array if no rows are found.
	 *
	 * @example $data = $db->getAll("SELECT * FROM table");
	 * @example $data = $db->getAll("SELECT * FROM table LIMIT ?i,?i", $start, $rows);
	 */
	public function getAll(string $query, ...$args): array
	{
		$ret   = array();
		$query = $this->prepareQuery($query, ...$args);
		if ($res = $this->rawQuery($query)) {
			while ($row = $this->fetch($res)) {
				$ret[] = $row;
			}
			$this->free($res);
		}
		return $ret;
	}

	/**
	 * Retrieve rows from a query and organize them into an indexed array.
	 *
	 * Retrieves rows from a query result and structures them into an indexed array.
	 * This function is especially useful when you want to index the resulting array by a specific field.
	 *
	 * @param string $index - The name of the field used to index the resulting array.
	 * @param string $query - An SQL query with placeholders.
	 * @param mixed  ...$args - Unlimited number of arguments to match placeholders in the query (optional).
	 *
	 * @return array - An associative 2D array containing the resultset. Returns an empty array if no rows are found.
	 *
	 * @example $data = $db->getInd("id", "SELECT * FROM table");
	 * @example $data = $db->getInd("id", "SELECT * FROM table LIMIT ?i,?i", $start, $rows);
	 */
	public function getInd(string $index, string $query, ...$args): array
	{
		if ($query !== null) {
			$query = $this->prepareQuery($query, ...$args);
		}

		$result = [];
		if ($query === null || $res = $this->rawQuery($query)) {
			while ($row = $this->fetch($res)) {
				$result[$row[$index]] = $row;
			}
			if ($query !== null) {
				$this->free($res);
			}
		}

		return $result;
	}

	/**
	 * Helper function to get a dictionary-style array right out of a query and optional arguments.
	 *
	 * This function retrieves rows from a query result and structures them into a dictionary-style array.
	 * It's particularly useful when you want to index the resulting array by a specific field.
	 *
	 * @param string $index - The name of the field to use as the index in the resulting array.
	 * @param string $query - An SQL query with placeholders.
	 * @param mixed  ...$args - An unlimited number of arguments to match placeholders in the query.
	 *
	 * @return array - An associative array containing key=value pairs from the resultset. Returns an empty array if no rows are found.
	 *
	 * @example $data = $db->getIndCol("name", "SELECT name, id FROM cities");
	 */
	public function getIndCol(string $index, string $query, ...$args): array
	{
		$query = $this->prepareQuery($query, ...$args);

		$ret = [];
		if ($res = $this->rawQuery($query)) {
			while ($row = $this->fetch($res)) {
				$key = $row[$index];
				unset($row[$index]);
				$ret[$key] = reset($row);
			}
			$this->free($res);
		}

		return $ret;
	}

	/**
	 * Function to parse placeholders either in the full query or a query part.
	 *
	 * Unlike native prepared statements, this function allows ANY query part to be parsed.
	 * It is useful for debugging and EXTREMELY useful for conditional query building,
	 * such as adding various query parts using loops, conditions, etc.
	 * Already parsed parts have to be added via ?p placeholder.
	 *
	 * @param string $query - Whatever expression contains placeholders.
	 * @param mixed  ...$args - An unlimited number of arguments to match placeholders in the expression.
	 *
	 * @return string - The initial expression with placeholders substituted with data.
	 *
	 * {@example
	 * $query = $db->parse("SELECT * FROM table WHERE foo=?s AND bar=?s", $foo, $bar);
	 * echo $query;
	 *
	 * if ($foo) {
	 *     $qpart = $db->parse(" AND foo=?s", $foo);
	 * }
	 * $data = $db->getAll("SELECT * FROM table WHERE bar=?s ?p", $bar, $qpart);
	 * }
	 */
	public function parse(string $query, ...$args): string
	{
		return $this->prepareQuery($query, ...$args);
	}

	/**
	 * Implements whitelisting feature.
	 *
	 * This function whitelists user-supplied data to ensure it matches one of the allowed values.
	 * It's especially useful when dealing with SQL queries, particularly for SQL operators and sorting/ordering fields.
	 *
	 * @param string $input   - Field name to test.
	 * @param  array  $allowed - An array with allowed variants.
	 * @param  string $default - Optional variable to set if no match is found. Defaults to false.
	 * @return string|false    - Either sanitized value or false.
	 *
	 * {@example
	 *     $order = $db->whiteList($_GET['order'], ['name','price']);
	 *     $dir   = $db->whiteList($_GET['dir'], ['ASC','DESC']);
	 *     if (!$order || !$dir) {
	 *         throw new Http404Exception(); // Non-expected values should trigger a 404 or similar response.
	 *     }
	 *     $sql  = "SELECT * FROM table ORDER BY ?p ?p LIMIT ?i,?i";
	 *     $data = $db->getArr($sql, $order, $dir, $start, $per_page);
	 * }
	 */
	public function whiteList($input, $allowed, $default = false)
	{
		$found = array_search($input, $allowed);
		return ($found === false) ? $default : $allowed[$found];
	}

	/**
	 * Filter an array to only include allowed field names for whitelisting purposes.
	 *
	 * This function is useful when you want to filter an array, such as a superglobal, to ensure that only
	 * the specified fields are retained. It should be used, especially when preparing data for INSERT or UPDATE queries,
	 * to restrict access to fields that a user should not have access to.
	 *
	 * @param  array $input   The source array to filter.
	 * @param  array $allowed An array containing allowed field names.
	 * @return array The filtered source array containing only allowed fields.
	 *
	 * {@example
	 *     $allowed = ['title', 'url', 'body', 'rating', 'term', 'type'];
	 *     $data = $db->filterArray($_POST, $allowed);
	 *     $sql = "INSERT INTO ?n SET ?u";
	 *     $db->query($sql, $table, $data);
	 * }
	 */
	public function filterArray(array $input, array $allowed): array
	{
		foreach (array_keys($input) as $key) {
			if (!in_array($key, $allowed)) {
				unset($input[$key]);
			}
		}
		return $input;
	}

	/**
	 * Get the last executed query.
	 *
	 * @return string|null The last executed query, or null if there were none.
	 */
	public function lastQuery(): ?string
	{
		$last = end($this->stats);
		return $last['query'];
	}

	/**
	 * Get all query statistics.
	 *
	 * @return array An array containing all executed queries with timings and errors.
	 */
	public function getStats(): array
	{
		return $this->stats;
	}

	/**
	 * Protected function which actually runs a query against the MySQL server.
	 * It also logs some statistics like profiling information and error messages.
	 *
	 * @param string $query - A regular SQL query.
	 * @return mysqli_result|bool A mysqli_result object on success, or bool on error.
	 */
	protected function rawQuery(string $query): mysqli_result|bool
	{
		$start = microtime(TRUE);
		$res   = mysqli_query($this->conn, $query);
		$timer = microtime(TRUE) - $start;

		$this->stats[] = [
			'query' => $query,
			'start' => $start,
			'timer' => $timer,
		];

		if (!$res) {
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

	/**
	 * Protected function to prepare an SQL query with placeholders and values.
	 *
	 * This function replaces placeholders in the SQL query with the provided values and returns the prepared query.
	 * It supports several placeholder types:
	 * - ?n for identifiers (table/column names).
	 * - ?s for string values.
	 * - ?i for integer values.
	 * - ?a for IN clause values (array of values).
	 * - ?u for SET clause values (associative array of column-value pairs).
	 * - ?p for raw, unescaped values.
	 *
	 * @param string $raw The raw SQL query with placeholders.
	 * @param mixed  ...$args An unlimited number of arguments to match placeholders in the query.
	 *
	 * @return string The prepared SQL query.
	 */
	protected function prepareQuery(string $raw, ...$args): string
	{
		$query = '';
		$array = preg_split('~(\?[nsiuap])~u', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
		$anum  = count($args);
		$pnum  = floor(count($array) / 2);

		if ($pnum != $anum) {
			$this->error("Number of arguments ($anum) doesn't match the number of placeholders ($pnum) in [$raw]");
		}

		foreach ($array as $i => $part) {
			if (($i % 2) == 0) {
				$query .= $part;
				continue;
			}

			$value = array_shift($args);
			switch ($part) {
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
					$part = $value;
					break;
			}
			$query .= $part;
		}

		return $query;
	}

	/**
	 * Protected function to escape an integer value.
	 *
	 * @param int|float|null $value The value to escape as an integer. May also be NULL.
	 *
	 * @return string|false The escaped integer value as a string or FALSE if an error occurs.
	 */
	protected function escapeInt(int|float|null $value): string|false
	{
		if ($value === null) {
			return 'NULL';
		}

		if (!is_numeric($value)) {
			$this->error("Integer (?i) placeholder expects a numeric value, " . gettype($value) . " given");
			return false;
		}

		if (is_float($value)) {
			$value = number_format($value, 0, '.', ''); // May lose precision on big numbers.
		}

		return (string) $value;
	}

	/**
	 * Protected function to escape a string value.
	 *
	 * @param string|null $value The string value to escape. May also be NULL.
	 *
	 * @return string The escaped string value enclosed in single quotes, or 'NULL' if the value is NULL.
	 */
	protected function escapeString(string|null $value): string
	{
		if ($value === null) {
			return 'NULL';
		}

		return "'" . mysqli_real_escape_string($this->conn, $value) . "'";
	}

	/**
	 * Protected function to escape an identifier value.
	 *
	 * @param string|null $value The identifier value to escape. May also be NULL.
	 *
	 * @return string The escaped identifier value enclosed in backticks or an empty string if the value is NULL.
	 */
	protected function escapeIdent(string|null $value): string
	{
		if ($value) {
			return "`" . str_replace("`", "``", $value) . "`";
		} else {
			$this->error("Empty value for identifier (?n) placeholder");
			return '';
		}
	}

	/**
	 * Protected function to create an IN clause for SQL queries.
	 *
	 * @param array|null $data The array of values for the IN clause. May also be NULL.
	 *
	 * @return string|null The formatted IN clause or NULL if the array is empty or NULL.
	 */
	protected function createIN(array|null $data): string|null
	{
		if (!is_array($data)) {
			$this->error("Value for IN (?a) placeholder should be an array");
			return null;
		}

		if (empty($data)) {
			return 'NULL';
		}

		$query = $comma = '';
		foreach ($data as $value) {
			$query .= $comma . $this->escapeString($value);
			$comma  = ",";
		}

		return $query;
	}

	/**
	 * Protected function to create a SET clause for SQL queries.
	 *
	 * @param array|null $data The associative array of key-value pairs for the SET clause. May also be NULL.
	 *
	 * @return string|null The formatted SET clause or NULL if the array is empty or NULL.
	 */
	protected function createSET(array|null $data): string|null
	{
		if (!is_array($data)) {
			$this->error("SET (?u) placeholder expects an array, " . gettype($data) . " given");
			return null;
		}

		if (empty($data)) {
			$this->error("Empty array for SET (?u) placeholder");
			return null;
		}

		$query = $comma = '';
		foreach ($data as $key => $value) {
			$query .= $comma . $this->escapeIdent($key) . '=' . $this->escapeString($value);
			$comma  = ",";
		}

		return $query;
	}

	/**
	 * Protected function to handle errors in the query builder.
	 *
	 * @param string $err The error message to be handled.
	 *
	 * @throws QueryBuilderException If the error handling mode is 'exception'.
	 */
	protected function error(string $err): void
	{
		$err = __CLASS__ . ": " . $err;

		if ($this->emode == 'error') {
			$err .= ". Error initiated in " . $this->caller() . ", thrown";
			trigger_error($err, E_USER_ERROR);
		} else {
			throw new $this->exname($err);
		}
	}

	/**
	 * Protected function to determine the caller location.
	 *
	 * @return string The file and line number where the error was initiated.
	 */
	protected function caller(): string
	{
		$trace = debug_backtrace();
		$caller = '';
		foreach ($trace as $t) {
			if (isset($t['class']) && $t['class'] == __CLASS__) {
				$caller = $t['file'] . " on line " . $t['line'];
			} else {
				break;
			}
		}
		return $caller;
	}

	/**
	 * Trim the query statistics to limit memory usage.
	 *
	 * On a long run, statistics can consume excessive memory. This method keeps the statistics array
	 * at a reasonable size by retaining only the last 100 entries.
	 */
	protected function cutStats(): void
	{
		if (count($this->stats) > 100) {
			reset($this->stats);
			$first = key($this->stats);
			unset($this->stats[$first]);
		}
	}
}
