<?php
/**
 * <XDOM.header>
 *
 * @php-version 5.2
 */

/**
 * Css Selector Parser
 */
class XDOM_CssParser implements Iterator
{
    /**
     * @var XDOM_Tokenizer
     */
    private $tokenizer;

    /**
     * @var string
     */
    private $string;

    /**
     * @var string|array
     */
    private $current;

    /**
     * @var string[]
     */
    private $currentCache;

    /**
     * @var int
     */
    private $offset, $currentOffset;

    /**
     * iterator will ignore tokens marked as "/*..."
     *
     * @var bool
     */
    private $ignore = TRUE;

    /**
     * @var int
     */
    private $peek;


    public function __construct()
    {
        $this->tokenizer = new XDOM_Tokenizer();
    }

    /**
     * @param string $string
     */
    public function setString($string)
    {
        $this->string = (string)$string;
        $this->rewind();
    }

    public function parse($string)
    {
        $this->string = (string)$string;
        $this->rewind();
        $this->selectors_group();
    }

    private function selectors_group()
    {
        # selectors_group
        #   : selector [ COMMA S* selector ]*
        #   ;
        if (!$this->expectP('selector')) return FALSE;
        while ($this->valid())
        {
            if (!$this->expect('COMMA')) return FALSE;
            while ($this->accept('S')) ;
            if (!$this->expectP('selector')) return FALSE;
        }
        return TRUE;
    }

    private function selector()
    {
        if (!$this->expectP('simple_selector_sequence')) return FALSE;

        // @todo incomplete (only if at end here then okay)

        while ($this->acceptP('combinator'))
        {
            if (!$this->expectP('simple_selector_sequence')) return FALSE;
        }
        return TRUE;
    }

    private function combinator()
    {
        // @todo incomplete
        return FALSE;
    }

    private function simple_selector_sequence()
    {
        # simple_selector_sequence
        #   : [ type_selector | universal ]
        #     <G1>[ HASH | class | attrib | pseudo | negation ]*
        #     | <G1>[ HASH | class | attrib | pseudo | negation ]+
        #   ;

        if (!($this->acceptP('type_selector') || $this->acceptP('universal'))) {
            if (!$this->expectP('simple_selector_sequence_G1')) return FALSE;
        }
        while ($this->acceptP('simple_selector_sequence_G1')) ;
        return TRUE;
    }

    private function simple_selector_sequence_G1()
    {
        # HASH | class | attrib | pseudo | negation
        if ($this->accept('HASH')) return TRUE;
        if ($this->acceptP('_class')) return TRUE;
        if ($this->acceptP('attrib')) return TRUE;
        if ($this->acceptP('pseudo')) return TRUE;
        if ($this->acceptP('negation')) return TRUE;
        return FALSE;
    }

    private function type_selector()
    {
        $this->acceptP('namespace_prefix');
        return $this->expectP('element_name');

    }

    private function namespace_prefix()
    {
        $this->accept('IDENT') || $this->acceptC('*');
        return $this->expectC('|');
    }

    private function element_name()
    {
        return $this->expect('IDENT');
    }

    private function universal()
    {
        $this->acceptP('namespace_prefix');
        return $this->expectC('*');
    }

    private function _class()
    {
        if (!$this->expectC('.')) return FALSE;
        if (!$this->expect('IDENT')) return FALSE;
        return TRUE;
    }

    private function attrib()
    {
        # attrib
        #   : '[' S* [ namespace_prefix ]? IDENT S*
        #     <G1>[ [ PREFIXMATCH |
        #             SUFFIXMATCH |
        #             SUBSTRINGMATCH |
        #             '=' |
        #             INCLUDES |
        #             DASHMATCH ] S* [ IDENT | STRING ] S*
        #         ]? ']'
        #   ;
        if (!$this->expectC('[')) return FALSE;
        while ($this->accept('S')) ;
        $this->acceptP('namespace_prefix');
        if (!$this->expect('IDENT')) return FALSE;
        while ($this->accept('S')) ;
        $this->acceptP('attrib_G1');
        if (!$this->expectC(']')) return FALSE;
        return TRUE;
    }

    private function attrib_G1()
    {
        if (!$this->expectGrammarOrString("PREFIXMATCH | SUFFIXMATCH | SUBSTRINGMATCH | '=' | INCLUDES | DASHMATCH")) return FALSE;
        while ($this->accept('S')) ;
        if (!$this->expectGrammarOrString("IDENT | STRING")) return FALSE;
        while ($this->accept('S')) ;
        return TRUE;
    }

    private function pseudo()
    {
        if (!$this->expectC(':')) return FALSE;
        $this->acceptC(':');
        if (!$this->expectGrammarOr(array('IDENT', 'functional_pseudo'))) return FALSE;

        return TRUE;
    }

    private function functional_pseudo()
    {
        if (!$this->expect('FUNCTION')) return FALSE;
        while ($this->accept('S')) ;
        if (!$this->expectP('expression')) return FALSE;
        if (!$this->expectC(')')) return FALSE;
        return TRUE;
    }

    private function expression()
    {
        $match = FALSE;
        while ($this->expectGrammarOrString("PLUS | '-' | DIMENSION | NUMBER | STRING | IDENT"))
        {
            $match = TRUE;
            while ($this->accept('S')) ;
        }
        return $match;
    }

    private function negation()
    {
        if (!$this->expect('NOT')) return FALSE;
        while ($this->accept('S')) ;
        if (!$this->expectP('negation_arg')) return FALSE;
        while ($this->accept('S')) ;
        if ($this->expectC(')')) return FALSE;
        return TRUE;
    }

    private function negation_arg()
    {
        return $this->expectGrammarOr("type_selector | universal | HASH | class | attrib | pseudo");
    }

    private function error($message)
    {
        $message = str_replace("\n", '/ ', $message);
        // echo "PARSE ERROR: $message\n";
        if (!$this->peek) {
            throw new Exception(sprintf('PARSE ERROR: %s at %d.', $message, $this->offset));
        }
    }

    private function acceptGrammarOrString($grammar)
    {
        $orParts = preg_split('/\\s+\\|\\s+/', $grammar);
        foreach ($orParts as $part)
        {
            $term = trim($part);
            if ('' === $term) throw new Exception(sprintf('Empty term "%s".', $term));
            if (FALSE !== strpos($term, ' ')) throw new Exception(sprintf('Invalid term "%s".', $term));
            if ($this->acceptTerm($term)) return TRUE;
        }
        return FALSE;
    }

    private function expectGrammarOrString($grammar)
    {
        if ($this->acceptGrammarOrString($grammar)) return TRUE;
        $this->error("expect: [$grammar]");
        return FALSE;
    }

    private function acceptGrammarOr(array $grammar)
    {
        while (list(, $term) = each($grammar)) {
            if ($this->acceptTerm($term)) return TRUE;
        }
        return FALSE;
    }

    private function expectGrammarOr(array $grammar)
    {
        if ($this->acceptGrammarOr($grammar)) return TRUE;
        $this->error("expect: [" . implode(' | ', $grammar) . "].");
        return FALSE;
    }

    private function acceptTerm($term)
    {
        $tc = $term[0];
        if ("'" === $tc) {
            // @note not utf-8 for chars not within single byte range (normally not the case)
            if (!$this->acceptC($term[1])) return FALSE;
        } elseif (strtoupper($tc) === $tc) {
            if (!$this->accept($term)) return FALSE;
        } else {
            if (!$this->acceptP($term)) return FALSE;
        }
        return TRUE;
    }

    private function acceptP($production)
    {
        $keep = $this->offset;
        $this->peek++;
        $result = call_user_func(array($this, $production));
        $this->peek--;
        if (!$result) {
            $this->seek($keep);
        }
        return $result;
    }

    private function expectP($production)
    {
        if ($this->acceptP($production)) return TRUE;
        $this->error("expect: [$production] unexpected @ token: " . print_r($this->current(), 1));
        return FALSE;
    }

    private function acceptC($character)
    {
        $current = $this->current();
        if ($current !== $character) return FALSE;
        $this->next();
        return TRUE;
    }

    private function expectC($character)
    {
        if ($this->acceptC($character)) return TRUE;
        $this->error("expectC: [$character] unexpected character: " . print_r($this->current(), 1));
        return FALSE;
    }

    private function accept($symbol)
    {
        $current = $this->current();
        $count = count($current);
        if ($count !== 2) return FALSE;
        if ($current[0] !== $symbol) return FALSE;
        $this->next();
        return TRUE;
    }

    private function expect($symbol)
    {
        if ($this->accept($symbol)) return TRUE;
        $this->error("expect: [$symbol] unexpected symbol: " . print_r($this->current(), 1));
        return FALSE;
    }

    public function current()
    {
        if ($this->offset === $this->currentOffset) {
            return $this->current;
        }
        $this->currentOffset = $this->offset;
        if (isset($this->currentCache[$this->offset])) {
            $this->current = $this->currentCache[$this->offset];
        } else {
            $this->current = $this->ignore ? $this->currentFetchIgnore() : $this->currentFetch();
            $this->currentCache[$this->offset] = $this->current;
        }
        return $this->current;
    }

    private function currentFetch()
    {
        return $this->tokenizer->tokenAt($this->string, $this->offset);
    }

    private function currentFetchIgnore()
    {
        while (NULL !== $peek = $this->tokenizer->tokenAt($this->string, $this->offset))
        {
            if (count($peek) === 1) break;
            if ($peek[0] !== '/* ignore comments */') break;
            $this->offset += strlen($peek[1]);
        }
        return $peek;
    }

    public function key()
    {
        return $this->offset;
    }

    public function rewind()
    {
        $this->peek = 0;
        $this->currentOffset = -1;
        $this->current = NULL;
        $this->currentCache = array();
        $this->seek(0);
    }

    public function seek($position)
    {
        $this->offset = $position;
    }

    public function valid()
    {
        return (bool)count($this->current());
    }

    public function next()
    {
        $current = $this->current();
        $count = count($current);
        if (!$count--) {
            return;
        }
        $length = strlen($count ? $current[1] : $current);
        if (!$length) {
            throw new UnexpectedValueException(sprintf('Current has no length.'));
        }
        $this->current = NULL;
        $this->offset += $length;
    }
}