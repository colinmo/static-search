<?php
/**
 * Provide a non-static search for the blog
 * for use in OpenSearch
 */

ini_set('error_reporting',E_ALL);
ini_set('error_log','error.log');

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

    public function __construct($index, object $options=null) {
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
        $i = preg_match_all("/\w{1,}/",$this->removeAccents($query), $queryWords);
        $queryWords = $queryWords[0];
        array_walk($queryWords, function($v) { $v = strtolower($v); });
        $words = array_map('stemmer', array_filter($queryWords, function($s)  {return !$this->isStopWord($s);}));
        $found = pick($this->index->words, $words);

        $docs = [];
        foreach ($found as $K=>$D) {
            $key = [$K,$D];
            foreach ($key[1] as $dc) {
                $d = is_numeric($dc) ? $dc : $dc[0];
                $docs[$d] = 1 + ($docs[$d]?:0);
            }
        }
        $docs = array_keys(array_filter($docs, function($p) use ($words) { return $p >= count($words)-1; }));
        $ranksByDoc = [];
        foreach ($found as $K=>$D) {
            $key = [$K,$D];
            foreach($key[1] as $dc) {
                $d = is_numeric($dc) ? $dc : $dc[0];
                if (in_array($d, $docs)) {
                    $r = is_numeric($dc) ? 0 : $dc[1];
                    $ranksByDoc[$d] = ($ranksByDoc[$d] ?: 0) + $r;
                }
            }
        }
        $tf = $this->titleFormat;
        $uf = $this->urlFormat;
        $df = $this->dateFormat;

        $r1 = array_filter($ranksByDoc, function($v) { return !$this->exclude[$ind->docs[$v]->u];});
        uasort($r1, function($p,$q) { return $q<=>$p; });
        $r1 = array_flip($r1);
        $ind = $this->index;
        array_walk($r1, function(&$v) use ($ind) { $v = $ind->docs[$v];});
        array_walk($r1, function(&$v) use ($tf,$uf,$df) { $v =  ["title" => $tf($v->t), "url" => $uf($v->u), "date" => $df($v->d)];});
        return $r1;
    }

    private function getIndex(&$v) {
        $v = $this->index->docs[$v];
    }
}

function stemmer(string $s): string {
    include_once 'vendor/autoload.php';
    return \Nadar\Stemming\Stemm::stem($s, "en");
}

function pick($object, $keys):array{
    if (is_object($object)) {
        $toReturn = [];
        foreach ($keys as $D) {
            if (isset($object->{$D})) {
                $toReturn[$D] = $object->{$D};
            }
        }
        return $toReturn;
    }
    return [];
}

$bob = file_get_contents("/home/relapse/www/search-index.js");
$bob = substr($bob, strlen("searchIndex = "));
$bob = json_decode($bob);
$search = new StaticSearch($bob, (object)[
        "titleFormat" => function ($s) { return preg_replace("/( - (Article|Indieweb|Page|Reply) )?- von Explaino$/", '',$s); },
        "dateFormat" => function ($s) { return preg_replace("/^(\d{4}-\d{2}-\d{2}).*/", "$1", $s);}]
);
echo searchAsFeed($_GET['query'], $search->search($_GET['query']?:"XXXXXXXXXXXXX"));

function searchAsFeed(string $terms, array $results): string {
    $qterms = urlencode($terms);
    $hterms = str_replace('"','&quot;',htmlentities($terms));
    $when = date("Y-m-dTH:i:sZ+1000");
    $total = count($results);
    $rss =<<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"
      xmlns:opensearch="http://a9.com/-/spec/opensearch/1.1/">
  <title>vonExplaino.com search: $hterms</title>
  <link href="https://vonexplaino.com/code/search/index.php?$qterms"/>
  <updated>$when</updated>
  <author><name>Colin Morris / Professor von Explaino</name></author>
  <opensearch:totalResults>$total</opensearch:totalResults>
  <opensearch:startIndex>1</opensearch:startIndex>
  <opensearch:itemsPerPage>$total</opensearch:itemsPerPage>
  <opensearch:Query role="request" searchTerms="$hterms" startPage="1" />
  <!--<link rel="alternate" href="https://vonexplaino.com/code/search/index.php?$qterms" type="text/html"/>-->
  <link rel="self" href="https://vonexplaino.com/code/search/index.php?$qterms" type="application/atom+xml"/>
  <!--<link rel="first" href="http://example.com/New+York+History?pw=1&amp;format=atom" type="application/atom+xml"/>
  <link rel="previous" href="http://example.com/New+York+History?pw=2&amp;format=atom" type="application/atom+xml"/>
  <link rel="next" href="http://example.com/New+York+History?pw=4&amp;format=atom" type="application/atom+xml"/>
  <link rel="last" href="http://example.com/New+York+History?pw=42299&amp;format=atom" type="application/atom+xml"/>-->
  <link rel="search" type="application/opensearchdescription+xml" href="https://vonexplaino.com/.well-known/search.xml"/>
EOF;
    foreach ($results as $D) {
        $rss .= <<<EOF

  <entry>
    <title>{$D['title']}</title>
    <link href="https://vonexplaino.com/blog/posts{$D['url']}"/>
    <updated>{$D['date']}</updated>
    <content type="text"></content>
  </entry>

EOF;
    }
    return $rss . '</feed>';
}