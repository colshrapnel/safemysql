<?php

use PHPUnit\Framework\TestCase;
use Impeck\SafeMySQL;

class SafeMySQLTest extends TestCase
{
    protected static $opts = [
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'db' => 'savemysql'
    ];
    protected $db;

    public static function setUpBeforeClass(): void
    {
        $conn = @new mysqli(self::$opts['host'], self::$opts['user'], self::$opts['password']);

        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "CREATE SCHEMA IF NOT EXISTS " . self::$opts['db'];

        $conn->query($sql);

        $conn->close();
    }

    public function setUp(): void
    {
        $this->db = new SafeMySQL(self::$opts);

        $createTableQuery = '
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL
            )
        ';
        $this->db->query($createTableQuery);
    }

    public function testUsersTableExists()
    {
        $query = "SHOW TABLES LIKE 'users'";
        $result = $this->db->getRow($query);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testInsertQuery()
    {
        $query = 'INSERT INTO users (username, email, created_at) VALUES (?s, ?s, ?s)';
        $result = $this->db->query($query, 'Username1', 'email@example.com', '2023-08-24 10:42:12');
        $this->assertTrue($result);

        $data = ['username' => 'Username1', 'email' => 'email@example.com'];
        $query  = "INSERT INTO users SET id=?i, created_at=NOW(), ?u ON DUPLICATE KEY UPDATE ?u";
        $result =  $this->db->query($query, 2, $data, $data);
        $this->assertTrue($result);
    }

    public function testSelectQuery()
    {
        $query = 'SELECT username FROM users WHERE id = ?i';
        $result = $this->db->getOne($query, 1);

        $this->assertEquals('Username1', $result);
    }

    public function testGetRow()
    {
        // Test case 1: Test retrieving a single row from a query result
        $result = $this->db->getRow("SELECT * FROM users WHERE id=1");
        $this->assertIsArray($result);
        $this->assertEquals(4, count($result));

        // Test case 2: Test retrieving a single row from a query result with placeholders
        $result = $this->db->getRow("SELECT * FROM users WHERE id=?i", 1);
        $this->assertIsArray($result);
        $this->assertEquals(4, count($result));

        // Test case 3: Test retrieving a single row from a query result with multiple placeholders
        $result = $this->db->getRow("SELECT * FROM users WHERE id=?i AND username=?s", 1, "Username1");
        $this->assertIsArray($result);
        $this->assertEquals(4, count($result));

        // Test case 4: Test retrieval of no rows
        $result = $this->db->getRow("SELECT * FROM users WHERE id=999");
        $this->assertNull($result);
    }

    public function testGetColWithNoRows()
    {
        $result = $this->db->getCol("SELECT id FROM users WHERE id=1");

        $this->assertIsArray($result);
        $this->assertEquals(1, count($result));
    }

    public function testGetColWithRows()
    {
        $result = $this->db->getCol("SELECT id FROM users WHERE username = ?s LIMIT 1", 'Username1');

        $this->assertIsArray($result);
        $this->assertEquals(1, count($result));
    }

    public function testGetAll(): void
    {
        // Test case 1: Retrieving all rows from a query result with no placeholders
        $query = "SELECT * FROM users";

        $result = $this->db->getAll($query);
        $this->assertIsArray($result);

        // Test case 2: Retrieving all rows from a query result with placeholders
        $start = 0;
        $rows = 2;
        $query = "SELECT * FROM users LIMIT ?i,?i";

        $result = $this->db->getAll($query, $start, $rows);
        $this->assertIsArray($result);
        $this->assertEquals(2, count($result));

        // Test case 3: Retrieving no rows from a query result
        $query = "SELECT * FROM users WHERE id = ?i";
        $result = $this->db->getAll($query, 1);
        $this->assertIsArray($result);
        $this->assertEquals(1, count($result));
    }

    public function testGetInd()
    {
        $expectedResult = [
            '1' => ['id' => '1', 'username' => 'Username1', 'email' => 'email@example.com', 'created_at' => '2023-08-24 10:42:12']
        ];

        $result = $this->db->getInd('id', 'SELECT * FROM users WHERE id = ?i', 1);
        $this->assertEquals($expectedResult, $result);
    }

    public function testGetIndWithNoRows()
    {
        $expectedResult = [];

        $result = $this->db->getInd('id', 'SELECT * FROM users WHERE id > 10');

        $this->assertEquals($expectedResult, $result);
    }

    public function testGetIndCol()
    {
        // Testing when resultset is empty
        $result = $this->db->getIndCol('username', 'SELECT username, id FROM users WHERE id > 10');
        $this->assertEquals([], $result);

        // Testing when resultset has one row
        $result = $this->db->getIndCol('username', 'SELECT username, id FROM users WHERE id = 1');
        $this->assertEquals(['Username1' => 1], $result);

        // Testing when resultset has multiple rows
        $result = $this->db->getIndCol('username', 'SELECT username, id FROM users');
        $this->assertIsArray($result);
    }

    public function testParseFunction()
    {
        // Test case 1: Test parsing a simple query with placeholders
        $query1 = "SELECT * FROM table WHERE foo=?s AND bar=?s";
        $foo1 = 'value1';
        $bar1 = 'value2';
        $expected1 = "SELECT * FROM table WHERE foo='value1' AND bar='value2'";
        $result1 = $this->db->parse($query1, $foo1, $bar1);
        $this->assertEquals($expected1, $result1);

        // Test case 2: Test parsing a query with a query part
        $query2 = "SELECT * FROM table WHERE bar=?s ?p";
        $bar2 = 'value3';
        $qpart2 = " AND foo='value1'";
        $expected2 = "SELECT * FROM table WHERE bar='value3'  AND foo='value1'";
        $result2 = $this->db->parse($query2, $bar2, $qpart2);
        $this->assertEquals($expected2, $result2);
    }

    public function testWhiteList()
    {
        // Testing when $input matches one of the allowed values
        $this->assertEquals('name', $this->db->whiteList('name', ['name', 'price']));
        $this->assertEquals('ASC', $this->db->whiteList('ASC', ['ASC', 'DESC']));

        // Testing when $input does not match any of the allowed values
        $this->assertEquals(false, $this->db->whiteList('color', ['name', 'price']));
        $this->assertEquals(false, $this->db->whiteList('NONE', ['ASC', 'DESC']));

        // Testing when $default is provided and $input does not match any of the allowed values
        $this->assertEquals('default', $this->db->whiteList('color', ['name', 'price'], 'default'));
        $this->assertEquals('default', $this->db->whiteList('NONE', ['ASC', 'DESC'], 'default'));
    }

    public function testFilterArray()
    {
        $input = [
            'title' => 'Title',
            'url' => 'http://example.com',
            'body' => 'Lorem ipsum dolor sit amet',
            'rating' => 5,
            'term' => 'Term',
            'type' => 'Type',
            'extra_field' => 'Extra Field',
        ];
        $allowed = ['title', 'url', 'body', 'rating', 'term', 'type'];

        $expected = [
            'title' => 'Title',
            'url' => 'http://example.com',
            'body' => 'Lorem ipsum dolor sit amet',
            'rating' => 5,
            'term' => 'Term',
            'type' => 'Type',
        ];

        $filtered = $this->db->filterArray($input, $allowed);

        $this->assertEquals($expected, $filtered);
    }

    public function testLastQuery(): void
    {
        $this->db->getCol("SELECT id FROM users WHERE id=1");

        $this->assertEquals('SELECT id FROM users WHERE id=1', $this->db->lastQuery());
    }

    public function testdropUsersTable()
    {
        $query = "DROP TABLE IF EXISTS users";

        $result = $this->db->query($query);
        $this->assertTrue($result);
    }
}
