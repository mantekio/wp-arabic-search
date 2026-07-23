<?php
/**
 * Plugin Name: WP Arabic Search
 * Plugin URI:  https://github.com/mantekio/wp-arabic-search
 * Description: Makes WordPress search actually match Arabic. Normalises diacritics, tatweel, alef and hamza forms on both the stored text and the query, and searches an indexed shadow copy instead of an unindexed LIKE scan. Works standalone, or as a normaliser only when you index somewhere else.
 * Version:     0.9.0
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
const SCHEMA_VERSION = '2';
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
	if ( get_option( SCHEMA_OPTION ) === SCHEMA_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			post_id bigint(20) unsigned NOT NULL,
			normalized_title text NOT NULL,
			normalized_content longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (post_id),
			FULLTEXT KEY search_ft (normalized_title, normalized_content)
		) {$charset};"
	);
	update_option( SCHEMA_OPTION, SCHEMA_VERSION, false );
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
	if ( ! $post || 'auto-draft' === $post->post_status ) {
		return;
	}

	$title   = normalize( (string) $post->post_title );
	$content = normalize( wp_strip_all_tags( (string) $post->post_content ) );

	/**
	 * Fires whenever a post's normalised text is (re)computed.
	 * Hook this in 'normaliser' mode to sync it to your own index.
	 */
	do_action( 'wpas_post_normalized', $post_id, $title, $content );

	if ( 'index' !== mode() ) {
		return;
	}

	global $wpdb;
	$wpdb->replace(
		table(),
		array(
			'post_id'            => $post_id,
			'normalized_title'   => $title,
			'normalized_content' => $content,
			'updated_at'         => current_time( 'mysql', true ),
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
 * Replace WordPress's LIKE search with a lookup against the normalised index.
 *
 * The query is put through the identical normaliser, then matched with
 * FULLTEXT in boolean mode with every token required, which mirrors the AND
 * behaviour of core's search rather than loosening it.
 */
function rewrite_search( $search, $query ) {
	if ( 'index' !== mode() || ! $query->is_search() ) {
		return $search;
	}
	$term = normalize( (string) $query->get( 's' ) );
	if ( '' === $term ) {
		return $search;
	}

	$tokens = preg_split( '/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY );
	$tokens = array_filter( array_map(
		static function ( $t ) {
			// Strip boolean-mode operators so a stray character cannot break the query.
			return '+' . preg_replace( '/[+\-><()~*"@]+/u', '', $t );
		},
		$tokens
	), static function ( $t ) {
		return strlen( $t ) > 1;
	} );
	if ( ! $tokens ) {
		return $search;
	}

	global $wpdb;
	return $wpdb->prepare(
		" AND {$wpdb->posts}.ID IN ( SELECT post_id FROM " . table() . " WHERE MATCH(normalized_title, normalized_content) AGAINST (%s IN BOOLEAN MODE) ) ",
		implode( ' ', $tokens )
	);
}

/**
 * Join the normalised index, so ranking can be computed on normalised text.
 *
 * Without this the fix is only half done. Core's search ordering boosts posts
 * whose RAW title contains the RAW term, which silently stops working for
 * exactly the variant spellings this plugin exists to match: matching would be
 * normalised and relevance would not. A 1:1 join on the primary key, so it
 * cannot duplicate rows.
 */
function join_index( $join, $query ) {
	if ( 'index' !== mode() || ! $query->is_search() ) {
		return $join;
	}
	if ( '' === normalize( (string) $query->get( 's' ) ) ) {
		return $join;
	}
	global $wpdb;
	return $join . ' LEFT JOIN ' . table() . " AS wpsi ON wpsi.post_id = {$wpdb->posts}.ID ";
}

/** Rank normalised title matches first, keeping core's semantics on folded text. */
function order_by_normalized( $orderby, $query ) {
	if ( 'index' !== mode() || ! $query->is_search() ) {
		return $orderby;
	}
	$term = normalize( (string) $query->get( 's' ) );
	if ( '' === $term ) {
		return $orderby;
	}
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
		'arabic-search reindex',
		function () {
			if ( 'index' !== mode() ) {
				\WP_CLI::error( 'reindex only applies in index mode (WPAS_MODE is "normaliser")' );
			}
			maybe_install();
			global $wpdb;
			$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('auto-draft','inherit')" );
			$total = count( $ids );
			$bar   = \WP_CLI\Utils\make_progress_bar( "Indexing {$total} posts", $total );
			foreach ( $ids as $id ) {
				index_post( $id );
				$bar->tick();
			}
			$bar->finish();
			\WP_CLI::success( "indexed {$total} posts" );
		},
		array( 'shortdesc' => 'Rebuild the normalised index for every post.' )
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
			$rows  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . table() );
			$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('auto-draft','inherit')" );
			\WP_CLI::log( 'table:  ' . table() );
			\WP_CLI::log( "rows:   {$rows} indexed / {$posts} posts" );
			if ( $rows < $posts ) {
				\WP_CLI::error( 'index is behind; run: wp arabic-search reindex' );
			}
			\WP_CLI::success( 'index is current' );
		},
		array( 'shortdesc' => 'Print mode, index size, and exit non-zero if the index is behind.' )
	);
}
