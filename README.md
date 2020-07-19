# joyquery-php
CSS selectors query language for DOMDocument, with XPath axes extension

# Example

```php
require_once 'joyquery-php/JoyQueryEmu.php';
use JoyQuery\JoyQueryEmu;

$doc = new DOMDocument;
$doc->loadHTML('<Div class=d hello=world><span>Hello</span></Div>');
foreach (JoyQueryEmu::evaluate('.d span:last-child', $doc) as $elem)
{	echo "Found: ", $doc->saveXml($elem->cloneNode(true)), "\n\n";
}
```
