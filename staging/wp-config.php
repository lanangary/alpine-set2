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
define( 'DB_NAME', 'u272785322_cwCW5' );
/** Database username */
define( 'DB_USER', 'u272785322_LgSv8' );
/** Database password */
define( 'DB_PASSWORD', 'h8pkNbypKh' );
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
define( 'AUTH_KEY',          'r;;Lu7K%A$vM0Y_a?Y!T17V5?$7t,xSH#c ;xqCzM<F0eL<YtTz6vk:d((:=uB`T' );
define( 'SECURE_AUTH_KEY',   'JgDVpX!,3]??{f?_JpLZ=OXHdI~M?;K=&U=F7<sC-a8~l41{T/cNK`6a,Cgl}Ud_' );
define( 'LOGGED_IN_KEY',     'r<Q{%8)yfSH0B~*v-@[5?81-?pA[4p#%`8fDPd:VK).aXV=W^MUvbr_c5iQ3jtIl' );
define( 'NONCE_KEY',         'eCZc-Sk!q.|Yo.5qg#8(uz9&o.OXL}gd/~mcLo<kOM3OzT6:P! $Y:}^0ti`y_?[' );
define( 'AUTH_SALT',         '7wy/`>[}nMMh+Vk%d7P;=^`YZ7$ &`++Cd2IPe3BkxX,@`={E+i~=<wEl8mWt,A}' );
define( 'SECURE_AUTH_SALT',  '1q1gxK>[pCX^q}0`^L/8U^((9+EV=&(wOrBJSB*4G]JW w$o4`6[VBzC|BQC:!{l' );
define( 'LOGGED_IN_SALT',    '!sTrtcy~J%7M1t5NcQNZ,!h``T}TG ,0;)6D$P1r;3h6*^ZuG*$mrmWD5|**Z=Tp' );
define( 'NONCE_SALT',        '1eH.{GoN:!!a{q{a,%5}5xV#pIG0}L}tiEmg^F5=0pL=kTWmB[#/R,Q=X=Su{_OT' );
define( 'WP_CACHE_KEY_SALT', 'w{F#l9,NGj#,,u0?eDV&;RT&|R-Eh*TM sAY!~A!hu)j~%bE[XnaF@MNlEk3tw1d' );
/**#@-*/
/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';
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
/* Add any custom values between this line and the "stop editing" line. */
ini_set('display_errors','off');
ini_set('error_reporting', E_ALL );
ini_set('log_errors', true );
ini_set('log_errors_max_len', '0' );
define( 'FS_METHOD', 'direct' );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
define('CONCATENATE_SCRIPTS', false );
define( 'WP_DEBUG_LOG', false );
define( 'SAVEQUERIES', true );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
/* That's all, stop editing! Happy publishing. */
/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';