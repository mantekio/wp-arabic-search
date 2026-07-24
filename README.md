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
add_action( 'wpas_post_normalized', function ( $post_id, $title, $search_text ) {
    // $search_text is everything searchable about the post, already folded:
    // title, excerpt, content, and whatever `wpas_searchable_parts` added.
    // Send it to your own index.
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

Nothing walks your archive when you activate the plugin, so that one command is what backfills everything you already have. After it, every save keeps its own row current. Skip it and the plugin notices the index is empty and stands aside, letting core answer your searches, rather than answering all of them with nothing.

**Which posts:** every one except revisions, autosaves and auto-drafts. That deliberately includes drafts, pending, scheduled, private and trashed posts, and attachments. Editors search those in wp-admin, and this plugin *replaces* the matching step rather than filtering it, so anything without a row is invisible to search. What a **visitor** can see is still WordPress's decision: it applies its own `post_status` rules on top, and the plugin never touches them.

**Which text:** title, excerpt and content, folded into one indexed column, so a word counts wherever it appears. Core searches those same three fields, so anything less would answer worse than the search being replaced. Attachments contribute their **caption**, **alt text** and **filename** too. Filename matters because core matches it through a separate filter that rewrites core's own `LIKE` clause, which our rewrite leaves nothing to hook onto, so indexing it ourselves is the only way a Media Library search keeps working. Alt text goes further than core, which does not search it at all.

Anything else goes in through one filter, with no schema change, because it all lands in the same column:

```php
add_filter( 'wpas_searchable_parts', function ( $parts, $post ) {
    if ( 'product' === $post->post_type ) {
        $parts[] = get_post_meta( $post->ID, 'sku', true );
    }
    return $parts;
}, 10, 2 );
```

Everything is indexed by default, deliberately, because anything left out is invisible to search. If a site carries a large custom post type nobody searches, a log or an import staging table, narrow it:

```php
add_filter( 'wpas_indexed_post_types', function () {
    return array( 'post', 'page', 'attachment' );
} );
```

Then run `wp arabic-search reindex --prune` to drop the rows for types you just excluded.

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
- `wp arabic-search explain "<term>"` answers "why did this not match?", which is the question this plugin gets asked most. It prints the folded form, every token with whether it was used and why not, whether the search falls back to core, the exact `MATCH ... AGAINST` it will run, and how many rows in the index that matches.
- `wp arabic-search reindex` rebuilds the index (index mode). It walks by primary key in batches, so memory stays flat on a large archive.
  - `--batch=<n>` posts per batch (default 1000).
  - `--after=<id>` resume after a post ID, so an interrupted run does not start again from zero.
  - `--prune` also delete rows whose post is gone or is no longer indexable.
- `wp arabic-search status` prints the mode, the index size, and each way the index can be wrong, counted separately:
  - **missing**, an indexable post with no row, so it cannot be found. Exits non-zero.
  - **stale**, a row older than its post, which happens when something changes a post without firing `save_post`: an importer, a migration, a direct SQL write. A row count alone cannot see this. Exits non-zero. Future-dated posts are ignored, so scheduled content does not report stale for ever.
  - **orphans**, a row whose post is gone or is no longer indexable. Warns without failing, because the join never matches them so search stays correct, and clears with `reindex --prune`.

  These are counted directly rather than inferred from totals, because orphaned rows inflate the row count: an index missing fifteen posts while carrying fifteen orphans has totals that match exactly, and would otherwise report itself healthy while fifteen posts were unsearchable.

```
$ wp arabic-search explain "من المكتبة"
mode:        index
raw:         من المكتبة
normalised:  من مكتبه

+--------+------+--------------------------------------------------+
| token  | used | reason                                           |
+--------+------+--------------------------------------------------+
| من     | no   | shorter than innodb_ft_min_token_size (3)        |
| مكتبه  | yes  | indexable                                        |
+--------+------+--------------------------------------------------+

matches with: MATCH(search_text) AGAINST ('+مكتبه' IN BOOLEAN MODE)
```

## Known limits

- **Single-letter prefixes are deliberately not stripped.** Removing و ف ب ك ل looks tempting and is destructive, because those letters also begin ordinary words: كتاب would become تاب, بيت would become يت, and فكرة would become كرة, a different word entirely. So وكتاب will not match كتاب. Real prefix handling needs morphology, which is out of scope.
- **Light folding is not stemming.** كتاب will not find its broken plural كتب, because Arabic plurals rewrite the word rather than append to it. That needs a root dictionary.
- **Folding is lossy on purpose.** Merging ة into ه, or ى into ي, will occasionally merge two genuinely different words. For archive search that trade is nearly always worth it, but it is a trade: it is filterable, so decide deliberately.
- **Words the index cannot hold fall back to core.** Two kinds: tokens below `innodb_ft_min_token_size` (3 by default, which covers common two-letter Arabic words like من, في and ما), and FULLTEXT stopwords, which InnoDB refuses to index at all. Rather than require a token the index does not hold, and so return nothing, the plugin hands those searches straight back to WordPress's own `LIKE`, and results then match core exactly. In a mixed query the unusable token is dropped and the longer ones still match. `wp arabic-search explain` shows exactly which happened. The built-in stopword list is English, so it rarely bites an Arabic archive, but a site can point `innodb_ft_server_stopword_table` at its own. No server change is needed for this: `innodb_ft_min_token_size` is a static setting that needs a MySQL restart and a rebuild of every FULLTEXT index on the server, and it buys nothing for correctness.
- **The fallback is correct, not fast.** A search that falls back to core gets core's behaviour entirely, including its speed, because it is core's query. On a large archive that means a search for a two-letter word takes as long as WordPress has always taken, measured at about three seconds on a 750,000-row index where an indexed search of the same archive took well under one. Nothing is worse than before, but nothing is better either, so do not expect the plugin to have made *every* search fast.
- **That "never worse than core" guarantee is scoped to the fallback path.** A search that falls back returns exactly what core returns. A search answered from the index returns what the index holds, so a post whose row is missing will not be found, which is true of every index-backed search and is exactly what `wp arabic-search status` exists to catch. Keep the index current and the two paths agree; let it drift and only the fallback is still identical to core.
- **Ranking is a title boost, not relevance scoring.** Matching and ranking are both done on normalised text, so the two agree, but ranking is still core's binary "does the title contain the term" rather than a real relevance score.
- **It is dramatically faster, with one narrow exception.** Measured on 50,000 posts: a selective search went from about 210 ms to 5 ms, roughly forty times faster, and returned matches that previously returned nothing. It stays ahead as the match count climbs, and even a term matching all 50,000 rows is faster through the index (about 205 ms against 300 ms). The exception needs two things at once: a term sitting at the very start of every document, so `LIKE` matches on the first bytes and abandons each row immediately, *and* a match count near the whole archive. Given both, the scan gets its best possible case and wins, about 135 ms against 212 ms. Measured crossover: the index wins until roughly 70% of the archive matches, and only loses beyond that when the scan also gets that early exit. Against a term the scan has to read in full, the index wins across the entire range.
- **The index costs disk, in the database.** It holds a second, normalised copy of your searchable text, so budget for it. Measured on a real archive: about 3 KB per article of normalised text (roughly half the raw size, since tags and repeated whitespace are stripped), and about 150 bytes per attachment. A 750,000-row index across a quarter of a million articles and half a million attachments came to about 1.1 GB, of which the `FULLTEXT` index itself was only 17 MB. Attachments were two thirds of the rows and a sixteenth of the bytes, so making your media findable is close to free. Building that index took about 25 minutes at flat memory.
- **Past a certain size, use a real search engine.** OpenSearch ships an `arabic` analyser that does normalisation, stemming and stopwords properly, and gives relevance ranking a `LIKE` never will. You still want a normaliser there, because the same text problem travels with you.

## License

GPL-2.0-or-later.
