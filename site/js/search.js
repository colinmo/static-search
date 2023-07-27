/*!
 * StaticSearch (c) 2013 Dmitry Chestnykh | BSD License
 * https://github.com/dchest/static-search
 */
var StaticSearch = (function () {

    var STOP_WORDS = {};
    [
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
    ].forEach((w) => STOP_WORDS[w] = true);

    function isStopWord(w) { return !!STOP_WORDS[w]; }

    var ACCENTS = {
        224: 'a', 225: 'a', 226: 'a', 227: 'a', 228: 'a', 229: 'a', 230: 'a',
        231: 'c', 232: 'e', 233: 'e', 234: 'e', 235: 'e', 236: 'i', 237: 'i',
        238: 'i', 239: 'i', 241: 'n', 242: 'o', 243: 'o', 244: 'o', 245: 'o',
        246: 'o', 339: 'o', 249: 'u', 250: 'u', 251: 'u', 252: 'u', 253: 'y',
        255: 'y'
    };

    function removeAccents(w) {
        var out = '', rep;
        for (var i = 0; i < w.length; i++) {
            c = w.charCodeAt(i);
            if (c >= 768 && c <= 879) {
                continue; // skip composed accent
            }
            rep = ACCENTS[c];
            out += rep ? rep : w.charAt(i);
        }
        return out;
    }

    function makeFormatter(name, v) {
        if (!v) {
            return function (x) { return x; }
        } else if (v instanceof Function) {
            return v;
        } else if (v instanceof String) {
            return function (x) {
                var data = {};
                data[name] = x;
                return _.template(v, data);
            };
        }
        throw 'expecting function or string';
    };

    var StaticSearch = function (index, options) {
        if (!index)
            throw 'Please provide a search index.';

        this._index = index;

        options || (options = {});

        this._titleFormat = makeFormatter('title', options.titleFormat);
        this._urlFormat = makeFormatter('url', options.urlFormat);
        this._dateFormat = makeFormatter('date', options.dateFormat);

        var that = this;
        this._exclude = {};
        if (options.exclude) {
            if (options.exclude instanceof Function) {
                this._exclude[options.exclude] = true;
            } else {
                options.exclude.forEach((u) => that._exclude[u] = true);
            }
        }
    };

    StaticSearch.prototype.search = function (query) {
        var that = this;
        var searchIndex = this._index;
        var queryWords = (removeAccents(query).match(/\w{1,}/g) || [])
            .map(function (s) { return s.toLowerCase(); });

        var lastWord = queryWords.pop();

        var words = queryWords
            .filter((s) => !isStopWord(s))
            .map(stemmer);

        var found = pick(searchIndex.words, words);

        // Consider last word in query a prefix and find the correct
        // word that matches it among all indexed words.
        if (lastWord) {
            var stemmedLastWord = stemmer(lastWord);
            Object.keys(searchIndex.words).forEach(function (key) {
                let obj = key, indexWord=searchIndex.words[key]
                if (indexWord[0] === lastWord[0]) {
                    if (indexWord.indexOf(lastWord) === 0 ||
                        indexWord.indexOf(stemmedLastWord) === 0) {
                        found[indexWord] = obj;
                    }
                }
            });
        }

        var docs = {};
        Object.entries(found).forEach(function (key) {
            key[1].forEach(function (dc) {
                var d = dc instanceof Number ?  dc : dc[0];
                docs[d] = (docs[d] || 0) + 1;
            });
        });

        docs = Object.entries(docs)
            .filter(function (p) { return p[1] >= words.length - 1; }) // allow 1 miss
            .map(function (p) { return +p[0]; });
        //console.log(docs);

        // Rank documents by word count.
        var ranksByDoc = {};
        Object.entries(found).forEach(function (key) {
            key[1].forEach(function (dc) {
                var d = dc instanceof Number ? dc : dc[0];
                if (docs.includes(d)) {
                    var r = dc instanceof Number ? 1 : dc[1];
                    ranksByDoc[d] = (ranksByDoc[d] || 0) + r;
                }
            });
        });

        return Object.entries(ranksByDoc)
            .filter((v) => !that._exclude[v.u]) // extract document number without rank
            .sort((p) => -p[1]) // sort by rank
            .map((x) => x[0])
            .map((v) => searchIndex.docs[v])
            .map((v) => {
                return {
                    title: that._titleFormat(v.t),
                    url: that._urlFormat(v.u),
                    date: that._dateFormat(v.d)
                };
            });
    };

    return StaticSearch;
})();

function pick(object, keys) {
    if (object instanceof Object) {
        toReturn = {}
        Object.entries(keys).forEeach((e) => {
            if (object.hasOwnProperty(e)) {
                toReturn[e] = object[e]
            }
        })
        return toReturn;
    }
    return {}
}