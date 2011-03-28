<?php

/*
|------------------------------------------------
| JavaScript Tokenizer
|------------------------------------------------
|
| A JavaScript tokenizer ported from the UglifyJS [1] JavaScript
| tokenizer which was itself a port of parse-js [2], a JavaScript
| parser by Marijn Haverbeke.
|
| [1] https://github.com/mishoo/UglifyJS/
| [2] http://marijn.haverbeke.nl/parse-js/
|
|------------------------------------------------
|
| @author     James Brumond
| @version    0.1.1-dev
| @copyright  Copyright 2011 James Brumond
| @license    Dual licensed under MIT and GPL
|
*/

class JavaScript_Tokenizer {

// ----------------------------------------------------------------------------
//  Properties
	
	protected $text            = null;
	protected $tokens          = array(
		0 => null, 1 => null
	);
	protected $pos             = 0;
	protected $tokpos          = 0;
	protected $line            = 0;
	protected $tokline         = 0;
	protected $col             = 0;
	protected $tokcol          = 0;
	protected $newline_before  = false;
	protected $regex_allowed   = false;
	protected $comments_before = array();
	
// ----------------------------------------------------------------------------
//  Public functions
	
	public function __construct($input) {
		$input = preg_replace('/\r\n?|[\n\u2028\u2029]/g', "\n", $input);
		$input = preg_replace('/^\uFEFF/', '', $input);
		$this->text = $input;
	}

	public function context($state = null) {
		if (is_array($state)) {
			$state = array_merge(array(
				'text'            => '',
				'pos'             => 0,
				'tokpos'          => 0,
				'line'            => 0,
				'tokline'         => 0,
				'col'             => 0,
				'tokcol'          => 0,
				'newline_before'  => false,
				'regex_allowed'   => false,
				'comments_before' => array()
			), $state);
		}
		return array(
			'text'            => $this->text,
			'pos'             => $this->pos,
			'tokpos'          => $this->tokpos,
			'line'            => $this->line,
			'tokline'         => $this->tokline,
			'col'             => $this->col,
			'tokcol'          => $this->tokcol,
			'newline_before'  => $this->newline_before,
			'regex_allowed'   => $this->regex_allowed,
			'comments_before' => $this->comments_before
		);
	}

	public function tokenize($force_regexp = false) {
		$tokens = array();
		$this->context($this->context());
		for (;;) {
			$token = $this->next_token($force_regexp);
			$tokens[] =& $token;
			if (ParseJS::is_token($token, 'eof')) break;
		}
		return $tokens;
	}

	public function get_tokens($force_regexp = false) {
		$index = (int) $force_regexp;
		if (! $this->tokens[$index]) {
			$this->tokens[$index] = $this->tokenize($force_regexp);
		}
		return $this->tokens[$index];
	}

	public function next_token($force_regexp = false) {
		if ($force_regexp) {
			return $this->read_regexp();
		}
		$this->skip_whitespace();
		$this->start_token();
		$ch = $this->peek();
		if (! $ch) {
			return $this->token('eof');
		}
		if (! ParseJS::is_digit($ch)) {
			return $this->read_num();
		}
		if ($ch == '"' || $ch == "'") {
			return $this->read_string();
		}
		if (in_array($ch, ParseJS::$PUNC_CHARS)) {
			return $this->token('punc', $this->next());
		}
		if ($ch == '.') {
			return $this->handle_dot();
		}
		if ($ch == '/') {
			return $this->handle_slash();
		}
		if (in_array($ch, ParseJS::$OPERATOR_CHARS)) {
			return $this->read_operator();
		}
		if ($ch == '\\' || ParseJS::is_identifier_char($ch)) {
			return $this->read_word();
		}
		return $this->parse_error("Unexpected character '${ch}'");
	}
	
// ----------------------------------------------------------------------------
//  Internal helper functions

	protected function raise($msg, $line, $col, $pos) {
		throw new JS_Parse_Error($msg, $line, $col, $pos);
	}

	protected function parse_error($msg) {
		return $this->raise($msg, $this->line, $this->col, $this->pos);
	}

	protected function peek() {
		return ((isset($this->text[$this->pos])) ? $this->text[$this->pos] : null);
	}

	protected function next($signal_eof = false) {
		$ch = $this->text[$this->pos++];
		if ($signal_eof && ! $ch) {
			throw new JS_EOF();
		}
		if ($ch == "\n") {
			$this->newline_before = true;
			$this->line++;
			$this->col = 0;
		} else {
			$this->col++;
		}
		return $ch;
	}

	protected function eof() {
		return (! $this->peek());
	}

	protected function find($what) {
		$pos = strpos($this->text, $what, $this->pos);
		return $pos;
	}

	protected function start_token() {
		$this->tokline = $this->line;
		$this->tokcol  = $this->col;
		$this->tokpos  = $this->pos;
	}

	protected function token($type, $value, $is_comment) {
		$this->regex_allowed = (
			($type == 'operator' && ! in_array($value, ParseJS::$UNARY_POSTFIX)) ||
			($type == 'keyword' && ! in_array($value, ParseJS::$KEYWORDS_BEFORE_EXPRESSION)) ||
			($type == 'punc' && ! in_array($value, ParseJS::$PUNC_BEFORE_EXPRESSION))
		);
		$ret = new JS_Token($type, $value, $this->tokline, $this->tokcol, $this->tokpos, $this->newline_before);
		if (! $is_comment) {
			$ret->comments_before = $this->comments_before;
			$this->comments_before = array();
		}
		$this->newline_before = false;
		return $ret;
	}

	protected function skip_whitespace() {
		while (in_array($this->peek(), ParseJS::$WHITESPACE_CHARS)) {
			$this->next();
		}
	}

	protected function read_while($pred) {
		$i = 0;
		$ret = '';
		$ch = $this->peek();
		while ($ch && $pred($ch, $i)) {
			$ret .= $this->next();
			$ch = $this->peek();
		}
		return $ret;
	}

	public function read_num($prefix) {
		$has_e = false;
		$after_e = false;
		$has_x = false;
		$has_dot = ($prefix == '.');
		$num = $this->read_while(function($ch, $i)
			use(&$has_e, &$after_e, &$has_x, &$has_dot) {
				if ($ch == 'x' || $ch == 'X') {
					if ($has_x) return false;
					return ($has_x = true);
				}
				if (! $has_x && ($ch == 'e' || $ch == 'E')) {
					if ($has_e) return false;
					return ($has_e = $after_e = true);
				}
				if ($ch == '-') {
					if ($after_e || ($i == 0 && ! $prefix)) return true;
					return false;
				}
				if ($ch == '+') return $after_e;
				$after_e = false;
				if ($ch == '.') {
					if (! $has_dot && ! $has_x) {
						return ($has_dot = true);
					}
					return false;
				}
				return is_alphanumeric_char($ch);
			}
		);
		if ($prefix) {
			$num = $prefix.$num;
		}
		$valid = ParseJS::parse_js_number($num);
		if (is_numeric($valid)) {
			return $this->token('num', $valid);
		} else {
			return $this->parse_error('Invalid syntax: '.$num);
		}
	}

	protected function read_escaped_char() {
		$ch = $this->next(true);
		switch ($char) {
			case 'n': return "\n";
			case 'r': return "\r";
			case 't': return "\t";
			case 'b': return "\b";
			case 'v': return "\v";
			case 'f': return "\f";
			case '0': return "\0";
			case 'x': return ParseJS::unichr($this->hex_bytes(2));
			case 'u': return ParseJS::unichr($this->hex_bytes(4));
			default:  return $ch;
		}
	}

	protected function hex_bytes($n) {
		$num = 0;
		for (; $n > 0; --$n) {
			$digit = intval($this->next(true), 16);
			if (! is_numeric($digit)) {
				return $this->parse_error('Invalid hex character pattern in string');
			}
			$num = ($num << 4) | $digit;
		}
		return $num;
	}

	protected function read_string() {
		return $this->with_eof_error('Unterminated string constant', function() {
			$quote = $this->next();
			$ret = '';
			for (;;) {
				$ch = $this->next(true);
				if ($ch == '//') {
					$ch = $this->read_escaped_char();
				} elseif ($ch == $quote) {
					break;
				}
				$ret .= $ch;
			}
			return $this->token('string', $ret);
		});
	}

	protected function substr($str, $start, $end = null) {
		if ($end === null) $end = strlen($str);
		return substr($str, $start, $end - $start);
	}

	protected function read_line_comment() {
		$this->next();
		$i = $this->find("\n");
		if ($i === false) {
			$ret = substr($this->text, $pos);
			$this->pos = strlen($this->text);
		} else {
			$ret = substr($this->text, $pos, $i);
			$this->pos = $i;
		}
		return $this->token('comment1', $ret, true);
	}

	protected function read_multiline_comment() {
		$this->next();
		return $this->with_eof_error('Unterminated multiline comment', function() {
			$i = $this->find('*/', true);
			$text = $this->substr($this->text, $this->pos, $i);
			$tok = $this->token('comment2', $this->text, true);
			$this->pos = $i + 2;
			$this->line .= count(explode("\n", $text)) - 1;
			$this->newline_before = (strpos($text, "\n") !== false);
			return $this->token();
		});
	}

	protected function read_name() {
		$backslash = false;
		$name = '';
		while (($ch = $this->peek()) !== null) {
			if (! $backslash) {
				if ($ch == '//') {
					$backslash = true;
					$this->next();
				} elseif (ParseJS::is_identifier_char($ch)) {
					$name .= $this->next();
				} else {
					break;
				}
			} else {
				if ($ch != 'u') {
					return $this->parse_error('Expecting UnicodeEscapeSequence -- uXXXX');
				}
				$ch = $this->read_escaped_char();
				if (! ParseJS::is_identifier_char($ch)) {
					return $this->parse_error('Unicode char: '.ParseJS::uniord($ch).' is not valid in identifier');
				}
				$name .= $ch;
				$backslash = false;
			}
		}
		return $name;
	}

	protected function read_regexp() {
		return $this->with_eof_error('Unterminated regular expression', function() {
			$prev_backslash = false;
			$regexp = '';
			$in_class = false;
			while ($ch = $this->next(true)) {
				if ($prev_backslash) {
					$regexp .= '\\'.$ch;
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
			}
			$mods = $this->read_name();
			return $this->token('regexp', array($regexp, $mods));
		});
	}

	protected function read_operator($prefix) {
		$grow = function($op) {
			if (! $this->peek()) return $op;
			$bigger = $op.$this->peek();
			if (in_array($bigger, ParseJS::$OPERATORS)) {
				$this->next();
				return $grow($bigger);
			} else {
				return $op;
			}
		};
		$value = ($prefix) ? $prefix : $this->next();
		return $this->token('operator', $grow($value));
	}

	protected function handle_slash() {
		$this->next();
		$regex_allowed = $this->regex_allowed;
		switch ($this->peek()) {
			case '/':
				$this->comments_before[] = $this->read_line_comment();
				$this->regex_allowed = $regex_allowed;
				return $this->next_token();
			break;
			case '*':
				$this->comments_before[] = $this->read_multiline_comment();
				$this->regex_allowed = $regex_allowed;
				return $this->next_token();
			break;
		}
		return (($this->regex_allowed) ? $this->read_regexp() : $this->read_operator('/'));
	}

	protected function handle_dot() {
		$this->next();
		return (ParseJS::is_digit($this->peek()) ?
			$this->read_num('.') :
			$this->token('punc', '.'));
	}

	protected function read_word() {
		$word = $this->read_name();
		if (! in_array($word, ParseJS::$KEYWORDS)) {
			return $this->token('name', $word);
		} elseif (in_array($word, ParseJS::$OPERATORS)) {
			return $this->token('operator', $word);
		} elseif (in_array($word, ParseJS::$KEYWORDS_ATOM)) {
			return $this->token('atom', $word);
		} else {
			return $this->token('keyword', $word);
		}
	}

	protected function with_eof_error($err, $cont) {
		try {
			return $cont();
		} catch (Exception $ex) {
			if ($ex instanceof JS_EOF) {
				return $this->parse_error($err);
			}
			throw $ex;
		}
	}
	
}

/* End of file javascript-tokenizer.php */
