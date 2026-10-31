<?php
/**
 * Writes languages/wintracker.pot from the plugin's PHP files: every __(), _e(), esc_html__(), esc_html_e(),
 * esc_attr__(), esc_attr_e(), _x(), _n() … call with the 'wintracker' text domain, with "translators:" comments
 * and file:line references. No dependencies (uses PHP's tokenizer), so it runs anywhere:
 *
 *     php bin/make-pot.php
 *
 * The release workflow runs it too and fails if the committed .pot is out of date.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root   = dirname( __DIR__ );
$domain = 'wintracker';
// function => [ msgid arg, plural arg or null, context arg or null, domain arg ] (0-based)
$functions = array(
	'__'          => array( 0, null, null, 1 ),
	'_e'          => array( 0, null, null, 1 ),
	'esc_html__'  => array( 0, null, null, 1 ),
	'esc_html_e'  => array( 0, null, null, 1 ),
	'esc_attr__'  => array( 0, null, null, 1 ),
	'esc_attr_e'  => array( 0, null, null, 1 ),
	'_x'          => array( 0, null, 1, 2 ),
	'_ex'         => array( 0, null, 1, 2 ),
	'esc_html_x'  => array( 0, null, 1, 2 ),
	'esc_attr_x'  => array( 0, null, 1, 2 ),
	'_n'          => array( 0, 1, null, 3 ),
	'_nx'         => array( 0, 1, 3, 4 ),
	'_n_noop'     => array( 0, 1, null, 2 ),
);

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$paths = array();
foreach ( $files as $file ) {
	$rel = substr( $file->getPathname(), strlen( $root ) + 1 );
	if ( 'php' === $file->getExtension() && ! preg_match( '#^(\.git|bin|vendor|node_modules)/#', $rel ) ) {
		$paths[] = $rel;
	}
}
sort( $paths );

/** Value of an argument made only of string literals (and "." joins), else null. */
function wt_literal( array $tokens ) {
	$out = '';
	$any = false;
	foreach ( $tokens as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
			continue;
		}
		if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
			$raw  = substr( $t[1], 1, -1 );
			$out .= "'" === $t[1][0] ? strtr( $raw, array( "\\\\" => "\\", "\\'" => "'" ) ) : stripcslashes( $raw );
			$any  = true;
		} elseif ( '.' !== $t ) {
			return null;
		}
	}
	return $any ? $out : null;
}

$entries = array(); // key => [msgid, plural, context, refs[], comments[]]
foreach ( $paths as $rel ) {
	$tokens  = token_get_all( file_get_contents( $root . '/' . $rel ) );
	$count   = count( $tokens );
	$comment = null;
	$comment_line = 0;
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) && preg_match( '#translators:\s*(.*?)\s*(\*/)?$#s', $t[1], $m ) ) {
			$comment      = trim( preg_replace( '#\s*\n\s*\*?\s*#', ' ', $m[1] ) );
			$comment_line = $t[2];
			continue;
		}
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $functions[ $t[1] ] ) ) {
			continue;
		}
		// Skip method calls and definitions: ->__(, ::__(, function __(.
		for ( $p = $i - 1; $p >= 0 && is_array( $tokens[ $p ] ) && T_WHITESPACE === $tokens[ $p ][0]; $p-- );
		if ( $p >= 0 && is_array( $tokens[ $p ] ) && in_array( $tokens[ $p ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		for ( $j = $i + 1; $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0]; $j++ );
		if ( '(' !== ( $tokens[ $j ] ?? null ) ) {
			continue;
		}
		$args  = array( array() );
		$depth = 0;
		for ( $j++; $j < $count; $j++ ) {
			$tok = $tokens[ $j ];
			if ( in_array( $tok, array( '(', '[', '{' ), true ) ) {
				$depth++;
			} elseif ( in_array( $tok, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $depth-- ) {
					break;
				}
			} elseif ( ',' === $tok && 0 === $depth ) {
				$args[] = array();
				continue;
			}
			$args[ count( $args ) - 1 ][] = $tok;
		}
		list( $id_arg, $plural_arg, $ctx_arg, $domain_arg ) = $functions[ $t[1] ];
		if ( wt_literal( $args[ $domain_arg ] ?? array() ) !== $domain ) {
			continue;
		}
		$msgid = wt_literal( $args[ $id_arg ] ?? array() );
		if ( null === $msgid || '' === $msgid ) {
			fwrite( STDERR, "Warning: {$rel}:{$t[2]} {$t[1]}() has a non-literal string; not extracted.\n" );
			continue;
		}
		$plural  = null !== $plural_arg ? wt_literal( $args[ $plural_arg ] ?? array() ) : null;
		$context = null !== $ctx_arg ? wt_literal( $args[ $ctx_arg ] ?? array() ) : null;
		$key     = $context . "\4" . $msgid;
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array( $msgid, $plural, $context, array(), array() );
		}
		$entries[ $key ][3][] = $rel . ':' . $t[2];
		if ( null !== $comment && $t[2] - $comment_line <= 3 ) {
			$entries[ $key ][4][] = $comment;
		}
		$comment = null;
	}
}

function wt_po( $s ) {
	$s = addcslashes( $s, "\\\"\t" );
	if ( false === strpos( $s, "\n" ) ) {
		return '"' . $s . '"';
	}
	$lines = explode( "\n", $s );
	$out   = '""';
	foreach ( $lines as $n => $line ) {
		$out .= "\n\"" . $line . ( $n < count( $lines ) - 1 ? '\n' : '' ) . '"';
	}
	return $out;
}

preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents( $root . '/wintracker.php' ), $v );
$pot  = "# Copyright (C) Wintracker\n# This file is distributed under the GPL-2.0-or-later.\n# Generated by bin/make-pot.php; do not edit by hand.\n";
$pot .= "msgid \"\"\nmsgstr \"\"\n";
$pot .= '"Project-Id-Version: Wintracker ' . ( $v[1] ?? '' ) . "\\n\"\n";
$pot .= "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n";
$pot .= "\"Language: \\n\"\n\"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n\"\n\"X-Domain: {$domain}\\n\"\n";
foreach ( $entries as $e ) {
	list( $msgid, $plural, $context, $refs, $comments ) = $e;
	$pot .= "\n";
	foreach ( array_unique( $comments ) as $c ) {
		$pot .= "#. translators: {$c}\n";
	}
	$pot .= '#: ' . implode( ' ', array_unique( $refs ) ) . "\n";
	if ( preg_match( '/%(\d+\$)?[sd]/', $msgid ) ) {
		$pot .= "#, php-format\n";
	}
	if ( null !== $context ) {
		$pot .= 'msgctxt ' . wt_po( $context ) . "\n";
	}
	$pot .= 'msgid ' . wt_po( $msgid ) . "\n";
	if ( null !== $plural ) {
		$pot .= 'msgid_plural ' . wt_po( $plural ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
	} else {
		$pot .= "msgstr \"\"\n";
	}
}

$target = $root . '/languages/wintracker.pot';
if ( in_array( '--check', $argv, true ) ) {
	$strip = static function ( $s ) {
		return preg_replace( '/^"Project-Id-Version:.*$/m', '', $s );
	};
	$same = is_file( $target ) && $strip( file_get_contents( $target ) ) === $strip( $pot );
	fwrite( $same ? STDOUT : STDERR, $same ? "languages/wintracker.pot is up to date.\n" : "languages/wintracker.pot is out of date: run php bin/make-pot.php and commit it.\n" );
	exit( $same ? 0 : 1 );
}
file_put_contents( $target, $pot );
echo 'Wrote languages/wintracker.pot: ' . count( $entries ) . " strings.\n";
