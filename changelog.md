# Changelog

All notable changes to **WP Arabic Search** are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.2]

**Upgrading: run `wp arabic-search reindex` after updating.** The index is
rebuilt empty on purpose, because the stored shape changed. Until you reindex,
search falls back to WordPress's own `LIKE` and `wp arabic-search status` exits
non-zero. Nothing returns wrong results in the meantime.

### Fixed
- **`post_excerpt` was never indexed, for any post type.** Core searches title,
  excerpt **and** content, so a post whose manual excerpt carried a word found
  nowhere else was returned by plain WordPress and missed here.
- **Attachments were indexed by title and description only.** Captions and
  filenames are indexed now too. Core matches a filename through a separate
  filter that rewrites core's own `LIKE` clause, and this plugin's rewrite left
  that clause absent, so the filename match was never appended and Media Library
  search had lost it silently.
- **`reindex` reported success having written nothing.** Its count came from the
  number of posts *selected*, never the number written, so every write could fail
  and the command still announced the full total. It now counts rows after the
  run, reports `indexed 0 of 20 posts; 20 writes did not land`, and recovers on
  its own from a table that has been dropped (previously it needed
  `wp option delete wpas_schema_version` first, which nobody would guess).
- **`status` reported a missing table as a raw SQL error** instead of a clear
  message telling you to reindex.
- **`table_exists()` matched underscores as wildcards.** `_` is a
  single-character wildcard in `LIKE`, and the table name is mostly underscores,
  so the check could match a same-length table with other characters in those
  positions. `esc_like()` makes it exact.

### Added
- **Alt text is searchable.** WordPress does not search
  `_wp_attachment_image_alt` at all, so this is capability core lacks rather than
  parity with it.
- **`wpas_searchable_parts( array $parts, WP_Post $post )`.** Add a meta field, a
  taxonomy, or a whole custom post type's worth of text and it becomes
  searchable, with no schema change, because everything lands in the same indexed
  column.

### Changed
- **One searchable column.** The index kept `normalized_title` and
  `normalized_content` and matched across both. It now keeps a single matched
  column, `search_text`, holding title, excerpt, content and anything a filter
  adds. `normalized_title` is retained solely so the ranking boost still
  discriminates between a word in the headline and the same word in paragraph
  forty. Schema version 3.
- **A schema change now drops and rebuilds rather than migrating in place.**
  `dbDelta` cannot reliably drop a column or swap a `FULLTEXT` key, and a
  half-migrated table is the dangerous direction: rows would exist with an empty
  `search_text`, so the plugin would consider the index usable and answer every
  search with nothing. Dropping fails safely, because an empty index makes the
  plugin stand aside for core.
- No measurable cost from any of the above: index size and reindex duration were
  unchanged at 50,000 posts (67 MB, 15 s), and query times were flat or slightly
  better.

## [0.9.1]

### Fixed
- **Two-letter Arabic words returned nothing at all.** The token guard counted
  bytes rather than characters, and an Arabic character is two bytes, so short
  tokens like من, في and ما were never dropped and became required terms that
  InnoDB has never indexed (`innodb_ft_min_token_size` is 3 by default). Short
  tokens are now dropped, and a search left with none falls back to core's `LIKE`
  and matches it exactly. In a mixed query the short token is dropped and the
  longer ones still match.
- **That fallback was then silently intersected with the index**, so a post
  missing its index row vanished from a search core would have answered. All
  three query filters now gate on one shared decision instead of each deciding
  for itself.
- **`status` could not see a stale index, only a missing one.** It compared row
  counts, so an importer, a migration or a direct SQL write left a row with stale
  contents while the totals still added up. It now compares `updated_at` against
  `post_modified_gmt`, reports a stale count, and exits non-zero.
- **Scheduled posts reported stale for ever**, because they carry a
  `post_modified_gmt` in the future that a row stamped "now" can never reach. And
  a post **published ahead of schedule** reported stale once its original time
  passed, because `wp_publish_post()` does not rewrite that timestamp. Both
  fixed.

### Changed
- **The search walks the index once instead of twice.** A single `INNER JOIN`
  carries both the match and the ranking, replacing an
  `IN ( SELECT ... MATCH ... )` subquery plus a separate join. About 16% faster
  on heavy queries, with no change to the query plan.

## [0.9.0]

### Added
- Initial release. A WordPress must-use plugin that makes search match Arabic,
  by folding both the stored text and the query with identical code:
  - **Normalisation:** diacritics (tashkeel), tatweel, alef forms and hamza
    carriers, plus filterable folding of ى to ي and ة to ه, and a guarded strip
    of the definite article الـ. Latin text is untouched throughout. Single-letter
    prefixes are deliberately **not** stripped, because و ف ب ك ل also begin
    ordinary words and removing them turns فكرة into كرة.
  - **`index` mode (default):** a normalised shadow table with a `FULLTEXT`
    index, kept in step on save and delete, with WordPress search rewritten to
    use it. Fixes the public site and the wp-admin post list at once, and needs
    nothing external.
  - **`normaliser` mode:** maintains nothing and rewrites nothing, exposing the
    normaliser and firing `wpas_post_normalized` on save, so an external index
    can fold its text and its queries with the same code.
  - **WP-CLI:** `normalize`, `reindex` and `status`.

[0.9.2]: https://github.com/mantekio/wp-arabic-search/releases/tag/v0.9.2
[0.9.1]: https://github.com/mantekio/wp-arabic-search/releases/tag/v0.9.1
[0.9.0]: https://github.com/mantekio/wp-arabic-search/releases/tag/v0.9.0
