# joyquery-php
CSS selectors query language for DOMDocument, with XPath axes extension

# Example

```php
require_once 'joyquery-php/JoyQuery.php';
use JoyQuery\JoyQuery;

$doc = new DOMDocument;
$doc->loadHTML('<Div class=d hello=world><span>Hello</span></Div>');
foreach (JoyQuery::evaluate('.d span:last-child', $doc) as $elem)
{	echo "Found: ", $doc->saveXml($elem->cloneNode(true)), "\n\n";
}
```
