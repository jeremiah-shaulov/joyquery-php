<?php

namespace JoyQuery\_;

use Exception, Iterator, DOMElement, DOMNode;

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

class JoyQueryEmu implements Iterator
{	public static $FUNCTIONS = [];
	private static $compiler_cache = [];

	private $ctx, $h, $path=null, $node=null, $functions=null, $iter=null, $i=-1, $n=0, $eof=false, $current_elem=null;

	public function __construct(JoyQueryEmulationCtx $ctx=null, $h=0)
	{	$this->ctx = $ctx;
		$this->h = $h;
	}

	public function ctx_evaluate($node)
	{	self::evaluate($this->ctx->s[$this->h], $node, $this->ctx->functions);
	}

	public function ctx_evaluate_one($node)
	{	$path = $this->ctx->s[$this->h];
		for ($i=count($path)-1; $i>=0; $i--)
		{	$positions = [];
			$lasts = [];
			$position_ots = [];
			$last_ots = [];
			$iter = self::select_matching($path[$i], 0, $node, null, $this->ctx->functions, 0, -1, -1, -1, -1, $positions, $lasts, $position_ots, $last_ots, 0, false);
			if ($iter)
			{	return $iter['result'];
			}
		}
	}

	public function current()
	{	if ($this->current_elem===null and !$this->eof)
		{	if ($this->i==-1 and is_scalar($this->path))
			{	$doc = $this->node;
				$nodeType = $doc->nodeType;
				$no_context_node = $nodeType==XML_DOCUMENT_NODE || $nodeType==XML_HTML_DOCUMENT_NODE;
				if ($no_context_node)
				{	$this->node = $this->node->documentElement;
				}
				else
				{	$doc = $doc->ownerDocument;
				}
				$this->path = self::compile($this->path, $no_context_node, $doc->nodeType!=XML_HTML_DOCUMENT_NODE);
			}
			while (!$this->iter)
			{	$this->i++;
				if (!isset($this->path[$this->i]))
				{	$this->eof = true;
					return null;
				}
				$positions = [];
				$lasts = [];
				$position_ots = [];
				$last_ots = [];
				$this->iter = self::select_matching($this->path[$this->i], 0, $this->node, null, $this->functions, 0, -1, -1, -1, -1, $positions, $lasts, $position_ots, $last_ots, 0, false);
			}
			$this->current_elem = $this->iter['result'];
			$this->iter = $this->iter['next'] ? $this->iter['next']() : null;
			if ($this->current_elem === null)
			{	$this->eof = true;
			}
		}
		return $this->current_elem;
	}

	public function key()
	{	return $this->n;
	}

	public function next()
	{	if ($this->current_elem === null)
		{	$this->current();
		}
		$this->current_elem = null;
		return ++$this->n;
	}

	public function valid()
	{	if ($this->current_elem === null)
		{	$this->current();
		}
		return $this->current_elem !== null;
	}

	public function rewind()
	{	$this->iter = null;
		$this->i = -1;
		$this->n = 0;
		$this->eof = false;
		$this->current_elem = null;
	}

	public function get($at=-1, $count=-1)
	{	$want_scalar = $at!=-1 && $count==-1;
		$count = $at==-1 ? 0x7FFFFFFF : ((integer)$count>0 ? (integer)$count : 1);
		$at = (integer)$at>0 ? (integer)$at : 0;
		$result = [];
		$j = -$at;
		while ($j<$count and ($elem=$this->current()))
		{	if (array_search($elem, $result, true) === false)
			{	$result[] = $elem;
				$j++;
			}
			$this->next();
		}
		if ($at > 0)
		{	$result = array_slice($result, $at);
		}
		return !$want_scalar ? $result : ($result ? $result[0] : null);
	}

	public static function evaluate($path_obj_or_str, DOMNode $node, $functions=null)
	{	$self = new self;
		$self->path = $path_obj_or_str;
		$self->node = $node;
		$self->functions = $functions ? $functions : [];
		return $self;
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
		$css_unescape = function($text)
		{	if (strpos($text, "\\") === false)
			{	return $text;
			}
			else
			{	return preg_replace_callback
				(	UNESCAPER,
					function($m)
					{	if (empty($m[1]))
						{	return $m[0]{1};
						}
						else
						{	return mb_convert_encoding('&#x'.$m[1].';', 'UTF-8', 'HTML-ENTITIES');
						}
					},
					$text
				);
			}
		};
		$read_ident = function($can_asterisk=false, $can_empty=false) use($token_types, $tokens, &$i, $css_unescape)
		{	if ($i < count($tokens))
			{	$token = $tokens[$i];
				if ($can_asterisk && $token=='*' or $token_types[$i]==TOKEN_TYPE_IDENT)
				{	$i++;
					return $css_unescape($token);
				}
				else if ($can_empty and ($token=='#' || $token=='.' || $token=='[' || $token==':'))
				{	return '*';
				}
			}
		};
		$read_string = function() use($token_types, $tokens, &$i, $css_unescape)
		{	if ($i < count($tokens))
			{	$token = $tokens[$i];
				if ($token_types[$i] == TOKEN_TYPE_STRING)
				{	$i++;
					return $css_unescape(substr($token, 1, -1));
				}
				if (is_numeric($token))
				{	$i++;
					return (double)$token;
				}
			}
		};
		$read_space = function() use($token_types, $tokens, &$i)
		{	if ($i<count($tokens) and $token_types[$i]==TOKEN_TYPE_SPACE)
			{	return $tokens[$i++];
			}
		};
		$error = function($message=null)
		{	throw new Exception($message ? $message : 'Unsupported selector');
		};
		$parse = function() {};
		$parse_simple_selector = function(&$cur_path, $axis) use($token_types, $tokens, $is_xml, &$i, &$parse, $read_ident, $read_string, $read_space, $error)
		{	$token = $read_ident(true, true);
			if (!$token)
			{	return false;
			}
			$token2 = $i<count($tokens) ? $tokens[$i] : null;
			if ($token2 == '::')
			{	$i++;
				$axis = array_search($token, AXIS_NAMES, true);
				if ($axis === false)
				{	$error("Unsupported axis: $token");
				}
				$token = $read_ident(true);
				if (!$token)
				{	$error();
				}
			}
			$simple_selector =
			[	'name' => $token=='*' ? '' : ($is_xml ? $token : strtolower($token)),
				'axis' => $axis,
				'from' => 1,
				'limit' => 0x7FFFFFFF,
				'sub' => [],
				'cond' => null
			];
			$conditions = [[], [], []];
			$add_condition = function($name, $oper, $value, $func_args=null, $first_complex_number_arg=null) use(&$simple_selector, &$conditions, $error)
			{	if ($name===null or $value===null)
				{	$error();
				}
				$func_arg = $func_args ? $func_args[0] : null;
				if ($oper == ':from')
				{	$simple_selector['from'] = $func_arg>1 ? (integer)$func_arg : 1;
					return;
				}
				if ($oper == ':limit')
				{	$simple_selector['limit'] = $func_arg>0 ? (integer)$func_arg : 0x7FFFFFFF;
					return;
				}
				if (substr($oper, 0, 1) != ':')
				{	$name = strtolower($name);
					$name_php = var_export($name, true);
					$get_attr = "\$n->getAttribute($name_php)";
					$use_value = $oper!='~=' ? $value : " $value ";
					$value_php = var_export($use_value, true);
				}
				$func = null;
				$priority = 0;
				switch ($oper)
				{	case '' :
						$func = "\$n->hasAttribute($name_php)";
					break;
					case '=':
						$func = "$get_attr==$value_php";
					break;
					case '!=':
						$func = "((\$a=$get_attr) ? \$a : '')!=$value_php";
					break;
					case '^=':
						$func = "(\$a=$get_attr)&&substr(\$a, 0,".strlen($value).")==$value_php";
					break;
					case '$=':
						$func = "(\$a=$get_attr)&&substr(\$a, strlen(\$a)-".strlen($value).")==$value_php";
					break;
					case '*=':
						$func = "(\$a=$get_attr)&&strpos(\$a, $value_php)!==false";
					break;
					case '|=':
						$func = "(\$a=$get_attr)&&(\$b=$value_php)&&(\$a==\$b||substr(\$a, 0,".(strlen($value)+1).")==\$b.'-')";
					break;
					case '~=':
						$func = "(strpos((' '.($get_attr).' '), $value_php)!==false)";
					break;
					case ':root':
						$func = "\$n===\$n->ownerDocument->documentElement";
					break;
					case ':first-child':
						$func = "\$l(0,1)==0";
						$priority = 1;
					break;
					case ':last-child':
						$func = "\$l(0,1)==\$l()-1";
						$priority = 1;
					break;
					case ':only-child':
						$func = "\$l()==1";
						$priority = 1;
					break;
					case ':nth-child':
						$func = !$first_complex_number_arg ? "\$l(0,1)==".($func_arg-1) : "(\$l(0,1)-(".($first_complex_number_arg['real']-1)."))%".$first_complex_number_arg['imag']."==0";
						$priority = 1;
					break;
					case ':nth-last-child':
						$func = !$first_complex_number_arg ? "\$l()-\$l(0,1)==$func_arg" : "(\$l()-\$l(0,1)-(".$first_complex_number_arg['real']."))%".$first_complex_number_arg['imag']."==0";
						$priority = 1;
					break;
					case ':first-of-type':
						$func = "\$l(1,1)==0";
						$priority = 1;
					break;
					case ':last-of-type':
						$func = "\$l(1,1)==\$l(1)-1";
						$priority = 1;
					break;
					case ':only-of-type':
						$func = "\$l(1)==1";
						$priority = 1;
					break;
					case ':nth-of-type':
						$func = !$first_complex_number_arg ? "\$l(1,1)==".($func_arg-1) : "(\$l(1,1)-(".($first_complex_number_arg['real']-1)."))%".$first_complex_number_arg['imag']."==0";
						$priority = 1;
					break;
					case ':nth-last-of-type':
						$func = !$first_complex_number_arg ? "\$l(1)-\$l(1,1)==$func_arg" : "(\$l(1)-\$l(1,1)-(".$first_complex_number_arg['real']."))%".$first_complex_number_arg['imag']."==0";
						$priority = 1;
					break;
					case ':not':
						$func = "(\$a=$func_arg)&&!\$a->ctx_evaluate_one(\$n)";
						$priority = 2;
					break;
					case ':has':
						$func = "(\$a=$func_arg)&&\$a->ctx_evaluate_one(\$n)";
						$priority = 2;
					break;
					case ':any':
						//$func = "((\$a=$func_args[0])&&\$a->ctx_evaluate_one(\$n) || (\$a=$func_args[1])&&\$a->ctx_evaluate_one(\$n))";
						$func = "((\$a=".implode(")&&\$a->ctx_evaluate_one(\$n) || (\$a=", $func_args).")&&\$a->ctx_evaluate_one(\$n))";
						$priority = 2;
					break;
					case ':target':
						$func = "(\$a=\$n->ownerDocument->documentURI) && (\$b=strpos(\$a, '#')) && (\$n->getAttribute('id')==substr(\$a, \$b+1))";
					break;
					case ':disabled':
						$func = "(\$n->hasAttribute('disabled')&&\$n->nodeName!='style'||(\$a=\$n->parentNode)&&(\$a->nodeType==XML_ELEMENT_NODE)&&\$a->hasAttribute('disabled'))";
					break;
					case ':enabled':
						$func = "!\$n->hasAttribute('disabled') && ((\$a=\$n->nodeName)=='input' || \$a=='textarea' || \$a=='select' || \$a=='optgroup' || \$a=='option' || \$a=='button')";
					break;
					case ':checked':
						$func = "(\$n->hasAttribute('checked')||\$n->hasAttribute('selected'))";
					break;
					case ':link':
						$func = "\$n->nodeName=='a'&&\$n->hasAttribute('href')";
					break;
					case ':visited':
						$func = "0";
					break;
					case ':input':
						$func = "((\$a=\$n->nodeName)=='input' || \$a=='select' || \$a=='textarea' || \$a=='button')";
					break;
					default:
						$func_name_php = var_export(str_replace('-', '_', substr($oper, 1)), true);
						array_unshift($func_args, '$n');
						$func = "(\$a=(isset(\$this->functions[$func_name_php]) ? \$this->functions[$func_name_php] : \JoyQuery\JoyQueryEmu::\$FUNCTIONS[$func_name_php])) && \$a(".implode(',', $func_args).')';
						$priority = 1;
					break;
				}
				$conditions[$priority][] = $func;
			};
			while (true)
			{	$token = $i<count($tokens) ? $tokens[$i] : null;
				if ($token == '#')
				{	$i++;
					$add_condition('id', '=', $read_ident());
				}
				else if ($token == '.')
				{	$i++;
					$add_condition('class', '~=', $read_ident());
				}
				else if ($token == '[')
				{	$i++;
					$read_space();
					$name = $read_ident(true);
					$read_space();
					$token = $i<count($tokens) ? $tokens[$i] : null;
					$oper = '';
					$value = '';
					if (substr($token, -1) == '=')
					{	$i++;
						$read_space();
						$oper = $token;
						$value = $read_string();
						$read_space();
					}
					$token = $i<count($tokens) ? $tokens[$i++] : null;
					if ($token != ']')
					{	$error();
					}
					$add_condition($name, $oper, $value);
				}
				else if ($token == ':')
				{	$i++;
					$name = $read_ident(true);
					$func_args = [];
					$first_complex_number_arg = null;
					$token = $i<count($tokens) ? $tokens[$i] : null;
					if ($token == '(')
					{	while (true)
						{	$i++;
							$read_space();
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
							{	$func_arg = $read_string();
								$token_type = $i<count($token_types) ? $token_types[$i] : -1;
								if ($func_arg !== null)
								{	$func_arg = var_export($func_arg, true);
								}
								else if ($token_type==TOKEN_TYPE_IDENT and !empty(self::$FUNCTIONS["$name.raw_argument"]))
								{	$token = $i<count($tokens) ? $tokens[$i++] : null;
									$func_arg = var_export($token, true);
								}
								else
								{	$func_arg = '(new \JoyQuery\JoyQueryEmu($this,'.count($simple_selector['sub']).'))';
									$simple_selector['sub'][] = $parse(AXIS_SELF, true);
								}
							}
							$func_args[] = $func_arg;
							$read_space();
							$token = $i<count($tokens) ? $tokens[$i] : null;
							if ($token != ',')
							{	break;
							}
						}
						$token = $i<count($tokens) ? $tokens[$i++] : null;
						if ($token != ')')
						{	$error();
						}
					}
					$add_condition('', ":$name", '', $func_args, $first_complex_number_arg);
				}
				else
				{	break;
				}
			}
			$conditions = array_merge($conditions[0], $conditions[1], $conditions[2]);
			if (count($conditions) > 0)
			{	$simple_selector['cond'] = '$n=$this->node; $l=$this->l; return '.implode('&&', $conditions).';';
			}
			$cur_path[] = $simple_selector;
			return true;
		};
		$parse = function($axis, $is_func_args=false) use($tokens, &$i, $parse_simple_selector, $read_space, $error)
		{	$initial_axis = $axis;
			$cur_path = [];
			$path = [];
			$read_space();
			while ($i < count($tokens))
			{	if (!$parse_simple_selector($cur_path, $axis))
				{	$error();
				}
				$axis = AXIS_DESCENDANT;
				$read_space();
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
					$path[] = $cur_path;
					$cur_path = [];
				}
				else if ($token == ')')
				{	break;
				}
				$read_space();
			}
			$path[] = $cur_path;
			return $path;
		};
		$path = $parse($no_context_node ? AXIS_DESCENDANT_OR_SELF : AXIS_DESCENDANT);
		if ($i < count($tokens))
		{	$error();
		}
		//
		if (count(self::$compiler_cache) >= COMPILER_CACHE_MAX)
		{	self::$compiler_cache = [];
		}
		self::$compiler_cache[$css_for_cache] = $path;
		return $path;
	}

	protected static function select_matching($cur_path, $step, $node, $subnode, $functions, $position_range, $position, $last, $position_ot, $last_ot, &$positions, &$lasts, &$position_ots, &$last_ots, $n_positions, $no_enter)
	{	$simple_selector = $cur_path[$step];
		$name = $simple_selector['name'];
		$axis = $simple_selector['axis'];
		$from = $simple_selector['from'];
		$limit = $simple_selector['limit'];
		$cond = $simple_selector['cond'];
		$sub_paths = $simple_selector['sub'];
		$is_last_step = $step == count($cur_path)-1;
		if (!$subnode)
		{	if ($axis==AXIS_SELF or $axis==AXIS_ANCESTOR_OR_SELF)
			{	$subnode = $node;
			}
			else if ($axis==AXIS_PARENT or $axis==AXIS_ANCESTOR)
			{	$subnode = $node->parentNode;
				if ($subnode and $subnode->nodeType!=XML_ELEMENT_NODE)
				{	$subnode = null;
				}
			}
			else if ($axis == AXIS_CHILD)
			{	$subnode = $node->firstChild;
			}
			else if ($axis==AXIS_FOLLOWING_SIBLING or $axis==AXIS_FIRST_FOLLOWING_SIBLING)
			{	$subnode = $node->nextSibling;
			}
			else if ($axis==AXIS_PRECEDING_SIBLING or $axis==AXIS_FIRST_PRECEDING_SIBLING)
			{	$subnode = $node->previousSibling;
			}
			else if ($axis == AXIS_DESCENDANT)
			{	$subnode = $node;
			}
			else
			{	$subnode = null;
			}
			if ($axis==AXIS_SELF or $axis==AXIS_DESCENDANT or $axis==AXIS_DESCENDANT_OR_SELF or $axis==AXIS_ANCESTOR_OR_SELF)
			{	// OK
			}
			else if ($axis == AXIS_CHILD)
			{	$position = 0;
			}
			else if ($axis==AXIS_FOLLOWING_SIBLING or $axis==AXIS_FIRST_FOLLOWING_SIBLING)
			{	if ($position != -1) $position++;
			}
			else if ($axis==AXIS_PRECEDING_SIBLING or $axis==AXIS_FIRST_PRECEDING_SIBLING)
			{	if ($position != -1) $position--;
			}
			else
			{	$position = -1;
			}
			if ($axis==AXIS_SELF or $axis==AXIS_DESCENDANT or $axis==AXIS_DESCENDANT_OR_SELF)
			{	// OK
			}
			else if ($axis == AXIS_CHILD)
			{	$position_ot = $name ? 0 : -1;
			}
			else
			{	$position_ot = -1;
			}
		}
		$inc_position = $axis==AXIS_PRECEDING_SIBLING ? -1 : +1;
		$get_last = function($is_ot=false, $is_p=false) use(&$subnode, &$name, &$position, &$last, &$position_ot, &$last_ot)
		{	$value = $is_ot ? ($is_p ? $position_ot : $last_ot) : ($is_p ? $position : $last);
			if ($value == -1)
			{	if (!$is_ot)
				{	if ($position == -1)
					{	$position = 0;
						for ($n=$subnode->previousSibling; $n; $n=$n->previousSibling)
						{	if ($n->nodeType == XML_ELEMENT_NODE)
							{	$position++;
							}
						}
						if ($is_p)
						{	return $position;
						}
					}
					$last = $position + 1;
					for ($n=$subnode->nextSibling; $n; $n=$n->nextSibling)
					{	if ($n->nodeType == XML_ELEMENT_NODE)
						{	$last++;
						}
					}
					$value = $last;
				}
				else
				{	$node_name = $subnode->nodeName;
					if ($node_name == $name)
					{	if ($position_ot == -1)
						{	$position_ot = 0;
							for ($n=$subnode->previousSibling; $n; $n=$n->previousSibling)
							{	if ($n->nodeName == $node_name)
								{	$position_ot++;
								}
							}
							if ($is_p)
							{	return $position_ot;
							}
						}
						$last_ot = $position_ot + 1;
						for ($n=$subnode->nextSibling; $n; $n=$n->nextSibling)
						{	if ($n->nodeName == $node_name)
							{	$last_ot++;
							}
						}
						$value = $last_ot;
					}
					else
					{	$p = 0;
						for ($n=$subnode->previousSibling; $n; $n=$n->previousSibling)
						{	if ($n->nodeName == $node_name)
							{	$p++;
							}
						}
						if (!$is_p)
						{	$p++;
							for ($n=$subnode->nextSibling; $n; $n=$n->nextSibling)
							{	if ($n->nodeName == $node_name)
								{	$p++;
								}
							}
						}
						$value = $p;
					}
				}
			}
			return $value;
		};
		$ctx = new JoyQueryEmulationCtx($functions, $sub_paths, $get_last);
		while (true)
		{	if ($axis==AXIS_SELF or $axis==AXIS_PARENT)
			{	$next = null;
			}
			else if ($axis == AXIS_FIRST_FOLLOWING_SIBLING)
			{	$next = $subnode && $subnode->nodeType!=XML_ELEMENT_NODE ? $subnode->nextSibling : null;
			}
			else if ($axis == AXIS_FIRST_PRECEDING_SIBLING)
			{	$next = $subnode && $subnode->nodeType!=XML_ELEMENT_NODE ? $subnode->previousSibling : null;
			}
			else if ($axis==AXIS_CHILD or $axis==AXIS_FOLLOWING_SIBLING)
			{	$next = $subnode ? $subnode->nextSibling : null;
			}
			else if ($axis == AXIS_PRECEDING_SIBLING)
			{	$next = $subnode ? $subnode->previousSibling : null;
			}
			else if ($axis==AXIS_ANCESTOR or $axis==AXIS_ANCESTOR_OR_SELF)
			{	$next = $subnode ? $subnode->parentNode : null;
				if ($next and $next->nodeType!=XML_ELEMENT_NODE)
				{	$next = null;
				}
				$position = -1;
				$last = -1;
				$position_ot = -1;
				$last_ot = -1;
			}
			else
			{	if ($subnode === null) // DESCENDANT_OR_SELF
				{	$subnode = $node;
				}
				else if (($n2 = $subnode->firstChild) and !$no_enter)
				{	$subnode = $n2;
					$positions[$n_positions] = $position;
					$lasts[$n_positions] = $last;
					$position_ots[$n_positions] = $position_ot;
					$last_ots[$n_positions] = $last_ot;
					$n_positions++;
					$position = 0;
					$last = -1;
					$position_ot = $name ? 0 : -1;
					$last_ot = -1;
				}
				else
				{	$no_enter = false;
					while (true)
					{	if ($subnode === $node)
						{	return;
						}
						if (($n2 = $subnode->nextSibling))
						{	$subnode = $n2;
							break;
						}
						$subnode = $subnode->parentNode;
						if ($subnode and $subnode->nodeType!=XML_ELEMENT_NODE)
						{	$subnode = null;
						}
						$n_positions--;
						$position = $positions[$n_positions];
						$last = $lasts[$n_positions];
						$position_ot = $position_ots[$n_positions];
						$last_ot = $last_ots[$n_positions];
					}
				}
				$next = $subnode;
			}
			if (!$subnode)
			{	break;
			}
			$is_found = false;
			$maybe_found_node = null;
			if ($subnode->nodeType == XML_ELEMENT_NODE)
			{	if (!$name or $subnode->nodeName==$name)
				{	$ctx->node = $subnode;
					if (!$cond or $ctx->eval_condition($cond))
					{	if (!$is_last_step)
						{	$next_axis = $cur_path[$step + 1]['axis'];
							if ($next_axis==AXIS_DESCENDANT or $next_axis==AXIS_DESCENDANT_OR_SELF)
							{	$no_enter = true; // no_enter is only used when axis==DESCENDANT || axis==DESCENDANT_OR_SELF
							}
						}
						$position_range++;
						$is_found = $from <= $position_range;
						$maybe_found_node = $subnode;
					}
					if ($position_ot != -1)
					{	$position_ot += $inc_position;
					}
				}
				if ($position != -1)
				{	$position += $inc_position;
				}
			}
			$subnode = $next;
			if ($is_found)
			{	$next_iter_found = null;
				if (!$is_last_step)
				{	$next_iter_found = self::select_matching($cur_path, $step+1, $maybe_found_node, null, $functions, 0, $position, $last, $position_ot, $last_ot, $positions, $lasts, $position_ots, $last_ots, $n_positions, false);
				}
				if ($is_last_step or $next_iter_found)
				{	$next_iter = !$subnode || $from-1+$limit <= $position_range ? null : function() use($cur_path, $step, $node, $subnode, $functions, $position_range, $position, $last, $position_ot, $last_ot, &$positions, &$lasts, &$position_ots, &$last_ots, $n_positions, $no_enter)
					{	return self::select_matching($cur_path, $step, $node, $subnode, $functions, $position_range, $position, $last, $position_ot, $last_ot, $positions, $lasts, $position_ots, $last_ots, $n_positions, $no_enter);
					};
					$iter =
					[	'result' => $is_last_step ? $maybe_found_node : $next_iter_found['result'],
						'next' => $is_last_step ? $next_iter : function() use(&$iter, &$next_iter_found, $next_iter)
						{	$next_iter_found = $next_iter_found['next'] ? $next_iter_found['next']() : null;
							if ($next_iter_found)
							{	$iter['result'] = $next_iter_found['result'];
								return $iter;
							}
							return $next_iter ? $next_iter() : null;
						}
					];
					return $iter;
				}
			}
		}
	}
}

class JoyQueryEmulationCtx
{	public $node = null;
	public $functions = null;
	public $s = null;
	public $l = null;

	public function __construct($functions, $s, $l)
	{	$this->functions = $functions;
		$this->s = $s;
		$this->l = $l;
	}

	public function eval_condition($cond)
	{	return eval($cond);
	}
}

JoyQueryEmu::$FUNCTIONS['empty'] = function(DOMElement $node)
{	for ($e=$node->firstChild; $e; $e=$e->nextSibling)
	{	if ($e->nodeType!=XML_TEXT_NODE && $e->nodeType!=XML_CDATA_SECTION_NODE or $e->length>0)
		{	return false;
		}
	}
	return true;
};


namespace JoyQuery;

/**	Usage:
	@code
		require_once 'JoyQuery.php';
		use JoyQuery\JoyQueryEmu;

		$doc = new DOMDocument;
		$doc->loadHTML('<Div class=d hello=world><span>Hello</span></Div>');
		foreach (JoyQueryEmu::evaluate('.d span:last-child', $doc) as $elem)
		{	echo $doc->saveXml($elem->cloneNode()), "\n\n";
		}
	@endcode
**/
class JoyQueryEmu extends _\JoyQueryEmu {}
