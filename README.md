# joyquery-php

CSS selectors query language for DOMDocument, with XPath axes extension.


## Installation

Create a directory for your application, `cd` to it, and issue:

```
composer require jeremiah-shaulov/joyquery-php
```

## Example

```php
<?php

require_once 'vendor/autoload.php';
use JoyQuery\JoyQuery;

$doc = new DOMDocument;
$doc->loadHTML('<div class=d hello=world><span>Hello</span></div>');
foreach (JoyQuery::evaluate('.d span:last-child', $doc) as $elem)
{	echo "Found: ", $doc->saveXml($elem->cloneNode(true)), "\n\n";
}
```


## Axes

Joyquery extends CSS selectors language with XPath-like axes. Example:

```javascript
td.clue parent::tr      /* select TR, which is parent of TD.clue */

td.clue ancestor::table /* select all TABLES, which are ancestors of TD.clue */

.clue descendant-or-self::*:any(th, td)
```

There are the following axes:

- self::
- child::
- descendant::
- descendant-or-self::
- parent::
- ancestor::
- ancestor-or-self::
- following-sibling::
- first-following-sibling::
- preceding-sibling::
- first-preceding-sibling::


## Extension functions

You can extend joyquery with custom functions.

```php
<?php

require_once 'vendor/autoload.php';
use JoyQuery\JoyQuery;

JoyQuery::$FUNCTIONS['has_text'] = function(DOMElement $node, $text)
{	return strpos($node->textContent, $text) !== false;
};

$doc = new DOMDocument;
$doc->loadHTML('<p>One</p> <p>Two</p> <p>Three</p>');
foreach (JoyQuery::evaluate('p:has-text("ee")', $doc) as $elem)
{	echo "Found: ", $doc->saveXml($elem->cloneNode(true)), "\n\n";
}
```
