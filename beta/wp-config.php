<?php
define( 'WP_CACHE', true );

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'u272785322_iShSy' );

/** Database username */
define( 'DB_USER', 'u272785322_pjAP5' );

/** Database password */
define( 'DB_PASSWORD', 'DXWDA1UJUQ' );

/** Database hostname */
define( 'DB_HOST', '127.0.0.1' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          '+Xpyd`_cBHWFA<yc=b<nrA,(S62ISX16Wr^uwM(GH3v}>#kCS2=)Q8_+FeN#v(S{' );
define( 'SECURE_AUTH_KEY',   'ib[8kyTZ+/[SYML6oyde*4f5]hxUuGtIa^5F}hmFlC~u2tQd-HXZD2L;VpXXW}J9' );
define( 'LOGGED_IN_KEY',     'P1y> (g9o@lvH??&1h|-Ha$di.Y{=5eY=T&GMS/5q81UjudW>ymBH:73@sJEuP5q' );
define( 'NONCE_KEY',         '=M(kc&pH%(  W*Jpf$r7/yxX}/$~D*h./@!]f FgKIxD=Pwzrq&8t]2Q^zgi$m!n' );
define( 'AUTH_SALT',         'y!V+/psVP4en*2Yvt+N*2OTvB{]n(i4g4&w,~T<Y<N&-6L)/A/e9ERjRkaP=q*`C' );
define( 'SECURE_AUTH_SALT',  'p^=XFR t{hD9?V~r<Lu4ok loBDG@^13@Yfw^cV//x=.0bx z EyJ?Llzd;Xdn#A' );
define( 'LOGGED_IN_SALT',    'oZ-icpMm/&Q{}t8atpqU2asSM,elx?CH{+BLU#C8JX+ppF<B8YzHnK~^Xfb*PA?L' );
define( 'NONCE_SALT',        '}o:@/9^/7t?*[+tm[lV[&vBvy-64[bNIMFnj,d4FJTQ-AJ6rR#&g;BNnEc^z[{^<' );
define( 'WP_CACHE_KEY_SALT', 'N>$ckK|X:A c:*)OJv4GYiu;#8lsYBKUV3esE(G-6.i~8Z?},lq^dPa?tlQ2NUSa' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define( 'FS_METHOD', 'direct' );
define( 'COOKIEHASH', 'f3642c5611f8c6eab535a3528cb29119' );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
