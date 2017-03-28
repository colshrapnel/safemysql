SafeMySQL
=========

SafeMySQL is a PHP class for safe and convenient handling of MySQL queries.
- Safe because <b>every</b> dynamic query part goes into the query via <b>placeholder</b>
- Convenient because it makes application code short and meaningful, without useless repetitions, making it ''extra'' <abbr title="Don't Repeat Yourself">DRY</abbr>

This class is distinguished by three main features
- Unlike standard libraries, it is using **type-hinted placeholders**, for the **everything** that may be put into the query
- Unlike standard libraries, it requires no repetitive binding, fetching and such,
thanks to set of helper methods to get the desired result right out of the query
- Unlike standard libraries, it can parse placeholders not in the whole query only, but in the arbitary query part, 
thanks to the indispensabe **parse()** method, making complex queries as easy and safe as regular ones.

Yet, it is very easy to use. You need to learn only a few things:

1. You have to **always** pass whatever dynamical data into the query via *placeholder*
2. Each placeholder have to be marked with data type. At the moment there are six types:
 * ?s ("string")  - strings (also ```DATE```, ```FLOAT``` and ```DECIMAL```)
 * ?i ("integer") - the name says it all 
 * ?n ("name")    - identifiers (table and field names) 
 * ?a ("array")   - complex placeholder for ```IN()``` operator  (substituted with string of 'a','b','c' format, without parentesis)
 * ?u ("update")  - complex placeholder for ```SET``` operator (substituted with string of `field`='value',`field`='value' format)
 * ?p ("parsed")  - special type placeholder, for inserting already parsed statements without any processing, to avoid double parsing.
3. To get data right out of the query there are helper methods for the most used:
 * query($query,$param1,$param2, ...) - returns mysqli resource.
 * getOne($query,$param1,$param2, ...) - returns scalar value
 * getRow($query,$param1,$param2, ...) - returns 1-dimensional array, a row
 * getCol($query,$param1,$param2, ...) - returns 1-dimensional array, a column
 * getAll($query,$param1,$param2, ...) - returns 2-dimensional array, an array of rows
 * getInd($key,$query,$par1,$par2, ...) - returns an indexed 2-dimensional array, an array of rows
 * getIndCol($key,$query,$par1,$par2, ...) - returns 1-dimensional array, an indexed column, consists of key => value pairs
4. For the whatever complex case always use the **parse()** method. And insert 

The rest is as usual - just create a regular SQL (with placeholders) and get a result:

* ```$name = $db->getOne('SELECT name FROM table WHERE id = ?i',$_GET['id']);```
* ```$data = $db->getInd('id','SELECT * FROM ?n WHERE id IN (?a)','table', array(1,2));```
* ```$data = $db->getAll("SELECT * FROM ?n WHERE mod=?s LIMIT ?i",$table,$mod,$limit);```

The main feature of this class is a <i>type-hinted placeholders</i>. 
And it's a really great step further from just ordinal placeholders used in prepared statements. 
Simply because <b>dynamical parts of the query aren't limited to just scalar data!</b>
In the real life we have to add identifiers, arrays for ```IN``` operator, and arrays for ```INSERT``` and ```UPDATE``` queries.
So - we need <b>many</b> different types of data formatting. Thus, we need the way to tell the driver how to format this particular data. 
Conventional prepared statements use toilsome and repeating bind_* functions. 
But there is a way more sleek and useful way - to set the type along with placeholder itself. It is not something new - well-known ```printf()``` function uses exactly the same mechanism. So, I hesitated not to borrow such a brilliant idea.

To implement such a feature, no doubt one have to have their own query parser. No problem, it's not a big deal. But the benefits are innumerable. 
Look at all the questions on Stack Overflow where developers are trying in vain to bind a field name.
Voila - with the identifier placeholder it is as easy as adding a field value:

```php
$field = $_POST['field'];
$value = $_POST['value'];
$sql   = "SELECT * FROM table WHERE ?n LIKE ?s";
$data  = $db->query($sql,$field,"%$value%");
```

Nothing could be easier!

Of course we will have placeholders for the common types - strings and numbers.
But as we started inventing new placeholders - let's make some more!

Another trouble in creating prepared queries - arrays going to the IN operator. Everyone is trying to do it their own way, but the type-hinted placeholder makes it as simple as adding a string:

```php
$array = array(1,2,3);
$data  = $db->query("SELECT * FROM table WHERE id IN (?a)",$array);
```

The same goes for such toilsome queries like ```INSERT``` and ```UPDATE```.

And, of course, we have a set of helper functions to turn type-hinted placeholders into real brilliant, making almost every call to the database as simple as one or two lines of code for all the regular real life tasks.
