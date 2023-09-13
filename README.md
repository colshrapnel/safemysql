# SafeMySQL

English | [Русский](https://github.com/Impeck/safemysql/blob/master/README.ru.md)

SafeMySQL is a PHP class designed for secure and efficient MySQL query handling.

Forked from [colshrapnel/safemysql](https://github.com/colshrapnel/safemysql).

It stands out for several key features:

- **Safety:** All dynamic query parts are incorporated into the query using placeholders, enhancing security.
- **Convenience:** It streamlines application code, reducing redundancy, and following the DRY (Don't Repeat Yourself) principle.

## Features

SafeMySQL offers three primary features that distinguish it from standard libraries:

1. **Type-Hinted Placeholders:** Unlike traditional libraries, SafeMySQL employs type-hinted placeholders for all query elements.
2. **Streamlined Usage:** It eliminates the need for repetitive binding and fetching, thanks to a range of helper methods.
3. **Partial Placeholder Parsing:** SafeMySQL allows placeholder parsing in any part of the query, making complex queries as easy as standard ones through the **parse()** method.

## Getting Started

Using SafeMySQL is straightforward. Here are the key steps:

1. Always use placeholders for dynamic data in your queries.
2. Mark each placeholder with a data type, including:
   - ?s ("string"): For strings (including `DATE`, `FLOAT`, and `DECIMAL`).
   - ?i ("integer"): For integers.
   - ?n ("name"): For identifiers (table and field names).
   - ?a ("array"): For complex placeholders used with the `IN()` operator (substituted with a string in 'a,'b,'c' format, without parentheses).
   - ?u ("update"): For complex placeholders used with the `SET` operator (substituted with a string in `field`='value',`field`='value' format).
   - ?p ("parsed"): A special placeholder type for inserting pre-parsed statements without further processing to avoid double parsing.
3. Utilize helper methods to retrieve data from queries, including:
   - `query($query, $param1, $param2, ...)`: Returns a mysqli resource.
   - `getOne($query, $param1, $param2, ...)`: Returns a scalar value.
   - `getRow($query, $param1, $param2, ...)`: Returns a 1-dimensional array (a row).
   - `getCol($query, $param1, $param2, ...)`: Returns a 1-dimensional array (a column).
   - `getAll($query, $param1, $param2, ...)`: Returns a 2-dimensional array (an array of rows).
   - `getInd($key, $query, $par1, $par2, ...)`: Returns an indexed 2-dimensional array (an array of rows).
   - `getIndCol($key, $query, $par1, $par2, ...)`: Returns a 1-dimensional array (an indexed column) consisting of key => value pairs.
4. For complex cases, rely on the **parse()** method.

### Example Usage

Here are some examples of how to use SafeMySQL:

```php
$name = $db->getOne('SELECT name FROM table WHERE id = ?i', $_GET['id']);
$data = $db->getInd('id', 'SELECT * FROM ?n WHERE id IN (?a)', 'table', [1, 2]);
$data = $db->getAll("SELECT * FROM ?n WHERE mod=?s LIMIT ?i", $table, $mod, $limit);
```

The standout feature of SafeMySQL is its type-hinted placeholders. This approach extends beyond simple scalar data, allowing you to include identifiers, arrays for the `IN` operator, and arrays for `INSERT` and `UPDATE` queries. No more struggling with binding field names or constructing complex queries manually.

For instance, consider binding a field name effortlessly:

```php
$field = $_POST['field'];
$value = $_POST['value'];
$sql   = "SELECT * FROM table WHERE ?n LIKE ?s";
$data  = $db->query($sql, $field, "%$value%");
```

Simplifying queries involving arrays for the `IN` operator:

```php
$array = [1, 2, 3];
$data  = $db->query("SELECT * FROM table WHERE id IN (?a)", $array);
```

The same convenience extends to complex queries like `INSERT` and `UPDATE`.

SafeMySQL also provides a set of helper functions, making database calls for everyday tasks quick and straightforward.