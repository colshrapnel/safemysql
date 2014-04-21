# SafeMySQL

SafeMySQL is a PHP class for **safe** and **convenient** handling of MySQL queries.
- safe because every dynamic query part goes into the query via placeholder
- convenient because it lets you write code that is short, meaningful and <abbr title="Don't Repeat Yourself">DRY</abbr> rather than writing lots of boilerplate for every query

This class provides three advantages over using raw [MySQLi](http://www.php.net/manual/en/book.mysqli.php) or [PDO](http://www.php.net/manual/en/book.pdo.php):
- it is uses **type-hinted placeholders** for **everything** that can be templated into a query
- the whole (tiny) API is built around letting you write **less, simpler code**:
  - no repetitive binding or fetching statements
  - executing a query (with placeholders) and getting back a result set is done in a single PHP statement
  - a handful of convenience functions allow common tasks, like `UPDATE`ing a table using an array mapping column names to values, to be done succinctly and easily
- the `parse()` method and `?p` placeholder allow unusually complicated queries to be built up piece-by-piece while still using type-hinted placeholders, making complex queries as safe and convenient as simple ones

Yet it is very easy to use. You need learn only a few things.

## Usage

1. You have to always pass variables into the query via *placeholder*
2. Each placeholder has to be marked with its data type. At the moment there are 6 types:
 * `?s` **("string")**              - strings (also `DATE`, `FLOAT` and `DECIMAL`)
 * `?i` **("integer")**             - integers
 * `?n` **("name")**                - identifiers (table and field names) 
 * `?a` **("array")**               - complex placeholder for `IN ()` clauses (expects an array of values; the placeholder will be substituted for a string in 'a','b','c' format, without parenthesis)
 * `?u` **("update")**              - complex placeholder for `SET` clauses (expects an associative array mapping field names to values; the placeholder will be substituted for a string in `` `field` ='value', `field` ='value' `` format)
 * `?m` **("multi-row")**           - complex placeholder for bulk `INSERT` queries with a `VALUES` clause. Expects an array of arrays, with the child arrays representing rows to be inserted. The placeholder will be substituted for a string in `('a', 'b', 'c'), ('e', 'f', 'g')` format.
 * `?k` **("key/value multi-row")** - another complex placeholder for `INSERT` queries with `VALUES` clauses. Expects an array of associative arrays, with the associative arrays representing the rows to be inserted as field => value mappings. The placeholder will be substituted for a string like `` (`col1`, `col2`) VALUES ('a', 'b'), ('c', 'd') ``
 * `?p` **("parsed")**              - special placeholder for inserting already parsed query components without any processing, to avoid double parsing
3. To get data right out of the query there are several helper methods:
 * `query($query,$param1,$param2, ...)` - returns mysqli resource.
 * `getOne($query,$param1,$param2, ...)` - returns a single scalar value
 * `getRow($query,$param1,$param2, ...)` - returns 1-dimensional array, a row
 * `getCol($query,$param1,$param2, ...)` - returns 1-dimensional array, a column
 * `getAll($query,$param1,$param2, ...)` - returns 2-dimensional array, an array of rows
 * `getInd($key,$query,$par1,$par2, ...)` - returns an indexed 2-dimensional array, an array of rows
 * `getIndCol($key,$query,$par1,$par2, ...)` - returns 1-dimensional array, an indexed column, consists of key => value pairs
4. For complex cases always use the `parse()` method, and insert already parsed parts via `?p` placeholder

The rest is as usual - just write regular SQL (with placeholders) and get a result:

* ```$name = $db->getOne('SELECT name FROM table WHERE id = ?i',$_GET['id']);```
* ```$data = $db->getInd('id','SELECT * FROM ?n WHERE id IN (?a)','table', array(1,2));```
* ```$data = $db->getAll("SELECT * FROM ?n WHERE mod=?s LIMIT ?i",$table,$mod,$limit);```

## What's so great about type-hinted placeholders?

The main feature of this class is *type-hinted placeholders*. And it's really great step further from just ordinal placeholders used in prepared statements, simply because **dynamical parts of the query aren't limited to just scalar data!**

In real life we have to add identifiers, arrays for `IN` operator, arrays for `INSERT` and `UPDATE` queries. So we need **many** different types of data formatting. Thus, we need the way to tell the driver how to format this particular data.

Conventional prepared statements use toilsome and repeating `bind_*` functions. But there is a far more sleek way - to set the type along with placeholder itself. This isn't revolutionary - the well-known `printf()` function uses exactly the same mechanism. So I didn't hesitate to borrow such a useful idea.

To implement such a feature, all we need is a simple query-parsing layer. But the benefits are innumerable. 
Look at all the questions on Stack Overflow with developers trying in vain to bind a field name.
Voila - with the identifier placeholder it is just like adding a field value:

```php
$field = $_POST['field'];
$value = $_POST['value'];
$sql   = "SELECT * FROM table WHERE ?n LIKE ?s";
$data  = $db->query($sql,$field,"%$value%");
```

Nothing could be easier!

Of course we will have placeholders for the common types - strings and numbers. But as we started inventing new placeholders - let's make some more!

Another difficult in creating prepared queries is arrays going to IN operator. Everyone is trying to do it their own way but the type-hinted placeholder makes it as simple as adding a string:

```php
$array = array(1,2,3);
$data  = $db->query("SELECT * FROM table WHERE id IN (?a)",$array);
```

The same goes for other toilsome queries like `INSERT` and `UPDATE`.

Combined with SafeMySQL's helper functions, these placeholders make almost every call to database as simple as one or two lines of code for all regular real life tasks.

## Some Examples

### Initialization

```php
$db = new SafeMySQL(); // with default settings
```

```php
$opts = array(
 *		'user'    => 'user',
 *		'pass'    => 'pass',
 *		'db'      => 'db',
 *		'charset' => 'latin1'
);
$db = new SafeMySQL($opts); // with some of the default settings overridden
```

### SELECT queries

```php
$name = $db->getOne('SELECT name FROM table WHERE id = ?i',$_GET['id']);
```

```php
$data = $db->getInd('id','SELECT * FROM ?n WHERE id IN ?a','table', array(1,2));
```

```php
$data = $db->getAll("SELECT * FROM ?n WHERE mod=?s LIMIT ?i",$table,$mod,$limit);
```

```php
$ids  = $db->getCol("SELECT id FROM tags WHERE tagname = ?s",$tag);
$data = $db->getAll("SELECT * FROM table WHERE category IN (?a)",$ids);
```

```php
if ($var === NULL) {
    $sqlpart = "field is NULL";
} else {
    $sqlpart = $db->parse("field = ?s", $var);
}
$data = $db->getAll("SELECT * FROM table WHERE ?p", $bar, $sqlpart);
```

### INSERT queries

```php
$data = array('offers_in' => $in, 'offers_out' => $out);
$sql  = "INSERT INTO stats SET pid=?i,dt=CURDATE(),?u ON DUPLICATE KEY UPDATE ?u";
$db->query($sql,$pid,$data,$data);
```

```php
$cars = array(
    array('Audi A3', 22, 24500),
    array('Ford Ka', 36, 29000),
    array('Ferrari 159 S', 792, 80000)
);
$db->query("INSERT INTO cars (model, age, mileage) ?m", $cars);
```

```php
$cars = array(
    array('model'=>'Audi A3',       'age'=>22,  'mileage'=>24500),
    array('model'=>'Ford Ka',       'age'=>36,  'mileage'=>29000),
    array('model'=>'Ferrari 159 S', 'age'=>792, 'mileage'=>80000)
);
$allowedColumns = array('model', 'age', 'mileage');
$filteredCars = $db->filter2DArray($_POST['cars'], $allowedColumns);
$db->query("INSERT INTO cars ?k", $filteredCars);
```