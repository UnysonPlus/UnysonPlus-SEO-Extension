<?php
/**
 * Contract tests for the Search Console connection.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/console-test.php
 *
 * NOTE: the live OAuth round trip is NOT exercised — it needs a real Google
 * Cloud client. What is pinned is everything around it, and in particular the
 * two failures that would only surface long after they were introduced: a
 * refresh that silently discards the refresh token (the connection dies an hour
 * later), and a `state` that can be replayed.
 */

class TK {
	public static $pass = 0;
	public static $fail = 0;
}

function tk( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TK::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TK::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/** A fabricated wp_remote_* response. */
function tk_response( $code, array $body ) {
	return [ 'response' => [ 'code' => $code ], 'body' => wp_json_encode( $body ) ];
}

$saved_tokens = get_option( FW_SEO_Console::TOKENS_OPTION, [] );
$saved_opts   = (array) fw_get_db_ext_settings_option( 'seo' );

echo "\nSearch Console contracts\n\n";

echo "The reporting window\n";

$w = FW_SEO_Console::window( 28 );
tk( 'the window ends days behind today, not today', $w['end'] < gmdate( 'Y-m-d' ), true );
tk( '...because Google finishes a day late', ( strtotime( $w['end'] ) <= time() - ( 2 * DAY_IN_SECONDS ) ), true );
tk( 'and spans the days asked for', (int) round( ( strtotime( $w['end'] ) - strtotime( $w['start'] ) ) / DAY_IN_SECONDS ), 28 );

echo "\nAuthorisation\n";

FW_SEO_Console::disconnect();
fw_set_db_ext_settings_option( 'seo', null, array_merge( $saved_opts, [ 'console_client_id' => '', 'console_client_secret' => '' ] ) );
FW_SEO_Settings::flush();

tk( 'no credentials means no authorisation URL', FW_SEO_Console::auth_url(), '' );
tk( '...and the box knows it', FW_SEO_Console::has_credentials(), false );

fw_set_db_ext_settings_option( 'seo', null, array_merge( $saved_opts, [
	'console_client_id'     => 'test-client.apps.googleusercontent.com',
	'console_client_secret' => 'test-secret',
] ) );
FW_SEO_Settings::flush();

$url = FW_SEO_Console::auth_url();

tk( 'an authorisation URL is produced', false !== strpos( $url, 'accounts.google.com' ), true );
tk( 'it asks for offline access, or no refresh token is ever issued', false !== strpos( $url, 'access_type=offline' ), true );
tk( 'it forces the consent screen, so a repeat connect still returns one', false !== strpos( $url, 'prompt=consent' ), true );
tk( 'it asks for read-only scope', false !== strpos( rawurldecode( $url ), 'webmasters.readonly' ), true );
tk( 'the redirect URI is the one shown to the user', false !== strpos( rawurldecode( $url ), FW_SEO_Console::redirect_uri() ), true );

preg_match( '/state=([^&]+)/', $url, $m );
$state = rawurldecode( $m[1] ?? '' );

tk( 'a state is issued', '' !== $state, true );
tk( 'the right state verifies', FW_SEO_Console::verify_state( $state ), true );
tk( '...and cannot be replayed', FW_SEO_Console::verify_state( $state ), false );

FW_SEO_Console::auth_url();
tk( 'a wrong state is refused', FW_SEO_Console::verify_state( 'not-the-state' ), false );

echo "\nTokens\n";

FW_SEO_Console::disconnect();

$ok = FW_SEO_Console::absorb_token_response( tk_response( 200, [
	'access_token'  => 'access-1',
	'refresh_token' => 'refresh-1',
	'expires_in'    => 3600,
] ), true );

tk( 'a first exchange is accepted', $ok['ok'], true );
tk( 'the access token is kept', FW_SEO_Console::tokens()['access'], 'access-1' );
tk( 'the refresh token is kept', FW_SEO_Console::tokens()['refresh'], 'refresh-1' );
tk( 'and the site counts as connected', FW_SEO_Console::connected(), true );

// THE bug worth a test: Google does not resend the refresh token on renewal.
// Overwriting with the absent value disconnects the site an hour later, which
// is long enough after the change to look like something else entirely.
$ok = FW_SEO_Console::absorb_token_response( tk_response( 200, [
	'access_token' => 'access-2',
	'expires_in'   => 3600,
] ), false );

tk( 'a renewal is accepted', $ok['ok'], true );
tk( 'it replaces the access token', FW_SEO_Console::tokens()['access'], 'access-2' );
tk( 'and KEEPS the refresh token it was not sent', FW_SEO_Console::tokens()['refresh'], 'refresh-1' );
tk( 'so the site is still connected afterwards', FW_SEO_Console::connected(), true );

$expiry = FW_SEO_Console::tokens()['expires'];
tk( 'expiry is stored a little early, not exactly on the hour', $expiry < time() + 3600, true );

echo "\nWhen Google says no\n";

$bad = FW_SEO_Console::absorb_token_response( tk_response( 400, [ 'error' => 'invalid_grant' ] ), false );
tk( 'a revoked grant is reported as revoked', $bad['reason'], 'revoked' );

$bad = FW_SEO_Console::absorb_token_response( tk_response( 401, [ 'error' => 'invalid_client' ] ), false );
tk( '...and bad credentials as rejected, which leads somewhere different', $bad['reason'], 'rejected' );

$bad = FW_SEO_Console::absorb_token_response( new WP_Error( 'http', 'down' ), false );
tk( 'a network failure is not mistaken for a refusal', $bad['reason'], 'network' );

tk( 'none of those lost the stored refresh token', FW_SEO_Console::tokens()['refresh'], 'refresh-1' );

echo "\nMatching the property\n";

$home = untrailingslashit( home_url() );
$host = wp_parse_url( $home, PHP_URL_HOST );

tk( 'an exact URL-prefix property wins', FW_SEO_Console::match_property( [ 'https://example.com/', $home . '/' ] ), $home . '/' );
tk( 'a domain property is matched', FW_SEO_Console::match_property( [ 'sc-domain:' . $host ] ), 'sc-domain:' . $host );
tk( 'nothing matching returns nothing, rather than guessing', FW_SEO_Console::match_property( [ 'https://somewhere-else.test/' ] ), '' );
tk( 'an empty list is handled', FW_SEO_Console::match_property( [] ), '' );

echo "\nNot connected\n";

FW_SEO_Console::disconnect();

tk( 'disconnecting forgets the tokens', FW_SEO_Console::connected(), false );
tk( '...and an API call says so rather than erroring', FW_SEO_Console::request( '/sites' )['reason'], 'notconnected' );
tk( 'a query without a property says THAT, specifically', FW_SEO_Console::query( [ 'startDate' => '2026-01-01', 'endDate' => '2026-01-02' ] )['reason'], 'noproperty' );

$html = FW_SEO_Console::report_html();
tk( 'the box renders when not connected', is_string( $html ) && '' !== $html, true );
tk( '...and offers to connect', false !== strpos( $html, 'Connect to Search Console' ), true );
// Compared against the escaped form: esc_html turns the & in the URI into
// &amp;, which is correct — the browser renders it back to a usable URI.
tk( '...showing the exact redirect URI to register', false !== strpos( $html, esc_html( FW_SEO_Console::redirect_uri() ) ), true );


echo "\nThe seven-day trap\n";

// An OAuth app left on "Testing" has its refresh tokens cancelled by Google
// after exactly seven days. Without naming it, the user reconnects and hits
// the same wall a week later, forever.
FW_SEO_Console::store_tokens( [ 'access' => 'a', 'refresh' => 'r', 'expires' => time() + 3600, 'property' => '', 'connected' => time() ] );
tk( 'a brand-new connection is not blamed on the seven-day expiry', FW_SEO_Console::looks_like_testing_expiry(), false );

FW_SEO_Console::store_tokens( [ 'access' => 'a', 'refresh' => 'r', 'expires' => time() + 3600, 'property' => '', 'connected' => time() - ( 7 * DAY_IN_SECONDS ) ] );
tk( 'one that died at seven days IS recognised', FW_SEO_Console::looks_like_testing_expiry(), true );

FW_SEO_Console::store_tokens( [ 'access' => 'a', 'refresh' => 'r', 'expires' => time() + 3600, 'property' => '', 'connected' => time() - ( 90 * DAY_IN_SECONDS ) ] );
tk( 'a connection that ran for months is not', FW_SEO_Console::looks_like_testing_expiry(), false );

echo "\nThe setup walkthrough\n";

$setup = FW_SEO_Console::setup_html();

tk( 'the walkthrough renders', is_string( $setup ) && '' !== $setup, true );
tk( 'it links to the Search Console API page', false !== strpos( $setup, 'searchconsole.googleapis.com' ), true );
tk( 'it tells the user to publish the app', false !== strpos( $setup, 'Publish app' ), true );
tk( '...and says why, before they hit it', false !== strpos( $setup, 'seven days' ), true );
tk( 'it warns about the unverified-app screen in advance', false !== strpos( $setup, 'not verified' ), true );
tk( 'it shows the redirect URI to copy', false !== strpos( $setup, esc_html( FW_SEO_Console::redirect_uri() ) ), true );

// Restore whatever the site had before.
update_option( FW_SEO_Console::TOKENS_OPTION, $saved_tokens, false );
fw_set_db_ext_settings_option( 'seo', null, $saved_opts );
FW_SEO_Settings::flush();
delete_transient( FW_SEO_Console::CACHE_KEY );

printf( "\n%d passed, %d failed\n", TK::$pass, TK::$fail );

if ( TK::$fail ) {
	exit( 1 );
}
