<?php
/**
 * Plugin Name: WP Arabic Search
 * Plugin URI:  https://github.com/mantekio/wp-arabic-search
 * Description: Makes WordPress search actually match Arabic. Normalises diacritics, tatweel, alef and hamza forms on both the stored text and the query, and searches an indexed shadow copy instead of an unindexed LIKE scan. Works standalone, or as a normaliser only when you index somewhere else.
 * Version:     0.9.2
 * Author:      Jaafar Abazid
 * Author URI:  https://www.mantek.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * A must-use plugin. Drop this file in wp-content/mu-plugins/. See README.md.
 *
 * Two modes, set with the WPAS_MODE constant in wp-config.php:
 *
 *   'index'      (default) self-contained. Maintains a normalised shadow table
 *                with a FULLTEXT index and rewrites WordPress search to use it.
 *                Needs nothing external.
 *   'normaliser' maintains nothing and rewrites nothing. Exposes the normaliser
 *                and fires an action on save, so your own pipeline can push the
 *                normalised text to wherever you actually index (an external
 *                table, OpenSearch, anything). The point is that both sides get
 *                normalised by identical code.
 *
 * @package ManTek\ArabicSearch
 */

namespace ManTek\ArabicSearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bump this whenever the table name or shape changes, otherwise maybe_install()
// short-circuits on an existing install and the new schema is never created.
const SCHEMA_VERSION = '3';
const SCHEMA_OPTION  = 'wpas_schema_version';

/** Which mode we are running in: 'index' (default) or 'normaliser'. */
function mode(): string {
	$mode = defined( 'WPAS_MODE' ) ? (string) WPAS_MODE : 'index';
	return 'normaliser' === $mode ? 'normaliser' : 'index';
}

/**
 * The index table.
 *
 * Named for what it holds rather than for this plugin, because it is simply
 * normalised searchable text per post and there is nothing Arabic about the
 * shape of it. Filterable, since a generic name is by definition likelier to
 * collide with another plugin than a vendor-prefixed one.
 */
function table(): string {
	global $wpdb;
	$base = (string) apply_filters( 'wpas_table_basename', 'search_index' );
	// Table names cannot be parameterised by $wpdb->prepare(), and this one is
	// filterable, so keep it to characters that can never change a query's meaning.
	$base = preg_replace( '/[^A-Za-z0-9_]/', '', $base );
	if ( '' === $base ) {
		$base = 'search_index';
	}
	// $wpdb->prefix, not base_prefix: it already carries a custom table prefix,
	// and on multisite it is the per-site prefix (wp_2_), which is right, because
	// each site has its own posts table and therefore wants its own index.
	return $wpdb->prefix . $base;
}

/**
 * Fold an Arabic string down to one canonical form.
 *
 * Steps 1 to 4 are pure character folding and are always safe: they only ever
 * merge forms that are the same letter or a mark on a letter. Steps 5 and 6 are
 * conventions rather than truths, so they are filterable.
 *
 * Deliberately NOT done: stripping single-letter prefixes (و ف ب ك ل). It looks
 * tempting and it is destructive, because those letters also begin ordinary
 * words: كتاب would become تاب, بيت would become يت, and فكرة would become كرة,
 * which is a different word entirely. Prefix handling needs real morphology,
 * which is out of scope here. See README, known limits.
 */
function normalize( string $text ): string {
	if ( '' === $text ) {
		return '';
	}

	// 1. Diacritics: tashkeel, superscript alef, Quranic marks.
	$text = preg_replace(
		'/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}]/u',
		'',
		$text
	);

	// 2. Tatweel: decoration, carries no meaning.
	$text = str_replace( "\u{0640}", '', $text );

	// 3. Alef forms to bare alef.
	$text = strtr( $text, array(
		"\u{0622}" => "\u{0627}", // آ
		"\u{0623}" => "\u{0627}", // أ
		"\u{0625}" => "\u{0627}", // إ
		"\u{0671}" => "\u{0627}", // ٱ
	) );

	// 4. Hamza carriers to their base letter.
	$text = strtr( $text, array(
		"\u{0624}" => "\u{0648}", // ؤ
		"\u{0626}" => "\u{064A}", // ئ
	) );

	// 5. Conventions. Both are choices; fold them the same way on both sides.
	if ( apply_filters( 'wpas_fold_alef_maqsura', true ) ) {
		$text = str_replace( "\u{0649}", "\u{064A}", $text ); // ى to ي
	}
	if ( apply_filters( 'wpas_fold_taa_marbuta', true ) ) {
		$text = str_replace( "\u{0629}", "\u{0647}", $text ); // ة to ه
	}

	// 6. Definite article, guarded so it can never eat a short word.
	if ( apply_filters( 'wpas_strip_definite_article', true ) ) {
		$text = preg_replace( '/(?<!\p{L})\x{0627}\x{0644}(\p{Arabic}{3,})/u', '$1', $text );
	}

	// 7. Tidy. Latin is untouched throughout: every rule above targets Arabic
	// codepoints only, so WordPress, AWS and S3 survive intact.
	$text = preg_replace( '/\s+/u', ' ', $text );

	return trim( (string) apply_filters( 'wpas_normalize', $text ) );
}

/* -------------------------------------------------------------------------
 * Index mode: shadow table, kept in step with the posts.
 * ---------------------------------------------------------------------- */

/** Create or update the shadow table. Safe to call on every load. */
function maybe_install(): void {
	// Only index mode owns a table. In normaliser mode we maintain nothing, so
	// we must not create anything either: the contract says no table, so no table.
	if ( 'index' !== mode() ) {
		return;
	}
	$installed = get_option( SCHEMA_OPTION );
	if ( $installed === SCHEMA_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = table();
	$charset = $wpdb->get_charset_collate();

	// On a schema change, drop and rebuild rather than migrate in place. The
	// table is derived data, rebuildable from the posts in one command, and
	// dbDelta cannot reliably drop a column or swap a FULLTEXT key anyway.
	//
	// The direction matters. Migrating in place would leave every existing row
	// with an empty search_text: rows exist, so index_has_rows() sees a usable
	// index, and every search on the site quietly returns nothing. Dropping
	// fails the safe way instead, because an empty table makes the plugin stand
	// aside so core answers, while status exits non-zero telling you to reindex.
	if ( false !== $installed ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
	}

	dbDelta(
		"CREATE TABLE {$table} (
			post_id bigint(20) unsigned NOT NULL,
			normalized_title text NOT NULL,
			search_text longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (post_id),
			FULLTEXT KEY search_ft (search_text)
		) {$charset};"
	);
	update_option( SCHEMA_OPTION, SCHEMA_VERSION, false );
}

/**
 * Is the index table actually there?
 *
 * The stored schema version says only what we last created, never what still
 * exists. Drop the table (a botched restore, a migration, a careless cleanup)
 * and maybe_install() sees a matching version and does nothing.
 *
 * Deliberately not called from maybe_install(), which runs on every init: that
 * would put a SHOW TABLES on every page load to catch something that almost
 * never happens. The search path is already safe without it, because
 * index_has_rows() cannot read a missing table and so falls back to core. This
 * is for the CLI, where the check is free and the lie was loudest.
 */
function table_exists(): bool {
	global $wpdb;
	// esc_like, because `_` is a single-character wildcard in LIKE and this table
	// name is mostly underscores. Without it the pattern would also match a
	// same-length table with any characters in those positions.
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( table() ) ) );
}

/**
 * The SQL predicate for "a post this plugin indexes".
 *
 * index_post() skips revisions, autosaves and auto-drafts, and takes everything
 * else, so the CLI has to select exactly that set or the two disagree. They did:
 * reindex excluded post_status 'inherit' as a way of skipping revisions, and
 * attachments use that status too, so a full reindex left every attachment that
 * predated the install with no row while newly uploaded ones still got one, and
 * a Media Library search quietly lost the older half.
 *
 * Autosaves are revisions as well (post_type 'revision'), so the type test
 * covers both. Kept in one place so reindex and status cannot drift apart again.
 */
function indexable_where( string $alias = '' ): string {
	$p     = '' === $alias ? '' : $alias . '.';
	$where = "{$p}post_status != 'auto-draft' AND {$p}post_type != 'revision'";

	/**
	 * Post types to index, or an empty array for "everything except revisions".
	 *
	 * Everything is indexed by default, because anything left out is invisible to
	 * search: this plugin replaces the matching step rather than filtering it. Use
	 * this to narrow that on a site carrying a large custom post type nobody
	 * searches, such as a log or an import staging table.
	 *
	 * Whatever this returns must also be honoured by is_indexable_post(), or the
	 * CLI and the save hook would disagree about what belongs in the index.
	 *
	 * @param string[] $types
	 */
	$types = array_filter( array_map( 'strval', (array) apply_filters( 'wpas_indexed_post_types', array() ) ) );
	if ( $types ) {
		global $wpdb;
		$in     = implode( ',', array_map( array( $wpdb, 'prepare' ), array_fill( 0, count( $types ), '%s' ), $types ) );
		$where .= " AND {$p}post_type IN ({$in})";
	}

	return $where;
}

/**
 * Does this post belong in the index? The save-hook twin of indexable_where().
 *
 * These two have to agree. They did not once before: reindex excluded a status
 * the hook accepted, and every attachment predating the install silently lost its
 * row. Any change to one belongs in the other.
 */
function is_indexable_post( \WP_Post $post ): bool {
	if ( 'auto-draft' === $post->post_status || 'revision' === $post->post_type ) {
		return false;
	}
	$types = array_filter( array_map( 'strval', (array) apply_filters( 'wpas_indexed_post_types', array() ) ) );

	return ! $types || in_array( $post->post_type, $types, true );
}

/**
 * Normalise one post and, in index mode, store it. The action fires in BOTH
 * modes: it is the seam an external pipeline hooks to push the same normalised
 * text into its own store.
 */
function index_post( $post_id ): void {
	$post_id = (int) $post_id;
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
		return;
	}
	if ( ! is_indexable_post( $post ) ) {
		// It may have been indexable before (a post type removed from the filter,
		// or a status change), so clear any row rather than leaving a stale one.
		unindex_post( $post_id );
		return;
	}

	// Everything searchable about this post, gathered into one field.
	//
	// Core searches post_title, post_excerpt AND post_content, so all three have
	// to be in here or we answer worse than the search we replace. A post whose
	// manual excerpt carries a word that is nowhere else would be found by plain
	// WordPress and missed by us.
	//
	// Attachments keep their text in still more places: the caption is the
	// excerpt, and the alt text and the filename are meta. Core matches the
	// filename through a separate posts_clauses filter that rewrites its own
	// LIKE clause, which our rewrite leaves nothing for, so the only way to keep
	// filenames findable is to index them ourselves.
	$parts = array(
		$post->post_title,
		$post->post_excerpt,
		wp_strip_all_tags( (string) $post->post_content ),
	);

	if ( 'attachment' === $post->post_type ) {
		$parts[] = get_post_meta( $post_id, '_wp_attachment_image_alt', true );
		$file    = (string) get_post_meta( $post_id, '_wp_attached_file', true );
		$parts[] = ( '' === $file ) ? '' : basename( $file );
	}

	/**
	 * The raw strings making up a post's searchable text, before normalising.
	 *
	 * This is the seam for everything the plugin does not know about: a meta
	 * field, a taxonomy, an entire custom post type's worth of them. Add to the
	 * array and it becomes searchable, with no schema change, because it all
	 * lands in the same indexed column.
	 *
	 * @param string[] $parts
	 * @param \WP_Post $post
	 */
	$parts = (array) apply_filters( 'wpas_searchable_parts', $parts, $post );

	$title       = normalize( (string) $post->post_title );
	$search_text = normalize( implode( ' ', array_filter( array_map( 'strval', $parts ) ) ) );

	/**
	 * Fires whenever a post's normalised text is (re)computed.
	 * Hook this in 'normaliser' mode to sync it to your own index. The third
	 * argument is the whole searchable text, not just the post body.
	 */
	do_action( 'wpas_post_normalized', $post_id, $title, $search_text );

	if ( 'index' !== mode() ) {
		return;
	}

	// Stamp the newest modification this row reflects, not simply "now".
	// A scheduled post carries a future post_modified_gmt, and wp_publish_post()
	// does not rewrite it on publish, so a row stamped "now" looks permanently
	// behind the moment that timestamp passes and status reports a correctly
	// indexed post as stale until someone reindexes. For every ordinary save
	// post_modified_gmt is already in the past and this is just "now".
	$now   = current_time( 'mysql', true );
	$stamp = ( (string) $post->post_modified_gmt > $now ) ? (string) $post->post_modified_gmt : $now;

	global $wpdb;
	$wpdb->replace(
		table(),
		array(
			'post_id'            => $post_id,
			'normalized_title'   => $title,
			'search_text'        => $search_text,
			'updated_at'         => $stamp,
		),
		array( '%d', '%s', '%s', '%s' )
	);
}

function unindex_post( $post_id ): void {
	if ( 'index' !== mode() ) {
		return;
	}
	global $wpdb;
	$wpdb->delete( table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
}

/**
 * The server's FULLTEXT minimum token length.
 *
 * InnoDB does not index tokens shorter than innodb_ft_min_token_size (3 by
 * default), so a REQUIRED `+token` below that length can never match anything.
 * We have to know the threshold to avoid asking an impossible question.
 * Cached per request; falls back to the documented default.
 */
function min_token_size(): int {
	static $min = null;
	if ( null !== $min ) {
		return $min;
	}
	global $wpdb;
	$val = $wpdb->get_var( 'SELECT @@innodb_ft_min_token_size' );
	$min = ( null === $val || '' === $val ) ? 3 : max( 1, (int) $val );
	return $min;
}

/**
 * The server's FULLTEXT stopwords, lowercased, as a lookup map.
 *
 * InnoDB refuses to index its stopwords, so a REQUIRED `+the` can only ever match
 * nothing. Same trap as a token below the length threshold, and the same fix:
 * drop it, and fall back to core if that leaves the search with nothing.
 *
 * The built-in list is English, so on an Arabic archive this rarely bites. It
 * still has to be handled, because a site can point innodb_ft_server_stopword_table
 * at its own list, and a search that silently returns nothing is exactly what this
 * plugin exists to remove.
 *
 * Cached in a transient: the list is fixed for a server, and reading it is a query
 * we should not repeat on every search.
 */
function stopwords(): array {
	static $words = null;
	if ( null !== $words ) {
		return $words;
	}

	$cached = get_transient( 'wpas_stopwords' );
	if ( is_array( $cached ) ) {
		$words = $cached;
		return $words;
	}

	global $wpdb;
	$suppress = $wpdb->suppress_errors( true );
	$rows     = $wpdb->get_col( 'SELECT value FROM INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD' );
	$wpdb->suppress_errors( $suppress );

	$words = array();
	foreach ( (array) $rows as $w ) {
		$w = strtolower( trim( (string) $w ) );
		if ( '' !== $w ) {
			$words[ $w ] = true;
		}
	}

	set_transient( 'wpas_stopwords', $words, WEEK_IN_SECONDS );
	return $words;
}

/**
 * Is there anything in the index to search at all?
 *
 * Install the plugin, forget to reindex, and an INNER JOIN against an empty
 * table answers every single search with nothing: precisely the silent failure
 * this plugin exists to remove, reintroduced by the plugin itself. So an index
 * that is empty, or a table that is not there yet, means we stand aside and let
 * core answer.
 *
 * Only the empty case is caught here, deliberately. Comparing row counts on
 * every search to spot a partially built index would cost more than the search,
 * and `wp arabic-search status` already reports that kind of drift. Checked once
 * per request with an index-only lookup rather than a COUNT.
 */
function index_has_rows(): bool {
	static $has = null;
	if ( null !== $has ) {
		return $has;
	}
	global $wpdb;
	$suppress = $wpdb->suppress_errors( true );
	$row      = $wpdb->get_var( 'SELECT 1 FROM ' . table() . ' LIMIT 1' );
	$wpdb->suppress_errors( $suppress );
	$has = ( null !== $row );
	return $has;
}

/**
 * The boolean-mode tokens for this query, or an empty array when the search
 * cannot be answered from the index and has to fall back to core.
 *
 * Deliberately the ONLY place that decision is made. rewrite_search(),
 * join_index() and order_by_normalized() must all reach the same answer, and
 * while each decided for itself they disagreed in two ways that both broke
 * search: a join added without a matching MATCH silently intersected core's
 * fallback with the set of indexed posts, so a post missing from the index
 * vanished from results core would have returned; and an ORDER BY naming the
 * `wpsi` alias when no join was added is a SQL error.
 */
function match_tokens( $query ): array {
	if ( 'index' !== mode() || ! $query->is_search() ) {
		return array();
	}
	$term = normalize( (string) $query->get( 's' ) );
	if ( '' === $term ) {
		return array();
	}
	if ( ! index_has_rows() ) {
		return array();
	}
	return array_column( classify_tokens( $term, false ), 'token' );
}

/**
 * Split a normalised term into tokens, recording what happened to each.
 *
 * Kept separate from match_tokens() so `wp arabic-search explain` can report the
 * reason a token was dropped rather than only the survivors. "Why did this not
 * match?" is the question this plugin will be asked most, and answering it should
 * not require reading the source.
 *
 * @return array<int, array{token: string, kept: bool, reason: string}>
 */
function classify_tokens( string $term, bool $include_dropped = true ): array {
	$min   = min_token_size();
	$stop  = stopwords();
	$out   = array();

	foreach ( preg_split( '/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY ) as $raw ) {
		// Strip boolean-mode operators so a stray character cannot break the query.
		$token = preg_replace( '/[+\-><()~*"@]+/u', '', $raw );

		if ( '' === $token ) {
			$kept   = false;
			$reason = 'empty after stripping boolean operators';
		} elseif ( mb_strlen( $token, 'UTF-8' ) < $min ) {
			// mb_strlen, not strlen: an Arabic character is two bytes, so a byte
			// count waves through exactly the short tokens FULLTEXT refuses to index.
			$kept   = false;
			$reason = sprintf( 'shorter than innodb_ft_min_token_size (%d)', $min );
		} elseif ( isset( $stop[ strtolower( $token ) ] ) ) {
			$kept   = false;
			$reason = 'a FULLTEXT stopword, which is never indexed';
		} else {
			$kept   = true;
			$reason = 'indexable';
		}

		if ( $kept || $include_dropped ) {
			$out[] = array(
				'token'  => $token,
				'kept'   => $kept,
				'reason' => $reason,
			);
		}
	}

	return $out;
}

/**
 * Replace WordPress's LIKE search with a lookup against the normalised index.
 *
 * The query is put through the identical normaliser, then matched with
 * FULLTEXT in boolean mode with every token required, which mirrors the AND
 * behaviour of core's search rather than loosening it.
 *
 * With no usable tokens the search is handed back to core untouched. The rule
 * is that this plugin must never answer worse than the search it replaces: a
 * two-letter Arabic word like من or في is below InnoDB's default threshold, so
 * requiring it would turn a search core answers perfectly well into an empty
 * page.
 */
function rewrite_search( $search, $query ) {
	$tokens = match_tokens( $query );
	if ( ! $tokens ) {
		return $search;
	}

	global $wpdb;
	// MATCH against the index joined in join_index(), so the table is touched
	// once for both filtering and ranking. This previously filtered with its own
	// `ID IN ( SELECT ... MATCH ... )` subquery and then joined the same table a
	// second time for the ordering, which made MySQL walk the index twice and
	// cost the most on exactly the searches that match a lot of rows.
	return $wpdb->prepare(
		' AND MATCH(wpsi.search_text) AGAINST (%s IN BOOLEAN MODE) ',
		'+' . implode( ' +', $tokens )
	);
}

/**
 * Join the normalised index. Carries both the match and the ranking.
 *
 * Ranking matters as much as matching here. Core's search ordering boosts posts
 * whose RAW title contains the RAW term, which silently stops working for
 * exactly the variant spellings this plugin exists to match: matching would be
 * normalised and relevance would not.
 *
 * INNER, not LEFT, because rewrite_search() puts its MATCH on this alias, so a
 * post with no index row can never satisfy the search anyway. A 1:1 join on the
 * primary key, so it still cannot duplicate rows. INNER also keeps the index as
 * the driving table, which is what lets MySQL lead with the FULLTEXT index; a
 * LEFT JOIN would force wp_posts to drive and throw that plan away.
 *
 * Skipped entirely when the search falls back to core, because otherwise the
 * join quietly filters core's own results down to whatever happens to be
 * indexed, and an unindexed post disappears from a search core would have
 * answered.
 */
function join_index( $join, $query ) {
	if ( ! match_tokens( $query ) ) {
		return $join;
	}
	global $wpdb;
	return $join . ' INNER JOIN ' . table() . " AS wpsi ON wpsi.post_id = {$wpdb->posts}.ID ";
}

/**
 * Rank normalised title matches first, keeping core's semantics on folded text.
 *
 * Gated on the same token test as the join: when the search falls back to core
 * no `wpsi` alias exists, and naming it here would be a SQL error rather than a
 * missing boost.
 */
function order_by_normalized( $orderby, $query ) {
	if ( ! match_tokens( $query ) ) {
		return $orderby;
	}
	$term = normalize( (string) $query->get( 's' ) );
	global $wpdb;
	return $wpdb->prepare( 'wpsi.normalized_title LIKE %s DESC', '%' . $wpdb->esc_like( $term ) . '%' );
}

add_action( 'init', __NAMESPACE__ . '\\maybe_install' );
add_action( 'save_post', __NAMESPACE__ . '\\index_post' );
add_action( 'deleted_post', __NAMESPACE__ . '\\unindex_post' );
add_filter( 'posts_search', __NAMESPACE__ . '\\rewrite_search', 10, 2 );
add_filter( 'posts_join', __NAMESPACE__ . '\\join_index', 10, 2 );
add_filter( 'posts_search_orderby', __NAMESPACE__ . '\\order_by_normalized', 10, 2 );

/* -------------------------------------------------------------------------
 * WP-CLI
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	\WP_CLI::add_command(
		'arabic-search normalize',
		function ( $args ) {
			$in = isset( $args[0] ) ? (string) $args[0] : '';
			\WP_CLI::log( 'in:  ' . $in );
			\WP_CLI::log( 'out: ' . normalize( $in ) );
		},
		array( 'shortdesc' => 'Show what a term normalises to. Works in both modes.' )
	);

	\WP_CLI::add_command(
		'arabic-search explain',
		function ( $args ) {
			$raw = isset( $args[0] ) ? (string) $args[0] : '';
			if ( '' === $raw ) {
				\WP_CLI::error( 'usage: wp arabic-search explain "<search term>"' );
			}
			$normalised = normalize( $raw );

			\WP_CLI::log( 'mode:        ' . mode() );
			\WP_CLI::log( 'raw:         ' . $raw );
			\WP_CLI::log( 'normalised:  ' . $normalised );

			if ( 'index' !== mode() ) {
				\WP_CLI::warning( 'normaliser mode: this plugin does not answer searches, so WordPress runs its own.' );
				return;
			}

			$rows = classify_tokens( $normalised );
			\WP_CLI::log( '' );
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					static function ( $r ) {
						return array(
							'token'  => $r['token'],
							'used'   => $r['kept'] ? 'yes' : 'no',
							'reason' => $r['reason'],
						);
					},
					$rows
				),
				array( 'token', 'used', 'reason' )
			);

			$kept = array_column( array_filter( $rows, static function ( $r ) {
				return $r['kept'];
			} ), 'token' );

			\WP_CLI::log( '' );
			if ( ! table_exists() ) {
				\WP_CLI::warning( 'the index table does not exist, so this search falls back to WordPress. Run: wp arabic-search reindex' );
				return;
			}
			if ( ! index_has_rows() ) {
				\WP_CLI::warning( 'the index is empty, so this search falls back to WordPress. Run: wp arabic-search reindex' );
				return;
			}
			if ( ! $kept ) {
				\WP_CLI::warning( 'no usable tokens, so this search falls back to WordPress\'s own LIKE and returns exactly what core returns.' );
				return;
			}

			$against = '+' . implode( ' +', $kept );
			\WP_CLI::log( 'matches with: MATCH(search_text) AGAINST (\'' . $against . '\' IN BOOLEAN MODE)' );
			\WP_CLI::log( 'ranked by:    normalized_title LIKE \'%' . $normalised . '%\' DESC, post_date DESC' );

			global $wpdb;
			$hits = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . table() . ' WHERE MATCH(search_text) AGAINST (%s IN BOOLEAN MODE)',
					$against
				)
			);
			\WP_CLI::log( 'index hits:   ' . $hits );
			\WP_CLI::success( 'explained' );
		},
		array( 'shortdesc' => 'Show how a search term is folded, which tokens are used, and what it matches.' )
	);

	\WP_CLI::add_command(
		'arabic-search reindex',
		function ( $args, $assoc ) {
			if ( 'index' !== mode() ) {
				\WP_CLI::error( 'reindex only applies in index mode (WPAS_MODE is "normaliser")' );
			}
			maybe_install();
			// maybe_install() trusts the stored version, so a table that has been
			// dropped out from under it is not noticed. Clear the version and let
			// it build again.
			if ( ! table_exists() ) {
				delete_option( SCHEMA_OPTION );
				maybe_install();
				if ( ! table_exists() ) {
					\WP_CLI::error( 'index table ' . table() . ' is missing and could not be created' );
				}
			}

			global $wpdb;
			$where = indexable_where();
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}" );

			$batch = max( 1, (int) ( $assoc['batch'] ?? 1000 ) );
			$after = max( 0, (int) ( $assoc['after'] ?? 0 ) );
			$prune = ! empty( $assoc['prune'] );

			if ( $after ) {
				\WP_CLI::log( "resuming after post ID {$after}" );
			}

			// Walk by primary key in batches rather than loading every ID at once.
			// The old version did SELECT ID for the whole table into one PHP array,
			// which is the wrong shape for the archives this plugin is aimed at: a
			// quarter of a million rows is survivable, a million is not, and a
			// failure at row 900,000 had to start again from zero. Keyset paging on
			// ID (not OFFSET, which rescans) keeps memory flat and makes --after a
			// real resume point.
			$done = 0;
			$bar  = \WP_CLI\Utils\make_progress_bar( "Indexing {$total} posts", $total );
			do {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE {$where} AND ID > %d ORDER BY ID ASC LIMIT %d",
						$after,
						$batch
					)
				);
				foreach ( $ids as $id ) {
					index_post( $id );
					$after = (int) $id;
					++$done;
					$bar->tick();
				}
				// Free the object cache between batches, or a long run grows until
				// it dies, and every post we just touched is cached for nothing.
				if ( function_exists( 'wp_cache_flush_runtime' ) ) {
					wp_cache_flush_runtime();
				}
			} while ( count( $ids ) === $batch );
			$bar->finish();

			if ( $prune ) {
				// Rows whose post is gone or is no longer indexable. Harmless while
				// they sit there, since the join simply never matches them, but they
				// make the counts drift, and status compares counts.
				$removed = (int) $wpdb->query(
					"DELETE i FROM " . table() . " i
					 LEFT JOIN {$wpdb->posts} p
					        ON p.ID = i.post_id AND " . indexable_where( 'p' ) . "
					 WHERE p.ID IS NULL"
				);
				\WP_CLI::log( "pruned {$removed} orphaned rows" );
			}

			// Count what landed, not what we set out to write. This used to report
			// the number of posts SELECTED, so every single write could fail and the
			// command still announced a full success. A command that cannot fail is
			// not a check.
			//
			// Compare against the rows this run was responsible for, not the whole
			// table: with --after we deliberately index a subset, and a full-table
			// comparison would call a correct resume a failure. Rows can also exceed
			// the count legitimately, since old rows survive unless --prune is given,
			// so only a shortfall is an error.
			$written = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . table() . ' WHERE post_id <= %d',
					$after
				)
			);
			if ( $written < $done ) {
				\WP_CLI::error( sprintf( 'indexed %d of %d posts; %d writes did not land', $written, $done, $done - $written ) );
			}
			\WP_CLI::success( "indexed {$done} posts" );
		},
		array(
			'shortdesc' => 'Rebuild the normalised index for every post.',
			'synopsis'  => array(
				array(
					'type'        => 'assoc',
					'name'        => 'batch',
					'description' => 'Posts per batch. Default 1000.',
					'optional'    => true,
				),
				array(
					'type'        => 'assoc',
					'name'        => 'after',
					'description' => 'Resume after this post ID, for restarting a long run.',
					'optional'    => true,
				),
				array(
					'type'        => 'flag',
					'name'        => 'prune',
					'description' => 'Also delete index rows whose post is gone or is no longer indexable.',
					'optional'    => true,
				),
			),
		)
	);

	\WP_CLI::add_command(
		'arabic-search status',
		function () {
			$mode = mode();
			\WP_CLI::log( 'mode:   ' . $mode );
			if ( 'index' !== $mode ) {
				\WP_CLI::success( 'normaliser mode: no table, no search rewrite' );
				return;
			}
			global $wpdb;
			$table = table();
			if ( ! table_exists() ) {
				\WP_CLI::log( 'table:  ' . $table );
				\WP_CLI::error( 'index table does not exist; run: wp arabic-search reindex' );
			}
			$rows  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
			$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE " . indexable_where() );
			// A row that exists but is out of date. Counting rows cannot see this:
			// an importer, a migration or a direct SQL write changes the post
			// without firing save_post, so the index keeps a stale copy and the
			// totals still add up. A check that only counts is a check that cannot
			// fail, so compare timestamps too.
			//
			// Ignore future timestamps. A scheduled post carries a post_modified_gmt
			// in the future, which index_post() (stamping "now") can never reach, so
			// without this guard every site publishing on a schedule reports stale
			// for ever and a red status becomes noise to scroll past. It also
			// absorbs clock skew between the app and database hosts.
			$stale = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 INNER JOIN {$table} i ON i.post_id = p.ID
				 WHERE " . indexable_where( 'p' ) . "
				   AND p.post_modified_gmt <= UTC_TIMESTAMP()
				   AND i.updated_at < p.post_modified_gmt"
			);
			\WP_CLI::log( 'table:  ' . $table );
			\WP_CLI::log( "rows:   {$rows} indexed / {$posts} posts" );
			\WP_CLI::log( "stale:  {$stale}" );
			if ( $rows < $posts ) {
				\WP_CLI::error( sprintf( 'index is behind by %d posts; run: wp arabic-search reindex', $posts - $rows ) );
			}
			if ( $stale > 0 ) {
				\WP_CLI::error( sprintf( '%d indexed posts are stale; run: wp arabic-search reindex', $stale ) );
			}
			\WP_CLI::success( 'index is current' );
		},
		array( 'shortdesc' => 'Print mode, index size and stale count, and exit non-zero if the index is behind or stale.' )
	);
}
