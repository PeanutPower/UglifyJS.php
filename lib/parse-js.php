<?php

/*
|----------------------------------------------------------
| UglifyJS.php
|----------------------------------------------------------
|
| A port of the UglifyJS JavaScript obfuscator in PHP.
|
| @author     James Brumond
| @version    0.1.1-a
| @copyright  Copyright 2010 James Brumond
| @license    Dual licensed under MIT and GPL
| @requires   PHP >= 5.3.0
|
|----------------------------------------------------------
|
| This file contains the tokenizer/parser. It is a port of
| the parse-js.js file included in UglifyJS [1] which is
| itself a port of the Common Lisp library parse-js [2]
| written by Marijn Haverbeke.
|
| [1] https://github.com/mishoo/UglifyJS
| [2] http://marijn.haverbeke.nl/parse-js/
|
*/

/**
 * Import Utilities
 */
require_once(UGLIFYJS_LIBPATH.'parse-utilities.php');

/**
 * The parse error exception class
 */
class UglifyJS_parse_error {
	public $msg = null;
	public $line = null;
	public $col = null;
	public $pos = null;
	public function __construct($err, $line, $col, $pos) {
		$this->msg = $err;
		$this->line = $line;
		$this->col = $col;
		$this->pos = $pos;
	}
}

/**
 * Used only for signaling EOFs
 */
class UglifyJS_EOF extends Exception { }

/**
 * The tokenizer class
 */
class UglifyJS_tokenizer {
	
	/**
	 * JavaScript Keywords
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $keywords = array(
		'break',
		'case',
		'catch',
		'const',
		'continue',
		'default',
		'delete',
		'do',
		'else',
		'finally',
		'for',
		'function',
		'if',
		'in',
		'instanceof',
		'new',
		'return',
		'switch',
		'throw',
		'try',
		'typeof',
		'var',
		'void',
		'while',
		'with'
	);
	
	/**
	 * Reserved Words
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $reserved_words = array(
		'abstract',
		'boolean',
		'byte',
		'char',
		'class',
		'debugger',
		'double',
		'enum',
		'export',
		'extends',
		'final',
		'float',
		'goto',
		'implements',
		'import',
		'int',
		'interface',
		'long',
		'native',
		'package',
		'private',
		'protected',
		'public',
		'short',
		'static',
		'super',
		'synchronized',
		'throws',
		'transient',
		'volatile'
	);
	
	/**
	 * Keywords Before Expression
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $keywords_before_expression = array(
		'return',
		'new',
		'delete',
		'throw',
		'else'
	);
	
	/**
	 * Keywords ATOM
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $keywords_atom = array(
		'false',
		'null',
		'true',
		'undefined'
	);
	
	/**
	 * Operator Characters
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $operator_chars = array(
		'+', '-', '*', '&', '%', '=', '<',
		'>', '!', '?', '|', '~', '^'
	);
	
	/**
	 * Hexadecimal Number Regular Expression
	 *
	 * @access  protected
	 * @type    string
	 */
	protected $hex_number = '/^0x[0-9a-f]+$/i';
	
	/**
	 * Octal Number Regular Expression
	 *
	 * @access  protected
	 * @type    string
	 */
	protected $oct_number = '/^0[0-7]+$/';
	
	/**
	 * Decimal Number Regular Expression
	 *
	 * @access  protected
	 * @type    string
	 */
	protected $dec_number = '/^\d*\.?\d*(?:e[+-]?\d*(?:\d\.?|\.?\d)\d*)?$/i';
	
	/**
	 * Operators
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $operators = array(
		'in',
		'instanceof',
		'typeof',
		'new',
		'void',
		'delete',
		'++',
		'--',
		'+',
		'-',
		'!',
		'~',
		'&',
		'|',
		'^',
		'*',
		'/',
		'%',
		'>>',
		'<<',
		'>>>',
		'<',
		'>',
		'<=',
		'>=',
		'==',
		'===',
		'!=',
		'!==',
		'?',
		'=',
		'+=',
		'-=',
		'/=',
		'*=',
		'%=',
		'>>=',
		'<<=',
		'>>>=',
		'~=',
		'%=',
		'|=',
		'^=',
		'&=',
		'&&',
		'||'
	);
	
	/**
	 * Whitespace Characters
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $whitespace_chars = array( ' ', "\n", "\r", "\t" );
	
	/**
	 * Puncuation Before Expressions
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $punc_before_expression = array( '[', '{', '}', '(', ',', '.', ';', ':' );
	
	/**
	 * Puncuation Characters
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $punc_chars = array( '[', ']', '{', '}', '(', ')', ',', ';', ':' );
	
	/**
	 * Regular Expression Modifiers
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $regexp_modifiers = array( 'g', 'i', 'm', 's', 'y' );
	
	/**
	 * Unary Prefixes
	 */
	protected $unary_prefix = array(
		'typeof',
		'void',
		'delete',
		'--',
		'++',
		'!',
		'~',
		'-',
		'+'
	);
	
	/**
	 * Unary Postfixes
	 */
	protected $unary_postfix = array( '--', '++' );
	
/*
|----------------------------------------------------------------------------------------
|                              END OF TOKEN DEFINITIONS
|----------------------------------------------------------------------------------------
*/
	
	/**
	 * Check if a character is alpha-numeric
	 *
	 * @access  protected
	 * @param   string    the character to test
	 * @return  bool
	 */
	protected function is_alphanumeric_char($ch) {
		$ord = ord($ch);
		return (
			($ord >= 48 && $ord <= 57) ||
			($ord >= 65 && $ord <= 90) ||
			($ord >= 97 && $ord <= 122)
		);
	}
	
	/**
	 * Check if a character is an identifier character
	 *
	 * @access  protected
	 * @param   string    the character to test
	 * @return  bool
	 */
	protected function is_identifier_char($ch) {
		return ($this->is_alphanumeric_char($ch) || $ch == '$' || $ch == '_');
	}
	
	/**
	 * Check if a character is a digit
	 *
	 * @access  protected
	 * @param   string    the character to test
	 * @return  bool
	 */
	protected function is_digit($ch) {
		$ord = ord($ch);
		return ($ord >= 48 && $ord <= 57);
	}
	
	/**
	 * Parse a number string into a number
	 *
	 * @access  protected
	 * @param   string    the hex string
	 * @return  mixed
	 */
	protected function parse_js_number($num) {
		if (preg_match($this->hex_number, $num)) {
			return hexdec($num);
		} elseif (preg_match($this->oct_number, $num)) {
			return octdec($num);
		} elseif (preg_match($this->dec_number, $num)) {
			return ((float) $num);
		}
		return false;
	}
	
/*
|------------------------------------------------------------------------------
|                       END OF TOKEN-TYPE FUNCTIONS
|------------------------------------------------------------------------------
*/
	
	/**
	 * Information about the current state of the tokenizer
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $state = array(
		'text'           => null,
		'pos'            => 0,
		'tokpos'         => 0,
		'line'           => 0,
		'tokline'        => 0,
		'col'            => 0,
		'tokcol'         => 0,
		'newline_before' => false,
		'regex_allowed'  => false
	);
	
	/**
	 * The skip_comments parameter of the constructor
	 *
	 * @access  protected
	 * @type    bool
	 */
	protected $skip_comments = null;
	
	/**
	 * Constructor
	 *
	 * @access  public
	 * @param   string    the source code
	 * @param   bool      skip comments?
	 * @return  void
	 */
	public function __construct($code, $skip_comments = false) {
		$this->state['text'] = $code;
		$this->skip_comments =!! $skip_comments;
	}
	
	/**
	 * Get the character at the current location
	 *
	 * @access  protected
	 * @return  string
	 */
	protected function peek() {
		return @$this->state['text'][$this->state['pos']];
	}
	
	/**
	 * Get the next character from source
	 *
	 * @access  protected
	 * @return  string
	 */
	protected function next($signal_eof = false) {
		$ch = @$this->state['text'][$this->state['pos']++];
		// Signal an EOF if requested
		if ($signal_eof && ! $ch) {
			throw new UglifyJS_EOF();
		}
		// Update the state
		if ($ch == "\n") {
			$this->state['newline_before'] = true;
			$this->state['line']++;
			$this->state['col'] = 0;
		} else {
			$this->state['col']++;
		}
		return $ch;
	}
	
	/**
	 * Check if the tokenizer has reached the end of the source
	 *
	 * @access  protected
	 * @return  bool
	 */
	protected function eof() {
		return (! $this->peek());
	}
	
	/**
	 * Find a string within the source starting at the current position
	 *
	 * @access  protected
	 * @param   string    the item to find
	 * @param   bool      signal an EOF
	 * @return  int
	 */
	protected function find($what, $signal_eof) {
		$pos = strpos($this->state['text'], $what, $this->state['pos']);
		// Signal EOF if requested
		if ($signal_eof && $pos === false) {
			throw new UglifyJS_EOF();
		}
		return $pos;
	}
	
	/**
	 * Start a token at the current location
	 *
	 * @access  protected
	 * @return  void
	 */
	protected function start_token() {
		$this->state['tokline'] = $this->state['line'];
		$this->state['tokcol'] = $this->state['col'];
		$this->state['tokpos'] = $this->state['pos'];
	}
	
	/**
	 * Creates a token object
	 *
	 * @access  protected
	 * @param   string    the token type
	 * @param   string    the token value
	 * @return  object
	 */
	protected function token($type, $value = null) {
		$this->state['regex_allowed'] = (
			($type == 'operator' && ! in_array($value, $this->unary_postfix)) ||
			($type == 'keyword' && in_array($value, $this->keywords_before_expression)) ||
			($type == 'punc' && in_array($value, $this->punc_before_expression))
		);
		// Build the token object
		$ret = ((object) array(
			'type' => $type,
			'value' => $value,
			'line' => $this->state['tokline'],
			'col' => $this->state['tokcol'],
			'pos' => $this->state['tokpos'],
			'nlb' => $this->state['newline_before']
		));
		$this->state['newline_before'] = false;
		return $ret;
	}
	
	/**
	 * Skips over whitespace
	 *
	 * @access  protected
	 * @return  void
	 */
	protected function skip_whitespace() {
		while (in_array($this->peek(), $this->whitespace_chars)) $this->next();
	}
	
	/**
	 * Continues reading while a given condition remains true
	 *
	 * @access  protected
	 * @param   callback  the condition
	 * @return  string
	 */
	protected function read_while($pred) {
		$ret = '';
		$ch = $this->peek();
		$i = 0;
		while ($ch && $pred($ch, $i++)) {
			$ret .= $this->next();
			$ch = $this->peek();
		}
		return $ret;
	}
	
	/**
	 * Throws a parse error
	 *
	 * @access  protected
	 * @param   string    the error message
	 * @return  void
	 */
	protected function parse_error($msg) {
		throw new UglifyJS_parse_error($msg, $this->state['tokline'], $this->state['tokcol'], $this->state['pos']);
	}
	
	/**
	 * Reads number values
	 *
	 * @access  protected
	 * @param   string    a prefix
	 * @return  object
	 */
	protected $tmp = null;
	protected function read_num($prefix = '') {
		$this->tmp = array(
			'has_e' => false,
			'after_e' => false,
			'has_x' => false
		);
		$num = $this->read_while(function($ch, $i) {
			if ($ch == 'x' || $ch == 'X') {
				if ($this->tmp['has_x']) return false;
				$this->tmp['has_x'] = true;
				return $true;
			}
			if (! $this->tmp['has_x'] && ($ch = 'e' || $ch = 'E')) {
				if ($this->tmp['has_e']) return false;
				$this->tmp['has_e'] = true;
				$this->tmp['after_e'] = true;
				return true;
			}
			if ($ch == '-') {
				if ($this->tmp['after_e'] || ($i == 0 && ! $prefix)) return true;
				return false;
			}
			if ($ch == '+') return $this->tmp['after_e'];
			return ($ch == '.' || $this->is_alphanumeric_char($ch));
		});
		$this->tmp = null;
		if ($prefix) $num = $prefix.$num;
		$valid = $this->parse_js_number($num);
		if (is_numeric($valid)) {
			return $this->token('num', $valid);
		} else {
			$this->parse_error("Invalid syntax: {$num}");
		}
	}
	
	/**
	 * Read backslash escaped characters
	 *
	 * @access  protected
	 * @return  string
	 */
	protected function read_escaped_char() {
		$ch = $this->next(true);
		switch ($ch) {
			case 'n' : return "\n";
			case 'r' : return "\r";
			case 't' : return "\t";
			case 'b' : return "\b";
			case 'v' : return "\v";
			case 'f' : return "\f";
			case '0' : return "\0";
			case 'x' : return chr($this->hex_bytes(2));
			case 'u' : return chr($this->hex_bytes(4));
			default  : return $ch;
		}
	}
	
	/**
	 * Reads a number of bytes as hex
	 *
	 * @access  protected
	 * @param   int       the number of bytes
	 * @return  string
	 */
	protected function hex_bytes($n) {
		$num = 0;
		for (; $n > 0; --$n) {
			$digit = hexdec($this->next(true));
			if (! is_int($digit))
				$this->parse_error('Invalid hex-character pattern in string');
			$num = ($num << 2) | $digit;
					read_line_comment();
					$this->state['regex_allowed'] = $regex_allowed;
					return $this->next_token();
		}
		return $num;
	}
	
	/**
	 * Read a JavaScript DOMString
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function read_string() {
		return $this->with_eof_error('Unterminated string constant', function() {
			$quote = $this->next();
			$ret = '';
			while (true) {
				$ch = $this->next(true);
				if ($ch == '\\') {
					$ch = $this->read_escaped_char();
				} else if ($ch == $quote) {
					break;
				}
				$ret .= $ch;
			}
			return $this->token('string', $ret);
		});
	}
	
	/**
	 * Fetch a substring starting at state['pos']
	 *
	 * @access  protected
	 * @param   int       the substring ending position
	 * @param   int       an extra amount to add to state['pos']
	 * @return  string
	 */
	protected function substr($to = false, $plus = 0) {
		if ($to === false) {
			$ret = substr($this->state['text'], $this->state['pos']);
			$this->state['pos'] = strlen($this->state['text']);
		} else {
			$len = $to - $this->state['pos'];
			$ret = substr($this->state['text'], $this->state['pos'], $len);
			$this->state['pos'] = $to + $plus;
		}
		return $ret;
	}
	
	/**
	 * Reads a line comment
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function read_line_comment() {
		next();
		$i = $this->find("\n");
		$ret = $this->substr($i);
		return $this->token('comment1', $ret);
	}
	
	/**
	 * Read multiline comment
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function read_multiline_comment() {
		return $this->with_eof_error('Unterminated multiline comment', function() {
			next();
			$i = $this->find('*/', true);
			$text = $this->substr($i, 2);
			$tok = $this->token('comment2', $text);
			$this->state['newline_before'] = (strpos($this->state['text'], "\n") !== false);
			return $tok;
		});
	}
	
	/**
	 * Read a regular expression literal
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function read_regexp() {
		return $this->with_eof_error('Unterminated regular expression', function() {
			$prev_backslash = false;
			$regexp = '';
			$in_class = false;
			while ($ch = $this->next(true)) {
				if ($prev_backslash) {
					$regexp .= '\\'.$ch;
					$prev_backslash = false;
				} elseif ($ch == '[') {
					$in_class = true;
					$regexp .= $ch;
				} elseif ($ch == ']' && $in_class) {
					$in_class = false;
					$regexp .= $ch;
				} elseif ($ch == '/' && ! $in_class) {
					break;
				} elseif ($ch == '\\') {
					$prev_backslash = true;
				} else {
					$regexp .= $ch;
				}
				$mods = $this->read_while(function($ch) {
					return in_array($ch, $this->regexp_modifiers);
				});
				return $this->token('regexp', array($regexp, $mods));
			}
		});
	}
	
	/**
	 * Reads an operator
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function read_operator($prefix = null) {
		$op = ($prefix ? $prefix : $this->next());
		while (in_array($op.$this->peek(), $this->operators)) {
			$op .= $this->peek();
			$this->next();
		}
		return $this->token('operator', $op);
	}
	
	/**
	 * Handles slashes
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function handle_slash() {
		$this->next();
		if ($this->skip_comments) {
			$regex_allowed = $this->state['regex_allowed'];
			switch ($this->peek()) {
				case '/':
					$this->read_line_comment();
					$this->state['regex_allowed'] = $regex_allowed;
					return $this->next_token();
				break;
				case '*':
					$this->read_multiline_comment();
					$this->state['regex_allowed'] = $regex_allowed;
					return $this->next_token();
				break;
			}
		} else {
			switch ($this->peek()) {
				case '/':
					return $this->read_line_comment();
				break;
				case '*':
					return $this->read_multiline_comment();
				break;
			}
		}
		return ($this->state['regex_allowed'] ? $this->read_regexp() : $this->read_operator('/'));
	}
	
	/**
	 * Handles a dot
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function handle_dot() {
		$this->next();
		return ($this->is_digit($this->peek()) ?
			$this->read_num('.') :
			$this->token('punc', '.'));
	}
	
	/**
	 * Reads a word (name, keyword, etc)
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function read_word() {
		$word = $this->read_while($this->is_identifier_char);
		if (! in_array($word, $this->keywords)) {
			return $this->token('name', $word);
		} elseif (in_array($word, $this->operators)) {
			return $this->token('operator', $word);
		} elseif (in_array($word, $this->keywords_atom)) {
			return $this->token('atom', $word);
		} else {
			return $this->token('keyword', $word);
		}
	}
	
	/**
	 * Run a callback with EOF handling
	 *
	 * @access  protected
	 * @param   string    the EOF error message
	 * @param   callback  the function to run
	 * @return  mixed
	 */
	protected function with_eof_error($err, $func) {
		try {
			return $func();
		} catch (UglifyJS_EOF $e) {
			$this->parse_error($err);
		}
	}
	
/*
|------------------------------------------------------------------------------
|                          END OF TOKENIZER FUNCTIONS
|------------------------------------------------------------------------------
*/	
	
	/**
	 * The public interface, retrieves the next token from
	 * the source code.
	 *
	 * @access  public
	 * @param   bool      force parsing in regex mode
	 * @return  object
	 */
	public function next_token($force_regexp = false) {
		if ($force_regexp) {
			return $this->read_regexp();
		}
		$this->skip_whitespace();
		$this->start_token();
		$ch = $this->peek();
		if (! $ch) return $this->token('eof');
		if ($this->is_digit($ch)) return $this->read_num();
		if ($ch == '"' || $ch == "'") return $this->read_string();
		if (in_array($ch, $this->punc_chars)) return $this->token('punc', $this->next());
		if ($ch == '.') return $this->handle_dot();
		if ($ch == '/') return $this->handle_slash();
		if (in_array($ch, $this->operator_chars)) return $this->read_operator();
		if (is_identifier_char($ch)) return $this->read_word();
		parse_error("Unexpected character '{$ch}'");
	}
	
	/**
	 * Manually override the state variable / get the current state
	 *
	 * @access  public
	 * @param   array     the new state
	 * @return  array
	 */
	public function context($state = null) {
		if ($state) {
			$this->state = $state;
		}
		return $this->state;
	}
	
}

/*
|------------------------------------------------------------------------------
|                        END OF TOKENIZER - BEGIN PARSER
|------------------------------------------------------------------------------
*/

class UglifyJS_node_with_token {
	public $name = null;
	public $start = null;
	public $end = null;
	public function __construct($name, $start, $end) {
		$this->name = $name;
		$this->start = $start;
		$this->end = $end;
	}
	public function __toString() {
		return $this->name;
	}
}

class UglifyJS_parser {
	
	/**
	 * Unary prefixes
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $unary_prefix = array(
		'typeof',
		'void',
		'delete',
		'--',
		'++',
		'!',
		'~',
		'-',
		'+'
	);
	
	/**
	 * Unary postfixes
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $unary_postfix = array( '--', '++' );
	
	/**
	 * Assignment operators
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $assignment = array(
		'='    => true,
		'+='   => '+',
		'-='   => '-',
		'/='   => '/',
		'*='   => '*',
		'%='   => '%',
		'>>='  => '>>',
		'<<='  => '<<',
		'>>>=' => '>>>',
		'~='   => '~',
		'%='   => '%',
		'|='   => '|',
		'^='   => '^',
		'&='   => '&'
	);
	
	/**
	 * Order of operations
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $precedence = array(
		'||'         => 1,
		'&&'         => 2,
		'|'          => 3,
		'^'          => 4,
		'&'          => 5,
		'=='         => 6,
		'==='        => 6,
		'!='         => 6,
		'!=='        => 6,
		'<'          => 7,
		'>'          => 7,
		'<='         => 7,
		'>='         => 7,
		'in'         => 7,
		'instanceof' => 7,
		'>>'         => 8,
		'<<'         => 8,
		'>>>'        => 8,
		'+'          => 9,
		'-'          => 9,
		'*'          => 10,
		'/'          => 10,
		'%'          => 10
	);
	
	/**
	 * Statements which can recieve labels
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $statements_with_labels = array(
		'for', 'do', 'while', 'switch'
	);
	
	/**
	 * Atomic start tokens
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $atomic_start_token = array(
		'atom', 'num', 'string', 'regexp', 'name'
	);
	
/*
|------------------------------------------------------------------------------
|                         END OF TOKEN DEFINITIONS
|------------------------------------------------------------------------------
*/
	
	/**
	 * Test the type/value of the current token
	 *
	 * @access  protected
	 * @param   string    the type
	 * @param   string    the value
	 * @return  bool
	 */
	protected function is($type, $value = null) {
		return UJSUtil()->is_token($this->state['token'], $type, $value);
	}
	
	/**
	 * The current parser state
	 *
	 * @access  protected
	 * @type    array
	 */
	protected $state = array(
		'input'       => null,
		'token'       => null,
		'prev'        => null,
		'peeked'      => null,
		'in_function' => 0,
		'in_loop'     => 0,
		'labels'      => array()
	);
	
	/**
	 * Run in strict mode
	 *
	 * @access  protected
	 * @type    bool
	 */
	protected $strict_mode = false;
	
	/**
	 * Embed tokens
	 *
	 * @access  protected
	 * @type    bool
	 */
	protected $embed_tokens = false;
	
	/**
	 * Constructor
	 *
	 * @access  public
	 * @param   string    the code
	 * @param   bool      run in strict mode
	 * @param   bool      embed tokens
	 * @return  void
	 */
	public function __construct($text, $strict_mode = false, $embed_tokens = false) {
		$this->state['input'] = new UglifyJS_tokenizer($text, true);
		$this->strict_mode = ($strict_mode == true);
		$this->embed_tokens = ($embed_tokens == true);
	}	
	
	/**
	 * Throws a parse error
	 *
	 * @access  protected
	 * @param   string    the error message
	 * @param   number    line
	 * @param   number    column
	 * @param   number    position
	 * @return  void
	 */
	protected function parse_error($msg, $line = null, $col = null, $pos = null) {
		$ctx = $this->state['input']->context();
		throw new UglifyJS_parse_error($msg,
			(($line === null) ? $ctx['tokline'] : $line),
			(($col === null)  ? $ctx['tokcol']  : $col),
			(($pos === null)  ? $cxt['pos']     : $pos)
		);
	}
	
	/**
	 * Throws a token error
	 *
	 * @access  protected
	 * @param   string    the error message
	 * @param   object    the token
	 * @return  void
	 */
	protected function token_error($msg, $token) {
		$this->parse_error($msg, $token->line, $token->col);
	}
	
	/**
	 * Throw an unexpected token error
	 *
	 * @access  protected
	 * @param   object    the token
	 * @return  void
	 */
	protected function unexpected($token = null) {
		if ($token === null) {
			$token = $this->state['token'];
		}
		$this->token_error('Unexpected token: '.$token->type.' ('.$token->value.')', $token);
	}
	
	/**
	 * Tell the parser to expect a specific token
	 *
	 * @access  protected
	 * @param   string    the token type
	 * @param   string    the token value
	 * @return  object
	 */
	protected function expect_token($type, $value = null) {
		if ($this->is($type, $value)) {
			return $this->next();
		}
		$token = $this->state['token'];
		$this->token_error('Unexpected token '.$token->type.', expected '.$type);
	}
	
	/**
	 * Expect a specific punctuation character
	 *
	 * @access  protected
	 * @param   string    the character
	 * @return  object
	 */
	protected function expect($punc) {
		return $this->expect_token('punc', $punc);
	}
	
	/**
	 * Peek at the current token
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function peek() {
		if (! $this->state['peeked']) {
			$this->state['peeked'] = $this->state['input']->next_token();
		}
		return $this->state['peeked'];
	}
	
	/**
	 * Move on to the next token
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function next() {
		$this->state['prev'] = $this->state['token'];
		if ($this->state['peeked']) {
			$this->state['token'] = $this->state['peeked'];
			$this->state['peeked'] = null;
		} else {
			$this->state['token'] = $this->state['input']->next_token();
		}
		return $this->state['token'];
	}
	
	/**
	 * Get the previous token
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function prev() {
		return $this->state['prev'];
	}
	
	/**
	 * Check if the parser can insert a semicolon
	 *
	 * @access  protected
	 * @return  bool
	 */
	protected function can_insert_semicolon() {
		return (! $this->strict_mode && (
			$this->state['token']->nlb || $this->is('eof') || $this->is('punc', '}')
		));
	}
	
	/**
	 * Check for a semicolon
	 *
	 * @access  protected
	 * @return  void
	 */
	protected function semicolon() {
		if ($this->is('punc', ';')) {
			$this->next();
		} elseif (! $this->can_insert_semicolon()) {
			$this->unexpected();
		}
	}
	
	/**
	 * Read a parenthesised value
	 *
	 * @access  protected
	 * @return  object
	 */
	protected function parenthesised() {
		$this->expect('(');
		$ex = $this->expression();
		$this->expect(')');
		return $ex;
	}
	
	/**
	 * Add tokens
	 *
	 * @access  protected
	 * @param   string    the text
	 * @param   int       start
	 * @param   int       end
	 * @return  object
	 */
	protected function add_tokens($str, $start, $end) {
		return new UglifyJS_node_with_token($str, $start, $end);
	}
	
	/**
	 * Gets a statement
	 *
	 * @access  protected
	 * @return  array
	 */
	protected function statement() {
		if ($this->embed_tokens) {
			$start = $this->state['token'];
			$statement = $this->_statement();
			$statement[0] = $this->add_tokens($statement[0], $start, $this->prev());
			return $statement;
		} else {
			return $this->_statement();
		}
	}
	
	/**
	 * Do the statement processing (a sub-function of $this->statement)
	 *
	 * @access  protected
	 * @return  array
	 */
	protected function _statement() {
		if ($this->is('operator', '/')) {
			$this->state['peeked'] = null;
			// Force regular expression parsing
			$this->state['token'] = $this->state['input']->next_token(true);
		} else {
			switch ($this->state['token']->type) {
				case 'num':
				case 'string':
				case 'regexp':
				case 'operator':
				case 'atom':
					return $this->simple_statement();
				break;
				case 'name':
					if (UJSUtil()->is_token($this->peek(), 'punc', ':')) {
						return $this->labeled_statement(
							UJSUtil()->prog1(
								$this->state['token']->value, $this->next, $this->next
							)
						);
					} else {
						return $this->simple_statement();
					}
				break;
			}
		}
	}
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
}






















/* End of file parse-js.php */
