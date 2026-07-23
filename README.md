# WP Arabic Search

Makes WordPress search actually match Arabic.

WordPress search is a literal substring test: `LIKE '%term%'` against the title, excerpt and content. In Arabic that means every ordinary spelling variation returns nothing. Searching محمد does not find مُحَمَّد. أحمد does not find احمد. A word ending ه does not find the same word ending ة. And because the comparison uses a leading wildcard, no index can help, so it is a full table scan every time.

This plugin fixes both halves: it folds the variants into one canonical form on **both** the stored text and the query, and it matches against an indexed shadow copy instead of scanning.

## Two modes

Set `WPAS_MODE` in `wp-config.php`:

```php
define( 'WPAS_MODE', 'index' );      // default
define( 'WPAS_MODE', 'normaliser' );
```

**`index` (default)** is self-contained and needs nothing external. It maintains a normalised shadow table with a FULLTEXT index, keeps it in step as posts are saved and deleted, and rewrites WordPress search to use it. This fixes the public site search and the wp-admin post list at once, because core runs both through the same query path.

**`normaliser`** maintains nothing and rewrites nothing. It exposes the normaliser and fires an action every time a post's text is recomputed, so your own pipeline can push the normalised text into wherever you actually index (an external table, OpenSearch, a search service). Use this when search does not run inside WordPress.

```php
add_action( 'wpas_post_normalized', function ( $post_id, $title, $content ) {
    // send $title and $content to your own index
}, 10, 3 );

// and normalise the incoming query with the identical function before you match:
$term = \ManTek\ArabicSearch\normalize( $_GET['s'] );
```

The point of the split is that the **normaliser** is universal and the **index** is deployment-specific. Whatever you search with, both sides have to be folded by identical code, and that is what this guarantees.

## Install

Must-use plugin. Drop the file in place:

```
wp-content/mu-plugins/wp-arabic-search.php
```

or with Composer:

```
composer require mantekio/wp-arabic-search
```

Then build the index once (index mode only):

```
wp arabic-search reindex
```

The index table is `{your_prefix}search_index`. On multisite each site gets its own, alongside its own posts table. Rename it with the `wpas_table_basename` filter if it ever collides with something else.

## What the normaliser does

In order:

1. **Strips diacritics**: tashkeel, superscript alef, Quranic marks.
2. **Strips tatweel** (`U+0640`), which is decoration and carries no meaning.
3. **Folds the alef forms**: أ إ آ ٱ all become ا.
4. **Folds the hamza carriers**: ؤ becomes و, ئ becomes ي.
5. **Folds ى to ي** and **ة to ه**. These are conventions, not truths, so both are filterable.
6. **Strips the definite article** الـ, guarded so the remaining stem must be at least three letters.

Latin text is never touched: every rule targets Arabic codepoints only, so WordPress, AWS and S3 survive intact.

Filters: `wpas_fold_alef_maqsura`, `wpas_fold_taa_marbuta`, `wpas_strip_definite_article`, and `wpas_normalize` on the final output.

Check any term with:

```
wp arabic-search normalize "مُحَمَّد"
```

## Commands

- `wp arabic-search normalize "<term>"` shows what a term folds to. Works in both modes.
- `wp arabic-search reindex` rebuilds the index (index mode).
- `wp arabic-search status` prints the mode and index size, and exits non-zero if the index is behind.

## Known limits

- **Single-letter prefixes are deliberately not stripped.** Removing و ف ب ك ل looks tempting and is destructive, because those letters also begin ordinary words: كتاب would become تاب, بيت would become يت, and فكرة would become كرة, a different word entirely. So وكتاب will not match كتاب. Real prefix handling needs morphology, which is out of scope.
- **Light folding is not stemming.** كتاب will not find its broken plural كتب, because Arabic plurals rewrite the word rather than append to it. That needs a root dictionary.
- **Folding is lossy on purpose.** Merging ة into ه, or ى into ي, will occasionally merge two genuinely different words. For archive search that trade is nearly always worth it, but it is a trade: it is filterable, so decide deliberately.
- **FULLTEXT ignores very short tokens.** MySQL's `innodb_ft_min_token_size` defaults to 3, so one and two letter search tokens are skipped.
- **Ranking is a title boost, not relevance scoring.** Matching and ranking are both done on normalised text, so the two agree, but ranking is still core's binary "does the title contain the term" rather than a real relevance score.
- **It is dramatically faster for selective searches, not universally faster.** Measured on 50,000 posts: a selective term went from about 230 ms to 4 to 6 ms, roughly forty times faster, and returned matches that previously returned nothing. A term matching almost the entire corpus is marginally slower than the old scan, about 245 ms against 230 ms, because a sequential scan can stop at `LIMIT 10` while this path materialises every matching id and counts them. That is not a query anyone really runs, but the honest claim is "much faster for real searches", not "faster always".
- **Past a certain size, use a real search engine.** OpenSearch ships an `arabic` analyser that does normalisation, stemming and stopwords properly, and gives relevance ranking a `LIKE` never will. You still want a normaliser there, because the same text problem travels with you.

## License

GPL-2.0-or-later.
