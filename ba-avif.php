<?php
/**
 * Plugin Name: BA AVIF Converter
 * Description: Copies AVIF et/ou WebP locales (Imagick ou GD) des images JPEG/PNG dans un miroir uploads-avifc/, servies par negociation .htaccess (cascade AVIF -> WebP -> original). Formats de sortie AVIF / WebP / AVIF + WebP, repertoires uploads/themes/plugins, scan disque complet, calcul automatique au chargement, conversion en arriere-plan, reglages complets, reconversion forcee, pause, colonne Mediatheque.
 * Version: 5.4.0
 * Author: Buzzarena
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BA_AVIF_MARKER', 'BA AVIF' );

/* =========================================================================
   REGLAGES
   ====================================================================== */

function ba_avif_settings() {
	return wp_parse_args( get_option( 'ba_avif_settings', array() ), array(
		'quality'      => 55,
		'quality_webp' => 75,
		'batch'        => 4,
		'order'        => 'asc',     // asc (anciennes d'abord) | desc (recentes d'abord)
		'format'       => 'avif',    // avif | webp | both
		'method'       => 'imagick', // imagick | gd
		'themes'       => 0,
		'plugins'      => 0,
		'png_src'      => 1,
		'gif_src'      => 0,
		'webp_src'     => 0,
		'auto_upload'  => 1,
		'guard_larger' => 1,
		'keep_meta'    => 0,
		'log_errors'   => 0,
		'media_column' => 1,
		'exclude'      => '',
	) );
}

// Motif d'extensions sources selon les reglages (.png/.gif/.webp optionnels).
function ba_avif_ext_pattern() {
	$s   = ba_avif_settings();
	$ext = array( 'jpe?g' );
	if ( ! empty( $s['png_src'] ) ) {
		$ext[] = 'png';
	}
	if ( ! empty( $s['gif_src'] ) ) {
		$ext[] = 'gif';
	}
	if ( ! empty( $s['webp_src'] ) ) {
		$ext[] = 'webp';
	}
	return implode( '|', $ext );
}

// Liste SQL des types MIME sources (chaine sure, valeurs fixes).
function ba_avif_mime_list() {
	$s    = ba_avif_settings();
	$mime = array( "'image/jpeg'" );
	if ( ! empty( $s['png_src'] ) ) {
		$mime[] = "'image/png'";
	}
	if ( ! empty( $s['gif_src'] ) ) {
		$mime[] = "'image/gif'";
	}
	if ( ! empty( $s['webp_src'] ) ) {
		$mime[] = "'image/webp'";
	}
	return implode( ',', $mime );
}

// Formats de sortie actifs, AVIF toujours en premier (priorite de la cascade).
function ba_avif_formats() {
	$f = ba_avif_settings()['format'];
	if ( 'webp' === $f ) {
		return array( 'webp' );
	}
	if ( 'both' === $f ) {
		return array( 'avif', 'webp' );
	}
	return array( 'avif' );
}

function ba_avif_imagick_ok() {
	return class_exists( 'Imagick' ) && in_array( 'AVIF', Imagick::queryFormats( 'AVIF' ), true );
}

function ba_avif_imagick_webp_ok() {
	return class_exists( 'Imagick' ) && in_array( 'WEBP', Imagick::queryFormats( 'WEBP' ), true );
}

// GD sait ecrire l'AVIF depuis PHP 8.1 (si compile avec libavif).
function ba_avif_gd_ok() {
	return function_exists( 'imageavif' );
}

function ba_avif_gd_webp_ok() {
	return function_exists( 'imagewebp' );
}

/* -------------------------------------------------------------------------
   COMPATIBILITE CLI / WEB (cache objet APCu)
   Sur cet hebergement, le cache APCu n'est pas partage entre le PHP web et
   le PHP ligne de commande (tache cron du panneau). Deux consequences :
   - les options ecrites d'un cote sont invisibles de l'autre (compteurs
     figes, mouchard a "jamais") -> lecture directe en base via ba_avif_opt()
   - un verrou en transient ne protege pas d'un chevauchement entre les deux
     contextes -> verrou par FICHIER sur le disque, visible par tous.
   ---------------------------------------------------------------------- */

// Lecture d'option directement en base (contourne le cache objet).
function ba_avif_opt( $name, $default = 0 ) {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	return null === $value ? $default : $value;
}

function ba_avif_lock_file( $name = 'lock' ) {
	return WP_CONTENT_DIR . '/uploads-avifc/.' . $name;
}

function ba_avif_locked( $name = 'lock', $ttl = 360 ) {
	$file = ba_avif_lock_file( $name );
	return file_exists( $file ) && ( time() - filemtime( $file ) ) < $ttl;
}

function ba_avif_lock( $name = 'lock' ) {
	wp_mkdir_p( dirname( ba_avif_lock_file( $name ) ) );
	@touch( ba_avif_lock_file( $name ) );
}

function ba_avif_unlock( $name = 'lock' ) {
	@unlink( ba_avif_lock_file( $name ) );
}

// Vrai si au moins un des formats actifs est encodable sur ce serveur.
function ba_avif_server_ok() {
	foreach ( ba_avif_formats() as $format ) {
		if ( 'avif' === $format && ( ba_avif_imagick_ok() || ba_avif_gd_ok() ) ) {
			return true;
		}
		if ( 'webp' === $format && ( ba_avif_imagick_webp_ok() || ba_avif_gd_webp_ok() ) ) {
			return true;
		}
	}
	return false;
}

/* =========================================================================
   CHEMINS — miroir wp-content/uploads-avifc/
   uploads : uploads-avifc/<relatif-a-uploads>.<format>
   theme   : uploads-avifc/themes/<relatif-au-dossier-themes>.<format>
   plugins : uploads-avifc/plugins/<relatif-au-dossier-plugins>.<format>
   ====================================================================== */

function ba_avif_mirror_path( $source_path, $format = 'avif' ) {
	$source_path = wp_normalize_path( $source_path );
	$uploads     = wp_normalize_path( trailingslashit( wp_get_upload_dir()['basedir'] ) );
	$themes      = wp_normalize_path( trailingslashit( get_theme_root() ) );
	$plugins     = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );

	if ( strpos( $source_path, $uploads ) === 0 ) {
		$relative = substr( $source_path, strlen( $uploads ) );
	} elseif ( strpos( $source_path, $themes ) === 0 ) {
		$relative = 'themes/' . substr( $source_path, strlen( $themes ) );
	} elseif ( strpos( $source_path, $plugins ) === 0 ) {
		$relative = 'plugins/' . substr( $source_path, strlen( $plugins ) );
	} else {
		return false;
	}

	return WP_CONTENT_DIR . '/uploads-avifc/' . $relative . '.' . $format;
}

/* =========================================================================
   CONVERSION D'UN FICHIER
   ====================================================================== */

// Repertoires exclus (reglages, liste separee par des virgules).
function ba_avif_is_excluded( $source_path ) {
	$excluded = array_filter( array_map( 'trim', explode( ',', ba_avif_settings()['exclude'] ) ) );
	foreach ( $excluded as $dir_name ) {
		if ( stripos( wp_normalize_path( $source_path ), '/' . $dir_name . '/' ) !== false ) {
			return true;
		}
	}
	return false;
}

function ba_avif_convert_file( $source_path ) {
	if ( ! file_exists( $source_path ) || ! preg_match( '/\.(' . ba_avif_ext_pattern() . ')$/i', $source_path ) ) {
		return false;
	}

	$settings = ba_avif_settings();

	if ( ba_avif_is_excluded( $source_path ) ) {
		return false;
	}

	// Filtre developpeur : return true pour ignorer un fichier.
	if ( apply_filters( 'ba_avif_skip_file', false, $source_path ) ) {
		return false;
	}

	$force   = (bool) ba_avif_opt( 'ba_avif_force' );
	$src_ext = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );

	foreach ( ba_avif_formats() as $format ) {
		// Une source .webp n'a rien a gagner a etre re-encodee en WebP.
		if ( 'webp' === $format && 'webp' === $src_ext ) {
			continue;
		}
		$dest = ba_avif_mirror_path( $source_path, $format );
		if ( ! $dest ) {
			return false;
		}
		if ( ! $force && ! ba_avif_needs_work( $source_path, $dest ) ) {
			continue;
		}
		ba_avif_encode( $source_path, $dest, $format, $settings );
	}

	return true;
}

// Le miroir est-il absent, perime ou vide (et sans marqueur .skip) ?
// Un fichier de 0 octet (processus tue en pleine ecriture) doit etre refait.
function ba_avif_needs_work( $source_path, $dest ) {
	return ! ( ( file_exists( $dest ) && filesize( $dest ) > 0 && filemtime( $dest ) >= filemtime( $source_path ) ) || file_exists( $dest . '.skip' ) );
}

function ba_avif_encode( $source_path, $dest, $format, $settings ) {
	wp_mkdir_p( dirname( $dest ) );
	if ( file_exists( $dest . '.skip' ) ) {
		unlink( $dest . '.skip' );
	}

	if ( 'webp' === $format ) {
		$quality    = (int) $settings['quality_webp'];
		$imagick_ok = ba_avif_imagick_webp_ok();
		$gd_ok      = ba_avif_gd_webp_ok();
	} else {
		$quality    = (int) apply_filters( 'ba_avif_quality', $settings['quality'], $source_path );
		$imagick_ok = ba_avif_imagick_ok();
		$gd_ok      = ba_avif_gd_ok();
	}

	// Methode choisie, avec repli automatique si elle manque sur le serveur.
	$method = ( 'gd' === $settings['method'] ) ? 'gd' : 'imagick';
	if ( 'gd' === $method && ! $gd_ok ) {
		$method = $imagick_ok ? 'imagick' : '';
	} elseif ( 'imagick' === $method && ! $imagick_ok ) {
		$method = $gd_ok ? 'gd' : '';
	}

	// Ecriture atomique : on encode vers un .tmp, on ne renomme en fichier
	// final qu'une fois l'ecriture complete et verifiee. Un processus tue en
	// plein encodage ne peut ainsi jamais laisser un miroir vide ou tronque
	// qui serait servi aux visiteurs.
	$tmp   = $dest . '.tmp';
	$error = '';

	if ( 'imagick' === $method ) {
		try {
			$im = new Imagick( $source_path );
			if ( $im->getNumberImages() > 1 ) {
				$im->setIteratorIndex( 0 ); // GIF anime : premiere image seulement
			}
			$im->setImageFormat( $format );
			$im->setImageCompressionQuality( $quality );
			if ( empty( $settings['keep_meta'] ) ) {
				$im->stripImage();
			}
			$im->writeImage( $format . ':' . $tmp ); // prefixe = format explicite malgre l'extension .tmp
			$im->clear();
			$im->destroy();
		} catch ( Exception $e ) {
			$error = $e->getMessage();
		}
	} elseif ( 'gd' === $method ) {
		$ext = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
		if ( 'png' === $ext ) {
			$img = @imagecreatefrompng( $source_path );
		} elseif ( 'gif' === $ext ) {
			$img = @imagecreatefromgif( $source_path );
		} elseif ( 'webp' === $ext ) {
			$img = @imagecreatefromwebp( $source_path );
		} else {
			$img = @imagecreatefromjpeg( $source_path );
		}
		if ( $img ) {
			imagepalettetotruecolor( $img );
			imagealphablending( $img, true );
			imagesavealpha( $img, true );
			$written = ( 'webp' === $format ) ? @imagewebp( $img, $tmp, $quality ) : @imageavif( $img, $tmp, $quality );
			if ( ! $written ) {
				$error = 'echec image' . $format . ' (GD)';
			}
			imagedestroy( $img );
		} else {
			$error = 'image illisible (GD)';
		}
	} else {
		$error = 'aucune methode de conversion disponible pour le ' . strtoupper( $format );
	}

	if ( ! $error && ( ! file_exists( $tmp ) || filesize( $tmp ) === 0 ) ) {
		$error = 'fichier de sortie vide';
	}

	if ( $error ) {
		@unlink( $tmp );
		@file_put_contents( $dest . '.skip', $error );
		if ( ! empty( $settings['log_errors'] ) ) {
			error_log( 'BA AVIF : echec ' . strtoupper( $format ) . ' sur ' . $source_path . ' — ' . $error );
		}
		return false;
	}

	// Garde-fou (optionnel) : une copie plus lourde que sa source ne sert a rien.
	if ( ! empty( $settings['guard_larger'] ) && filesize( $tmp ) >= filesize( $source_path ) ) {
		unlink( $tmp );
		@file_put_contents( $dest . '.skip', $format . ' plus lourd que la source' );
		return true;
	}

	rename( $tmp, $dest ); // bascule atomique : le miroir apparait complet ou pas du tout

	return true;
}

/* =========================================================================
   CONVERSION D'UNE PIECE JOINTE (original + toutes les tailles)
   ====================================================================== */

function ba_avif_convert_attachment( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file ) {
		return;
	}

	@set_time_limit( 120 );
	ba_avif_convert_file( $file );

	$meta = wp_get_attachment_metadata( $attachment_id );
	if ( ! empty( $meta['sizes'] ) ) {
		$dir = trailingslashit( dirname( $file ) );
		foreach ( $meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				ba_avif_convert_file( $dir . $size['file'] );
			}
		}
	}
}
add_action( 'ba_avif_convert_single', 'ba_avif_convert_attachment' );

/* =========================================================================
   NOUVEAUX UPLOADS : asynchrone
   ====================================================================== */

add_filter( 'wp_generate_attachment_metadata', function( $metadata, $attachment_id ) {
	$settings = ba_avif_settings();
	if ( ! empty( $settings['auto_upload'] ) && ba_avif_server_ok() ) {
		// Conversion immediate : auto-appel non bloquant du tick, envoye en
		// fin de requete (shutdown) quand les metadonnees sont en base —
		// l'upload n'attend rien, l'encodage demarre dans la seconde.
		add_action( 'shutdown', 'ba_avif_spawn_tick' );
		// Filet n1 : evenement WP-Cron +30 s si le loopback est bloque.
		if ( ! wp_next_scheduled( 'ba_avif_convert_single', array( $attachment_id ) ) ) {
			wp_schedule_single_event( time() + 30, 'ba_avif_convert_single', array( $attachment_id ) );
		}
		// Filet n2 : la priorite "uploads recents" du prochain tick cron.
	}
	return $metadata;
}, 20, 2 );

function ba_avif_spawn_tick() {
	static $done = false;
	if ( $done ) {
		return; // un seul auto-appel par requete, meme avec plusieurs uploads
	}
	$done = true;
	wp_remote_get( admin_url( 'admin-post.php?action=ba_avif_tick&key=' . rawurlencode( ba_avif_tick_key() ) ), array(
		'timeout'   => 0.01,
		'blocking'  => false,
		'sslverify' => false,
	) );
}

/* =========================================================================
   STOCK EXISTANT : moulinette par lots (cron 5 min)
   ====================================================================== */

add_filter( 'cron_schedules', function( $schedules ) {
	$schedules['ba_avif_5min'] = array(
		'interval' => 300,
		'display'  => 'Toutes les 5 minutes (BA AVIF)',
	);
	return $schedules;
} );

// Priorite absolue aux uploads recents (48 h) : une nouvelle image
// d'article est convertie au tick suivant (~2 min), quel que soit l'ordre
// du curseur et meme stock termine. Les images deja a jour sont sautees
// en un stat(), ce controle ne coute quasiment rien.
function ba_avif_convert_recent() {
	global $wpdb;
	$ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'attachment'
		   AND post_mime_type IN (" . ba_avif_mime_list() . ")
		   AND post_date > DATE_SUB( NOW(), INTERVAL 2 DAY )
		 ORDER BY ID DESC LIMIT 10"
	);
	foreach ( $ids as $id ) {
		ba_avif_convert_attachment( (int) $id );
	}
}

// Verrou anti-chevauchement : cron + boucle AJAX + bouton peuvent se
// declencher en meme temps -> plusieurs encodeurs en parallele -> CPU du
// mutualise sature (503). Une seule moulinette a la fois.
function ba_avif_process_batch() {
	if ( ! ba_avif_server_ok() || ba_avif_opt( 'ba_avif_paused' ) ) {
		return;
	}
	if ( ba_avif_locked() ) {
		return;
	}
	ba_avif_lock();
	ba_avif_convert_recent();
	if ( ! ba_avif_opt( 'ba_avif_bulk_done' ) ) {
		ba_avif_process_batch_locked();
	}
	ba_avif_unlock();
}

function ba_avif_process_batch_locked() {
	@set_time_limit( 300 );

	$settings = ba_avif_settings();
	$last_id  = (int) ba_avif_opt( 'ba_avif_last_id', 0 );

	global $wpdb;
	if ( 'desc' === $settings['order'] ) {
		// Plus recentes d'abord : curseur descendant depuis le plus grand ID.
		if ( $last_id <= 0 ) {
			$last_id = PHP_INT_MAX;
		}
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			   AND post_mime_type IN (" . ba_avif_mime_list() . ")
			   AND ID < %d
			 ORDER BY ID DESC
			 LIMIT %d",
			$last_id,
			max( 1, (int) $settings['batch'] )
		) );
	} else {
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			   AND post_mime_type IN (" . ba_avif_mime_list() . ")
			   AND ID > %d
			 ORDER BY ID ASC
			 LIMIT %d",
			$last_id,
			max( 1, (int) $settings['batch'] )
		) );
	}

	if ( empty( $ids ) ) {
		// Fin du stock uploads : theme puis plugins si demandes (une passe
		// dediee chacun, pour rester sous le timeout), puis termine.
		if ( ! empty( $settings['themes'] ) && ! ba_avif_opt( 'ba_avif_theme_done' ) ) {
			ba_avif_convert_theme();
			update_option( 'ba_avif_theme_done', 1, false );
			return;
		}
		if ( ! empty( $settings['plugins'] ) && ! ba_avif_opt( 'ba_avif_plugins_done' ) ) {
			ba_avif_convert_plugins();
			update_option( 'ba_avif_plugins_done', 1, false );
			return;
		}
		// Scan disque final : ce que la Mediatheque ne connait pas.
		// ~15 fichiers par piece jointe, on garde le meme ordre de grandeur.
		if ( ! ba_avif_opt( 'ba_avif_scan_done' ) ) {
			if ( ba_avif_scan_uploads( 15 * max( 1, (int) $settings['batch'] ) ) ) {
				update_option( 'ba_avif_scan_done', 1, false );
			}
			return;
		}
		update_option( 'ba_avif_bulk_done', 1, false );
		delete_option( 'ba_avif_force' );
		wp_clear_scheduled_hook( 'ba_avif_batch_event' );
		return;
	}

	foreach ( $ids as $id ) {
		ba_avif_convert_attachment( (int) $id );
		update_option( 'ba_avif_last_id', (int) $id, false );
	}
}
add_action( 'ba_avif_batch_event', 'ba_avif_process_batch' );

/* =========================================================================
   IMAGES DU THEME ACTIF (sprite, logo...) ET DES PLUGINS
   ====================================================================== */

function ba_avif_convert_dir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && preg_match( '/\.(' . ba_avif_ext_pattern() . ')$/i', $file->getFilename() ) ) {
			ba_avif_convert_file( $file->getPathname() );
		}
	}
}

function ba_avif_convert_theme() {
	ba_avif_convert_dir( get_stylesheet_directory() );
}

function ba_avif_convert_plugins() {
	ba_avif_convert_dir( WP_PLUGIN_DIR );
}

/* =========================================================================
   SCAN DISQUE COMPLET DE UPLOADS (comme Converter for Media)
   Ramasse les images presentes sur le disque mais absentes de la
   Mediatheque (FTP, plugins qui ecrivent en direct...). Auto-reprenable :
   pas de position stockee, les miroirs .avif deja a jour sont sautes en
   un stat(), donc chaque passe reprend la ou la precedente s'est arretee.
   Renvoie true quand une passe complete n'a plus rien trouve a convertir.
   ====================================================================== */

function ba_avif_scan_uploads( $max_conversions = 60 ) {
	$root = wp_get_upload_dir()['basedir'];
	if ( ! is_dir( $root ) ) {
		return true;
	}

	$converted = 0;
	$iterator  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || ! preg_match( '/\.(' . ba_avif_ext_pattern() . ')$/i', $file->getFilename() ) ) {
			continue;
		}
		$path = $file->getPathname();
		if ( ba_avif_is_excluded( $path ) ) {
			continue;
		}
		$src_ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$needs   = false;
		foreach ( ba_avif_formats() as $format ) {
			if ( 'webp' === $format && 'webp' === $src_ext ) {
				continue;
			}
			$dest = ba_avif_mirror_path( $path, $format );
			if ( $dest && ba_avif_needs_work( $path, $dest ) ) {
				$needs = true;
				break;
			}
		}
		if ( ! $needs ) {
			continue;
		}
		ba_avif_convert_file( $path );
		$converted++;
		if ( $converted >= $max_conversions ) {
			return false; // budget de la passe epuise, la suite au prochain tour
		}
	}
	return true;
}

/* =========================================================================
   .HTACCESS — uploads/ (les regles doivent vivre ici : un .htaccess de
   sous-dossier avec RewriteEngine On court-circuite celui du parent)
   + wp-content/ pour les images du theme.
   ====================================================================== */

// Regles de reecriture par format actif : l'AVIF est teste avant le WebP
// (ba_avif_formats renvoie toujours avif en premier), l'original sert de repli.
function ba_avif_serve_rules( $pattern ) {
	$out = "<IfModule mod_rewrite.c>\nRewriteEngine On\n";
	foreach ( ba_avif_formats() as $format ) {
		$out .= "RewriteCond %{HTTP_ACCEPT} image/" . $format . "\n"
			. "RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads-avifc/$1." . $format . " -f\n"
			. "RewriteRule ^(" . $pattern . ")$ /wp-content/uploads-avifc/$1." . $format . " [NC,T=image/" . $format . ",L]\n";
	}
	return $out . "</IfModule>\n";
}

function ba_avif_block_uploads() {
	$ext = ba_avif_ext_pattern();
	return "# BEGIN " . BA_AVIF_MARKER . "\n"
		. "<IfModule mod_mime.c>\nAddType image/avif .avif\nAddType image/webp .webp\n</IfModule>\n"
		. ba_avif_serve_rules( ".+\\.(?:" . $ext . ")" )
		. "<IfModule mod_headers.c>\n<FilesMatch \"\\.(" . $ext . ")$\">\nHeader append Vary Accept\n</FilesMatch>\n</IfModule>\n"
		. "# END " . BA_AVIF_MARKER . "\n";
}

function ba_avif_block_wpcontent() {
	$settings = ba_avif_settings();
	$dirs     = array();
	if ( ! empty( $settings['themes'] ) ) {
		$dirs[] = 'themes';
	}
	if ( ! empty( $settings['plugins'] ) ) {
		$dirs[] = 'plugins';
	}
	if ( empty( $dirs ) ) {
		return '';
	}
	$ext = ba_avif_ext_pattern();
	return "# BEGIN " . BA_AVIF_MARKER . "\n"
		. "<IfModule mod_mime.c>\nAddType image/avif .avif\nAddType image/webp .webp\n</IfModule>\n"
		. ba_avif_serve_rules( "(?:" . implode( '|', $dirs ) . ")/.+\\.(?:" . $ext . ")" )
		. "<IfModule mod_headers.c>\n<FilesMatch \"\\.(" . $ext . ")$\">\nHeader append Vary Accept\n</FilesMatch>\n</IfModule>\n"
		. "# END " . BA_AVIF_MARKER . "\n";
}

function ba_avif_write_block( $htaccess, $block ) {
	$existing = file_exists( $htaccess ) ? file_get_contents( $htaccess ) : '';
	$existing = preg_replace( '/# BEGIN ' . BA_AVIF_MARKER . '.*?# END ' . BA_AVIF_MARKER . '\n?/s', '', $existing );
	file_put_contents( $htaccess, $block === '' ? $existing : $block . "\n" . $existing );
}

function ba_avif_install_htaccess() {
	$uploads = wp_get_upload_dir();
	ba_avif_write_block( trailingslashit( $uploads['basedir'] ) . '.htaccess', ba_avif_block_uploads() );

	// Bloc wp-content : ecrit si themes ou plugins actives, retire sinon
	// (ba_avif_block_wpcontent renvoie '' quand aucun repertoire n'est coche).
	ba_avif_write_block( trailingslashit( WP_CONTENT_DIR ) . '.htaccess', ba_avif_block_wpcontent() );
}

function ba_avif_remove_htaccess() {
	$uploads = wp_get_upload_dir();
	ba_avif_write_block( trailingslashit( $uploads['basedir'] ) . '.htaccess', '' );
	ba_avif_write_block( trailingslashit( WP_CONTENT_DIR ) . '.htaccess', '' );
}

/* =========================================================================
   ACTIVATION / DESACTIVATION
   ====================================================================== */

register_activation_hook( __FILE__, function() {
	if ( ! ba_avif_server_ok() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( 'BA AVIF : aucun encodeur disponible sur ce serveur pour les formats choisis (ni Imagick ni GD).' );
	}
	ba_avif_install_htaccess();
	delete_option( 'ba_avif_bulk_done' );
	delete_option( 'ba_avif_scan_done' );
	if ( ! wp_next_scheduled( 'ba_avif_batch_event' ) ) {
		wp_schedule_event( time() + 60, 'ba_avif_5min', 'ba_avif_batch_event' );
	}
} );

register_deactivation_hook( __FILE__, function() {
	ba_avif_remove_htaccess();
	wp_clear_scheduled_hook( 'ba_avif_batch_event' );
	ba_avif_unlock();
	ba_avif_unlock( 'scanlock' );
	// Les fichiers uploads-avifc/ sont conserves (reactivation sans reconversion).
} );

// Desinstallation : options et regles purgees. Les fichiers AVIF sont
// laisses sur le disque (supprimer uploads-avifc/ a la main si voulu).
register_uninstall_hook( __FILE__, 'ba_avif_uninstall' );
function ba_avif_uninstall() {
	ba_avif_remove_htaccess();
	foreach ( array( 'ba_avif_settings', 'ba_avif_last_id', 'ba_avif_bulk_done', 'ba_avif_paused', 'ba_avif_force', 'ba_avif_theme_done', 'ba_avif_plugins_done', 'ba_avif_scan_done', 'ba_avif_tick_key' ) as $opt ) {
		delete_option( $opt );
	}
	delete_transient( 'ba_avif_disk_stats' );
	delete_transient( 'ba_avif_source_scan' );
}

// Nombre de pieces jointes deja passees par le curseur, selon l'ordre.
function ba_avif_done_count() {
	global $wpdb;
	$last = (int) ba_avif_opt( 'ba_avif_last_id', 0 );
	if ( 'desc' === ba_avif_settings()['order'] ) {
		if ( $last <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN (" . ba_avif_mime_list() . ") AND ID >= %d",
			$last
		) );
	}
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN (" . ba_avif_mime_list() . ") AND ID <= %d",
		$last
	) );
}

/* =========================================================================
   DECLENCHEUR DIRECT POUR LE CRON SERVEUR (contourne WP-Cron)
   WP-Cron est capricieux sur cet hebergement (cache objet APCu : les
   evenements planifies se perdent). La tache cPanel appelle directement :
   wget "<site>/wp-admin/admin-post.php?action=ba_avif_tick&key=<cle>"
   Chaque appel = un lot converti, sans planification WordPress au milieu.
   ====================================================================== */

function ba_avif_tick_key() {
	$key = get_option( 'ba_avif_tick_key' );
	if ( ! $key ) {
		$key = wp_generate_password( 20, false );
		update_option( 'ba_avif_tick_key', $key, false );
	}
	return $key;
}

function ba_avif_tick() {
	// Mouchard : trace du dernier appel, meme rate (cle invalide), pour
	// diagnostiquer la tache cron du panneau depuis la page d'admin.
	update_option( 'ba_avif_last_tick_try', time(), false );

	if ( empty( $_GET['key'] ) || ! hash_equals( ba_avif_tick_key(), (string) $_GET['key'] ) ) {
		wp_die( 'BA AVIF : cle invalide.', '', array( 'response' => 403 ) );
	}

	update_option( 'ba_avif_last_tick', time(), false );

	// Uploads recents en priorite puis stock : tout est dans process_batch,
	// qui gere aussi le verrou anti-chevauchement.
	ba_avif_process_batch();

	wp_die( 'BA AVIF tick : ok.', '', array( 'response' => 200 ) );
}
add_action( 'admin_post_nopriv_ba_avif_tick', 'ba_avif_tick' );
add_action( 'admin_post_ba_avif_tick', 'ba_avif_tick' );

/* =========================================================================
   OPTIMISATION EN MASSE "LIVE" (AJAX, boucle depuis la page d'admin)
   ====================================================================== */

add_action( 'wp_ajax_ba_avif_batch', function() {
	check_ajax_referer( 'ba_avif_admin' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	if ( ! ba_avif_opt( 'ba_avif_paused' ) ) {
		ba_avif_process_batch();
	}
	// Ne purger les caches de stats qu'en fin de traitement : les vider a
	// chaque lot forcait un re-parcours complet du disque (~75 000 stat())
	// au moindre rechargement de la page pendant la conversion.
	if ( ba_avif_opt( 'ba_avif_bulk_done' ) || ba_avif_opt( 'ba_avif_paused' ) ) {
		delete_transient( 'ba_avif_disk_stats' );
		delete_transient( 'ba_avif_source_scan' );
	}

	global $wpdb;
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN (" . ba_avif_mime_list() . ")" );
	$done  = ba_avif_done_count();

	wp_send_json( array(
		'done'     => $done,
		'total'    => $total,
		'pct'      => $total ? round( $done / $total * 100 ) : 0,
		'finished' => (bool) ba_avif_opt( 'ba_avif_bulk_done' ),
		'paused'   => (bool) ba_avif_opt( 'ba_avif_paused' ),
	) );
} );

/* =========================================================================
   CALCUL AUTOMATIQUE (comme le "Calcul, veuillez patienter" de Converter)
   Compte les fichiers images reels sur le disque — uploads + themes/plugins
   selon les reglages — et leur etat de conversion. Comptage seul, aucune
   conversion : quelques secondes meme sur un gros stock. Cache 5 min,
   purge a chaque lot converti pour que le rafraichissement recalcule.
   ====================================================================== */

function ba_avif_source_scan() {
	$stats = get_transient( 'ba_avif_source_scan' );
	if ( $stats !== false ) {
		return $stats;
	}

	// Pas de parcours du disque pendant qu'une conversion tourne, ni deux
	// parcours en parallele : sur le mutualise, l'IO s'additionne au CPU.
	if ( ba_avif_locked() || ba_avif_locked( 'scanlock', 120 ) ) {
		return array( 'computing' => true );
	}
	ba_avif_lock( 'scanlock' );

	@set_time_limit( 300 );
	$settings = ba_avif_settings();
	$formats  = ba_avif_formats();
	$stats    = array( 'total' => 0, 'formats' => array() );
	foreach ( $formats as $f ) {
		$stats['formats'][ $f ] = array( 'total' => 0, 'done' => 0, 'skips' => 0, 'src_bytes' => 0, 'out_bytes' => 0 );
	}

	$dirs = array( wp_get_upload_dir()['basedir'] );
	if ( ! empty( $settings['themes'] ) ) {
		$dirs[] = get_stylesheet_directory();
	}
	if ( ! empty( $settings['plugins'] ) ) {
		$dirs[] = WP_PLUGIN_DIR;
	}

	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || ! preg_match( '/\.(' . ba_avif_ext_pattern() . ')$/i', $file->getFilename() ) ) {
				continue;
			}
			$path = $file->getPathname();
			if ( ba_avif_is_excluded( $path ) || ! ba_avif_mirror_path( $path ) ) {
				continue;
			}
			$stats['total']++;
			$src_ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			foreach ( $formats as $f ) {
				if ( 'webp' === $f && 'webp' === $src_ext ) {
					continue;
				}
				$stats['formats'][ $f ]['total']++;
				$mirror = ba_avif_mirror_path( $path, $f );
				if ( file_exists( $mirror ) ) {
					$stats['formats'][ $f ]['done']++;
					$stats['formats'][ $f ]['src_bytes'] += $file->getSize();
					$stats['formats'][ $f ]['out_bytes'] += filesize( $mirror );
				} elseif ( file_exists( $mirror . '.skip' ) ) {
					$stats['formats'][ $f ]['skips']++;
				}
			}
		}
	}

	foreach ( $formats as $f ) {
		$s = $stats['formats'][ $f ];
		$stats['formats'][ $f ]['pct']   = $s['total'] ? round( $s['done'] / $s['total'] * 100 ) : 0;
		$stats['formats'][ $f ]['gain']  = $s['src_bytes'] ? round( ( 1 - $s['out_bytes'] / $s['src_bytes'] ) * 100 ) : 0;
		$stats['formats'][ $f ]['src_h'] = size_format( $s['src_bytes'] );
		$stats['formats'][ $f ]['out_h'] = size_format( $s['out_bytes'] );
	}

	set_transient( 'ba_avif_source_scan', $stats, 15 * MINUTE_IN_SECONDS );
	ba_avif_unlock( 'scanlock' );
	return $stats;
}

add_action( 'wp_ajax_ba_avif_scan_stats', function() {
	check_ajax_referer( 'ba_avif_admin' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}
	wp_send_json( ba_avif_source_scan() );
} );

/* =========================================================================
   STATS DISQUE (cache 15 min)
   ====================================================================== */

function ba_avif_disk_stats() {
	$stats = get_transient( 'ba_avif_disk_stats' );
	if ( $stats !== false ) {
		return $stats;
	}
	$stats = array( 'files' => 0, 'bytes' => 0, 'skips' => 0 );
	$root  = WP_CONTENT_DIR . '/uploads-avifc';
	if ( is_dir( $root ) ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$suffix = substr( $file->getFilename(), -5 );
			if ( '.avif' === $suffix || '.webp' === $suffix ) {
				$stats['files']++;
				$stats['bytes'] += $file->getSize();
			} elseif ( '.skip' === $suffix ) {
				$stats['skips']++;
			}
		}
	}
	set_transient( 'ba_avif_disk_stats', $stats, 15 * MINUTE_IN_SECONDS );
	return $stats;
}

/* =========================================================================
   PAGE D'ADMINISTRATION
   ====================================================================== */

add_action( 'admin_menu', function() {
	add_options_page( 'BA AVIF', 'BA AVIF', 'manage_options', 'ba-avif', 'ba_avif_admin_page' );
} );


function ba_avif_admin_page() {
	$notices = array();

	// --- Actions ---
	if ( isset( $_POST['ba_avif_save'] ) && check_admin_referer( 'ba_avif_admin' ) ) {
		$s = ba_avif_settings();
		// Deux formulaires (Reglages / Reglages avances) : chacun ne met a
		// jour que ses propres champs, les autres sont conserves.
		if ( isset( $_POST['ba_avif_form'] ) && 'avances' === $_POST['ba_avif_form'] ) {
			$s['method']       = ( isset( $_POST['method'] ) && 'gd' === $_POST['method'] ) ? 'gd' : 'imagick';
			$s['png_src']      = empty( $_POST['png_src'] ) ? 0 : 1;
			$s['gif_src']      = empty( $_POST['gif_src'] ) ? 0 : 1;
			$s['webp_src']     = empty( $_POST['webp_src'] ) ? 0 : 1;
			$s['auto_upload']  = empty( $_POST['auto_upload'] ) ? 0 : 1;
			$s['guard_larger'] = empty( $_POST['guard_larger'] ) ? 0 : 1;
			$s['keep_meta']    = empty( $_POST['keep_meta'] ) ? 0 : 1;
			$s['log_errors']   = empty( $_POST['log_errors'] ) ? 0 : 1;
			$s['media_column'] = empty( $_POST['media_column'] ) ? 0 : 1;
			$s['exclude']      = sanitize_text_field( wp_unslash( $_POST['exclude'] ) );
		} else {
			$old_order         = $s['order'];
			$s['quality']      = min( 80, max( 30, (int) $_POST['quality'] ) );
			$s['quality_webp'] = min( 95, max( 50, (int) $_POST['quality_webp'] ) );
			$s['batch']        = min( 10, max( 1, (int) $_POST['batch'] ) );
			$s['order']        = ( isset( $_POST['order'] ) && 'desc' === $_POST['order'] ) ? 'desc' : 'asc';
			$s['format']       = ( isset( $_POST['format'] ) && in_array( $_POST['format'], array( 'avif', 'webp', 'both' ), true ) ) ? $_POST['format'] : 'avif';
			$s['themes']       = empty( $_POST['themes'] ) ? 0 : 1;
			$s['plugins']      = empty( $_POST['plugins'] ) ? 0 : 1;
			if ( $old_order !== $s['order'] ) {
				// Changement d'ordre : le curseur repart du bon bout. Les
				// fichiers deja convertis sont sautes en un stat(), rien n'est refait.
				update_option( 'ba_avif_last_id', 'desc' === $s['order'] ? PHP_INT_MAX : 0, false );
			}
		}
		update_option( 'ba_avif_settings', $s, false );
		ba_avif_install_htaccess();
		delete_option( 'ba_avif_theme_done' );
		delete_option( 'ba_avif_plugins_done' );
		delete_option( 'ba_avif_scan_done' );
		// Un changement de format (ex. avif -> avif + webp) doit relancer la
		// moulinette : les fichiers deja a jour sont sautes en un stat().
		delete_option( 'ba_avif_bulk_done' );
		delete_transient( 'ba_avif_source_scan' );
		if ( ! wp_next_scheduled( 'ba_avif_batch_event' ) ) {
			wp_schedule_event( time() + 60, 'ba_avif_5min', 'ba_avif_batch_event' );
		}
		$notices[] = array( 'success', 'Reglages enregistres.' );
	}
	if ( isset( $_POST['ba_avif_pause'] ) && check_admin_referer( 'ba_avif_admin' ) ) {
		update_option( 'ba_avif_paused', 1, false );
		$notices[] = array( 'warning', 'Conversion du stock en pause (les nouveaux uploads restent convertis).' );
	}
	if ( isset( $_POST['ba_avif_resume'] ) && check_admin_referer( 'ba_avif_admin' ) ) {
		delete_option( 'ba_avif_paused' );
		$notices[] = array( 'success', 'Conversion reprise.' );
	}
	if ( isset( $_POST['ba_avif_run'] ) && check_admin_referer( 'ba_avif_admin' ) ) {
		delete_option( 'ba_avif_bulk_done' );
		delete_option( 'ba_avif_scan_done' ); // re-balaye le disque (fichiers FTP recents)
		delete_option( 'ba_avif_paused' );
		if ( ! wp_next_scheduled( 'ba_avif_batch_event' ) ) {
			wp_schedule_event( time() + 5, 'ba_avif_5min', 'ba_avif_batch_event' );
		}
		ba_avif_process_batch();
		delete_transient( 'ba_avif_disk_stats' );
		$notices[] = array( 'success', 'Lot converti — la suite tourne en arriere-plan (cron 5 min).' );
	}
	if ( isset( $_POST['ba_avif_force'] ) && check_admin_referer( 'ba_avif_admin' ) ) {
		update_option( 'ba_avif_force', 1, false );
		update_option( 'ba_avif_last_id', 'desc' === ba_avif_settings()['order'] ? PHP_INT_MAX : 0, false );
		delete_option( 'ba_avif_bulk_done' );
		delete_option( 'ba_avif_theme_done' );
		delete_option( 'ba_avif_plugins_done' );
		delete_option( 'ba_avif_scan_done' );
		delete_option( 'ba_avif_paused' );
		if ( ! wp_next_scheduled( 'ba_avif_batch_event' ) ) {
			wp_schedule_event( time() + 5, 'ba_avif_5min', 'ba_avif_batch_event' );
		}
		$notices[] = array( 'warning', 'Reconversion forcee lancee : tout le stock repasse a la moulinette.' );
	}

	// --- Donnees ---
	$settings = ba_avif_settings();
	global $wpdb;
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN (" . ba_avif_mime_list() . ")" );
	$done  = ba_avif_done_count();
	$pct   = $total ? round( $done / $total * 100 ) : 0;
	$disk  = ba_avif_disk_stats();
	if ( ba_avif_opt( 'ba_avif_bulk_done' ) ) {
		$etat = 'Termine';
	} elseif ( ba_avif_opt( 'ba_avif_paused' ) ) {
		$etat = 'En pause';
	} elseif ( $pct >= 100 && ! ba_avif_opt( 'ba_avif_scan_done' ) ) {
		$etat = 'Scan disque'; // Mediatheque finie, balayage des fichiers hors base
	} else {
		$etat = 'En cours';
	}
	$tab   = 'masse';
	if ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'reglages', 'avances' ), true ) ) {
		$tab = $_GET['tab'];
	}
	$base  = admin_url( 'options-general.php?page=ba-avif' );
	$circ  = 2 * 3.14159 * 52;
	$off   = $circ * ( 1 - $pct / 100 );
	?>
	<style>
	.baavif-wrap { max-width: 960px; margin: 20px 20px 0 0; font-size: 14px; }
	.baavif-banner { background: #1a3a50; border-radius: 10px; padding: 26px 30px; display: flex; align-items: center; gap: 16px; }
	.baavif-banner .bolt { width: 46px; height: 46px; border-radius: 50%; background: #3cb4fb; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
	.baavif-banner h1 { color: #fff; margin: 0; font-size: 22px; }
	.baavif-banner p { color: #a8c6dd; margin: 2px 0 0; }
	.baavif-tabs { margin: 18px 0 0; border-bottom: 1px solid #c3c4c7; display: flex; gap: 4px; }
	.baavif-tabs a { padding: 10px 18px; text-decoration: none; color: #50575e; font-weight: 600; border: 1px solid transparent; border-bottom: none; border-radius: 6px 6px 0 0; }
	.baavif-tabs a.active { background: #fff; border-color: #c3c4c7; color: #1a3a50; position: relative; top: 1px; }
	.baavif-panel { background: #fff; border: 1px solid #c3c4c7; border-top: none; border-radius: 0 0 10px 10px; padding: 26px 30px; }
	.baavif-grid { display: flex; gap: 30px; align-items: center; flex-wrap: wrap; }
	.baavif-donut { position: relative; width: 150px; height: 150px; }
	.baavif-donut .pct { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
	.baavif-donut .pct strong { font-size: 28px; color: #1a3a50; }
	.baavif-donut .pct span { font-size: 11px; color: #777; }
	.baavif-cards { display: flex; gap: 14px; flex-wrap: wrap; flex: 1; }
	.baavif-card { background: #f6f8fa; border-radius: 8px; padding: 14px 18px; min-width: 150px; }
	.baavif-card .num { font-size: 20px; font-weight: 700; color: #1a3a50; }
	.baavif-card .lbl { font-size: 12px; color: #666; margin-top: 2px; }
	.baavif-actions { margin-top: 24px; display: flex; gap: 10px; flex-wrap: wrap; }
	.baavif-btn { background: #3cb4fb; border: none; border-radius: 6px; color: #06202e; font-weight: 700; padding: 10px 20px; cursor: pointer; }
	.baavif-btn:hover { background: #64c4fc; }
	.baavif-btn.ghost { background: #fff; border: 1px solid #c3c4c7; color: #1a3a50; font-weight: 600; }
	.baavif-btn.danger { background: #fff; border: 1px solid #d63638; color: #d63638; font-weight: 600; }
	.baavif-status { display: inline-block; padding: 3px 12px; border-radius: 12px; font-size: 12px; font-weight: 700; }
	.baavif-status.encours { background: #e6f4ff; color: #1a6aa8; }
	.baavif-status.pause { background: #fff3cd; color: #8a6d1a; }
	.baavif-status.fini { background: #e5f5e8; color: #1a7a33; }
	.baavif-form th { text-align: left; padding: 14px 20px 14px 0; width: 220px; color: #1a3a50; }
	.baavif-form td { padding: 14px 0; }
	.baavif-form input[type=number] { width: 80px; }
	.baavif-form .desc { color: #777; font-size: 12px; display: block; margin-top: 4px; }
	.baavif-scanbox { background: #f6f8fa; border: 1px solid #e3e6e9; border-radius: 8px; padding: 14px 18px; margin: 0 0 22px; color: #444; line-height: 1.7; }
	.baavif-scanbox strong { color: #1a3a50; }
	.baavif-formats { display: flex; gap: 12px; flex-wrap: wrap; }
	.baavif-format-card { border: 2px solid #c3c4c7; border-radius: 8px; padding: 14px 18px; min-width: 150px; max-width: 220px; cursor: pointer; display: flex; flex-direction: column; gap: 4px; }
	.baavif-format-card:has(input:checked) { border-color: #3cb4fb; background: #f0f9ff; }
	.baavif-format-card strong { color: #1a3a50; }
	.baavif-format-card span { color: #777; font-size: 12px; }
	.baavif-notice { border-left: 4px solid #3cb4fb; background: #fff; padding: 10px 14px; margin: 14px 0 0; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
	.baavif-notice.warning { border-color: #dba617; }
	.baavif-section-title { margin: 28px 0 6px; color: #1a3a50; font-size: 15px; border-top: 1px solid #e3e6e9; padding-top: 22px; }
	.baavif-checks { margin: 6px 0 0; }
	.baavif-checks li { margin: 5px 0; color: #444; }
	.baavif-ok { color: #1a7a33; font-weight: 700; }
	.baavif-ko { color: #d63638; font-weight: 700; }
	.baavif-checks-note { color: #777; font-size: 12px; }
	</style>

	<div class="baavif-wrap">
		<div class="baavif-banner">
			<span class="bolt"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#06202e" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></span>
			<div>
				<h1>BA AVIF Converter</h1>
				<p>Encodage AVIF + WebP local (Imagick ou GD) &middot; cascade AVIF &rarr; WebP &rarr; original &middot; fait maison pour Buzzarena</p>
			</div>
		</div>

		<?php foreach ( $notices as $n ) : ?>
			<div class="baavif-notice <?php echo esc_attr( $n[0] ); ?>"><?php echo esc_html( $n[1] ); ?></div>
		<?php endforeach; ?>

		<div class="baavif-tabs">
			<a href="<?php echo esc_url( $base ); ?>" class="<?php echo $tab === 'masse' ? 'active' : ''; ?>">Optimisation en masse</a>
			<a href="<?php echo esc_url( $base . '&tab=reglages' ); ?>" class="<?php echo $tab === 'reglages' ? 'active' : ''; ?>">Reglages generaux</a>
			<a href="<?php echo esc_url( $base . '&tab=avances' ); ?>" class="<?php echo $tab === 'avances' ? 'active' : ''; ?>">Reglages avances</a>
		</div>

		<div class="baavif-panel">
		<?php if ( $tab === 'masse' ) : ?>

			<div class="baavif-scanbox">
				<strong>Liste des fichiers pouvant &ecirc;tre optimis&eacute;s :</strong><br />
				<span id="baavif-scan-line">Calcul, veuillez patienter&hellip;</span>
			</div>

			<div class="baavif-grid">
				<?php $ring_colors = array( 'avif' => '#3cb4fb', 'webp' => '#34c26e' ); ?>
				<?php foreach ( ba_avif_formats() as $f ) : ?>
				<div class="baavif-donut">
					<svg width="150" height="150" viewBox="0 0 120 120">
						<circle cx="60" cy="60" r="52" fill="none" stroke="#e8eef3" stroke-width="11"/>
						<circle id="baavif-ring-<?php echo esc_attr( $f ); ?>" cx="60" cy="60" r="52" fill="none" stroke="<?php echo esc_attr( $ring_colors[ $f ] ); ?>" stroke-width="11" stroke-linecap="round"
							stroke-dasharray="<?php echo round( $circ, 1 ); ?>" stroke-dashoffset="<?php echo round( $off, 1 ); ?>"
							transform="rotate(-90 60 60)" style="transition: stroke-dashoffset .4s ease;"/>
					</svg>
					<div class="pct"><strong id="baavif-pct-<?php echo esc_attr( $f ); ?>"><?php echo $pct; ?>%</strong><span>convertis en <?php echo strtoupper( $f ); ?></span></div>
				</div>
				<?php endforeach; ?>
				<div class="baavif-cards">
					<div class="baavif-card"><div class="num" id="baavif-count"><?php echo number_format_i18n( $done ); ?> / <?php echo number_format_i18n( $total ); ?></div><div class="lbl">pieces jointes traitees</div></div>
					<div class="baavif-card"><div class="num"><?php echo number_format_i18n( $disk['files'] ); ?></div><div class="lbl">fichiers AVIF/WebP sur disque (<?php echo size_format( $disk['bytes'] ); ?>)</div></div>
					<div class="baavif-card"><div class="num"><?php echo number_format_i18n( $disk['skips'] ); ?></div><div class="lbl">ignores (non rentables / illisibles)</div></div>
					<div class="baavif-card"><div class="num"><span class="baavif-status <?php echo $etat === 'Termine' ? 'fini' : ( $etat === 'En pause' ? 'pause' : 'encours' ); ?>"><?php echo $etat; ?></span></div><div class="lbl">etat du traitement &middot; serveur <?php echo ba_avif_server_ok() ? 'OK' : 'KO'; ?></div></div>
					<?php
					$tick_ok  = (int) ba_avif_opt( 'ba_avif_last_tick', 0 );
					$tick_try = (int) ba_avif_opt( 'ba_avif_last_tick_try', 0 );
					?>
					<div class="baavif-card"><div class="num"><?php echo $tick_ok ? 'il y a ' . human_time_diff( $tick_ok ) : 'jamais'; ?></div><div class="lbl">dernier tick cron reussi<?php echo ( $tick_try && $tick_try > $tick_ok ) ? ' &middot; appel RATE (cle ?) il y a ' . human_time_diff( $tick_try ) : ''; ?></div></div>
				</div>
			</div>

			<form method="post" class="baavif-actions">
				<?php wp_nonce_field( 'ba_avif_admin' ); ?>
				<button type="button" class="baavif-btn" id="baavif-live">Demarrer l'optimisation en masse</button>
				<button class="baavif-btn ghost" name="ba_avif_run" value="1">Convertir un seul lot</button>
				<?php if ( ba_avif_opt( 'ba_avif_paused' ) ) : ?>
					<button class="baavif-btn ghost" name="ba_avif_resume" value="1">Reprendre</button>
				<?php else : ?>
					<button class="baavif-btn ghost" name="ba_avif_pause" value="1">Mettre en pause</button>
				<?php endif; ?>
				<button class="baavif-btn danger" name="ba_avif_force" value="1" onclick="return confirm('Reconvertir TOUT le stock ? (plusieurs heures)')">Forcer la reconversion de toutes les images</button>
			</form>

			<script>
			(function() {
				var btn = document.getElementById('baavif-live');
				if (!btn) { return; }
				var circ = <?php echo round( $circ, 1 ); ?>;
				var nonce = '<?php echo esc_js( wp_create_nonce( 'ba_avif_admin' ) ); ?>';
				var fmts = <?php echo wp_json_encode( ba_avif_formats() ); ?>;
				var actif = false;
				function majAnneau(f, pct) {
					var ring = document.getElementById('baavif-ring-' + f);
					var num = document.getElementById('baavif-pct-' + f);
					if (ring && num) {
						num.textContent = pct + '%';
						ring.style.strokeDashoffset = (circ * (1 - pct / 100)).toFixed(1);
					}
				}
				btn.addEventListener('click', function() {
					if (actif) { actif = false; btn.textContent = 'Demarrer l\'optimisation en masse'; return; }
					actif = true;
					btn.textContent = 'Conversion en cours... (cliquer pour arreter)';
					(function boucle() {
						if (!actif) { return; }
						fetch(ajaxurl + '?action=ba_avif_batch&_wpnonce=' + nonce)
							.then(function(r) { return r.json(); })
							.then(function(j) {
								fmts.forEach(function(f) { majAnneau(f, j.pct); });
								document.getElementById('baavif-count').textContent = j.done.toLocaleString('fr-FR') + ' / ' + j.total.toLocaleString('fr-FR');
								if (j.finished || j.paused) {
									actif = false;
									btn.textContent = j.finished ? 'Termine !' : 'En pause';
									setTimeout(function() { location.reload(); }, 1200);
								} else {
									// 3 s de pause entre les lots : le CPU du
									// mutualise redescend au lieu de saturer.
									setTimeout(boucle, 3000);
								}
							})
							.catch(function() { actif = false; btn.textContent = 'Erreur reseau — reessayer'; });
					})();
				});

				// Calcul automatique au chargement de la page (fichiers reels sur
				// le disque), comme le "Calcul, veuillez patienter" de Converter.
				var line = document.getElementById('baavif-scan-line');
				if (!line) { return; }
				fetch(ajaxurl + '?action=ba_avif_scan_stats&_wpnonce=' + nonce)
					.then(function(r) { return r.json(); })
					.then(function(j) {
						if (j.computing || !j.formats) {
							line.textContent = 'Conversion en cours — le calcul detaille du disque reviendra a la prochaine accalmie (les donuts restent fiables).';
							return;
						}
						var parts = [];
						Object.keys(j.formats).forEach(function(f) {
							var s = j.formats[f];
							var reste = s.total - s.done - s.skips;
							parts.push(f.toUpperCase() + ' : ' + s.done.toLocaleString('fr-FR') + '/' + s.total.toLocaleString('fr-FR')
								+ ' convertis, ' + reste.toLocaleString('fr-FR') + ' restants, ' + s.skips.toLocaleString('fr-FR')
								+ ' ignores — economie -' + s.gain + '% (' + s.src_h + ' → ' + s.out_h + ')');
							majAnneau(f, s.pct);
						});
						line.textContent = j.total.toLocaleString('fr-FR') + ' fichiers images detectes sur le disque. ' + parts.join(' · ') + '.';
					})
					.catch(function() {
						line.textContent = 'Calcul impossible (erreur reseau ou timeout) — rechargez la page.';
					});
			})();
			</script>

		<?php elseif ( $tab === 'reglages' ) : ?>

			<form method="post">
				<?php wp_nonce_field( 'ba_avif_admin' ); ?>
				<input type="hidden" name="ba_avif_form" value="general" />
				<table class="baavif-form">
					<tr><th>Liste des repertoires pris en charge</th><td>
						<label><input type="checkbox" checked disabled /> /uploads (toujours actif)</label><br />
						<label><input type="checkbox" name="themes" value="1" <?php checked( 1, $settings['themes'] ); ?> /> /themes — theme actif (sprite, logo...)</label><br />
						<label><input type="checkbox" name="plugins" value="1" <?php checked( 1, $settings['plugins'] ); ?> /> /plugins — images des extensions</label>
						<span class="desc">Themes et plugins sont convertis en fin de traitement du stock. Ajoute un bloc de regles dans wp-content/.htaccess.</span>
					</td></tr>
					<tr><th>Formats de sortie</th><td>
						<div class="baavif-formats">
							<label class="baavif-format-card">
								<input type="radio" name="format" value="webp" <?php checked( 'webp', $settings['format'] ); ?> />
								<strong>WebP</strong>
								<span>compatible quasi partout, gain moyen</span>
							</label>
							<label class="baavif-format-card">
								<input type="radio" name="format" value="avif" <?php checked( 'avif', $settings['format'] ); ?> />
								<strong>AVIF</strong>
								<span>gain maximal, ~95 % des navigateurs</span>
							</label>
							<label class="baavif-format-card">
								<input type="radio" name="format" value="both" <?php checked( 'both', $settings['format'] ); ?> />
								<strong>AVIF + WebP</strong>
								<span>le meilleur format pour chaque visiteur — permet de desinstaller Converter for Media</span>
							</label>
						</div>
						<span class="desc">Cascade servie par .htaccess : AVIF d'abord, WebP ensuite, l'original sinon. (Chez Converter for Media, AVIF et AVIF + WebP sont payants.)</span>
					</td></tr>
					<tr><th>Qualite des images AVIF</th><td>
						<input type="number" name="quality" min="30" max="80" value="<?php echo (int) $settings['quality']; ?>" />
						<span class="desc">30 = tres compresse, 80 = quasi sans perte (defaut 55). Vaut pour les prochaines conversions — utilisez &laquo; Forcer &raquo; pour reprendre le stock.</span>
					</td></tr>
					<tr><th>Qualite des images WebP</th><td>
						<input type="number" name="quality_webp" min="50" max="95" value="<?php echo (int) $settings['quality_webp']; ?>" />
						<span class="desc">Utilisee quand la sortie WebP est active (defaut 75 — le WebP supporte moins bien la compression agressive que l'AVIF).</span>
					</td></tr>
					<tr><th>Pieces jointes par lot</th><td>
						<input type="number" name="batch" min="1" max="10" value="<?php echo (int) $settings['batch']; ?>" />
						<span class="desc">Par passe de cron. Plus haut = plus rapide, mais plus de charge CPU sur le mutualise.</span>
					</td></tr>
					<tr><th>Ordre de conversion</th><td>
						<label><input type="radio" name="order" value="desc" <?php checked( 'desc', $settings['order'] ); ?> /> Plus recentes d'abord (recommande en production)</label><br />
						<label><input type="radio" name="order" value="asc" <?php checked( 'asc', $settings['order'] ); ?> /> Plus anciennes d'abord</label>
						<span class="desc">&laquo; Recentes d'abord &raquo; : les articles chauds (Discover, accueil) profitent de l'AVIF des les premieres heures, les archives attendent leur tour. Changer d'ordre ne reconvertit rien, le curseur repart juste de l'autre bout.</span>
					</td></tr>
				</table>
				<p><button class="baavif-btn" name="ba_avif_save" value="1">Enregistrer les reglages</button></p>
			</form>

		<?php else : ?>

			<form method="post">
				<?php wp_nonce_field( 'ba_avif_admin' ); ?>
				<input type="hidden" name="ba_avif_form" value="avances" />
				<table class="baavif-form">
					<tr><th>Extensions de fichiers prises en charge</th><td>
						<label><input type="checkbox" checked disabled /> .jpg / .jpeg (toujours actifs)</label><br />
						<label><input type="checkbox" name="png_src" value="1" <?php checked( 1, $settings['png_src'] ); ?> /> .png</label><br />
						<label><input type="checkbox" name="gif_src" value="1" <?php checked( 1, $settings['gif_src'] ); ?> /> .gif</label>
						<span class="desc" style="display:inline;">— GIF animes : seule la premiere image est conservee, laisser decoche si vous en utilisez</span><br />
						<label><input type="checkbox" name="webp_src" value="1" <?php checked( 1, $settings['webp_src'] ); ?> /> .webp</label>
						<span class="desc" style="display:inline;">— conversion vers AVIF uniquement</span>
					</td></tr>
					<tr><th>Repertoires exclus</th><td>
						<input type="text" name="exclude" value="<?php echo esc_attr( $settings['exclude'] ); ?>" placeholder="dossier-1,dossier-2" style="width:340px;" />
						<span class="desc">Noms de repertoires separes par des virgules, ignores lors de la conversion.</span>
					</td></tr>
					<tr><th>Methode de conversion</th><td>
						<label><input type="radio" name="method" value="imagick" <?php checked( 'imagick', $settings['method'] ); ?> <?php disabled( ! ba_avif_imagick_ok() && ! ba_avif_imagick_webp_ok() ); ?> /> Imagick (recommande)<?php echo ( ba_avif_imagick_ok() || ba_avif_imagick_webp_ok() ) ? '' : ' — indisponible sur ce serveur'; ?></label><br />
						<label><input type="radio" name="method" value="gd" <?php checked( 'gd', $settings['method'] ); ?> <?php disabled( ! ba_avif_gd_ok() && ! ba_avif_gd_webp_ok() ); ?> /> GD<?php echo ( ba_avif_gd_ok() || ba_avif_gd_webp_ok() ) ? '' : ' — indisponible sur ce serveur'; ?></label>
						<span class="desc">Si la methode choisie ne sait pas produire un des formats actifs, l'autre prend le relais pour ce format (l'AVIF via GD demande PHP 8.1+ avec libavif). Pas de &laquo; serveur distant &raquo; ici : c'est l'option payante de Converter, tout est encode localement.</span>
					</td></tr>
					<tr><th>Mode de chargement d'image</th><td>
						<label><input type="radio" checked disabled /> via .htaccess (recommande)</label>
						<span class="desc">Les modes &laquo; contournement NGINX &raquo; et &laquo; Pass Thru &raquo; de Converter servent aux serveurs sans .htaccess ; o2switch (LiteSpeed) le supporte — cascade validee en live, rien a contourner.</span>
					</td></tr>
					<tr><th>Fonctions supplementaires</th><td>
						<label><input type="checkbox" name="auto_upload" value="1" <?php checked( 1, $settings['auto_upload'] ); ?> /> Conversion automatique des nouvelles images envoyees dans la Mediatheque</label><br />
						<label><input type="checkbox" name="guard_larger" value="1" <?php checked( 1, $settings['guard_larger'] ); ?> /> Suppression automatique des copies AVIF/WebP plus lourdes que l'original</label><br />
						<label><input type="checkbox" name="keep_meta" value="1" <?php checked( 1, $settings['keep_meta'] ); ?> /> Conserver les metadonnees des images (EXIF, profil couleur) — indisponible pour la methode GD</label><br />
						<label><input type="checkbox" name="log_errors" value="1" <?php checked( 1, $settings['log_errors'] ); ?> /> Journaliser les erreurs de conversion dans debug.log</label>
					</td></tr>
					<tr><th>Statistiques d'optimisation</th><td>
						<label><input type="checkbox" name="media_column" value="1" <?php checked( 1, $settings['media_column'] ); ?> /> Afficher les statistiques dans la Mediatheque (colonne BA AVIF)</label>
					</td></tr>
					<tr><th>Declencheur cron serveur</th><td>
						<input type="text" readonly onclick="this.select();" style="width:100%;font-family:monospace;font-size:12px;"
							value='wget "<?php echo esc_url( admin_url( 'admin-post.php?action=ba_avif_tick&key=' . ba_avif_tick_key() ) ); ?>" -q -O /dev/null -t 1 -T 300' />
						<span class="desc">A coller dans une tache cron cPanel (toutes les 5 minutes : */5). Chaque appel convertit un lot directement, sans dependre de WP-Cron — recommande sur cet hebergement. Clic dans le champ = tout selectionner.</span>
					</td></tr>
				</table>
				<p><button class="baavif-btn" name="ba_avif_save" value="1">Enregistrer les reglages</button></p>
			</form>

			<?php
			// --- Configuration serveur (comme le panneau d'erreurs de Converter) ---
			$uploads_ht = trailingslashit( wp_get_upload_dir()['basedir'] ) . '.htaccess';
			$checks = array(
				array( ba_avif_imagick_ok(), 'Imagick avec support AVIF' ),
				array( ba_avif_imagick_webp_ok(), 'Imagick avec support WebP' ),
				array( ba_avif_gd_ok(), 'GD avec support AVIF (PHP 8.1+)' ),
				array( ba_avif_gd_webp_ok(), 'GD avec support WebP' ),
				array( file_exists( $uploads_ht ) && strpos( file_get_contents( $uploads_ht ), BA_AVIF_MARKER ) !== false, 'Regles BA AVIF presentes dans uploads/.htaccess' ),
				array( wp_is_writable( WP_CONTENT_DIR ), 'wp-content inscriptible (miroir uploads-avifc/)' ),
				array( (bool) wp_next_scheduled( 'ba_avif_batch_event' ) || (bool) get_option( 'ba_avif_bulk_done' ), 'Tache cron de conversion planifiee' ),
			);
			?>
			<h2 class="baavif-section-title">Configuration serveur</h2>
			<ul class="baavif-checks">
				<?php foreach ( $checks as $c ) : ?>
					<li><span class="<?php echo $c[0] ? 'baavif-ok' : 'baavif-ko'; ?>"><?php echo $c[0] ? '&#10003;' : '&#10007;'; ?></span> <?php echo esc_html( $c[1] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="baavif-checks-note">Pour chaque format de sortie actif, il faut au moins Imagick ou GD qui le supporte ; sinon ce format est ignore (.skip).</p>

		<?php endif; ?>
		</div>
	</div>
	<?php
}

/* =========================================================================
   MEDIATHEQUE : colonne "BA AVIF" (stats + conversion unitaire)
   ====================================================================== */

function ba_avif_attachment_paths( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file ) {
		return array();
	}
	$paths = array( $file );
	$meta  = wp_get_attachment_metadata( $attachment_id );
	if ( ! empty( $meta['sizes'] ) ) {
		$dir = trailingslashit( dirname( $file ) );
		foreach ( $meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$paths[] = $dir . $size['file'];
			}
		}
	}
	return $paths;
}

add_filter( 'manage_media_columns', function( $cols ) {
	if ( ! empty( ba_avif_settings()['media_column'] ) ) {
		$cols['ba_avif'] = 'BA AVIF';
	}
	return $cols;
} );

add_action( 'manage_media_custom_column', function( $col, $attachment_id ) {
	if ( 'ba_avif' !== $col ) {
		return;
	}
	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! preg_match( '/\.(' . ba_avif_ext_pattern() . ')$/i', $file ) ) {
		echo '&mdash;';
		return;
	}

	$formats   = ba_avif_formats();
	$src_bytes = 0; $out_bytes = 0; $total = 0;
	$per       = array_fill_keys( $formats, 0 );
	foreach ( ba_avif_attachment_paths( $attachment_id ) as $p ) {
		if ( ! file_exists( $p ) ) {
			continue;
		}
		$total++;
		foreach ( $formats as $f ) {
			if ( 'webp' === $f && preg_match( '/\.webp$/i', $p ) ) {
				continue;
			}
			$mirror = ba_avif_mirror_path( $p, $f );
			if ( $mirror && file_exists( $mirror ) ) {
				$per[ $f ]++;
				$src_bytes += filesize( $p );
				$out_bytes += filesize( $mirror );
			}
		}
	}

	$converted = array_sum( $per );
	if ( $converted && $src_bytes ) {
		$gain = round( ( 1 - $out_bytes / $src_bytes ) * 100 );
		echo '<strong style="color:#1a7a33;">Reduction moyenne : -' . $gain . '%</strong><br />';
		foreach ( $formats as $f ) {
			echo '<span style="color:#777;">' . $per[ $f ] . '/' . $total . ' fichiers en ' . strtoupper( $f ) . '</span><br />';
		}
	} else {
		echo '<span style="color:#777;">Pas encore converti</span><br />';
	}

	$url = wp_nonce_url( admin_url( 'admin-post.php?action=ba_avif_one&id=' . (int) $attachment_id ), 'ba_avif_one_' . (int) $attachment_id );
	echo '<a href="' . esc_url( $url ) . '" style="color:#2271b1;">' . ( $converted ? 'Reconvertir maintenant' : 'Convertir maintenant' ) . '</a>';
}, 10, 2 );

add_action( 'admin_post_ba_avif_one', function() {
	$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	$nonce = isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '';
	if ( ! $id || ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'ba_avif_one_' . $id ) ) {
		wp_die( 'Non autorise.' );
	}

	// Reconversion propre : on purge les copies et marqueurs des deux formats.
	foreach ( ba_avif_attachment_paths( $id ) as $p ) {
		foreach ( array( 'avif', 'webp' ) as $f ) {
			$mirror = ba_avif_mirror_path( $p, $f );
			if ( $mirror ) {
				if ( file_exists( $mirror ) ) { unlink( $mirror ); }
				if ( file_exists( $mirror . '.skip' ) ) { unlink( $mirror . '.skip' ); }
			}
		}
	}
	ba_avif_convert_attachment( $id );
	delete_transient( 'ba_avif_disk_stats' );
	delete_transient( 'ba_avif_source_scan' );

	$back = wp_get_referer();
	wp_safe_redirect( $back ? $back : admin_url( 'upload.php' ) );
	exit;
} );
