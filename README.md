SafeMySQL
=========

SafeMySQL is a PHP class for safe and convenient building Mysql queries.
- safe because <b>every</b> dynamic query part goes into query via <b>placeholder</b>
- convenient because it makes application code short and meaningful, without useless repetitions, making it Extra <abbr title="Don't Repeat Yourself">DRY</abbr>

The main feature of this class is a <i>type-hinted placeholders</i>. 
And it's really great step further from just ordinal placeholders used in prepared statements. 
Simply because <b>dynamical parts of the query aren't limited to just scalar data!</b>
In the real life we have to add identifiers, arrays for IN operator, arrays for INSERT and UPDATE queries.
So - we need <b>many</b> different types of data formatting. Thus, we need the way to tell the driver how to format this particular data. 
Conventional prepared statements use toilsome and repeating bind_* functions. 
But there is a way more sleek and useful way - to set the type along with placeholder itself. It is not something new - well-known printf() function uses exactly the same mechanism. So, I hesitated not to borrow such a brilliant idea.

To implement such a feature, no doubt one have to have their own query parser. No problem, it's not a big deal. But the benefits are innumerable. 
Look at all the questions on Stackoverflow where developers trying in vain to bind a field name.
Voila - with identifier placeholder it is as easy as adding a field value:

<code>
$field = $_POST['field'];<br>
$value = $_POST['value'];<br>
$sql   = "SELECT * FROM table WHERE ?n LIKE ?s";<br>
$data  = $db->query($sql,$field,"%$value%");</code>

Nothing could be easier!

Of course we will have placeholders for the common types - strings and numbers.
But as we started inventing new placeholders - let's make some more!

Another trouble in creating prepared queries - arrays going to IN operator. Everyone is trying to do it their own way but the type-hinted placeholder makes it as simple as adding a string:

<code>
$array = array(1,2,3);<br>
$data  = $db->query("SELECT * FROM table WHERE id IN (?a)",$array);</code>

Same goes for such toilsome queries like INSERT and UPDATE.

And, of course, we have a set of helper functions to turn type-hinted placeholders into real brilliant, making almost every call to database as simple as 1 or 2 lines of code for all the regular real life tasks.

