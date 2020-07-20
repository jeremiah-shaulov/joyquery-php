<?php

namespace JoyQuery;

/**	Usage:
	@code
		require_once 'JoyQuery.php';
		use JoyQuery\JoyQuery;

		$doc = new DOMDocument;
		$doc->loadHTML('<Div class=d hello=world><span>Hello</span></Div>');
		foreach (JoyQuery::evaluate('.d span:last-child', $doc) as $elem)
		{	echo "Found: ", $doc->saveXml($elem->cloneNode(true)), "\n\n";
		}
	@endcode
**/
class JoyQuery extends _\JoyQuery {}


namespace JoyQuery\_;

use Exception, DOMElement, DOMNode, DOMXpath;

const COMPILER_CACHE_MAX = 4;

const TOKENIZER = '/[~|^$*!]?=|::|([+\\-]?\\d+n(?:\\s*[+\\-]?\\d+|\\s*[+\\-]\\s+[+\\-]?\\d+)?)|((?:[\\w_\\-\\x80-\\xFF]|\\\\(?:[0-9A-Fa-f]{1,6}|.))+)|("[^"\\\\]*(?:\\\\[\\S\\s][^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\[\\S\\s][^\'\\\\]*)*\')|(\\s+|\\/\\*[\\S\\s]*?\\*\\/)|./i';
const COMPLEX_NUMBER_TOKENIZER = '/^\\s*([+\\-])\\s*([+\\-]?\\d+)$/';
const UNESCAPER = '/\\\\(?:([0-9A-Fa-f]{1,6})|.)/';

const TOKEN_TYPE_COMPLEX_NUMBER = 0;
const TOKEN_TYPE_IDENT = 1;
const TOKEN_TYPE_STRING = 2;
const TOKEN_TYPE_SPACE = 3;
const TOKEN_TYPE_OTHER = 4;

const AXIS_SELF = 0;
const AXIS_CHILD = 1;
const AXIS_DESCENDANT = 2;
const AXIS_DESCENDANT_OR_SELF = 3;
const AXIS_PARENT = 4;
const AXIS_ANCESTOR = 5;
const AXIS_ANCESTOR_OR_SELF = 6;
const AXIS_FOLLOWING_SIBLING = 7;
const AXIS_FIRST_FOLLOWING_SIBLING = 8;
const AXIS_PRECEDING_SIBLING = 9;
const AXIS_FIRST_PRECEDING_SIBLING = 10;

const AXIS_NAMES =
[	'self',
	'child',
	'descendant',
	'descendant-or-self',
	'parent',
	'ancestor',
	'ancestor-or-self',
	'following-sibling',
	'first-following-sibling',
	'preceding-sibling',
	'first-preceding-sibling'
];

class JoyQuery
{	public static $FUNCTIONS = [];
	private static $functions = null;
	private static $compiler_cache = [];

	private static function css_unescape($text)
	{	if (strpos($text, "\\") === false)
		{	return $text;
		}
		else
		{	return preg_replace_callback
			(	UNESCAPER,
				function($m)
				{	if (empty($m[1]))
					{	return $m[0][1];
					}
					else
					{	return mb_convert_encoding('&#x'.$m[1].';', 'UTF-8', 'HTML-ENTITIES');
					}
				},
				$text
			);
		}
	}

	private static function xpath_escape($value)
	{	return strpos($value, "'")===false ? "'$value'" : (strpos($value, '"')===false ? "\"$value\"" : "concat('".implode("', \"'\", '", explode("'", $value))."')");
	}

	private static function error($message=null)
	{	throw new Exception($message ? $message : 'Unsupported selector');
	}

	private static function read_ident($token_types, $tokens, &$i, $can_asterisk=false, $can_empty=false)
	{	if ($i < count($tokens))
		{	$token = $tokens[$i];
			if ($can_asterisk && $token=='*' or $token_types[$i]==TOKEN_TYPE_IDENT)
			{	$i++;
				return self::css_unescape($token);
			}
			else if ($can_empty and ($token=='#' || $token=='.' || $token=='[' || $token==':'))
			{	return '*';
			}
		}
	}
	private static function read_string($token_types, $tokens, &$i)
	{	if ($i < count($tokens))
		{	$token = $tokens[$i];
			if ($token_types[$i] == TOKEN_TYPE_STRING)
			{	$i++;
				return self::css_unescape(substr($token, 1, -1));
			}
			if (is_numeric($token))
			{	$i++;
				return (double)$token;
			}
		}
	}

	private static function read_space($token_types, $tokens, &$i)
	{	if ($i<count($tokens) and $token_types[$i]==TOKEN_TYPE_SPACE)
		{	return $tokens[$i++];
		}
	}

	private static function add_condition(&$simple_selector, &$conditions, $name, $oper, $value, $func_args=null, $first_complex_number_arg=null)
	{	if ($name===null or $value===null)
		{	self::error();
		}
		$func_arg = $func_args ? $func_args[0] : null;
		if ($oper == ':from')
		{	$func_arg = substr($func_arg, 1, -1); // cut quotes
			$simple_selector['from'] = $func_arg>1 ? (integer)$func_arg : 1;
			return;
		}
		if ($oper == ':limit')
		{	$func_arg = substr($func_arg, 1, -1); // cut quotes
			$simple_selector['limit'] = $func_arg>0 ? (integer)$func_arg : 0x7FFFFFFF;
			return;
		}
		if (substr($oper, 0, 1) != ':')
		{	$name = strtolower($name);
			$use_value = $oper!='~=' ? $value : " $value ";
			$value_xpath = self::xpath_escape($use_value);
		}
		$func = null;
		$priority = 0;
		switch ($oper)
		{	case '' :
				$func = "@$name";
			break;
			case '=':
				$func = "@$name=$value_xpath";
			break;
			case '!=':
				$func = "(not(@$name) or @$name!=$value_xpath)";
			break;
			case '^=':
				$func = "substring(@$name, 1, ".strlen($value).")=$value_xpath";
			break;
			case '$=':
				$func = "substring(@$name, string-length(@$name)-".(strlen($value)+1).")=$value_xpath";
			break;
			case '*=':
				$func = "contains(@$name, $value_xpath)";
			break;
			case '|=':
				$func = "(@$name=$value_xpath or substring(@$name, 1, ".(strlen($value)+2).")=concat($value_xpath, '-'))";
			break;
			case '~=':
				$func = "contains(concat(' ', @$name, ' '), $value_xpath)";
			break;
			case ':root':
				$func = "count(parent::*)=0";
			break;
			case ':first-child':
				$func = "position()=1";
				if ($simple_selector['axis']!=AXIS_CHILD or $simple_selector['name']!='*')
				{	$func = ". = ../*[$func]";
				}
			break;
			case ':last-child':
				$func = "position()=last()";
				if ($simple_selector['axis']!=AXIS_CHILD or $simple_selector['name']!='*')
				{	$func = ". = ../*[$func]";
				}
			break;
			case ':only-child':
				$func = "last()=1";
				if ($simple_selector['axis']!=AXIS_CHILD or $simple_selector['name']!='*')
				{	$func = ". = ../*[$func]";
				}
			break;
			case ':nth-child':
				if (!$first_complex_number_arg)
				{	$func = "position()=$func_arg";
				}
				else
				{	$func = "(position()-{$first_complex_number_arg['real']}) mod {$first_complex_number_arg['imag']} = 0";
				}
				if ($simple_selector['axis']!=AXIS_CHILD or $simple_selector['name']!='*')
				{	$func = ". = ../*[$func]";
				}
			break;
			case ':nth-last-child':
				if (!$first_complex_number_arg)
				{	$func = "(last()-position()+1)=$func_arg";
				}
				else
				{	$func = "(last()-position()+1-{$first_complex_number_arg['real']}) mod {$first_complex_number_arg['imag']} = 0";
				}
				if ($simple_selector['axis']!=AXIS_CHILD or $simple_selector['name']!='*')
				{	$func = ". = ../*[$func]";
				}
			break;
			case ':first-of-type':
				$func = "position()=1";
				if ($simple_selector['axis'] != AXIS_CHILD)
				{	$func = ". = ../{$simple_selector['name']}[$func]";
				}
			break;
			case ':last-of-type':
				$func = "position()=last()";
				if ($simple_selector['axis'] != AXIS_CHILD)
				{	$func = ". = ../{$simple_selector['name']}[$func]";
				}
			break;
			case ':only-of-type':
				$func = "last()=1";
				if ($simple_selector['axis'] != AXIS_CHILD)
				{	$func = ". = ../{$simple_selector['name']}[$func]";
				}
			break;
			case ':nth-of-type':
				if (!$first_complex_number_arg)
				{	$func = "position()=$func_arg";
				}
				else
				{	$func = "(position()-{$first_complex_number_arg['real']}) mod {$first_complex_number_arg['imag']} = 0";
				}
				if ($simple_selector['axis'] != AXIS_CHILD)
				{	$func = ". = ../{$simple_selector['name']}[$func]";
				}
			break;
			case ':nth-last-of-type':
				if (!$first_complex_number_arg)
				{	$func = "(last()-position()+1)=$func_arg";
				}
				else
				{	$func = "(last()-position()+1-{$first_complex_number_arg['real']}) mod {$first_complex_number_arg['imag']} = 0";
				}
				if ($simple_selector['axis'] != AXIS_CHILD)
				{	$func = ". = ../{$simple_selector['name']}[$func]";
				}
			break;
			case ':not':
				$func = "not($func_arg)";
				$priority = 1;
			break;
			case ':has':
				$func = "($func_arg)";
				$priority = 1;
			break;
			case ':any':
				$func = "((".implode(") | (", $func_args)."))";
				$priority = 1;
			break;
			case ':disabled':
				$func = "(@disabled and name()!='style' or parent::*[@disabled])";
			break;
			case ':enabled':
				$func = "not(@disabled) and (name()='input' or name()='textarea' or name()='select' or name()='optgroup' or name()='option' or name()='button')";
			break;
			case ':checked':
				$func = "(@checked or @selected)";
			break;
			case ':link':
				$func = "name()='a' and @href";
			break;
			case ':visited':
				$func = "false()";
			break;
			case ':input':
				$func = "(name()='input' or name()='select' or name()='textarea' or name()='button')";
			break;
			default:
				$func_name_xpath = self::xpath_escape(str_replace('-', '_', substr($oper, 1)));
				array_unshift($func_args, '.');
				array_unshift($func_args, $func_name_xpath);
				array_unshift($func_args, "'\JoyQuery\JoyQuery::call_function'");
				$func = "joyqueryphp:function(".implode(',', $func_args).')';
				$priority = 2;
			break;
		}
		$conditions[$priority][] = $func;
	}

	private static function parse_simple_selector($token_types, $tokens, &$i, $axis, $is_xml)
	{	$token = self::read_ident($token_types, $tokens, $i, true, true);
		if (!$token)
		{	self::error();
		}
		$token2 = $i<count($tokens) ? $tokens[$i] : null;
		if ($token2 == '::')
		{	$i++;
			$axis = array_search($token, AXIS_NAMES, true);
			if ($axis === false)
			{	self::error("Unsupported axis: $token");
			}
			$token = self::read_ident($token_types, $tokens, $i, true);
			if (!$token)
			{	self::error();
			}
		}
		$simple_selector =
		[	'name' => $is_xml ? $token : strtolower($token),
			'axis' => $axis,
			'from' => 1,
			'limit' => 0x7FFFFFFF
		];
		$conditions = [[], [], []];
		while (true)
		{	$token = $i<count($tokens) ? $tokens[$i] : null;
			if ($token == '#')
			{	$i++;
				self::add_condition($simple_selector, $conditions, 'id', '=', self::read_ident($token_types, $tokens, $i));
			}
			else if ($token == '.')
			{	$i++;
				self::add_condition($simple_selector, $conditions, 'class', '~=', self::read_ident($token_types, $tokens, $i));
			}
			else if ($token == '[')
			{	$i++;
				self::read_space($token_types, $tokens, $i);
				$name = self::read_ident($token_types, $tokens, $i, true);
				self::read_space($token_types, $tokens, $i);
				$token = $i<count($tokens) ? $tokens[$i] : null;
				$oper = '';
				$value = '';
				if (substr($token, -1) == '=')
				{	$i++;
					self::read_space($token_types, $tokens, $i);
					$oper = $token;
					$value = self::read_string($token_types, $tokens, $i);
					self::read_space($token_types, $tokens, $i);
				}
				$token = $i<count($tokens) ? $tokens[$i++] : null;
				if ($token != ']')
				{	self::error();
				}
				self::add_condition($simple_selector, $conditions, $name, $oper, $value);
			}
			else if ($token == ':')
			{	$i++;
				$name = self::read_ident($token_types, $tokens, $i, true);
				$func_args = [];
				$first_complex_number_arg = null;
				$token = $i<count($tokens) ? $tokens[$i] : null;
				if ($token == '(')
				{	while (true)
					{	$i++;
						self::read_space($token_types, $tokens, $i);
						$func_arg = null;
						$token_type = $i<count($token_types) ? $token_types[$i] : -1;
						if ($token_type == TOKEN_TYPE_COMPLEX_NUMBER)
						{	$complex_number = explode('n', strtolower($tokens[$i]));
							$real = $complex_number[1];
							$imag = $complex_number[0];
							if (!$real)
							{	$real = '0';
							}
							else if (!is_numeric($real))
							{	preg_match(COMPLEX_NUMBER_TOKENIZER, $real, $match);
								$real = $match[1]=='-' ? -$match[2] : $match[2];
							}
							$func_arg = "['imag'=>$imag, 'real'=>$real]";
							if (count($func_args) == 0)
							{	$first_complex_number_arg = ['imag'=>$imag, 'real'=>$real];
							}
							$i++;
						}
						else
						{	$func_arg = self::read_string($token_types, $tokens, $i);
							$token_type = $i<count($token_types) ? $token_types[$i] : -1;
							if ($func_arg !== null)
							{	$func_arg = self::xpath_escape($func_arg);
							}
							else if ($token_type==TOKEN_TYPE_IDENT and !empty(self::$FUNCTIONS["$name.raw_argument"]))
							{	$token = $i<count($tokens) ? $tokens[$i++] : null;
								$func_arg = self::xpath_escape($token);
							}
							else
							{	$func_arg = self::parse($token_types, $tokens, $i, AXIS_SELF, $is_xml, true);
							}
						}
						$func_args[] = $func_arg;
						self::read_space($token_types, $tokens, $i);
						$token = $i<count($tokens) ? $tokens[$i] : null;
						if ($token != ',')
						{	break;
						}
					}
					$token = $i<count($tokens) ? $tokens[$i++] : null;
					if ($token != ')')
					{	self::error();
					}
				}
				self::add_condition($simple_selector, $conditions, '', ":$name", '', $func_args, $first_complex_number_arg);
			}
			else
			{	break;
			}
		}
		if ($simple_selector['axis'] == AXIS_FIRST_FOLLOWING_SIBLING)
		{	$simple_selector['axis'] = AXIS_FOLLOWING_SIBLING;
			array_unshift($conditions[0], "position()=1");
		}
		else if ($simple_selector['axis'] == AXIS_FIRST_PRECEDING_SIBLING)
		{	$simple_selector['axis'] = AXIS_PRECEDING_SIBLING;
			array_unshift($conditions[0], "position()=1");
		}
		$conditions = array_merge($conditions[0], $conditions[1], $conditions[2]);
		$xpath_str = AXIS_NAMES[$simple_selector['axis']]."::".$simple_selector['name'];
		if (count($conditions) > 0)
		{	$xpath_str .= "[".implode(' and ', $conditions)."]";
		}
		if ($simple_selector['from']>1 or $simple_selector['limit']!=0x7FFFFFFF)
		{	if (!($simple_selector['from'] > 1))
			{	// only limit
				$xpath_str = "{$xpath_str}[position() <= {$simple_selector['limit']}]";
			}
			else if ($simple_selector['limit'] == 0x7FFFFFFF)
			{	// only from
				$xpath_str = "{$xpath_str}[position() >= {$simple_selector['from']}]";
			}
			else
			{	// both from and limit
				$xpath_str = "{$xpath_str}[position()>={$simple_selector['from']} and position()<".($simple_selector['from'] + $simple_selector['limit'])."]";
			}
		}
		return $xpath_str;
	}

	private static function parse($token_types, $tokens, &$i, $axis, $is_xml, $is_func_args=false)
	{	$initial_axis = $axis;
		$cur_path = [];
		$path = [];
		self::read_space($token_types, $tokens, $i);
		while ($i < count($tokens))
		{	$cur_path[] = self::parse_simple_selector($token_types, $tokens, $i, $axis, $is_xml);
			$axis = AXIS_DESCENDANT;
			self::read_space($token_types, $tokens, $i);
			$token = $i<count($tokens) ? $tokens[$i] : null;
			if ($token == '>')
			{	$i++;
				$axis = AXIS_CHILD;
			}
			else if ($token=='~' or $token=='+')
			{	$i++;
				$axis = $token=='~' ? AXIS_FOLLOWING_SIBLING : AXIS_FIRST_FOLLOWING_SIBLING;
			}
			else if ($token == ',')
			{	if ($is_func_args)
				{	break;
				}
				$i++;
				$axis = $initial_axis;
				$path[] = implode('/', $cur_path);
				$cur_path = [];
			}
			else if ($token == ')')
			{	break;
			}
			self::read_space($token_types, $tokens, $i);
		}
		$path[] = implode('/', $cur_path);
		return count($path)==1 ? $path[0] : "(".implode(") | (", $path).")";
	}

	protected static function compile($css, $no_context_node=false, $is_xml=false)
	{	// cache hit?
		$css_for_cache = (($no_context_node ? 1 : 0) | ($is_xml ? 2 : 0)) . $css;
		if (isset(self::$compiler_cache[$css_for_cache]))
		{	return self::$compiler_cache[$css_for_cache];
		}
		// cache miss
		$token_types = [];
		$tokens = [];
		$i = 0;
		preg_replace_callback
		(	TOKENIZER,
			function($m) use(&$token_types, &$tokens)
			{	$tokens[] = $m[0];
				if (!empty($m[1]))
				{	$token_types[] = TOKEN_TYPE_COMPLEX_NUMBER;
				}
				else if (!empty($m[2]))
				{	$token_types[] = TOKEN_TYPE_IDENT;
				}
				else if (!empty($m[3]))
				{	$token_types[] = TOKEN_TYPE_STRING;
				}
				else if (!empty($m[4]))
				{	$token_types[] = TOKEN_TYPE_SPACE;
				}
				else
				{	$token_types[] = TOKEN_TYPE_OTHER;
				}
			},
			$css
		);
		$xpath_str = self::parse($token_types, $tokens, $i, $no_context_node ? AXIS_DESCENDANT_OR_SELF : AXIS_DESCENDANT, $is_xml);
		if ($i < count($tokens))
		{	self::error();
		}
		//
		if (count(self::$compiler_cache) >= COMPILER_CACHE_MAX)
		{	self::$compiler_cache = [];
		}
		self::$compiler_cache[$css_for_cache] = $xpath_str;
		return $xpath_str;
	}

	public static function evaluate($css_selector, DOMNode $node, array $functions=null)
	{	$no_context_node = $node->nodeType==XML_DOCUMENT_NODE || $node->nodeType==XML_HTML_DOCUMENT_NODE;
		$doc = $no_context_node ? $node : $node->ownerDocument;
		$xpath_str = self::compile($css_selector, $no_context_node, $doc->nodeType!=XML_HTML_DOCUMENT_NODE);
		$xpath = new DOMXpath($doc);
		if ($functions or self::$FUNCTIONS)
		{	self::$functions = $functions;
			$xpath->registerNamespace("joyqueryphp", "http://php.net/xpath");
			$xpath->registerPHPFunctions("\JoyQuery\JoyQuery::call_function");
		}
		try
		{	$elements = $xpath->query($xpath_str, $no_context_node ? null : $node);
			if (!$elements)
			{	self::error();
			}
		}
		catch (Exception $e)
		{	self::$functions = null;
			throw $e;
		}
		self::$functions = null;
		return $elements;
	}

	public static function call_function($func_name)
	{	$callable = null;
		if (isset(self::$functions[$func_name]))
		{	$callable = self::$functions[$func_name];
		}
		else if (isset(self::$FUNCTIONS[$func_name]))
		{	$callable = self::$FUNCTIONS[$func_name];
		}
		if (!is_callable($callable))
		{	self::error("No such function: $func_name");
		}
		$args = func_get_args();
		array_shift($args); // remove $func_name
		if (empty($args[0]))
		{	return false;
		}
		$args[0] = $args[0][0]; // nodes array to node
		return call_user_func_array($callable, $args);
	}
}

JoyQuery::$FUNCTIONS['empty'] = function(DOMElement $node)
{	for ($e=$node->firstChild; $e; $e=$e->nextSibling)
	{	if ($e->nodeType!=XML_TEXT_NODE && $e->nodeType!=XML_CDATA_SECTION_NODE or $e->length>0)
		{	return false;
		}
	}
	return true;
};
JoyQuery::$FUNCTIONS['target'] = function(DOMElement $node)
{	$id = $node->getAttribute('id');
	if ($id)
	{	$uri = $node->ownerDocument->documentURI;
		$pos = strpos($uri, '#');
		return $pos && $id==substr($uri, $pos+1);
	}
	return false;
};
