<?php
/**
 * Provide a non-static search for the blog
 * for use in OpenSearch
 */

class StaticSearch {
    private $STOP_WORDS = [];
    private $index;
    private $titleFormat;
    private $urlFormat;
    private $dateFormat;
    private $exclude;
    private $ACCENTS = [
        224=> 'a', 225=> 'a', 226=> 'a', 227=> 'a', 228=> 'a', 229=> 'a', 230=> 'a',
        231=> 'c', 232=> 'e', 233=> 'e', 234=> 'e', 235=> 'e', 236=> 'i', 237=> 'i',
        238=> 'i', 239=> 'i', 241=> 'n', 242=> 'o', 243=> 'o', 244=> 'o', 245=> 'o',
        246=> 'o', 339=> 'o', 249=> 'u', 250=> 'u', 251=> 'u', 252=> 'u', 253=> 'y',
        255=> 'y'
    ];

    public function __construct($index, object $options=[]) {
        if (!$index) {
            throw new \Exception("Please provide a search index");
        }
        $this->STOP_WORDS = array_fill_keys([
            "all", "am", "an", "and", "any", "are", "aren't", "as", "at", "be",
            "because", "been", "before", "being", "below", "between", "both",
            "but", "by", "can't", "cannot", "could", "couldn't", "did", "didn't",
            "do", "does", "doesn't", "doing", "don't", "down", "for", "from",
            "further", "had", "hadn't", "has", "hasn't", "have", "haven't",
            "having", "he", "he'd", "he'll", "he's", "her", "here", "here's",
            "hers", "herself", "him", "himself", "his", "how", "how's", "i'd",
            "i'll", "i'm", "i've", "if", "in", "into", "is", "isn't", "it", "it's",
            "its", "itself", "let's", "me", "more", "most", "mustn't", "my",
            "myself", "no", "nor", "not", "of", "off", "on", "once", "only", "or",
            "other", "ought", "our", "ours ", "ourselves", "out", "over", "own",
            "same", "shan't", "she", "she'd", "she'll", "she's", "should",
            "shouldn't", "so", "some", "such", "than", "that", "that's", "the",
            "their", "theirs", "them", "themselves", "then", "there", "there's",
            "these", "they", "they'd", "they'll", "they're", "they've", "this",
            "those", "through", "to", "too", "under", "until", "up", "very", "was",
            "wasn't", "we", "we'd", "we'll", "we're", "we've", "were", "weren't",
            "what", "what's", "when", "when's", "where", "where's", "which",
            "while", "who", "who's", "whom", "why", "why's", "with", "won't",
            "would", "wouldn't", "you", "you'd", "you'll", "you're", "you've",
            "your", "yours", "yourself", "yourselves"
        ],true);
        $this->index = $index;
        $this->titleFormat = $this->makeFormatter("title", $options->titleFormat);
        $this->urlFormat = $this->makeFormatter("url", $options->urlFormat);
        $this->dateFormat = $this->makeFormatter("date", $options->dateFormat);
        $this->exclude = [];
        if ($options->exclude) {
            if (is_callable($options->exclude)) {
                $this->exclude[$options->exclude] = true;
            } else {
                $this->exclude = array_fill_keys($options->exclude, true);
            }
        }
    }

    public function isStopWord(string $w): bool {
        return !!$this->STOP_WORDS[$w];
    }

    public function removeAccents(string $w): string {
        $out = '';
        for($i=0;$i<strlen($w);$i++) {
            $D = $w[$i];
            $c = mb_ord($D, "UTF-8");
            if ($c >= 768 && $c <=879) {
                continue;
            }
            $rep = $this->ACCENTS[$c];
            $out .= $rep ? $rep : $D;
        }
        return $out;
    }

    public function makeFormatter(string $name, $v) {
        if (!$v) {
            return function($x) { return $x;};
        }
        if (is_string($v)) {
            return function($x) use ($name,$v) { return sprintf($v, [$name=>$x]); };
        }
        if (is_callable($v)) {
            return $v;
        }
        throw new \Exception("Unknown option to make formatter");
    }

    public function search(string $query): array {
        $queryWords = [];
        preg_match_all("/\w{1,}/g",$this->removeAccents($query, $queryWords));
        array_walk($queryWords, 'strtolower');
        $lastWord = array_pop($queryWords);
        $words = array_map('stemmer', array_filter($queryWords, function($s)  {return !$this->isStopWord($s);}));
        $found = pick($this->index, $words);
        if ($lastWord) {
            $stemmedLastWord = stemmer($lastWord);
            foreach ($this->index->words as $K=>$indexWord) {
                if ($indexWord[0] == $lastWord[0]) {
                    if (strpos($indexWord, $lastWord) == 0 || strpos($indexWord, $stemmedLastWord) === 0) {
                        $found[$indexWord] = $K;
                    }
                }
            }
        }
        $docs = [];
        foreach ($found as $key) {
            foreach ($key[1] as $dc) {
                $d = is_numeric($dc) ? $dc : $dc[0];
                $docs[$d] = 1 + ($docs[$d]?:0);
            }
        }
        $docs = array_map(function($p) { return +$p[0]; }, array_filter($docs, function($p) use ($words) { return $p[1] >= count($words)-1; }));

        $ranksByDoc = [];
        foreach ($found as $key) {
            foreach($key[1] as $dc) {
                $d = is_numeric($dc) ? $dc : $dc[0];
                if (in_array($d, $docs)) {
                    $r = is_numeric($dc) ? 1 : $dc[1];
                    $ranksByDoc[$d] = ($ranksByDoc[$d] ?: 0) + $r;
                }
            }
        }
        $tf = $this->titleFormat;
        $uf = $this->urlFormat;
        $df = $this->dateFormat;

        $r1 = array_filter($ranksByDoc, function($v) { return !$this->exclude[$v->u];});
        usort($r1, function($p,$q) { return $p<=>$q; });
        array_walk($r1, function($x) {return $x[0];});
        array_walk($r1, function($v) { return $this->index->docs[$v];});
        array_walk($r1, function($v) use ($tf,$uf,$df) { return ["title" => $tf($v->t), "url" => $uf($v->u), "date" => $df($v->d)];});
        return $r1;
    }
}

function stemmer(string $s): string {
    return $s;
}

function pick($object, $keys):array{
    if (is_object($object)) {
        $toReturn = [];
        foreach ($object as $K=>$D) {
            if (in_array($K, $keys)) {
                $toReturn[$K] = $D;
            }
        }
        return $toReturn;
    }
    return [];
}
