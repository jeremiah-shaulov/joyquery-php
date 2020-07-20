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


## Nonstandard features

The following features are not found in standard, but implemented:

- `E[foo!="bar"]` - an E element that either doesn't have "foo" attribute, or has it with value not equal to "bar"
- `E:not(s)` - an E element that does not match selector s. Selector s can be any simple or complex (standard requires simple)
- `E:has(s)` - an E element that also matches selector s
- `E:any(s1, s2, ...)` - an E element that also matches any of given selectors s1, s2, ...
- `E:input` - an E element which is INPUT, SELECT, TEXTAREA or BUTTON
- `:from(n)` - When applied to a simple selector, e.g. E.cls, selects only elements starting from number n in matched set
- `:limit(n)` - When applied to a simple selector, e.g. E.cls, limits matched set to no more than n elements
- `axis::E` - XPath-like axis, see below
- `:php-func-name` - PHP function to test elements, see below


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
