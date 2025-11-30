	<?php
	/*
	Plugin Name: SportTic
	Plugin URI: https://www.tactic.cat
	Description: Plugin per a la gestió de pavellons amb programació d'horaris, condicions ambientals i plantilles.
	Version: 3.269
	Author: Albert Noe Noe
	Author URI: https://www.albertnoe.com
	License: GPL2
	*/
	
	// Evitem l'accés directe (Bona pràctica)
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}
	
	// ================================================================
	// SECCIÓ 1: DEFINICIONS I ACTIVACIÓ DEL PLUGIN (CODI MODIFICAT)
	// ================================================================
	
	if ( ! defined( 'SPORTIC_LOCK_TABLE' ) ) {
		define('SPORTIC_LOCK_TABLE', 'sportic_bloqueig');
	}
	if ( ! defined('SPORTIC_UNDO_DB_VERSION') ) {
		define('SPORTIC_UNDO_DB_VERSION', '1.0');
	}
	if ( ! defined('SPORTIC_UNDO_TABLE') ) {
		define('SPORTIC_UNDO_TABLE', 'sportic_undo_history');
	}
	if ( ! defined('SPORTIC_REDO_TABLE') ) {
		define('SPORTIC_REDO_TABLE', 'sportic_redo_history');
	}
	
	// ----------------------------------------------------------------
	// (A) Crear LES TAULES a l'activació
	//     Aquesta única funció s'encarrega de tot en activar:
	//     - Crear taula de programació (si no existeix)
	//     - Crear taula de bloqueig (si no existeix)
	//     - Crear taules undo/redo (si no existeixen)
	//     - Executar la migració inicial (si la taula de programació està buida)
	// ----------------------------------------------------------------
	register_activation_hook( __FILE__, 'sportic_activar_plugin' );
	
function sportic_activar_plugin() {
		// <-- INICI DE LA SOLUCIÓ: Comencem a capturar qualsevol sortida
		ob_start();
	
		/* =====================================================================
		 *  Configuració bàsica
		 * ===================================================================*/
		global $wpdb;
		$charsetCollate = $wpdb->get_charset_collate();
		$prefix         = $wpdb->prefix;
	
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	
		/* =====================================================================
		 *  1) TAULA PRINCIPAL DE PROGRAMACIÓ
		 * ===================================================================*/
		$nomTaulaProgramacio = $prefix . 'sportic_programacio';
	
		$sqlProgramacio = "CREATE TABLE IF NOT EXISTS $nomTaulaProgramacio (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			piscina_slug  VARCHAR(50)  NOT NULL,
			dia_data      DATE         NOT NULL,
			hores_serial  LONGTEXT,
			PRIMARY KEY (id),
			-- Índex antic conservat per a retrocompatibilitat (primer camp = piscina_slug)
			KEY piscina_dia             (piscina_slug, dia_data),
			-- Índex d’unicitat ja existent (evita duplicats)
			UNIQUE KEY idx_piscina_dia_unique (piscina_slug, dia_data)
		) $charsetCollate;";
	
		dbDelta( $sqlProgramacio );
	
		/* -------------------------------------------------------------------
		 *  OPTIMITZACIÓ: índex dedicat per a la columna 'dia_data'
		 * ------------------------------------------------------------------*/
		$idx_dia_existeix = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
			  WHERE table_schema = %s
				AND table_name   = %s
				AND index_name   = 'idx_dia_data'",
			DB_NAME,
			$nomTaulaProgramacio
		) );
	
		if ( ! $idx_dia_existeix ) {
			// S’afegeix sense trencar cap restricció existent
			$wpdb->query( "ALTER TABLE $nomTaulaProgramacio
						   ADD INDEX idx_dia_data (dia_data)" );
		}
	
		/* =====================================================================
		 *  2) TAULA DE BLOQUEIG
		 * ===================================================================*/
		if ( ! defined( 'SPORTIC_LOCK_TABLE' ) ) {
			define( 'SPORTIC_LOCK_TABLE', 'sportic_bloqueig' ); // Assegurem la constant
		}
		$nomTaulaBloqueig = $prefix . SPORTIC_LOCK_TABLE;
	
		$sqlBloqueig = "CREATE TABLE IF NOT EXISTS $nomTaulaBloqueig (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			piscina_slug  VARCHAR(50)  NOT NULL,
			dia_data      DATE         NOT NULL,
			hora          VARCHAR(5)   NOT NULL,
			carril_index  SMALLINT UNSIGNED NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY unique_cell      (piscina_slug, dia_data, hora, carril_index),
			-- Índex antic (1r camp = piscina_slug)
			KEY piscina_dia_hora        (piscina_slug, dia_data, hora)
		) $charsetCollate;";
	
		dbDelta( $sqlBloqueig );
	
		/* Índex dedicat per a 'dia_data' a la taula de bloqueig */
		$idx_dia_blk_existeix = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
			  WHERE table_schema = %s
				AND table_name   = %s
				AND index_name   = 'idx_dia_data'",
			DB_NAME,
			$nomTaulaBloqueig
		) );
	
		if ( ! $idx_dia_blk_existeix ) {
			$wpdb->query( "ALTER TABLE $nomTaulaBloqueig
						   ADD INDEX idx_dia_data (dia_data)" );
		}
	
		/* =====================================================================
		 *  NOU: 3) TAULA D'EXCEPCIONS PER A ESDEVENIMENTS RECURRENTS
		 * ===================================================================*/
		$nomTaulaExcepcions = $prefix . 'sportic_recurrent_exceptions';
		$sqlExcepcions = "CREATE TABLE IF NOT EXISTS $nomTaulaExcepcions (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			piscina_slug  VARCHAR(50)  NOT NULL,
			dia_data      DATE         NOT NULL,
			hora          VARCHAR(5)   NOT NULL,
			carril_index  SMALLINT UNSIGNED NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY unique_exception (piscina_slug, dia_data, hora, carril_index),
			KEY idx_dia_data (dia_data)
		) $charsetCollate;";
		
		dbDelta( $sqlExcepcions );
		
		/* =====================================================================
		 *  4) TAULES D'UNDO/REDO  (sense canvis rellevants)
		 * ===================================================================*/
		$table_undo = $prefix . SPORTIC_UNDO_TABLE;
		$sql_undo   = "CREATE TABLE IF NOT EXISTS $table_undo (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT(20) UNSIGNED NOT NULL,
			option_name   VARCHAR(255) NOT NULL,
			diff          LONGTEXT,
			date_recorded DATETIME     NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY option_name (option_name)
		) $charsetCollate;";
		dbDelta( $sql_undo );
	
		$table_redo = $prefix . SPORTIC_REDO_TABLE;
		$sql_redo   = str_replace( SPORTIC_UNDO_TABLE, SPORTIC_REDO_TABLE, $sql_undo );
		dbDelta( $sql_redo );
	
		if ( get_option( 'sportic_undo_db_version' ) === false ) {
			add_option( 'sportic_undo_db_version', SPORTIC_UNDO_DB_VERSION );
		}
	
		/* =====================================================================
		 *  5) MIGRACIÓ INICIAL (sense canvis)
		 * ===================================================================*/
		$recompte = $wpdb->get_var( "SELECT COUNT(*) FROM $nomTaulaProgramacio" );
		if ( $recompte == 0 ) {
			migrar_dades_sportic_a_taula();
		}
	
		// <-- FI DE LA SOLUCIÓ: Netegem i aturem la captura de sortida
		ob_end_clean();
	}
	
	// ----------------------------------------------------------------
	// (B) Migrar dades des de l'opció antic (sportic_unfile_dades) cap a la taula
	//     Aquesta funció es manté igual, només afecta la taula de programació.
	// ----------------------------------------------------------------
	function migrar_dades_sportic_a_taula() {
		global $wpdb;
		$nomTaula = $wpdb->prefix . 'sportic_programacio';
	
		// Llegim tot el que hi havia a l'antiga opció
		$dadesAntigues = get_option('sportic_unfile_dades', array());
		if (!is_array($dadesAntigues) || empty($dadesAntigues)) {
			return; // no hi ha res a migrar
		}
	
		// Recorrem piscines i dates
		foreach ($dadesAntigues as $slugPiscina => $datesArr) {
			if (!is_array($datesArr)) continue;
			foreach ($datesArr as $dia => $hores) {
				if (!is_array($hores)) continue;
	
				// Guardem com a format "serialized" (sense '!')
				$horesSerial = maybe_serialize($hores);
	
				$wpdb->insert(
					$nomTaula,
					array(
						'piscina_slug' => $slugPiscina,
						'dia_data'     => $dia,
						'hores_serial' => $horesSerial // Valor sense '!'
					),
					array('%s','%s','%s')
				);
			}
		}
		// Un cop feta la migració, buidem l'antic opció (per no duplicar tot)
		update_option('sportic_unfile_dades', array());
	}
	
	// ================================================================
	// FI SECCIÓ 1 (ACTIVACIÓ I MIGRACIÓ)
	// ================================================================
	
	
	// ================================================================
	// SECCIÓ 2: INTERCEPTAR GET/UPDATE OPCIÓ (CODI ORIGINAL - SENSE CANVIS EN AQUEST PAS)
	// ================================================================
	
	// ----------------------------------------------------------------
	// (C) Interceptem GET i UPDATE de l'opció 'sportic_unfile_dades'
	//     Aquestes funcions ARA cridaran a les funcions de càrrega/desat
	//     que ja tenen en compte la taula de bloqueig (que modificarem després).
	// ----------------------------------------------------------------
	
	// 1) Quan algun codi fa get_option('sportic_unfile_dades'),
	//    fem que en realitat retorni les dades de la nova taula SENSE '!'
add_filter('pre_option_sportic_unfile_dades', 'sportic_llegir_de_taula_sense_prefix', 10, 2); // Afegim el 2 per acceptar $option
function sportic_llegir_de_taula_sense_prefix($pre_value, $option) {
	static $cached_sense_prefix = null;
	if ($cached_sense_prefix !== null && $option === 'sportic_unfile_dades') {
		return $cached_sense_prefix;
	}

	global $wpdb;
	$nomTaulaProg = $wpdb->prefix . 'sportic_programacio';
	$nomTaulaLock = defined('SPORTIC_LOCK_TABLE') ? ($wpdb->prefix . SPORTIC_LOCK_TABLE) : ($wpdb->prefix . 'sportic_bloqueig');

	if ( defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && $_REQUEST['action'] === 'sportic_unfile_csv') {
		return $pre_value;
	}

	if ( is_admin() && isset($_GET['page']) && in_array($_GET['page'], array('sportic-onefile-menu', 'sportic-onefile-settings'), true) ) {
		$refDate = isset($_GET['selected_date']) ? sanitize_text_field($_GET['selected_date']) : current_time('Y-m-d');
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate)) $refDate = current_time('Y-m-d');
		try { $dObj = new DateTime($refDate); } catch (Exception $e) { $dObj = new DateTime(current_time('Y-m-d')); }

		$startObj = clone $dObj; $startObj->modify('monday this week');
		$endObj   = clone $startObj; $endObj->modify('sunday this week');
		$start_week_day = $startObj->format('Y-m-d');
		$end_week_day   = $endObj->format('Y-m-d');

		// Clau de caché amb suport per a 'lloc'
		$lloc_slug_for_key = isset($_GET['lloc']) ? sanitize_key($_GET['lloc']) : 'default_lloc';
		$transient_key = 'sportic_week_data_' . $lloc_slug_for_key . '_' . $start_week_day;
		
		$data_amb_prefix_transient = get_transient($transient_key);

		if ($data_amb_prefix_transient !== false && is_array($data_amb_prefix_transient)) {
			$configured_pools_for_transient = sportic_unfile_get_pool_labels_sorted();
			$filtered_transient_data = array_intersect_key($data_amb_prefix_transient, $configured_pools_for_transient);
			$cleaned_data = sportic_remove_lock_prefix_from_data($filtered_transient_data);
			$cached_sense_prefix = $cleaned_data;
			return $cleaned_data;
		}
		
		$rowsProg = $wpdb->get_results($wpdb->prepare("SELECT piscina_slug, dia_data, hores_serial FROM $nomTaulaProg WHERE dia_data BETWEEN %s AND %s", $start_week_day, $end_week_day), ARRAY_A);
		$rowsLock = $wpdb->get_results($wpdb->prepare("SELECT piscina_slug, dia_data, hora, carril_index FROM $nomTaulaLock WHERE dia_data BETWEEN %s AND %s", $start_week_day, $end_week_day), ARRAY_A);
		
		// NOTA: Aquí hem eliminat la càrrega de $rowsExceptions perquè ja no es fan servir.

		$configured_pools = sportic_unfile_get_pool_labels_sorted();
		$pool_slugs_configurats = array_keys($configured_pools);
		$ret_amb_prefix_setmana = array();
		$period = new DatePeriod(clone $startObj, new DateInterval('P1D'), (clone $endObj)->modify('+1 day'));

		foreach ($pool_slugs_configurats as $slug_p) {
			$ret_amb_prefix_setmana[$slug_p] = array();
			foreach ($period as $day_obj) {
				$diaF = $day_obj->format('Y-m-d');
				if (!isset($ret_amb_prefix_setmana[$slug_p][$diaF])) $ret_amb_prefix_setmana[$slug_p][$diaF] = [];
			}
		}

		if ($rowsProg) {
			foreach ($rowsProg as $fila) {
				$slug_db = $fila['piscina_slug'];
				$dia_db = $fila['dia_data'];
				if (!in_array($slug_db, $pool_slugs_configurats)) continue;
				$hores = (!empty($fila['hores_serial'])) ? @maybe_unserialize($fila['hores_serial']) : false;
				if ($hores === false || !is_array($hores)) $hores = sportic_unfile_crear_programacio_default($slug_db);
				elseif (isset($configured_pools[$slug_db]['lanes'])) {
					$num_carrils_config = $configured_pools[$slug_db]['lanes'];
					foreach ($hores as $hora_key => $carrils_data) {
						if (is_array($carrils_data)) {
							$count_data = count($carrils_data);
							if ($count_data < $num_carrils_config) $hores[$hora_key] = array_pad($carrils_data, $num_carrils_config, 'l');
							elseif ($count_data > $num_carrils_config) $hores[$hora_key] = array_slice($carrils_data, 0, $num_carrils_config);
						} else { $hores[$hora_key] = array_fill(0, $num_carrils_config, 'l'); }
					}
				}
				$ret_amb_prefix_setmana[$slug_db][$dia_db] = $hores;
			}
		}
		foreach ($pool_slugs_configurats as $slug_p) {
			foreach ($period as $day_obj) {
				$diaF = $day_obj->format('Y-m-d');
				if (empty($ret_amb_prefix_setmana[$slug_p][$diaF])) {
					$ret_amb_prefix_setmana[$slug_p][$diaF] = sportic_unfile_crear_programacio_default($slug_p);
				}
			}
		}

		// PAS 1: Aplicar esdeveniments recurrents (ELIMINAT - Ja no hi ha bucle aquí)
		
		// PAS 2: Aplicar bloquejos manuals al final
		$locksWeek_map = array();
		if ($rowsLock) {
			foreach ($rowsLock as $lockInfo) {
				$locksWeek_map[$lockInfo['piscina_slug']][$lockInfo['dia_data']][$lockInfo['hora']][intval($lockInfo['carril_index'])] = true;
			}
		}
		foreach ($ret_amb_prefix_setmana as $slug_piscina_actual => &$diesArr_piscina) {
			if (!isset($locksWeek_map[$slug_piscina_actual])) continue;
			foreach ($diesArr_piscina as $dia_actual => &$horesArr_dia) {
				if (!isset($locksWeek_map[$slug_piscina_actual][$dia_actual])) continue;
				foreach ($horesArr_dia as $hora_actual => &$carrilsArr_hora) {
					if (!is_array($carrilsArr_hora) || !isset($locksWeek_map[$slug_piscina_actual][$dia_actual][$hora_actual])) continue;
					$num_carrils_definitius = count($carrilsArr_hora);
					for ($c_idx = 0; $c_idx < $num_carrils_definitius; $c_idx++) {
						if (isset($locksWeek_map[$slug_piscina_actual][$dia_actual][$hora_actual][$c_idx])) {
							$valorActualCarril = $carrilsArr_hora[$c_idx] ?? 'l';
							if (strpos($valorActualCarril, '!') !== 0) $carrilsArr_hora[$c_idx] = '!' . $valorActualCarril;
						}
					}
				}
				unset($carrilsArr_hora);
			}
			unset($horesArr_dia);
		}
		unset($diesArr_piscina);

		set_transient($transient_key, $ret_amb_prefix_setmana, HOUR_IN_SECONDS);
		$cleaned_data = sportic_remove_lock_prefix_from_data($ret_amb_prefix_setmana);
		$cached_sense_prefix = $cleaned_data;
		return $cleaned_data;
	} 

	$dades_tot_amb_prefix = sportic_carregar_tot_com_array(); 
	$cleaned_data_tot = sportic_remove_lock_prefix_from_data($dades_tot_amb_prefix); 
	$cached_sense_prefix = $cleaned_data_tot;
	return $cleaned_data_tot;
}
	// 2) Quan algun codi fa update_option('sportic_unfile_dades', $valor),
	//    en realitat ho guardem a les noves taules (programació i bloqueig)
	add_filter('pre_update_option_sportic_unfile_dades', 'sportic_guardar_a_taula', 10, 3);
	function sportic_guardar_a_taula($valorNou, $valorVell, $nomOption) {
		// $valorNou és tot el "mega array" (amb possible '!')
		// IMPORTANT: Aquesta funció 'sportic_emmagatzemar_tot_com_array' SERÀ MODIFICADA després.
		sportic_emmagatzemar_tot_com_array($valorNou);
	
		// Retornem un array buit perquè WordPress no guardi res a wp_options
		return array();
	}
	
	// ----------------------------------------------------------------
	// (D) Funcions auxiliars per carregar / desar la mega-array
	//     a la taula
	// ----------------------------------------------------------------
function sportic_carregar_tot_com_array() {
		global $wpdb;
		$nomTaulaProg = $wpdb->prefix . 'sportic_programacio';
		$nomTaulaLock = defined('SPORTIC_LOCK_TABLE') ? ($wpdb->prefix . SPORTIC_LOCK_TABLE) : ($wpdb->prefix . 'sportic_bloqueig');
		
		// Ja no necessitem la taula d'excepcions aquí
	
		$ret_final = array();
		$dies_endavant = defined('SPORTIC_DIES_ENDAVANT') ? absint(SPORTIC_DIES_ENDAVANT) : 30;
		$data_avui = current_time('Y-m-d');
		$data_fi   = date('Y-m-d', strtotime("+$dies_endavant days", strtotime($data_avui)));
		
		$cache_key   = 'sportic_all_data_with_locks';
		$cache_group = 'sportic_data';
		$cached_data = wp_cache_get($cache_key, $cache_group);
	
		if (false !== $cached_data && is_array($cached_data)) {
			$configured_pools_for_cache = sportic_unfile_get_pool_labels_sorted();
			$filtered_cached_data = array_intersect_key($cached_data, $configured_pools_for_cache);
			return $filtered_cached_data;
		}
	
		$configured_pools = sportic_unfile_get_pool_labels_sorted();
		$pool_slugs_configurats = array_keys($configured_pools);
	
		foreach ($pool_slugs_configurats as $s_slug) {
			$ret_final[$s_slug] = array();
		}
	
		// 1. Carreguem la programació base
		$sql_prog = $wpdb->prepare("SELECT piscina_slug, dia_data, hores_serial FROM $nomTaulaProg WHERE dia_data BETWEEN %s AND %s", $data_avui, $data_fi);
		$filesProg = $wpdb->get_results($sql_prog, ARRAY_A);
	
		if ($filesProg) {
			foreach ($filesProg as $fila) {
				$slug_db = $fila['piscina_slug'];
				if (!in_array($slug_db, $pool_slugs_configurats)) continue;
				$dia_db = $fila['dia_data'];
				$hores_serial = $fila['hores_serial'];
				$hores_data = (!empty($hores_serial)) ? @maybe_unserialize($hores_serial) : false;
	
				if (false === $hores_data || !is_array($hores_data)) {
					$hores_data = sportic_unfile_crear_programacio_default($slug_db);
				} elseif (isset($configured_pools[$slug_db]['lanes'])) {
					$num_carrils_config = $configured_pools[$slug_db]['lanes'];
					foreach ($hores_data as $hora_key => $carrils_in_db) {
						if (is_array($carrils_in_db)) {
							$count_db = count($carrils_in_db);
							if ($count_db < $num_carrils_config) $hores_data[$hora_key] = array_pad($carrils_in_db, $num_carrils_config, 'l');
							elseif ($count_db > $num_carrils_config) $hores_data[$hora_key] = array_slice($carrils_in_db, 0, $num_carrils_config);
						} else {
							$hores_data[$hora_key] = array_fill(0, $num_carrils_config, 'l');
						}
					}
				}
				$ret_final[$slug_db][$dia_db] = $hores_data;
			}
		}
	
		// Identifiquem dies que tenen bloquejos per assegurar que estan inicialitzats
		$dies_potencials_map = [];
		$sql_lock_dates = $wpdb->prepare("SELECT DISTINCT dia_data FROM $nomTaulaLock WHERE dia_data BETWEEN %s AND %s", $data_avui, $data_fi);
		$lock_dates = $wpdb->get_col($sql_lock_dates);
	
		foreach ($ret_final as $slug_p => &$dies_data) {
			$dates_in_pool = array_keys($dies_data);
			$all_dates_for_pool = array_unique(array_merge($dates_in_pool, $lock_dates));
			foreach ($all_dates_for_pool as $date_str) {
				if (!isset($dies_data[$date_str])) {
					$dies_data[$date_str] = sportic_unfile_crear_programacio_default($slug_p);
				}
			}
		}
		unset($dies_data);
		
		// 2. Apliquem bloquejos manuals (afegeix '!' si cal)
		$sql_lock = $wpdb->prepare("SELECT piscina_slug, dia_data, hora, carril_index FROM $nomTaulaLock WHERE dia_data BETWEEN %s AND %s", $data_avui, $data_fi);
		$filesLock = $wpdb->get_results($sql_lock, ARRAY_A);
		$locks_map = array();
		if ($filesLock) {
			foreach ($filesLock as $lockInfo) {
				$slug_db = $lockInfo['piscina_slug'];
				if (!in_array($slug_db, $pool_slugs_configurats)) continue;
				$locks_map[$slug_db][$lockInfo['dia_data']][$lockInfo['hora']][intval($lockInfo['carril_index'])] = true;
			}
		}
		foreach ($ret_final as $slug_actual => &$diesArr) {
			if (!isset($locks_map[$slug_actual])) continue;
			foreach($diesArr as $dia_actual => &$horesArr) {
				if (!isset($locks_map[$slug_actual][$dia_actual])) continue;
				foreach ($horesArr as $hora_key => &$carrilsArr) {
					if (!is_array($carrilsArr) || !isset($locks_map[$slug_actual][$dia_actual][$hora_key])) continue;
					$num_carrils_def = count($carrilsArr);
					for ($c_idx = 0; $c_idx < $num_carrils_def; $c_idx++) {
						if (isset($locks_map[$slug_actual][$dia_actual][$hora_key][$c_idx])) {
							$valorCarril = $carrilsArr[$c_idx] ?? 'l';
							if (strpos($valorCarril, '!') !== 0) {
								$carrilsArr[$c_idx] = '!' . $valorCarril;
							}
						}
					}
				}
				unset($carrilsArr);
			}
			unset($horesArr);
		}
		unset($diesArr);
	
		wp_cache_set($cache_key, $ret_final, $cache_group, 0);
		return $ret_final;
	}
				
	/**
	 * ============================================================================
	 * NOVA FUNCIÓ AUXILIAR PER NETEJAR ELS TRANSIENTS DEL CSV
	 * Aquesta funció s'encarregarà d'esborrar totes les dades en cau
	 * generades per a les pantalles quan es desa un canvi.
	 * ============================================================================
	 */
	function sportic_clear_csv_transients() {
		global $wpdb;
		// El nom del transient a la BD comença per _transient_ o _transient_timeout_
		$prefix = '_transient_sportic_csv_data_';
		$timeout_prefix = '_transient_timeout_sportic_csv_data_';
		
		// Escapem els caràcters especials de SQL per a la clàusula LIKE
		$pattern = $wpdb->esc_like($prefix) . '%';
		$timeout_pattern = $wpdb->esc_like($timeout_prefix) . '%';
		
		// Esborrem les opcions de la base de dades que coincideixen amb els patrons
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
				$pattern,
				$timeout_pattern
			)
		);
	}
	
	
 
if ( ! function_exists('sportic_get_custom_activities_indexed') ) {
	function sportic_get_custom_activities_indexed() {
		$activities = get_option('sportic_unfile_custom_letters', array());
		if (!is_array($activities)) {
			return array();
		}
		$needs_update = false;
		foreach ($activities as $idx => &$item) {
			$description = $item['description'] ?? '';
			if (empty($item['shortcode'])) {
				$slug = sanitize_title($description);
				if ($slug === '') {
					$slug = 'equip-' . ($idx + 1);
				}
				$item['shortcode'] = $slug;
				$needs_update = true;
			}
			if (!isset($item['letter'])) {
				$item['letter'] = $description;
				$needs_update = true;
			}
		}
		unset($item);
		if ($needs_update) {
			update_option('sportic_unfile_custom_letters', $activities);
		}
		$indexed = array();
		foreach ($activities as $item) {
			$slug = $item['shortcode'] ?? '';
			if ($slug === '') continue;
			$indexed[$slug] = $item;
		}
		return $indexed;
	}
}

if ( ! function_exists('sportic_hex_to_rgb') ) {
	function sportic_hex_to_rgb($hex) {
		$hex = ltrim(trim($hex), '#');
		if (strlen($hex) === 3) {
			$hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
		}
		if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
			return null;
		}
		return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
	}
}

if ( ! function_exists('sportic_rgb_to_hex') ) {
	function sportic_rgb_to_hex($r,$g,$b) {
		$r = max(0,min(255,$r));
		$g = max(0,min(255,$g));
		$b = max(0,min(255,$b));
		return sprintf('#%02x%02x%02x', $r, $g, $b);
	}
}

if ( ! function_exists('sportic_adjust_hex_color') ) {
	function sportic_adjust_hex_color($hex, $factor = 0.2) {
		$rgb = sportic_hex_to_rgb($hex);
		if (!$rgb) return $hex;
		list($r,$g,$b) = $rgb;
		if ($factor >= 0) {
			$r = $r + (255 - $r) * $factor;
			$g = $g + (255 - $g) * $factor;
			$b = $b + (255 - $b) * $factor;
		} else {
			$f = 1 + $factor;
			$r = $r * $f;
			$g = $g * $f;
			$b = $b * $f;
		}
		return sportic_rgb_to_hex((int)round($r), (int)round($g), (int)round($b));
	}
}

if ( ! function_exists('sportic_get_contrast_for_hex') ) {
	function sportic_get_contrast_for_hex($hex) {
		$rgb = sportic_hex_to_rgb($hex);
		if (!$rgb) return '#ffffff';
		list($r,$g,$b) = $rgb;
		$luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b);
		return ($luminance < 150) ? '#ffffff' : '#0f172a';
	}
}

if ( ! function_exists('sportic_add_minutes_to_time') ) {
	function sportic_add_minutes_to_time($time, $minutes) {
		list($h,$m) = array_map('intval', explode(':', $time));
		$total = $h*60 + $m + intval($minutes);
		if ($total < 0) $total = 0;
		$hour = intdiv($total, 60);
		$min = $total % 60;
		if ($hour >= 24) {
			$hour = 24;
			$min = 0;
		}
		return sprintf('%02d:%02d', $hour, $min);
	}
}

if ( ! function_exists('sportic_format_duration_label') ) {
	function sportic_format_duration_label($minutes) {
		$minutes = max(0, intval($minutes));
		$hours = intdiv($minutes, 60);
		$mins  = $minutes % 60;
		$parts = [];
		if ($hours > 0) {
			$parts[] = $hours . ' h';
		}
		if ($mins > 0) {
			$parts[] = $mins . ' min';
		}
		return !empty($parts) ? implode(' ', $parts) : '0 min';
	}
}

if ( ! function_exists('sportic_clean_schedule_value') ) {
	function sportic_clean_schedule_value($value) {
		if (!is_string($value)) return '';
		$clean = ltrim($value, '!@');
		return trim($clean);
	}
}

if ( ! function_exists('sportic_extract_primary_token') ) {
	function sportic_extract_primary_token($value) {
		$parts = explode(':', $value, 2);
		return trim($parts[0]);
	}
}

if ( ! function_exists('sportic_extract_subitem_token') ) {
	function sportic_extract_subitem_token($values) {
		foreach ($values as $raw) {
			if (strpos($raw, ':') !== false) {
				$parts = explode(':', $raw, 2);
				$sub = trim($parts[1]);
				if ($sub !== '') {
					return $sub;
				}
			}
		}
		return '';
	}
}

if ( ! function_exists('sportic_collect_team_events') ) {
	function sportic_collect_team_events($schedule_map, $piscines, $team_description, $start_date, $days = 7) {
		$events_by_day = [];
		$base_name_norm = mb_strtolower(trim($team_description));
		$startDateObj = new DateTime($start_date);
		for ($dayOffset = 0; $dayOffset < $days; $dayOffset++) {
			$current = clone $startDateObj;
			$current->modify('+' . $dayOffset . ' days');
			$date_key = $current->format('Y-m-d');
			$day_events = [];
			foreach ($piscines as $slug => $info) {
				$day_schedule = $schedule_map[$slug][$date_key] ?? [];
				if (empty($day_schedule)) continue;
				$hores = array_keys($day_schedule);
				sort($hores);
				if (empty($hores)) continue;
				$first_hour = $hores[0];
				$lane_labels = $info['lane_labels'] ?? [];
				if (empty($lane_labels)) {
					$lane_count = isset($day_schedule[$first_hour]) && is_array($day_schedule[$first_hour]) ? count($day_schedule[$first_hour]) : ($info['lanes'] ?? 0);
					for ($li = 1; $li <= $lane_count; $li++) {
						$lane_labels[] = 'Pista ' . $li;
					}
				}
				$lane_total = isset($day_schedule[$first_hour]) ? count($day_schedule[$first_hour]) : count($lane_labels);
				for ($lane_index = 0; $lane_index < $lane_total; $lane_index++) {
					$current_event = null;
					foreach ($hores as $hora) {
						$valor = $day_schedule[$hora][$lane_index] ?? 'l';
						$valor_net = sportic_clean_schedule_value($valor);
						$primary = mb_strtolower(sportic_extract_primary_token($valor_net));
						$matches_team = ($primary !== '' && $primary === $base_name_norm);
						if ($matches_team) {
							if ($current_event === null) {
								$current_event = [
									'start' => $hora,
									'end'   => sportic_add_minutes_to_time($hora, 15),
									'pavilion_slug'  => $slug,
									'pavilion_label' => $info['label'] ?? ucfirst($slug),
									'lane_label'     => $lane_labels[$lane_index] ?? ('Pista ' . ($lane_index + 1)),
									'lane_index'     => $lane_index + 1,
									'raw_values'     => [$valor_net],
								];
							} else {
								$current_event['end'] = sportic_add_minutes_to_time($hora, 15);
								$current_event['raw_values'][] = $valor_net;
							}
						} else {
							if ($current_event !== null) {
								$current_event['duration_minutes'] = max(15, count($current_event['raw_values']) * 15);
								$current_event['subitem'] = sportic_extract_subitem_token($current_event['raw_values']);
								$day_events[] = $current_event;
								$current_event = null;
							}
						}
					}
					if ($current_event !== null) {
						$current_event['duration_minutes'] = max(15, count($current_event['raw_values']) * 15);
						$current_event['subitem'] = sportic_extract_subitem_token($current_event['raw_values']);
						$day_events[] = $current_event;
					}
				}
			}
			usort($day_events, function($a,$b){ return strcmp($a['start'], $b['start']); });
			$events_by_day[$date_key] = $day_events;
		}
	return $events_by_day;
	}
}

if ( ! function_exists('sportic_get_activity_palette') ) {
	/**
	 * Retorna un mapa de colors per a cada activitat (incloent lliure i bloquejat).
	 */
	function sportic_get_activity_palette() {
		$palette = [
			'l' => [ 'color' => '#e2e8f0', 'label' => __( 'Lliure', 'sportic' ) ],
			'b' => [ 'color' => '#94a3b8', 'label' => __( 'Tancat', 'sportic' ) ],
		];

		$custom_letters = get_option( 'sportic_unfile_custom_letters', [] );
		if ( is_array( $custom_letters ) ) {
			foreach ( $custom_letters as $info ) {
				$desc = isset( $info['description'] ) ? trim( $info['description'] ) : '';
				if ( $desc === '' ) {
					continue;
				}
				$color = isset( $info['color'] ) && is_string( $info['color'] ) ? $info['color'] : '#2563eb';
				$palette[ $desc ] = [
					'color' => sanitize_hex_color( $color ) ?: '#2563eb',
					'label' => $desc,
				];
			}
		}

		return $palette;
	}
}

if ( ! function_exists('sportic_build_day_sessions') ) {
	/**
	 * Construeix blocs de sessions per cada pavelló i carril agrupant intervals consecutius.
	 */
	function sportic_build_day_sessions( $schedule_map, $pools_config, $target_day ) {
		$result = [];
		$palette = sportic_get_activity_palette();

		foreach ( $pools_config as $slug => $pool_info ) {
			$day_schedule = $schedule_map[ $slug ][ $target_day ] ?? sportic_unfile_crear_programacio_default( $slug );
			if ( ! is_array( $day_schedule ) ) {
				$day_schedule = sportic_unfile_crear_programacio_default( $slug );
			}

			$lane_labels = $pool_info['lane_labels'] ?? [];
			$lane_count  = $pool_info['lanes'] ?? ( isset( $day_schedule ) && is_array( reset( $day_schedule ) ) ? count( reset( $day_schedule ) ) : 0 );
			if ( $lane_count < 1 ) {
				$result[ $slug ] = [];
				continue;
			}
			if ( empty( $lane_labels ) ) {
				for ( $l = 1; $l <= $lane_count; $l++ ) {
					$lane_labels[] = sprintf( __( 'Pista %d', 'sportic' ), $l );
				}
			}

			$hours = array_keys( $day_schedule );
			sort( $hours );
			$lane_sessions = [];

			for ( $lane_index = 0; $lane_index < $lane_count; $lane_index++ ) {
				$current_value   = null;
				$current_locked  = false;
				$current_recurrent = false;
				$current_start   = null;
				$current_blocks  = 0;

				foreach ( $hours as $hour ) {
					$value_raw = $day_schedule[ $hour ][ $lane_index ] ?? 'l';
					$is_locked = strpos( (string) $value_raw, '!' ) === 0;
					$value_without_lock = $is_locked ? substr( $value_raw, 1 ) : $value_raw;
					$is_recurrent = strpos( (string) $value_without_lock, '@' ) === 0;
					$clean_value = sportic_clean_schedule_value( $value_raw );
					if ( $clean_value === '' ) {
						$clean_value = 'l';
					}

					if ( $current_value === null ) {
						$current_value   = $clean_value;
						$current_locked  = $is_locked;
						$current_recurrent = $is_recurrent;
						$current_start   = $hour;
						$current_blocks  = 1;
						continue;
					}

					if ( $clean_value === $current_value && $is_locked === $current_locked && $is_recurrent === $current_recurrent ) {
						$current_blocks++;
						continue;
					}

					$lane_sessions[] = sportic_finalize_session_block(
						$current_start,
						$current_blocks,
						$current_value,
						$current_locked,
						$current_recurrent,
						$lane_labels[ $lane_index ] ?? sprintf( __( 'Pista %d', 'sportic' ), $lane_index + 1 ),
						$palette
					);

					$current_value   = $clean_value;
					$current_locked  = $is_locked;
					$current_recurrent = $is_recurrent;
					$current_start   = $hour;
					$current_blocks  = 1;
				}

				if ( $current_value !== null && $current_start !== null ) {
					$lane_sessions[] = sportic_finalize_session_block(
						$current_start,
						$current_blocks,
						$current_value,
						$current_locked,
						$current_recurrent,
						$lane_labels[ $lane_index ] ?? sprintf( __( 'Pista %d', 'sportic' ), $lane_index + 1 ),
						$palette
					);
				}
			}

			usort( $lane_sessions, function( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			});

			$result[ $slug ] = $lane_sessions;
		}

		return $result;
	}
}

if ( ! function_exists('sportic_finalize_session_block') ) {
	/**
	 * Construeix l'estructura d'un bloc de sessió a partir de les dades acumulades.
	 */
	function sportic_finalize_session_block( $start_time, $block_count, $value, $locked, $recurrent, $lane_label, $palette ) {
		$duration_minutes = max( 15, $block_count * 15 );
		$end_time = sportic_add_minutes_to_time( $start_time, $duration_minutes );
		$label = $value;
		$sub = '';
		if (strpos($value, ':') !== false) {
			list($primary, $subitems) = array_map('trim', explode(':', $value, 2));
			$label = $primary;
			$sub = $subitems;
		}

		if ($value === 'l') {
			$label = __( 'Lliure', 'sportic' );
		} elseif ($value === 'b') {
			$label = __( 'Tancat', 'sportic' );
		}

		$palette_key = array_key_exists( $value, $palette ) ? $value : $label;
		$color_info = $palette[ $palette_key ] ?? $palette['l'];

		return [
			'start'     => $start_time,
			'end'       => $end_time,
			'duration'  => $duration_minutes,
			'value'     => $value,
			'label'     => $label,
			'sub_label' => $sub,
			'lane'      => $lane_label,
			'locked'    => $locked,
			'recurrent' => $recurrent,
			'color'     => $color_info['color'] ?? '#e2e8f0',
			'badge'     => $color_info['label'] ?? $label,
		];
	}
}

if ( ! function_exists('sportic_group_events_into_sessions') ) {
	/**
	 * Agrupa esdeveniments individuals (per pista) en sessions coherents.
	 * Si un equip té entrenaments a la mateixa hora i pavelló però en diferents pistes,
	 * aquesta funció els fusiona en una única sessió visual.
	 *
	 * @param array $day_events Llista d'esdeveniments d'un dia.
	 * @return array Llista d'esdeveniments agrupats per sessió.
	 */
	function sportic_group_events_into_sessions($day_events) {
		if (empty($day_events)) {
			return [];
		}

		$sessions_map = [];
		foreach ($day_events as $event) {
			// Creem una clau única per a cada sessió (hora inici + fi + pavelló)
			$key = $event['start'] . '|' . $event['end'] . '|' . $event['pavilion_slug'];

			if (!isset($sessions_map[$key])) {
				// Si és la primera vegada que veiem aquesta sessió, creem l'entrada
				$sessions_map[$key] = $event;
				$sessions_map[$key]['lanes_list'] = [
					[
						'label' => $event['lane_label'],
						'index' => $event['lane_index']
					]
				];
				// Eliminem les claus originals que ara estan dins de 'lanes_list'
				unset($sessions_map[$key]['lane_label']);
				unset($sessions_map[$key]['lane_index']);
			} else {
				// Si la sessió ja existeix, només afegim la nova pista a la llista
				$sessions_map[$key]['lanes_list'][] = [
					'label' => $event['lane_label'],
					'index' => $event['lane_index']
				];
			}
		}
		
		// Ordenem les pistes dins de cada sessió pel seu índex
		foreach ($sessions_map as &$session) {
			usort($session['lanes_list'], function($a, $b) {
				return $a['index'] <=> $b['index'];
			});
		}
		unset($session);

		// Retornem els valors del mapa com un array indexat
		return array_values($sessions_map);
	}
}

if ( ! function_exists('sportic_process_team_sessions') ) {
	/**
	 * Processa els esdeveniments d'un equip per agrupar-los en sessions contínues,
	 * gestionant correctament els canvis de pistes dins d'una mateixa sessió.
	 *
	 * @param array $day_events Array d'esdeveniments individuals (de 15 min) per a un dia.
	 * @return array Array de sessions processades i agrupades.
	 */
	function sportic_process_team_sessions($day_events) {
		if (empty($day_events)) {
			return [];
		}

		// Pas 1: Agrupar esdeveniments per franja horària
		$events_by_time = [];
		foreach ($day_events as $event) {
			$events_by_time[$event['start']][] = $event;
		}
		$sorted_times = array_keys($events_by_time);
		sort($sorted_times);

		$final_sessions = [];
		$current_session = null;

		// Pas 2: Recórrer les franges horàries per construir les sessions
		foreach ($sorted_times as $i => $time) {
			$events_at_this_time = $events_by_time[$time];
			$main_event_data = $events_at_this_time[0]; // Totes les dades principals són iguals
			
			// Extreure i ordenar les pistes per aquesta franja horària
			$lanes_this_slot_list = [];
			foreach($events_at_this_time as $e) {
				$lanes_this_slot_list[] = ['label' => $e['lane_label'], 'index' => $e['lane_index']];
			}
			usort($lanes_this_slot_list, function($a, $b){ return $a['index'] <=> $b['index']; });
			$lanes_key = implode(',', array_column($lanes_this_slot_list, 'label'));

			if ($current_session === null) {
				// Comença una nova sessió
				$current_session = [
					'pavilion_slug'  => $main_event_data['pavilion_slug'],
					'pavilion_label' => $main_event_data['pavilion_label'],
					'subitem'        => $main_event_data['subitem'],
					'overall_start'  => $time,
					'overall_end'    => sportic_add_minutes_to_time($time, 15),
					'lane_config'    => [
						[
							'start'      => $time,
							'end'        => sportic_add_minutes_to_time($time, 15),
							'lanes_list' => $lanes_this_slot_list,
							'lanes_key'  => $lanes_key
						]
					]
				];
			} else {
				// Comprova si la franja actual és una continuació de la sessió
				$is_continuous = ($time === $current_session['overall_end']);
				$is_same_pavilion = ($main_event_data['pavilion_slug'] === $current_session['pavilion_slug']);

				if ($is_continuous && $is_same_pavilion) {
					// És una continuació. Actualitzem l'hora final general de la sessió.
					$current_session['overall_end'] = sportic_add_minutes_to_time($time, 15);
					$last_sub_block = &$current_session['lane_config'][count($current_session['lane_config']) - 1];

					if ($last_sub_block['lanes_key'] === $lanes_key) {
						// La configuració de pistes no ha canviat, només estenem l'hora final del sub-bloc actual.
						$last_sub_block['end'] = $current_session['overall_end'];
					} else {
						// La configuració de pistes HA canviat. Tanquem el sub-bloc anterior i n'obrim un de nou.
						$current_session['lane_config'][] = [
							'start'      => $time,
							'end'        => $current_session['overall_end'],
							'lanes_list' => $lanes_this_slot_list,
							'lanes_key'  => $lanes_key
						];
					}
					unset($last_sub_block);
				} else {
					// Hi ha un salt en el temps o un canvi de pavelló. La sessió anterior ha acabat.
					$final_sessions[] = $current_session;
					// Comencem una nova sessió amb la franja actual.
					$current_session = [
						'pavilion_slug'  => $main_event_data['pavilion_slug'],
						'pavilion_label' => $main_event_data['pavilion_label'],
						'subitem'        => $main_event_data['subitem'],
						'overall_start'  => $time,
						'overall_end'    => sportic_add_minutes_to_time($time, 15),
						'lane_config'    => [
							[
								'start'      => $time,
								'end'        => sportic_add_minutes_to_time($time, 15),
								'lanes_list' => $lanes_this_slot_list,
								'lanes_key'  => $lanes_key
							]
						]
					];
				}
			}
		}

		// Afegim l'última sessió que estava en curs al final del bucle
		if ($current_session !== null) {
			$final_sessions[] = $current_session;
		}
		
		// Calculem la durada total de cada sessió final
		foreach ($final_sessions as &$session) {
			$start_dt = new DateTime($session['overall_start']);
			$end_dt = new DateTime($session['overall_end']);
			$diff = $start_dt->diff($end_dt);
			$session['duration_minutes'] = ($diff->h * 60) + $diff->i;
		}
		unset($session);

		return $final_sessions;
	}
}

if ( ! function_exists('sportic_find_and_build_sessions') ) {
	/**
	 * Analitza un mapa d'horaris i construeix sessions basades en blocs de cel·les
	 * contigües del mateix equip, creant una mini-graella per a cada sessió.
	 */
	function sportic_find_and_build_sessions($schedule_map, $piscines, $team_name, $start_date, $days = 7) {
		$sessions_by_day = [];
		$team_name_norm = mb_strtolower(trim($team_name));
		$startDateObj = new DateTime($start_date);

		for ($dayOffset = 0; $dayOffset < $days; $dayOffset++) {
			$currentDateObj = (clone $startDateObj)->modify("+$dayOffset days");
			$date_key = $currentDateObj->format('Y-m-d');
			$day_sessions = [];

			foreach ($piscines as $slug => $pool_info) {
				$day_schedule = $schedule_map[$slug][$date_key] ?? [];
				if (empty($day_schedule)) continue;

				$hores = array_keys($day_schedule);
				sort($hores);
				if (empty($hores)) continue;

				$num_carrils = $pool_info['lanes'] ?? 0;
				$lane_labels = $pool_info['lane_labels'] ?? [];
				if (empty($lane_labels)) {
					for ($li = 1; $li <= $num_carrils; $li++) $lane_labels[] = 'Pista ' . $li;
				}
				
				$visited = [];
				for ($r = 0; $r < count($hores); $r++) {
					for ($c = 0; $c < $num_carrils; $c++) {
						$hora = $hores[$r];
						if (isset($visited[$hora][$c])) continue;

						$valor_net = sportic_clean_schedule_value($day_schedule[$hora][$c] ?? 'l');
						$primary_token = mb_strtolower(sportic_extract_primary_token($valor_net));

						if ($primary_token !== $team_name_norm) {
							$visited[$hora][$c] = true;
							continue;
						}

						$current_session_cells = [];
						$queue = [[$r, $c]];
						$visited[$hora][$c] = true;

						while (!empty($queue)) {
							list($row_idx, $col_idx) = array_shift($queue);
							$current_hora = $hores[$row_idx];
							$current_session_cells[] = ['hora' => $current_hora, 'carril_idx' => $col_idx];

							$neighbors = [[$row_idx - 1, $col_idx], [$row_idx + 1, $col_idx], [$row_idx, $col_idx - 1], [$row_idx, $col_idx + 1]];
							foreach ($neighbors as $neighbor) {
								list($nr, $nc) = $neighbor;
								if ($nr >= 0 && $nr < count($hores) && $nc >= 0 && $nc < $num_carrils) {
									$neighbor_hora = $hores[$nr];
									if (!isset($visited[$neighbor_hora][$nc])) {
										$neighbor_val_net = sportic_clean_schedule_value($day_schedule[$neighbor_hora][$nc] ?? 'l');
										if (mb_strtolower(sportic_extract_primary_token($neighbor_val_net)) === $team_name_norm) {
											$visited[$neighbor_hora][$nc] = true;
											$queue[] = [$nr, $nc];
										}
									}
								}
							}
						}

						if (!empty($current_session_cells)) {
							$min_time = min(array_column($current_session_cells, 'hora'));
							$max_time = max(array_column($current_session_cells, 'hora'));
							$overall_end = sportic_add_minutes_to_time($max_time, 15);
							
							$occupied_lookup = [];
							foreach($current_session_cells as $cell) $occupied_lookup[$cell['hora']][$cell['carril_idx']] = true;

							$schedule_grid = [];
							$session_times = [];
							$temp_time = new DateTime($min_time);
							$end_time_obj = new DateTime($overall_end);
							while($temp_time < $end_time_obj){
								$current_slot = $temp_time->format('H:i');
								$session_times[] = $current_slot;
								for($i = 0; $i < $num_carrils; $i++){
									$schedule_grid[$current_slot][$i] = isset($occupied_lookup[$current_slot][$i]);
								}
								$temp_time->modify('+15 minutes');
							}
							
							$start_dt = new DateTime($min_time);
							$end_dt = new DateTime($overall_end);
							$duration = ($start_dt->diff($end_dt)->h * 60) + $start_dt->diff($end_dt)->i;

							$day_sessions[] = [
								'pavilion_label' => $pool_info['label'],
								'overall_start' => $min_time,
								'overall_end' => $overall_end,
								'duration_minutes' => $duration,
								'all_lanes_in_pavilion' => $lane_labels,
								'schedule_grid' => $schedule_grid
							];
						}
					}
				}
			}
			usort($day_sessions, function($a, $b) { return strcmp($a['overall_start'], $b['overall_start']); });
			$sessions_by_day[$date_key] = $day_sessions;
		}
		return $sessions_by_day;
	}
}

if ( ! function_exists('sportic_team_schedule_shortcode') ) {
function sportic_team_schedule_shortcode($atts) {
	$atts = shortcode_atts(['code' => '', 'title' => ''], $atts, 'sportic_team_schedule');
	
	$code = sanitize_title($atts['code']);
	if ($code === '') return '<div class="sportic-team-schedule-error">Cal indicar l\'atribut <code>code</code> al shortcode.</div>';
	
	$teams = sportic_get_custom_activities_indexed();
	if (!isset($teams[$code])) return '<div class="sportic-team-schedule-error">Equip no trobat. Revisa el codi del shortcode.</div>';
	
	$team = $teams[$code];
	$team_name  = $team['description'] ?? ucfirst($code);

	// Lògica per a la càrrega INICIAL de la primera setmana
	$current_week_start_obj = new DateTime(current_time('Y-m-d'));
	$current_week_start_obj->modify('monday this week');
	$start_date_str = isset($_GET['week_start']) ? sanitize_text_field($_GET['week_start']) : $current_week_start_obj->format('Y-m-d');
	try {
		$start_date_obj = new DateTime($start_date_str);
		$start_date_obj->modify('monday this week');
	} catch (Exception $e) { $start_date_obj = clone $current_week_start_obj; }
	if ($start_date_obj < $current_week_start_obj) { $start_date_obj = clone $current_week_start_obj; }
	
	$initial_display_week_start_str = $start_date_obj->format('Y-m-d');
	
	$piscines = sportic_unfile_get_pool_labels_sorted();
	$schedule_map = sportic_carregar_finestra_bd($initial_display_week_start_str, 6, 0);
	$sessions_by_day = sportic_find_and_build_sessions($schedule_map, $piscines, $team_name, $initial_display_week_start_str, 7);

	$shortcode_options = get_option('sporttic_shortcode_options', ['hide_empty_days' => '0']);
	$hide_empty_days = isset($shortcode_options['hide_empty_days']) && $shortcode_options['hide_empty_days'] === '1';

	$days_to_display = $sessions_by_day;
	if ($hide_empty_days) {
		$days_to_display = array_filter($sessions_by_day, function($day_sessions) {
			return !empty($day_sessions);
		});
	}

	$end_date_obj = (clone $start_date_obj)->modify('+6 days');
	$total_sessions = array_sum(array_map('count', $sessions_by_day));
	$week_range_label = 'Setmana del ' . $start_date_obj->format('d/m') . ' al ' . $end_date_obj->format('d/m');
	$hero_title = ($atts['title'] !== '') ? $atts['title'] : $team_name;
	$monthsCat = ['Gener','Febrer','Març','Abril','Maig','Juny','Juliol','Agost','Setembre','Octubre','Novembre','Desembre'];
	$daysCat   = ['Diumenge','Dilluns','Dimarts','Dimecres','Dijous','Divendres','Dissabte'];
	$now_label = current_time('d/m/Y H:i');
	$base_url = strtok($_SERVER["REQUEST_URI"], '?');
	
	$corporate_colors = get_option('sportic_shortcode_colors', ['main_color' => '#2563eb', 'accent_color' => '#2563eb']);
	$main_color = $corporate_colors['main_color'];
	$gradient_start = sportic_adjust_hex_color($main_color, -0.12);
	$gradient_end   = sportic_adjust_hex_color($main_color, 0.28);
	$accent_color   = $corporate_colors['accent_color'];
	$accent_light   = sportic_adjust_hex_color($accent_color, 0.92);
	$accent_text    = sportic_adjust_hex_color($accent_color, -0.45);
	$hero_text      = sportic_get_contrast_for_hex($main_color);
	$hero_icon_color = 'rgba(255,255,255,0.85)';

	static $css_printed = false;
	$css_output = '';
	if (!$css_printed) {
		$css_printed = true;
		$css_output .= '<style>
			.sportic-team-schedule{font-family:"Inter",system-ui,sans-serif;margin:40px auto;max-width:1140px;padding:0 20px;color:#0f172a;}
			.sportic-schedule-grid{transition: opacity 0.3s ease-in-out;}
			.sportic-schedule-grid.loading{opacity:0.4; pointer-events:none;}
			.sportic-team-hero{border-radius:24px;padding:32px 36px;color:#fff;box-shadow:0 20px 50px -10px rgba(15,23,42,0.2);position:relative;overflow:hidden;display:flex;flex-direction:column;gap:18px;}
			.sportic-team-hero .hero-pill{align-self:flex-start;background:rgba(255,255,255,0.15);padding:6px 16px;border-radius:999px;font-size:0.8rem;font-weight:600;}
			.sportic-team-hero h2{margin:0;font-size:clamp(2.2rem, 4vw, 3rem);font-weight:700;}
			.sportic-team-hero .hero-meta{display:flex;align-items:center;flex-wrap:wrap;gap:14px;color:rgba(255,255,255,0.85);font-size:0.95rem;font-weight:500;}
			.sportic-hero-stat{display:flex;align-items:center;gap:8px;padding:6px 14px;border-radius:12px;background:rgba(0,0,0,0.1);}
			.sportic-week-nav{display:flex;align-items:center;gap:12px;margin-left:auto;}
			.sportic-week-nav a{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;background:rgba(0,0,0,0.1);border-radius:50%;color:rgba(255,255,255,0.85);text-decoration:none;transition:background .2s;}
			.sportic-week-nav a:hover{background:rgba(0,0,0,0.25);color:white;}
			.sportic-week-nav a.disabled{opacity:0.5;cursor:not-allowed;pointer-events:none;}
			.sportic-schedule-grid{margin-top:32px;display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));}
			.sportic-day-card{background:#f8fafc;border-radius:20px;padding:24px;box-shadow:0 10px 30px -15px rgba(15,23,42,0.1);display:flex;flex-direction:column;gap:18px;border:1px solid #e2e8f0;}
			.sportic-day-card-header{display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;}
			.sportic-day-card .day-name{font-weight:700;font-size:1.5rem;color:#111827;}
			.sportic-day-card .day-date{font-size:1rem;color:#64748b;}
			.sportic-session-list{display:flex;flex-direction:column;gap:20px;flex-grow:1;}
			.sportic-session-block{background:#fff;border:1px solid #e9eef5;border-radius:16px;padding:18px;display:flex;flex-direction:column;gap:14px;box-shadow:0 4px 16px rgba(15,23,42,0.05);}
			.sportic-session-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;}
			.sportic-session-header-info{display:flex;flex-direction:column;gap:4px;}
			.sportic-session-header-info .pavilion{font-weight:700;font-size:1.1rem;color:#1e293b;line-height:1.3;}
			.sportic-session-header-info .time{font-size:0.95rem;color:#475569;}
			.sportic-session-duration{background:var(--accent-light);color:var(--accent-text);padding:5px 12px;border-radius:999px;font-size:0.8rem;font-weight:600;flex-shrink:0;}
			.sportic-session-grid-wrapper{margin-top:10px;border-top:1px solid #e2e8f0;padding-top:12px;}
			.sportic-session-table{width:100%;border-collapse:collapse;table-layout:fixed;}
			.sportic-session-table thead tr{height: 80px;}
			.sportic-session-table th, .sportic-session-table td{text-align:center;border:1px solid #e2e8f0;padding:0;vertical-align:middle;}
			.sportic-session-table td{height: 30px; line-height:30px;}
			.sportic-session-table th{font-weight:600;color:#334151;background-color:#f1f5f9;font-size:0.8rem;}
			.sportic-session-table th:not(:first-child){writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap;padding:8px 4px;vertical-align:bottom;}
			.sportic-session-table th:first-child, .sportic-session-table td.time-col{width:25%;}
			.sportic-session-table td.time-col{font-weight:600;color:#334151;background-color:#f8fafc;font-size:0.8rem;}
			.sportic-session-table td.occupied{background-color:var(--accent-color);color:var(--hero-text);font-size:1.2rem;font-weight:bold;}
			.sportic-session-table td.empty{background-color:#f1f5f9;}
			.sportic-day-card .no-session{flex-grow:1;display:flex;align-items:center;justify-content:center;text-align:center;color:#9ca3af;border:2px dashed #e5e7eb;border-radius:16px;padding:24px;}
			.sportic-schedule-footer{margin-top:32px;text-align:center;font-size:0.85rem;color:#6b7280;}
			@media(max-width:720px){
				.sportic-team-hero{padding:26px 24px;}
				.sportic-schedule-grid{gap:18px;}
				.sportic-hero-meta{flex-direction:column;align-items:flex-start;}
				.sportic-week-nav{margin-left:0;margin-top:10px;}
				.sportic-session-table thead tr { height: 140px; }
				.sportic-session-table th:not(:first-child) { writing-mode: initial; transform: none; padding: 0; vertical-align: middle; }
				.sportic-session-table .th-content-wrapper { transform: rotate(-90deg); white-space: nowrap; display: block; font-size: 0.85rem; }
			}
			/* ======================================================================== */
			/* INICI DEL CANVI: Afegeix un nou estil per a lelement fantasma */
			/* ======================================================================== */
			.sportic-day-card-placeholder { visibility: hidden; border: none; box-shadow: none; background: none; }
			/* ======================================================================== */
			/* FI DEL CANVI */
			/* ======================================================================== */
		</style>';
	}

	ob_start();
	echo $css_output;
	$hero_style = sprintf('background:linear-gradient(135deg,%s 0%%,%s 100%%); color:%s;', esc_attr($gradient_start), esc_attr($gradient_end), esc_attr($hero_text));
	?>
	<div class="sportic-team-schedule" id="sportic-schedule-<?php echo esc_attr($code); ?>" data-current-week="<?php echo esc_attr($initial_display_week_start_str); ?>" style="--accent-color:<?php echo esc_attr($main_color); ?>; --accent-light:<?php echo esc_attr($accent_light); ?>; --accent-text:<?php echo esc_attr($accent_text); ?>; --hero-text:<?php echo esc_attr($hero_text); ?>;">
		
		<section class="sportic-team-hero" style="<?php echo $hero_style; ?>">
			<h2><?php echo esc_html($hero_title); ?></h2>
			<div class="hero-meta">
				<span class="sportic-hero-stat week-range-label">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr($hero_icon_color); ?>" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
					<?php echo esc_html($week_range_label); ?>
				</span>
				<span class="sportic-hero-stat sessions-count-label">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr($hero_icon_color); ?>" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"></path><path d="m9 12 2 2 4-4"></path></svg>
					<?php echo esc_html($total_sessions); ?> sessions totals aquesta setmana
				</span>
				<div class="sportic-week-nav">
					<a href="#" class="sportic-nav-btn sportic-prev-week" title="Setmana Anterior">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
					</a>
					<a href="#" class="sportic-nav-btn sportic-next-week" title="Setmana Següent">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
					</a>
				</div>
			</div>
		</section>

		<section class="sportic-schedule-grid">
			<?php
			if (empty($days_to_display)) : ?>
				<article class="sportic-day-card">
					<div class="no-session">No hi ha entrenaments programats per a cap dia d'aquesta setmana.</div>
				</article>
			<?php else :
				foreach ($days_to_display as $date_key => $day_sessions):
					$dateObj = new DateTime($date_key);
					$day_name = $daysCat[(int)$dateObj->format('w')];
					$day_label = $dateObj->format('d') . ' de ' . ($monthsCat[(int)$dateObj->format('n') - 1] ?? '');
					?>
					<article class="sportic-day-card">
						<header class="sportic-day-card-header">
							<span class="day-name"><?php echo esc_html($day_name); ?></span>
							<span class="day-date"><?php echo esc_html($day_label); ?></span>
						</header>
						<div class="sportic-session-list">
							<?php if (!empty($day_sessions)): foreach ($day_sessions as $session): ?>
								<div class="sportic-session-block">
									<div class="sportic-session-header">
										<div class="sportic-session-header-info">
											<span class="pavilion"><?php echo esc_html($session['pavilion_label']); ?></span>
											<span class="time"><?php echo esc_html($session['overall_start'] . ' - ' . $session['overall_end']); ?></span>
										</div>
										<span class="sportic-session-duration"><?php echo esc_html(sportic_format_duration_label($session['duration_minutes'])); ?></span>
									</div>
									<div class="sportic-session-grid-wrapper">
										<table class="sportic-session-table">
											<thead>
												<tr>
													<th>Hora</th>
													<?php foreach($session['all_lanes_in_pavilion'] as $lane_label): ?>
														<th><div class="th-content-wrapper"><?php echo esc_html($lane_label); ?></div></th>
													<?php endforeach; ?>
												</tr>
											</thead>
											<tbody>
												<?php foreach($session['schedule_grid'] as $hora => $lanes_status): ?>
													<tr>
														<td class="time-col"><?php echo esc_html($hora); ?></td>
														<?php foreach($lanes_status as $is_occupied): ?>
															<td class="<?php echo $is_occupied ? 'occupied' : 'empty'; ?>">
																<?php if($is_occupied) echo '✓'; ?>
															</td>
														<?php endforeach; ?>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
								</div>
							<?php endforeach; else: ?>
								<div class="no-session">Sense entrenaments programats</div>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach;
			endif;

			// ========================================================================
			// INICI DEL CANVI: Lògica per afegir els elements fantasma
			// ========================================================================
			if ($hide_empty_days) {
				$visible_days_count = count($days_to_display);
				$grid_columns = 3; // Assumim un màxim de 3 columnes en escriptori per calcular els placeholders
				$placeholders_needed = ($visible_days_count > 0 && $visible_days_count % $grid_columns !== 0) ? $grid_columns - ($visible_days_count % $grid_columns) : 0;
				
				for ($i = 0; $i < $placeholders_needed; $i++) {
					echo '<div class="sportic-day-card-placeholder"></div>';
				}
			}
			// ========================================================================
			// FI DEL CANVI
			// ========================================================================
			?>
		</section>

		<footer class="sportic-schedule-footer">Dades actualitzades: <?php echo esc_html($now_label); ?></footer>
	</div>
	
	<script>
	(function() {
		window.addEventListener('load', function() {
			try {
				const scheduleId = 'sportic-schedule-<?php echo esc_js($code); ?>';
				const scheduleWrapper = document.getElementById(scheduleId);
				if (!scheduleWrapper) return;

				const config = {
					ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
					nonce: '<?php echo esc_js(wp_create_nonce('sportic_week_nonce')); ?>',
					teamCode: '<?php echo esc_js($code); ?>',
					shortcodeTitle: '<?php echo esc_js($atts['title']); ?>',
					baseUrl: '<?php echo esc_js($base_url); ?>',
					earliestWeek: '<?php echo esc_js($current_week_start_obj->format('Y-m-d')); ?>'
				};

				const nav = {
					prev: scheduleWrapper.querySelector('.sportic-prev-week'),
					next: scheduleWrapper.querySelector('.sportic-next-week')
				};

				const display = {
					grid: scheduleWrapper.querySelector('.sportic-schedule-grid'),
					weekRangeLabel: scheduleWrapper.querySelector('.week-range-label'),
					sessionsCountLabel: scheduleWrapper.querySelector('.sessions-count-label')
				};
				
				if(!nav.prev || !nav.next || !display.grid) return;
				
				function fetchAndRenderWeek(weekStartStr) {
					display.grid.classList.add('loading');

					const formData = new FormData();
					formData.append('action', 'sportic_get_week_html');
					formData.append('nonce', config.nonce);
					formData.append('week_start', weekStartStr);
					formData.append('team_code', config.teamCode);
					formData.append('shortcode_title', config.shortcodeTitle);

					fetch(config.ajaxUrl, { method: 'POST', body: formData })
					.then(response => {
						if (!response.ok) throw new Error(`Error de xarxa: ${response.statusText}`);
						return response.json();
					})
					.then(response => {
						if (response.success) {
							const data = response.data;
							display.grid.innerHTML = data.html;
							display.weekRangeLabel.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> ${data.week_label}`;
							display.sessionsCountLabel.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"></path><path d="m9 12 2 2 4-4"></path></svg> ${data.session_count} sessions totals aquesta setmana`;
							
							scheduleWrapper.dataset.currentWeek = weekStartStr;
							updateNavButtons(weekStartStr);
							
							const newUrl = `${config.baseUrl}?week_start=${weekStartStr}`;
							history.pushState({week: weekStartStr}, '', newUrl);
						} else {
							throw new Error(response.data.message || 'Error desconegut en la resposta AJAX.');
						}
					})
					.catch(error => {
						console.error('Error en la petició AJAX:', error);
						display.grid.innerHTML = `<p style="color:red; text-align:center;">Hi ha hagut un error al carregar les dades. Si us plau, intenta-ho de nou.</p>`;
					})
					.finally(() => {
						display.grid.classList.remove('loading');
					});
				}

				function updateNavButtons(currentWeek) {
					nav.prev.classList.toggle('disabled', currentWeek <= config.earliestWeek);
				}

				function getRelativeWeek(dateStr, weekOffset) {
					const dateParts = dateStr.split('-').map(part => parseInt(part, 10));
					const d = new Date(Date.UTC(dateParts[0], dateParts[1] - 1, dateParts[2]));
					d.setUTCDate(d.getUTCDate() + (weekOffset * 7));
					const year = d.getUTCFullYear();
					const month = String(d.getUTCMonth() + 1).padStart(2, '0');
					const day = String(d.getUTCDate()).padStart(2, '0');
					return `${year}-${month}-${day}`;
				}

				nav.prev.addEventListener('click', function(e) {
					e.preventDefault();
					if (this.classList.contains('disabled')) return;
					const currentWeek = scheduleWrapper.dataset.currentWeek;
					const prevWeek = getRelativeWeek(currentWeek, -1);
					fetchAndRenderWeek(prevWeek);
				});

				nav.next.addEventListener('click', function(e) {
					e.preventDefault();
					if (this.classList.contains('disabled')) return;
					const currentWeek = scheduleWrapper.dataset.currentWeek;
					const nextWeek = getRelativeWeek(currentWeek, 1);
					fetchAndRenderWeek(nextWeek);
				});
				
				updateNavButtons(scheduleWrapper.dataset.currentWeek);

			} catch(e) {
				console.error("SporTic: Error fatal en l'script de navegació.", e);
			}
		});
	})();
	</script>
	<?php
	return ob_get_clean();
}
}

if ( ! shortcode_exists('sportic_team_schedule') ) {
	add_shortcode('sportic_team_schedule', 'sportic_team_schedule_shortcode');
}
if ( ! shortcode_exists('sportic_team_schedule') ) {
	add_shortcode('sportic_team_schedule', 'sportic_team_schedule_shortcode');
}


/* =========================================================================
 * INICI FUNCIÓ – SUBSTITUEIX 1:1 LA VERSIÓ ACTUAL
 * =========================================================================*/
function sportic_emmagatzemar_tot_com_array( $megaArray ) {
	
	   /* ---------- 1. Inicialització bàsica ---------- */
	   global $wpdb;
   
	   $taula_prog = $wpdb->prefix . 'sportic_programacio';
	   // Definim només la taula de bloqueig, ignorem la d'excepcions
	   $taula_lock = defined( 'SPORTIC_LOCK_TABLE' ) ? $wpdb->prefix . SPORTIC_LOCK_TABLE : $wpdb->prefix . 'sportic_bloqueig';
   
	   if ( ! is_array( $megaArray ) || empty( $megaArray ) ) {
		   return;
	   }
   
	   $piscines_cfg   = sportic_unfile_get_pool_labels_sorted();
	   $slugs_permesos = array_keys( $piscines_cfg );
   
	   /* ---------- 2. Contenidors ---------- */
	   $prog_a_desar       = [];
	   $bloqueigs_nous     = [];
	   $setmanes_cache     = [];
	   $dies_processats    = [];
   
	   /* ---------- 3. Processament de l’entrada ---------- */
	   foreach ( $megaArray as $slug => $dies ) {
		   if ( ! in_array( $slug, $slugs_permesos, true ) || ! is_array( $dies ) ) {
			   continue;
		   }
   
		   foreach ( $dies as $data => $hores ) {
			   if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data ) ) continue;
			   
			   try {
				   $dt = new DateTime( $data );
				   $dt->modify( 'monday this week' );
				   $setmanes_cache[ $dt->format( 'Y-m-d' ) ] = true;
			   } catch ( Exception $e ) { /* ignorat */ }
   
			   $dies_processats[$slug][$data] = true;
   
			   if ( ! is_array( $hores ) ) continue;
   
			   $hores_netes = [];
			   foreach ( $hores as $hora => $carrils ) {
				   if ( ! preg_match( '/^\d{2}:\d{2}$/', $hora ) || ! is_array( $carrils ) ) continue;
   
				   $carrils_nets = [];
				   foreach ( $carrils as $idx => $val_nou_raw ) {
					   
					   // Neteja total: eliminem qualsevol prefix antic per obtenir el valor real
					   $valor_string = (string) $val_nou_raw;
					   $valor_net_a_desar = preg_replace('/^[@!]/', '', $valor_string);
 
					   if ($valor_net_a_desar === '' || $valor_net_a_desar === false) {
						   $valor_net_a_desar = 'l';
					   }
					   
					   // 1. Guardem el valor net a la programació
					   $carrils_nets[$idx] = $valor_net_a_desar;
					   
					   // 2. Si l'usuari ha posat un bloqueig (!), el guardem a la taula de bloqueig
					   if (strpos($valor_string, '!') === 0) {
						   $bloqueigs_nous[] = [
							   'piscina_slug' => $slug, 
							   'dia_data' => $data,
							   'hora' => $hora, 
							   'carril_index' => (int) $idx
						   ];
					   }
				   }
				   $hores_netes[ $hora ] = $carrils_nets;
			   }
			   $prog_a_desar[ $slug ][ $data ] = $hores_netes;
		   }
	   }
   
	   /* ---------- 4. Guardem la programació ---------- */
	   foreach ( $prog_a_desar as $slug => $dies ) {
		   foreach ( $dies as $data => $hores_arr ) {
			   $serial = maybe_serialize( $hores_arr );
			   $wpdb->replace($taula_prog, ['piscina_slug' => $slug, 'dia_data' => $data, 'hores_serial' => $serial], ['%s','%s','%s']);
		   }
	   }
   
	   /* ---------- 5. Gestió dels bloquejos ---------- */
	   // Primer esborrem els bloquejos antics dels dies que hem tocat
	   foreach ( $dies_processats as $slug => $dies ) {
		   foreach ( array_keys( $dies ) as $data ) {
			   $wpdb->delete( $taula_lock, [ 'piscina_slug' => $slug, 'dia_data' => $data ], [ '%s', '%s' ] );
		   }
	   }
	   // Després inserim els nous
	   foreach ( $bloqueigs_nous as $fila ) {
		   $wpdb->replace( $taula_lock, $fila, [ '%s', '%s', '%s', '%d' ] );
	   }
   
	   /* ---------- 6. Purga de memòria cau (ADAPTAT PER A MULTILLOC) ---------- */
	   // Obtenim el 'lloc' del formulari per netejar la memòria cau correcta.
	   $lloc_slug_context_for_cache = isset($_POST['lloc']) ? sanitize_key($_POST['lloc']) : '';
  
	   foreach ( array_keys( $setmanes_cache ) as $dia_inici_setmana ) {
		   // NOMÉS esborrem el transient setmanal si sabem exactament QUIN 'lloc' s'està desant.
		   if (!empty($lloc_slug_context_for_cache)) {
			   delete_transient( 'sportic_week_data_' . $lloc_slug_context_for_cache . '_' . $dia_inici_setmana );
		   }
	   }
 
	   wp_cache_delete( 'sportic_all_data_with_locks', 'sportic_data' );
	   if (function_exists('sportic_clear_csv_transients')) {
		   sportic_clear_csv_transients();
	   }
   }

 /* =========================================================================
 * FI FUNCIÓ – NO TOQUIS RES PER SOTA AQUEST COMENTARI
 * =========================================================================*/

 
	
	
	// 🔧 Carreguem Font Awesome correctament
	function sportic_carregar_font_awesome() {
		wp_enqueue_style(
			'font-awesome',
			'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
			array(), // dependències
			'6.5.0'  // versió
		);
	}
	add_action('admin_enqueue_scripts', 'sportic_carregar_font_awesome'); // Si és per l'àrea d'admin
	add_action('wp_enqueue_scripts', 'sportic_carregar_font_awesome');     // Si és per la part pública
	
	/**
	* ----------------------------------------------------------------------------
	*  AFEGIM LA NOVA SECCIÓ DE CONFIGURACIÓ
	* ----------------------------------------------------------------------------
	*/
	
	// Enqueue the external CSS file
	function sportic_enqueue_styles() {
		wp_enqueue_style(
			'sportic-style', // Handle for the stylesheet
			plugins_url('css/sportic.css', __FILE__), // Path to the CSS file
			array(), // Dependencies (if any)
			'1.0.0' // Version number
		);
	}
	add_action('wp_enqueue_scripts', 'sportic_enqueue_styles');
	
	
	/**
	* Registre de submenu "Configuració" dins el menú "SporTIC"
	*/
	add_action('admin_menu', 'sportic_unfile_admin_menu');
function sportic_unfile_admin_menu() {
		// Pàgina principal (existent):
		add_menu_page(
			'SporTIC',
			'SporTIC',
			'manage_options',
			'sportic-onefile-menu',
			'sportic_unfile_mostrar_pagina'
		);
	
		// Submenú de Plantilles (existent):
		add_submenu_page(
			'sportic-onefile-menu',
			'Plantilles',
			'Plantilles',
			'manage_options',
			'sportic-onefile-templates',
			'sportic_unfile_plantilles_page'
		);
	
		// Submenú de Configuració (existent):
		add_submenu_page(
			'sportic-onefile-menu',
			'Configuració',
			'Configuració',
			'manage_options',
			'sportic-onefile-settings',
			'sportic_unfile_settings_page'
		);
	}


















	   
 
  






  
  
 
  
  
   
   
/* =========================================================================
	* INICI FUNCIÓ CORREGIDA – SUBSTITUEIX L'ORIGINAL
	* Aquesta versió llegeix la descripció de l'activitat desada i
	* la prepara correctament per aplicar-la a la graella.
	* =========================================================================*/
function sportic_get_all_recurrent_events_map() {
		// Retornem un array buit per desactivar completament els esdeveniments recurrents
		return [];
	}
/**
	* ----------------------------------------------------------------------------
	*  AFEGIM LA NOVA SECCIÓ DE CONFIGURACIÓ
	* ----------------------------------------------------------------------------
	*/
	
function sportic_unfile_settings_page() {
		if ( ! current_user_can('manage_options') ) {
			return;
		}
	
		$action_processed_this_request = false;
	
		if ( isset($_POST['sportic_clear_cache_manual_submit']) && isset($_POST['sportic_clear_cache_nonce']) ) {
			check_admin_referer( 'sportic_clear_cache_action', 'sportic_clear_cache_nonce' );
			
			global $wpdb;
	
			$prefix_week = '_transient_sportic_week_data_';
			$prefix_csv = '_transient_sportic_csv_data_';
			$timeout_prefix_week = '_transient_timeout_sportic_week_data_';
			$timeout_prefix_csv = '_transient_timeout_sportic_csv_data_';
			
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like($prefix_week) . '%',
				$wpdb->esc_like($timeout_prefix_week) . '%'
			) );
	
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like($prefix_csv) . '%',
				$wpdb->esc_like($timeout_prefix_csv) . '%'
			) );
	
			wp_cache_delete('sportic_all_data_with_locks', 'sportic_data');
			
			echo '<div class="updated notice"><p>La memòria cau del plugin s\'ha netejat de manera forçada. Els esdeveniments fantasma haurien d\'haver desaparegut.</p></div>';
			$action_processed_this_request = true;
		}
	
		if ( !$action_processed_this_request && isset($_POST['clean_schedule']) && isset($_POST['sportic_clean_schedule_nonce_field']) ) {
			check_admin_referer( 'sportic_clean_schedule_nonce_action', 'sportic_clean_schedule_nonce_field' );
			global $wpdb;
			$nomTaulaProgramacio = $wpdb->prefix . 'sportic_programacio';
			$nomTaulaBloqueig = $wpdb->prefix . (defined('SPORTIC_LOCK_TABLE') ? SPORTIC_LOCK_TABLE : 'sportic_bloqueig');
			$wpdb->query("DELETE FROM $nomTaulaProgramacio");
			$wpdb->query("DELETE FROM $nomTaulaBloqueig");
			if (function_exists('wp_cache_delete')) { wp_cache_delete('sportic_all_data_with_locks', 'sportic_data'); }
			if (function_exists('sportic_clear_all_week_transients')) { sportic_clear_all_week_transients(); }
			echo '<div class="updated notice"><p>La programació s\'ha netejat completament.</p></div>';
			$action_processed_this_request = true;
		}
	
		if ( !$action_processed_this_request && isset($_POST['delete_year']) && isset($_POST['year_to_delete']) && isset($_POST['sportic_delete_year_nonce_field']) ) {
			check_admin_referer( 'sportic_delete_year_nonce_action', 'sportic_delete_year_nonce_field' );
			$year_to_delete_action = sanitize_text_field( $_POST['year_to_delete'] );
			if ( ! preg_match('/^\d{4}$/', $year_to_delete_action) ) {
				echo '<div class="error notice"><p>El format de l\'any no és vàlid.</p></div>';
			} else {
				global $wpdb;
				$nomTaulaProgramacio = $wpdb->prefix . 'sportic_programacio';
				$nomTaulaBloqueig = $wpdb->prefix . (defined('SPORTIC_LOCK_TABLE') ? SPORTIC_LOCK_TABLE : 'sportic_bloqueig');
				$start_of_year = $year_to_delete_action . '-01-01';
				$end_of_year = $year_to_delete_action . '-12-31';
				$wpdb->query( $wpdb->prepare( "DELETE FROM $nomTaulaProgramacio WHERE dia_data BETWEEN %s AND %s", $start_of_year, $end_of_year ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM $nomTaulaBloqueig WHERE dia_data BETWEEN %s AND %s", $start_of_year, $end_of_year ) );
				if (function_exists('wp_cache_delete')) { wp_cache_delete('sportic_all_data_with_locks', 'sportic_data'); }
				if (function_exists('sportic_clear_all_week_transients')) { sportic_clear_all_week_transients(); }
				echo '<div class="updated notice"><p>Dades de l\'any ' . esc_html($year_to_delete_action) . ' eliminades correctament.</p></div>';
			}
			$action_processed_this_request = true;
		}
	
		if ( !$action_processed_this_request && isset($_POST['sportic_main_settings_submit_button']) && isset($_POST['sportic_nonce']) ) {
			if (wp_verify_nonce($_POST['sportic_nonce'], 'sportic_save_settings')) {
				$feedback_messages = [];
				$settings_changed = false;
				
				if ( isset($_POST['sportic_colors']) && is_array($_POST['sportic_colors']) ) {
					$colors_input = $_POST['sportic_colors'];
					$clean_colors = [
						'main_color' => sanitize_hex_color($colors_input['main_color'] ?? '#2563eb'),
						'accent_color' => sanitize_hex_color($colors_input['accent_color'] ?? '#2563eb'),
					];
					$old_colors = get_option('sportic_shortcode_colors', []);
					if (serialize($old_colors) !== serialize($clean_colors)) {
						update_option('sportic_shortcode_colors', $clean_colors);
						$feedback_messages[] = 'Colors corporatius del shortcode desats correctament!';
						$settings_changed = true;
					}
				}
	
				// ========================================================================
				// INICI DE LA LÒGICA PER DESAR LA NOVA OPCIÓ
				// ========================================================================
				if ( isset($_POST['sporttic_shortcode_options']) ) {
					$options_input = $_POST['sporttic_shortcode_options'];
					$clean_options = [
						// Si la casella està marcada, rebem '1'. Si no, no rebem res.
						'hide_empty_days' => isset($options_input['hide_empty_days']) ? '1' : '0'
					];
					$old_options = get_option('sporttic_shortcode_options', []);
					if (serialize($old_options) !== serialize($clean_options)) {
						update_option('sporttic_shortcode_options', $clean_options);
						$feedback_messages[] = 'Opcions del shortcode desades correctament!';
						$settings_changed = true;
					}
				} else {
					// Si no rebem 'sporttic_shortcode_options', significa que la casella no està marcada
					// i hem d'assegurar que es desa com a '0'.
					$old_options = get_option('sporttic_shortcode_options', []);
					if (!empty($old_options['hide_empty_days']) && $old_options['hide_empty_days'] === '1') {
						update_option('sporttic_shortcode_options', ['hide_empty_days' => '0']);
						$feedback_messages[] = 'Opcions del shortcode desades correctament!';
						$settings_changed = true;
					}
				}
				// ========================================================================
				// FI DE LA LÒGICA PER DESAR
				// ========================================================================
	
				if ( isset($_POST['sportic_opening_hours_start']) && isset($_POST['sportic_opening_hours_end']) ) { $start_h = sanitize_text_field($_POST['sportic_opening_hours_start']); $end_h = sanitize_text_field($_POST['sportic_opening_hours_end']); $old_hours = get_option('sportic_unfile_opening_hours', array('start' => '16:00', 'end' => '23:00')); if ($old_hours['start'] !== $start_h || $old_hours['end'] !== $end_h) { update_option('sportic_unfile_opening_hours', array('start' => $start_h, 'end' => $end_h)); $feedback_messages[] = 'Rangs horaris desats correctament!'; $settings_changed = true; } }
				
				if ( isset($_POST['sportic_custom_activities']) && is_array($_POST['sportic_custom_activities']) ) {
					$activities_input = $_POST['sportic_custom_activities'];
					$clean_activities_to_save = [];
					$descriptions_used = [];
					$shortcodes_used = [];
					$error_found = false;
					$error_message = '';
	
					foreach ($activities_input as $activity_row) {
						if ( !isset($activity_row['description']) || !isset($activity_row['color']) ) continue;
						$description = trim(stripslashes(sanitize_text_field($activity_row['description'])));
						$color = sanitize_hex_color($activity_row['color']);
						$raw_shortcode = isset($activity_row['shortcode']) ? sanitize_text_field($activity_row['shortcode']) : '';
	
						if ($description === '') continue;
	
						if (in_array(strtolower($description), array_map('strtolower', $descriptions_used))) {
							$error_found = true;
							$error_message = "La descripció '" . esc_html($description) . "' està duplicada. No es poden repetir. No s'ha desat res.";
							break;
						}
	
						$shortcode = sanitize_title($raw_shortcode !== '' ? $raw_shortcode : $description);
						if ($shortcode === '') {
							$shortcode = sanitize_title($description);
						}
						$base_shortcode = $shortcode;
						$counter = 2;
						while (in_array($shortcode, $shortcodes_used, true)) {
							$shortcode = $base_shortcode . '-' . $counter;
							$counter++;
						}
	
						if ($color) {
							$descriptions_used[] = $description;
							$shortcodes_used[] = $shortcode;
							$clean_item = [
								'description' => $description,
								'color'       => $color,
								'shortcode'   => $shortcode,
							];
							if (isset($activity_row['letter']) && $activity_row['letter'] !== '') {
								$clean_item['letter'] = sanitize_text_field($activity_row['letter']);
							} else {
								$clean_item['letter'] = $description;
							}
							if (isset($activity_row['subitems'])) {
								$clean_item['subitems'] = $activity_row['subitems'];
							}
							$clean_activities_to_save[] = $clean_item;
						}
					}
	
					if ($error_found) {
						echo '<div class="error notice"><p>' . esc_html($error_message) . '</p></div>';
					} else {
						$old_custom_activities = get_option('sportic_unfile_custom_letters', array());
						if (serialize($old_custom_activities) !== serialize($clean_activities_to_save)) {
							update_option('sportic_unfile_custom_letters', $clean_activities_to_save);
							$feedback_messages[] = 'Activitats personalitzades desades correctament!';
							$settings_changed = true;
						}
					}
				}
	
				if ( isset($_POST['sportic_pool_labels']) && is_array($_POST['sportic_pool_labels']) ) { $raw_posted_pool_labels_form = $_POST['sportic_pool_labels']; $pool_settings_changed_flag = false; if (function_exists('sportpavellons_get_pools') && defined('sportpavellons_CONFIG_OPTION_NAME')) { $current_sportpavellons_config_save = get_option(sportpavellons_CONFIG_OPTION_NAME, []); if (!is_array($current_sportpavellons_config_save)) $current_sportpavellons_config_save = []; $updated_sportpavellons_config_save = $current_sportpavellons_config_save; foreach ($raw_posted_pool_labels_form as $slug_from_form_save => $data_from_form_save) { $clean_slug_form = sanitize_key($slug_from_form_save); if (isset($updated_sportpavellons_config_save[$clean_slug_form]) && is_array($data_from_form_save)) { if (isset($data_from_form_save['label']) && $updated_sportpavellons_config_save[$clean_slug_form]['name'] !== sanitize_text_field($data_from_form_save['label'])) { $updated_sportpavellons_config_save[$clean_slug_form]['name'] = sanitize_text_field($data_from_form_save['label']); $pool_settings_changed_flag = true; } if (isset($data_from_form_save['order']) && $updated_sportpavellons_config_save[$clean_slug_form]['order'] !== intval($data_from_form_save['order'])) { $updated_sportpavellons_config_save[$clean_slug_form]['order'] = intval($data_from_form_save['order']); $pool_settings_changed_flag = true; } } } if ($pool_settings_changed_flag) { update_option(sportpavellons_CONFIG_OPTION_NAME, $updated_sportpavellons_config_save); $feedback_messages[] = 'Noms i ordre de camps desats correctament!'; $settings_changed = true; } } else { $cleanPoolLabels_local_save = array(); $allowedSlugs_local_save = array_keys(sportic_unfile_get_pool_labels_sorted()); foreach ($allowedSlugs_local_save as $slug_local_form) { if ( isset($raw_posted_pool_labels_form[$slug_local_form]) && is_array($raw_posted_pool_labels_form[$slug_local_form]) ) { $lbl_local_form = sanitize_text_field( $raw_posted_pool_labels_form[$slug_local_form]['label'] ); $ord_local_form = intval($raw_posted_pool_labels_form[$slug_local_form]['order']); $cleanPoolLabels_local_save[$slug_local_form] = array('label' => $lbl_local_form, 'order' => $ord_local_form); } } if (serialize(get_option('sportic_unfile_pool_labels', array())) !== serialize($cleanPoolLabels_local_save)) { update_option('sportic_unfile_pool_labels', $cleanPoolLabels_local_save); $feedback_messages[] = 'Noms i ordre de camps desats correctament!'; $settings_changed = true; } } }
				if ($settings_changed) { if (function_exists('wp_cache_delete')) { wp_cache_delete('sportic_all_data_with_locks', 'sportic_data'); } if (function_exists('sportic_clear_all_week_transients')) { sportic_clear_all_week_transients(); } }
				if (!empty($feedback_messages)) { echo '<div class="updated notice"><p>' . implode('<br>', $feedback_messages) . '</p></div>'; } 
				elseif (!$settings_changed && !$action_processed_this_request && isset($_POST['sportic_main_settings_submit_button'])) { echo '<div class="info notice"><p>No s\'han detectat canvis a la configuració.</p></div>'; }
			} else { if (isset($_POST['sportic_nonce'])) echo '<div class="error notice"><p>Error de seguretat (nonce invàlid).</p></div>'; }
		}
	
		$hours_view = get_option('sportic_unfile_opening_hours', array('start' => '16:00', 'end' => '23:00')); $currentStart_view = $hours_view['start']; $currentEnd_view = $hours_view['end']; $timeSlots_view = array(); 
		try { 
			$startTime_dt_view = new DateTime('00:00'); 
			$endTime_dt_view = new DateTime('24:00'); 
			$interval_dt_view = new DateInterval('PT15M');
			for ($t_ts_view = clone $startTime_dt_view; $t_ts_view < $endTime_dt_view; $t_ts_view->add($interval_dt_view)) { 
				$timeSlots_view[] = $t_ts_view->format('H:i'); 
			} 
		} catch(Exception $e) {
			$timeSlots_view = [];
		}
		$customActivities_view = get_option('sportic_unfile_custom_letters', array());
		$poolLabelsConfig_view = sportic_unfile_get_pool_labels_sorted();
		
		// Carreguem els colors corporatius guardats
		$corporate_colors = get_option('sportic_shortcode_colors', [
			'main_color' => '#2563eb',
			'accent_color' => '#2563eb',
		]);
	
		// ========================================================================
		// INICI DE LA LÒGICA PER LLEGIR LA NOVA OPCIÓ
		// ========================================================================
		$shortcode_options = get_option('sporttic_shortcode_options', ['hide_empty_days' => '0']);
		$hide_empty_days_checked = isset($shortcode_options['hide_empty_days']) && $shortcode_options['hide_empty_days'] === '1';
		// ========================================================================
		// FI DE LA LÒGICA PER LLEGIR
		// ========================================================================
	
		?>
		<div class="wrap sportic-settings">
			<h1 class="sportic-title">🛠 Configuració d'Horaris d'Entrenaments</h1>
			
			<form method="post" class="sportic-form">
				<?php wp_nonce_field('sportic_save_settings', 'sportic_nonce'); ?>
				<div class="card"><h2 class="title">⏰ Rangs Horaris</h2><div class="time-range-container"><div class="time-picker"><label for="sportic_opening_hours_start">Hora d'inici</label><div class="custom-select"><select id="sportic_opening_hours_start" name="sportic_opening_hours_start"><?php foreach ($timeSlots_view as $slot_item_view) : ?><option value="<?php echo esc_attr($slot_item_view); ?>" <?php selected($slot_item_view, $currentStart_view); ?>><?php echo esc_html($slot_item_view); ?></option><?php endforeach; ?></select></div></div><div class="time-picker"><label for="sportic_opening_hours_end">Hora de tancament</label><div class="custom-select"><select id="sportic_opening_hours_end" name="sportic_opening_hours_end"><?php foreach ($timeSlots_view as $slot_item_view) : ?><option value="<?php echo esc_attr($slot_item_view); ?>" <?php selected($slot_item_view, $currentEnd_view); ?>><?php echo esc_html($slot_item_view); ?></option><?php endforeach; ?></select></div></div></div><p class="description">📌 Només es mostraran les hores dins d'aquest interval.</p></div>
	
				<div class="card">
					<h2 class="title">🎨 Colors Corporatius del Shortcode</h2>
					<p class="intro-text">Defineix aquí els colors principals per al shortcode <code>[sportic_team_schedule]</code>. Aquests colors s'aplicaran a tots els horaris d'equip.</p>
					<div class="corporate-colors-grid">
						<div class="color-picker-wrapper">
							<label for="sportic_main_color">Color Principal</label>
							<input type="color" id="sportic_main_color" name="sportic_colors[main_color]" value="<?php echo esc_attr($corporate_colors['main_color']); ?>">
							<p class="description">S'utilitza per al fons de la capçalera i les cel·les actives de la graella.</p>
						</div>
						<div class="color-picker-wrapper">
							<label for="sportic_accent_color">Color d'Accent</label>
							<input type="color" id="sportic_accent_color" name="sportic_colors[accent_color]" value="<?php echo esc_attr($corporate_colors['accent_color']); ?>">
							<p class="description">S'utilitza per a detalls com la duració de la sessió.</p>
						</div>
					</div>
				</div>
	
				<!-- ======================================================================== -->
				<!-- NOU BLOC: Opcions del Shortcode -->
				<!-- ======================================================================== -->
				<div class="card">
					<h2 class="title">⚙️ Opcions del Shortcode</h2>
					<p class="intro-text">Configura el comportament del shortcode <code>[sportic_team_schedule]</code>.</p>
					<div class="form-field-wrapper">
						<label for="hide_empty_days_checkbox">
							<input type="checkbox" id="hide_empty_days_checkbox" name="sporttic_shortcode_options[hide_empty_days]" value="1" <?php checked($hide_empty_days_checked); ?>>
							Amagar dies sense entrenaments
						</label>
						<p class="description">Si marques aquesta opció, a la vista pública de la setmana només es mostraran els dies que tinguin alguna sessió programada per a l'equip.</p>
					</div>
				</div>
				<!-- ======================================================================== -->
				<!-- FI DEL NOU BLOC -->
				<!-- ======================================================================== -->
				
				<div class="card sportic-activities-card">
					<h2 class="title">🎨 Equips i Activitats</h2>
					<p class="intro-text">Defineix aquí els equips o activitats que apareixeran a l'horari. El nom de l'activitat no es pot repetir.</p>
					
					<div id="sportic-activity-list" class="sportic-activity-list">
						<?php if (!empty($customActivities_view) && is_array($customActivities_view)) : foreach ($customActivities_view as $index_cl_view => $item_cl_view) :
							$description_val = stripslashes($item_cl_view['description'] ?? '');
							$shortcode_val   = $item_cl_view['shortcode'] ?? sanitize_title($description_val);
							$color_val       = $item_cl_view['color'] ?? '#2563eb';
						?>
						<div class="activity-item" data-index="<?php echo esc_attr($index_cl_view); ?>">
							<div class="activity-details">
								<input type="text" class="activity-description" name="sportic_custom_activities[<?php echo $index_cl_view; ?>][description]" value="<?php echo esc_attr($description_val); ?>" placeholder="Nom de l'activitat" required>
								<div class="activity-shortcode-wrapper">
									<input type="text" class="activity-shortcode" name="sportic_custom_activities[<?php echo $index_cl_view; ?>][shortcode]" value="<?php echo esc_attr($shortcode_val); ?>" placeholder="shortcode-opcional">
									<small class="shortcode-hint">Shortcode per a vistes públiques: <code>[sportic_team_schedule code="<?php echo esc_html($shortcode_val ?: sanitize_title($description_val ?: 'equip')); ?>"]</code></small>
								</div>
							</div>
							<div class="activity-color">
								<input type="color" name="sportic_custom_activities[<?php echo $index_cl_view; ?>][color]" value="<?php echo esc_attr($color_val); ?>">
							</div>
							<div class="activity-actions">
								<button type="button" class="button-icon-delete sportic-remove-activity-item" aria-label="Elimina aquesta activitat"><span class="dashicons dashicons-trash"></span></button>
							</div>
						</div>
						<?php endforeach; endif; ?>
	
						<div class="activity-list-empty-state" style="display: none;">
							<p>Encara no hi ha activitats definides. Afegeix-ne una per començar.</p>
						</div>
					</div>
	
					<div class="sportic-activities-actions">
						<button type="button" class="button button-primary" id="add-custom-activity-item">➕ Afegir Nova Activitat</button>
					</div>
	
					<template id="activity-item-template">
						<div class="activity-item" data-index="__INDEX__">
							<div class="activity-details">
								<input type="text" class="activity-description" name="sportic_custom_activities[__INDEX__][description]" value="" placeholder="Nom de l'activitat" required>
								<div class="activity-shortcode-wrapper">
									<input type="text" class="activity-shortcode" name="sportic_custom_activities[__INDEX__][shortcode]" value="" placeholder="shortcode-opcional">
									<small class="shortcode-hint">Shortcode per a vistes públiques: <code>[sportic_team_schedule code=""]</code></small>
								</div>
							</div>
							<div class="activity-color">
								<input type="color" name="sportic_custom_activities[__INDEX__][color]" value="#2563eb">
							</div>
							<div class="activity-actions">
								<button type="button" class="button-icon-delete sportic-remove-activity-item" aria-label="Elimina aquesta activitat"><span class="dashicons dashicons-trash"></span></button>
							</div>
						</div>
					</template>
				</div>
				
				<div class="card"><h2 class="title">🏟 Camps i Pavellons</h2><p class="intro-text">Configura el nom i l'ordre de les instal·lacions.</p><table class="wp-list-table widefat"><thead><tr><th style="width:20%">ID (Slug)</th><th style="width:40%">Nom Visible</th><th style="width:15%">Ordre</th><th style="width:25%">Pistes Definides</th></tr></thead><tbody id="piscines-table"><?php if (empty($poolLabelsConfig_view)): ?><tr><td colspan="4">No hi ha camps configurats.</td></tr><?php else: foreach ($poolLabelsConfig_view as $slug_plc_view => $info_plc_view) : ?><tr data-slug="<?php echo esc_attr($slug_plc_view); ?>"><td><code><?php echo esc_html($slug_plc_view); ?></code></td><td><input type="text" name="sportic_pool_labels[<?php echo esc_attr($slug_plc_view); ?>][label]" value="<?php echo esc_attr($info_plc_view['label']); ?>" class="widefat"></td><td><input type="number" name="sportic_pool_labels[<?php echo esc_attr($slug_plc_view); ?>][order]" value="<?php echo intval($info_plc_view['order']); ?>" class="order-input"></td><td><?php echo isset($info_plc_view['lanes']) ? intval($info_plc_view['lanes']) : 'N/D'; ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
				
				<?php submit_button('💾 Desa els Canvis', 'primary large', 'sportic_main_settings_submit_button'); ?>
			</form>
	
			<div class="card collapsible-section"><h2 class="title" style="cursor:pointer;" id="toggleCacheManagement">⚡ Gestió de la Memòria Cau<span class="dashicons dashicons-arrow-down-alt2" id="arrowCacheManagement"></span></h2><div id="contentCacheManagement" style="display: none;"><div class="avis avis-general"><p><strong>AVÍS:</strong> Fes servir aquest botó si has esborrat un esdeveniment recurrent i encara apareix a les taules, o si les dades no es refresquen correctament després d'un canvi. Aquesta acció és segura i no esborra cap dada de la teva programació.</p></div><form method="post" action=""><?php wp_nonce_field( 'sportic_clear_cache_action', 'sportic_clear_cache_nonce' ); ?><button type="submit" name="sportic_clear_cache_manual_submit" class="button button-secondary" onclick="return confirm('Estàs segur de voler netejar tota la memòria cau del plugin?');"><span class="dashicons dashicons-trash" style="vertical-align: text-bottom;"></span> Forçar Neteja de la Memòria Cau</button></form></div></div>
			<div class="card collapsible-section"><h2 class="title" style="cursor:pointer;" id="toggleGestionarAnys">Gestionar Anys de Dades<span class="dashicons dashicons-arrow-down-alt2" id="arrowGestionarAnys"></span></h2><div class="avis avis-general"><p><strong>AVÍS:</strong> Eliminar dades d’un any és permanent. Fes una còpia de seguretat abans.</p></div><div id="contentGestionarAnys" style="display: none;"><p>Elimina totes les dades d’un any concret per optimitzar.</p><?php global $wpdb; $nomTaulaProgAnys = $wpdb->prefix . 'sportic_programacio'; $years_list_from_db = $wpdb->get_col("SELECT DISTINCT YEAR(dia_data) FROM {$nomTaulaProgAnys} ORDER BY YEAR(dia_data) DESC"); if ( empty($years_list_from_db) ) { echo '<p>No hi ha dades per cap any.</p>'; } else { ?><table class="widefat striped"><thead><tr><th>Any</th><th>Accions</th></tr></thead><tbody><?php foreach ($years_list_from_db as $any_item_view): ?><tr><td><?php echo esc_html($any_item_view); ?></td><td><form method="post" action="" style="display:inline;"><?php wp_nonce_field( 'sportic_delete_year_nonce_action', 'sportic_delete_year_nonce_field' ); ?><input type="hidden" name="year_to_delete" value="<?php echo esc_attr($any_item_view); ?>"><input type="submit" name="delete_year" class="button button-danger" value="Eliminar aquest any" onclick="return confirm('Segur de voler eliminar totes les dades de l\'any <?php echo esc_js($any_item_view); ?>? Aquesta acció no es pot desfer.');"></form></td></tr><?php endforeach; ?></tbody></table><?php } ?></div></div>
			<div class="card collapsible-section"><h2 class="title" style="cursor:pointer;" id="toggleNetejaTotal">Neteja Total de la Programació<span class="dashicons dashicons-arrow-down-alt2" id="arrowNetejaTotal"></span></h2><div class="avis avis-critic"><p><strong>AVÍS CRÍTIC:</strong> Aquesta operació esborrarà TOTES les dades de programació. Fes una còpia de seguretat abans.</p></div><div id="contentNetejaTotal" style="display: none;"><p>Prem aquest botó per eliminar totes les cel·les de programació.</p><form method="post" action=""><?php wp_nonce_field( 'sportic_clean_schedule_nonce_action', 'sportic_clean_schedule_nonce_field' ); ?><input type="submit" name="clean_schedule" class="button button-danger" value="Netejar Tota la Programació" onclick="return confirm('ATENCIÓ: Estàs a punt d\'esborrar totes les dades. Aquesta acció no es pot desfer. Vols continuar?');"></form></div></div>
		</div>
		<style>
		.sportic-settings { padding: 30px 40px; max-width: 1200px !important; margin: 0 auto; box-sizing: border-box; }
		.card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 30px; padding: 35px 40px; max-width: 1000px !important; }
		.title { font-size: 24px; margin: 0 0 30px; color: #1a1a1a; display: flex; align-items: center; gap: 15px; padding-bottom: 15px; border-bottom: 2px solid #f0f0f1; }
		.time-range-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 40px; margin: 25px 0; }
		.custom-select { position: relative; width: 100%; margin-top: 8px; }
		.custom-select select { width: 100%; padding: 12px 20px; border: 2px solid #c3c4c7; border-radius: 8px; appearance: none; font-size: 16px; background: #fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="%23666" d="M7 10l5 5 5-5z"/></svg>') no-repeat right 12px center; background-size: 16px; }
		.wp-list-table { width: 100%; border-collapse: collapse; margin: 25px 0; }
		.wp-list-table th, .wp-list-table td { padding: 8px 4px; text-align: left; vertical-align: top; }
		.button-danger { background: #ffffff !important; border-color: #aaa7a7 !important; color: #000000 !important; }
		.order-input { width: 80px !important; padding: 10px 15px !important; text-align: center; }
		input[type="submit"].button.button-primary.button-large { margin-top: 30px !important; padding: 15px 25px !important; font-size: 16px !important; }
		.avis { background-color: #fff3cd; border: 1px solid #ffeeba; padding: 10px; margin-bottom: 15px; border-radius: 5px; font-size: 14px; }
		.avis.avis-critic { background-color: #f8d7da; border-color: #f5c6cb; }
		.dashicons { color: #444; }
		.notice.info { border-left-color: #72aee6; }
	
		/* Estils per al nou bloc de colors */
		.corporate-colors-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 40px; margin: 25px 0; }
		.color-picker-wrapper { display: flex; flex-direction: column; gap: 8px; }
		.color-picker-wrapper label { font-weight: 500; font-size: 1em; color: #3c434a; }
		.color-picker-wrapper input[type="color"] { width: 80px; height: 40px; border: 1px solid #ddd; border-radius: 8px; padding: 4px; cursor: pointer; }
		.color-picker-wrapper p.description { font-size: 0.9em; color: #64748b; margin: 4px 0 0; }
	
		.sportic-activity-list { display: flex; flex-direction: column; gap: 1rem; }
		.activity-item { display: flex; align-items: center; gap: 1.5rem; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem 1.5rem; transition: box-shadow 0.2s ease-in-out; }
		.activity-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); border-color: #cbd5e1; }
		.activity-details { flex-grow: 1; display: flex; flex-direction: column; gap: 0.5rem; }
		.activity-details input { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1em; transition: border-color 0.2s, box-shadow 0.2s; }
		.activity-details input:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2); outline: none; }
		.activity-details .activity-description { font-weight: 500; }
		.activity-details .activity-shortcode { font-family: monospace; font-size: 0.9em; color: #475569; }
		.activity-shortcode-wrapper { display: flex; flex-direction: column; gap: 4px; }
		.activity-shortcode-wrapper .shortcode-hint { font-size: 0.8rem; color: #64748b; padding-left: 2px; }
		.activity-color { flex-shrink: 0; }
		.activity-color input[type="color"] { width: 48px; height: 48px; border: none; border-radius: 8px; padding: 0; background: none; cursor: pointer; }
		.activity-actions { flex-shrink: 0; }
		.button-icon-delete { background: none; border: none; color: #9ca3af; cursor: pointer; width: 36px; height: 36px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; transition: background-color 0.2s, color 0.2s; }
		.button-icon-delete:hover { background-color: #fee2e2; color: #b91c1c; }
		.button-icon-delete .dashicons { font-size: 1.2rem; line-height: 1; }
		.sportic-activities-actions { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0; }
		.activity-list-empty-state { text-align: center; padding: 2.5rem; border: 2px dashed #e2e8f0; border-radius: 12px; color: #64748b; }
		
		/* Estils per al nou bloc d'opcions */
		.form-field-wrapper { padding: 10px 0; }
		.form-field-wrapper label { display: flex; align-items: center; gap: 8px; font-weight: 500; font-size: 1em; color: #3c434a; }
		.form-field-wrapper input[type="checkbox"] { width: 18px; height: 18px; }
		.form-field-wrapper p.description { font-size: 0.9em; color: #64748b; margin: 8px 0 0 26px; }
	
		</style>
		<script>
		jQuery(document).ready(function($) {
			const $listContainer = $('#sportic-activity-list');
			const $addButton = $('#add-custom-activity-item');
			const $template = $('#activity-item-template');
			const $emptyState = $('.activity-list-empty-state');
	
			function slugify(text) {
				return text.toString().toLowerCase().trim()
					.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
					.replace(/[^a-z0-9]+/g, '-')
					.replace(/^-+|-+$/g, '');
			}
	
			function updateHint($item) {
				const description = $item.find('.activity-description').val();
				const slugDefault = slugify(description || 'activitat');
				const $shortcodeField = $item.find('.activity-shortcode');
				const shortcodeValue = $shortcodeField.val().trim();
				const shortcodeFinal = shortcodeValue !== '' ? shortcodeValue : slugDefault;
				
				$shortcodeField.attr('placeholder', 'ex: ' + (slugDefault || 'equip'));
				$item.find('.shortcode-hint code').text('[sportic_team_schedule code="' + shortcodeFinal + '"]');
			}
	
			function buildActivityItem(index, data) {
				const info = data || {};
				const newHtml = $template.html().replace(/__INDEX__/g, index);
				const $newItem = $(newHtml);
	
				if (info.description) $newItem.find('.activity-description').val(info.description);
				if (info.shortcode) $newItem.find('.activity-shortcode').val(info.shortcode);
				if (info.color) $newItem.find('input[type="color"]').val(info.color);
				
				updateHint($newItem);
				return $newItem;
			}
	
			function ensureListNotEmpty() {
				if ($listContainer.children('.activity-item').length === 0) {
					$emptyState.show();
				} else {
					$emptyState.hide();
				}
			}
			
			$listContainer.find('.activity-item').each(function(){
				updateHint($(this));
			});
	
			$addButton.on('click', function() {
				$emptyState.hide();
				const newIndex = Date.now();
				const $newItem = buildActivityItem(newIndex, null);
				$listContainer.append($newItem.hide().fadeIn(300));
			});
	
			$listContainer.on('click', '.sportic-remove-activity-item', function() {
				const $item = $(this).closest('.activity-item');
				$item.fadeOut(300, function() {
					$item.remove();
					ensureListNotEmpty();
				});
			});
			
			$listContainer.on('input', '.activity-description, .activity-shortcode', function() {
				const $item = $(this).closest('.activity-item');
				updateHint($item);
			});
	
			$('#toggleGestionarAnys').click(function() { $('#contentGestionarAnys').slideToggle(300); $('#arrowGestionarAnys').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2'); });
			$('#toggleNetejaTotal').click(function() { $('#contentNetejaTotal').slideToggle(300); $('#arrowNetejaTotal').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2'); });
			$('#toggleCacheManagement').click(function() { $('#contentCacheManagement').slideToggle(300); $('#arrowCacheManagement').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2'); });
	
			ensureListNotEmpty();
		});
		</script>
		<?php
	}	
		
	/**
	* Helpers per obtenir i comparar el rang d'hores
	*/
	function sportic_unfile_is_time_in_open_range($hora) {
		// Recupera la configuració del Rang d'hores d'obertura
		// (VALOR PER DEFECTE CANVIAT A 23:30 en comptes de 22:00)
		$config = get_option('sportic_unfile_opening_hours', array('start' => '06:00', 'end' => '23:30'));  // <--- CANVIAT
		$start = $config['start'];
		$end   = $config['end'];
	
		// Converteix l'hora d'inici, final i l'hora actual a minuts des de la mitjanit
		list($startHour, $startMin) = explode(':', $start);
		$startMinutes = intval($startHour) * 60 + intval($startMin);
	
		list($endHour, $endMin) = explode(':', $end);
		$endMinutes = intval($endHour) * 60 + intval($endMin);
	
		list($currentHour, $currentMin) = explode(':', $hora);
		$currentMinutes = intval($currentHour) * 60 + intval($currentMin);
	
		// Retorna true si està dins del rang (inclusiu)
		return ($currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes);
	}
	
	
		
	
	
	
	/**
	* ----------------------------------------------------------------------------
	*   RESTA DEL CODI ORIGINAL (MENSAJOS, PROGRAMACIO, PLANTILLES, SHORTCODE, ETC.)
	* ----------------------------------------------------------------------------
	*/
	
	/**
	* Funció per a obtenir la programació per defecte segons la piscina
	* (ara amb totes les hores cada 30 mins de 00:00 a 23:30)
	* (Ho deixem igual, però recorda que després filtrarem a l'hora de mostrar.)
	*/
function sportic_unfile_crear_programacio_default($piscina_slug) {
		$config_hores = get_option('sportic_unfile_opening_hours', array('start'=>'16:00','end'=>'23:00'));
		$startTime = isset($config_hores['start']) && preg_match('/^\d{2}:\d{2}$/', $config_hores['start']) ? $config_hores['start'] : '16:00';
		$endTime   = isset($config_hores['end']) && preg_match('/^\d{2}:\d{2}$/', $config_hores['end']) ? $config_hores['end'] : '23:00';
	
		$horesBase = array();
		try {
			$current_dt_func = new DateTime($startTime); 
			$end_dt_func     = new DateTime($endTime);
			if ($current_dt_func > $end_dt_func) {
				 $current_dt_func = new DateTime('16:00');
				 $end_dt_func     = new DateTime('23:00');
			}
		} catch (Exception $e) {
			error_log("SporTIC Error creant programació default: format d'hora invàlid. Utilitzant defaults.");
			$current_dt_func = new DateTime('16:00');
			$end_dt_func     = new DateTime('23:00');
		}
	
		while ($current_dt_func <= $end_dt_func) {
			$horesBase[] = $current_dt_func->format('H:i');
			$current_dt_func->modify('+15 minutes'); // <-- CANVI A 15 MINUTS
		}
	
		$configured_pools_func = sportic_unfile_get_pool_labels_sorted();
		$num_carrils_func = 4;
	
		if (isset($configured_pools_func[$piscina_slug]) && isset($configured_pools_func[$piscina_slug]['lanes'])) {
			$num_carrils_func = intval($configured_pools_func[$piscina_slug]['lanes']);
		}
		if ($num_carrils_func < 1) $num_carrils_func = 1;
	
		$hores_programacio_func = array();
		foreach($horesBase as $hora_func) {
			$hores_programacio_func[$hora_func] = array_fill(0, $num_carrils_func, 'l');
		}
		return $hores_programacio_func;
	}
			
	/**
	* Funcions per a la gestió dels avisos d'administració (les "notices")
	*/
	function sportic_unfile_disable_admin_notices_mainpage() {
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'sportic-onefile-menu' ) {
			remove_all_filters( 'admin_notices' );
			remove_all_filters( 'all_admin_notices' );
			remove_all_filters( 'user_admin_notices' );
			remove_all_filters( 'network_admin_notices' );
		}
	}
	add_action( 'admin_head', 'sportic_unfile_disable_admin_notices_mainpage', 1 );
	
	function sportic_unfile_disable_admin_notices_templates() {
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'sportic-onefile-templates' ) {
			remove_all_filters( 'admin_notices' );
			remove_all_filters( 'all_admin_notices' );
			remove_all_filters( 'user_admin_notices' );
			remove_all_filters( 'network_admin_notices' );
		}
	}
	add_action( 'admin_head', 'sportic_unfile_disable_admin_notices_templates', 1 );
	
	/**
	* Mostra la interfície de calendari (admin)
	*/
function sportic_unfile_mostrar_calendari() {
		$year = isset($_GET['cal_year']) ? intval($_GET['cal_year']) : intval(date('Y', current_time('timestamp')));
		$month = isset($_GET['cal_month']) ? intval($_GET['cal_month']) : intval(date('n', current_time('timestamp')));
		$firstDay = new DateTime();
		$firstDay->setDate($year, $month, 1);
		$daysInMonth = intval($firstDay->format('t'));
		$firstDayOfWeek = intval($firstDay->format('N'));
	
		$mesosCatalans = array(
			1 => 'Gener', 2 => 'Febrer', 3 => 'Març', 4 => 'Abril', 5 => 'Maig', 6 => 'Juny',
			7 => 'Juliol', 8 => 'Agost', 9 => 'Setembre', 10 => 'Octubre', 11 => 'Novembre', 12 => 'Desembre'
		);
	
		$prevMonth = $month - 1; $prevYear = $year;
		if ( $prevMonth < 1 ) { $prevMonth = 12; $prevYear--; }
		$nextMonth = $month + 1; $nextYear = $year;
		if ( $nextMonth > 12 ) { $nextMonth = 1; $nextYear++; }
		
		$defaultSelectedQueryParam = isset($_GET['selected_date']) ? sanitize_text_field($_GET['selected_date']) : current_time('Y-m-d');
		$baseUrl = admin_url( 'admin.php?page=sportic-onefile-menu' );
	
		// === INICI NOU HTML REESTRUCTURAT (ARA SENSE TÍTOL NI CAIXA EXTERNA) ===
			
		// 1. Navegació (ara és el primer element)
		echo '<div class="cal-header" style="display: flex; align-items: center; justify-content: center; gap: 15px; margin-bottom: 20px; position: relative;">';
			
			$todaysDate = current_time('Y-m-d');
			$todaysYear = current_time('Y');
			$todaysMonth = date('n', current_time('timestamp'));
	
			echo '<a href="' . esc_url( $baseUrl . '&cal_year=' . $todaysYear . '&cal_month=' . $todaysMonth . '&selected_date=' . $todaysDate ) . '" class="cal-today" style="text-decoration: none; color: #555; font-size: 1.2em;" title="Tornar a avui"><span class="dashicons dashicons-image-rotate"></span></a>';
			echo '<a href="' . esc_url( $baseUrl . '&cal_year=' . $prevYear . '&cal_month=' . $prevMonth . '&selected_date=' . $defaultSelectedQueryParam ) . '" style="text-decoration: none; color: #333; font-size: 1em;"><span class="dashicons dashicons-arrow-left-alt2"></span></a>';
			
			echo '<div class="cal-title-container">';
				echo '<div id="sportic-cal-title-clickable" class="cal-title" style="font-weight: bold; font-size: 1.2em; color: #2c3338; cursor:pointer; padding: 5px 10px; border-radius:4px; transition: background-color 0.2s;" title="Canviar mes/any">';
				echo esc_html($mesosCatalans[$month]) . ' ' . esc_html($year);
				echo '</div>';
				
				echo '<div id="sportic-date-picker-popup" class="sportic-date-picker-popup">';
					echo '<div class="year-selector">';
						echo '<button type="button" id="prev-year-popup" aria-label="Any anterior">«</button>';
						echo '<input type="number" id="year-input-popup" value="' . esc_attr($year) . '" min="1900" max="2100" aria-label="Any">';
						echo '<button type="button" id="next-year-popup" aria-label="Any següent">»</button>';
					echo '</div>';
					echo '<div class="month-grid">';
					foreach ($mesosCatalans as $num => $nomMes) {
						echo '<button type="button" data-month="' . esc_attr($num) . '">' . esc_html(substr($nomMes, 0, 3)) . '</button>';
					}
					echo '</div>';
					echo '<div class="popup-actions">';
						echo '<button type="button" id="cancel-date-popup" class="cancel-btn">Cancel·lar</button>';
						echo '<button type="button" id="apply-date-popup" class="apply-btn">Aplicar</button>';
					echo '</div>';
				echo '</div>';
			echo '</div>';
	
			echo '<a href="' . esc_url( $baseUrl . '&cal_year=' . $nextYear . '&cal_month=' . $nextMonth . '&selected_date=' . $defaultSelectedQueryParam ) . '" style="text-decoration: none; color: #333; font-size: 1em;"><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
		echo '</div>';
	
		// 2. Estils CSS (es mantenen aquí)
		echo '<style>
		.sportic-calendari .cal-table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed;}
		.sportic-calendari .cal-table thead { border-bottom: 1px solid #e5e7eb; }
		.sportic-calendari .cal-table thead th { border: none !important; padding: 12px 8px; text-align: center; font-weight: 600; color: #6b7280; font-size: 0.9em; }
		.sportic-calendari .cal-table td.cal-day { border: none !important; padding: 2px; background-color: transparent; height: 50px; position: relative; }
		.sportic-calendari td.cal-day a { text-decoration: none; color: #111827; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 1em;}
		.sportic-calendari td.cal-day a span { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; font-weight: 500; line-height: 1; box-sizing: border-box; transition: background-color 0.2s, color 0.2s, box-shadow 0.2s; }
		.sportic-calendari td.cal-day:hover a span { background-color: #eaeef6; }
		.sportic-calendari td.cal-day.today a span { box-shadow: inset 0 0 0 2px #3fafc7; }
		.sportic-calendari td.cal-day.selected a span { background-color: #4061aa; color: white; font-weight: 700; }
		.sportic-calendari td.cal-day.today.selected a span { background-color: #4061aa; color: white; box-shadow: none; }
		.wp-core-ui select { min-height: 40px !important; }
		.sportic-calendari td.cal-day a:focus { outline: none !important; box-shadow: none !important; }
		</style>';
	
		// 3. Taula del calendari
		echo '<table class="cal-table">';
			$diesSetmana = array( 'Dl', 'Dt', 'Dc', 'Dj', 'Dv', 'Ds', 'Dg' );
			echo '<thead><tr>';
				foreach ( $diesSetmana as $diaAbreujat ) { echo '<th>' . $diaAbreujat . '</th>'; }
			echo '</tr></thead>';
			echo '<tbody>';
				$currentDay = 1; $weekDay = 1; echo '<tr>';
				for ( $i = 1; $i < $firstDayOfWeek; $i++ ) { echo '<td class="empty" style="border: none;"></td>'; $weekDay++; }
				while ( $currentDay <= $daysInMonth ) {
					if ( $weekDay > 7 ) { echo '</tr><tr>'; $weekDay = 1; }
					$dateStr = sprintf( '%04d-%02d-%02d', $year, $month, $currentDay );
					$classes = 'cal-day';
					if ( $dateStr === $defaultSelectedQueryParam ) { $classes .= ' selected'; }
					if ( $dateStr === current_time('Y-m-d') ) { $classes .= ' today'; }
					echo '<td class="' . esc_attr( $classes ) . '">';
						echo '<a href="' . esc_url( $baseUrl . '&cal_year=' . $year . '&cal_month=' . $month . '&selected_date=' . $dateStr ) . '">';
							echo '<span>' . $currentDay . '</span>';
						echo '</a>';
					echo '</td>';
					$currentDay++; $weekDay++;
				}
				while ( $weekDay <= 7 ) { echo '<td class="empty" style="border: none;"></td>'; $weekDay++; }
				echo '</tr>';
			echo '</tbody>';
		echo '</table>';
		// === FI NOU HTML ===
	}
	
	   /**
		* Mostra les taules de programació per dia dins d'una piscina, amb pestanyes per a cada dia de la setmana.
		*
		* @param string $slug           Slug de la piscina.
		* @param array  $dadesPiscina   Array amb les dades de programació de la piscina.
		* @param array  $weekDates      Array associatiu de DateTime amb els dies de la setmana (clau = nom en minúscules, ex. 'dilluns').
		* @param string $selectedDayKey Nom del dia seleccionat (ex. 'dilluns').
		*/
function sportic_unfile_mostrar_dies( $slug, $dadesPiscina, $weekDates = array(), $selectedDayKey = '' ) {
			$dies = array( 'dilluns', 'dimarts', 'dimecres', 'dijous', 'divendres', 'dissabte', 'diumenge' );
			
			if ( ! empty( $weekDates ) ) {
				$monday = $weekDates['dilluns'];
				$sunday = $weekDates['diumenge'];
				$mesosCatalans = array(
					'January' => 'Gener', 'February' => 'Febrer', 'March' => 'Març',
					'April' => 'Abril', 'May' => 'Maig', 'June' => 'Juny', 'July' => 'Juliol',
					'August' => 'Agost', 'September' => 'Setembre', 'October' => 'Octubre',
					'November' => 'Novembre', 'December' => 'Desembre'
				);
				$startDate = $monday->format('j') . ' ' . $mesosCatalans[$monday->format('F')] . ' ' . $monday->format('Y');
				$endDate   = $sunday->format('j') . ' ' . $mesosCatalans[$sunday->format('F')] . ' ' . $sunday->format('Y');
			
				$iconaCalendari = plugin_dir_url(__FILE__) . 'imatges/icon-calendar.png';
			
				echo '<div class="week-header">
						<img src="' . esc_url($iconaCalendari) . '" alt="Calendari" style="width:20px; height:20px; margin-right:4px; margin-bottom: 4px; vertical-align:middle;">
						<i class="fas fa-arrow-down" style="font-size: 0.8em; margin-right: 1px;"></i>
						Setmana: Del ' . $startDate . ' al ' . $endDate . '
						<i class="fas fa-arrow-down" style="font-size: 0.8em; margin-left: 4px;"></i>
					  </div>';
			}
		
			echo '<div class="sportic-secondary-tabs-wrapper">';
			foreach ( $dies as $dia ) {
				$dayLabel = ucfirst($dia);
				if ( ! empty($weekDates) && isset($weekDates[$dia]) && $weekDates[$dia] instanceof DateTime ) {
					$dayLabel .= ' (' . $weekDates[$dia]->format('j') . ')';
				}
				$activeClass = ($selectedDayKey === $dia) ? 'active' : '';
				echo '<button class="sportic-folder-tab sportic-secondary-tab ' . $activeClass . '" data-target="#' . esc_attr($slug . '-' . $dia) . '" data-daykey="' . esc_attr($dia) . '">' . esc_html($dayLabel) . '</button>';
			}
			echo '</div>';
		
			$customActivities = get_option('sportic_unfile_custom_letters', array());
			$activitiesMap = [];
			if (is_array($customActivities)) {
				foreach ($customActivities as $activity) {
					if (!empty($activity['description']) && !empty($activity['color'])) {
						$activitiesMap[trim($activity['description'])] = trim($activity['color']);
					}
				}
			}
		
			global $wpdb;
			$nomTaulaLock = defined('SPORTIC_LOCK_TABLE') ? ($wpdb->prefix . SPORTIC_LOCK_TABLE) : ($wpdb->prefix . 'sportic_bloqueig');
			$start_date_week = $weekDates['dilluns']->format('Y-m-d');
			$end_date_week = $weekDates['diumenge']->format('Y-m-d');
			$locks_setmana_raw = $wpdb->get_results($wpdb->prepare("SELECT dia_data, hora, carril_index FROM $nomTaulaLock WHERE piscina_slug = %s AND dia_data BETWEEN %s AND %s", $slug, $start_date_week, $end_date_week), ARRAY_A);
			
			$mapa_bloquejos = [];
			if ($locks_setmana_raw) {
				foreach ($locks_setmana_raw as $lock) {
					$mapa_bloquejos[$lock['dia_data']][$lock['hora']][$lock['carril_index']] = true;
				}
			}
		
			$configured_pools = sportic_unfile_get_pool_labels_sorted();
			$lane_labels = $configured_pools[$slug]['lane_labels'] ?? [];
			$numCarrils = $configured_pools[$slug]['lanes'] ?? 4;
			if (empty($lane_labels)) {
				for ($i = 1; $i <= $numCarrils; $i++) {
					$lane_labels[] = 'Pista ' . $i;
				}
			}
		
			echo '<div class="sportic-secondary-content-box">';
			foreach ( $dies as $dia ) {
				$dateStr = '';
				if (!empty($weekDates) && isset($weekDates[$dia])) {
					$dateStr = $weekDates[$dia]->format('Y-m-d');
				}
				
				$displayStyle = ($selectedDayKey === $dia) ? 'display:block;' : 'display:none;';
				$activeClass = ($selectedDayKey === $dia) ? 'active' : '';
				echo '<div id="' . esc_attr($slug . '-' . $dia) . '" class="sportic-dia-content ' . $activeClass . '" style="' . $displayStyle . '">';
				
				$iconaCalendari = plugin_dir_url(__FILE__) . 'imatges/icon-calendar.png';
				
				echo '<h3>
						<img src="' . esc_url($iconaCalendari) . '" alt="Calendari" style="width:20px; height:20px; margin-right:4px; vertical-align:middle;">
						Taula corresponent a → ' . ucfirst($dia);
				if ($dateStr !== '') {
					echo ' - ' . date('d/m/Y', strtotime($dateStr));
				}
				echo '</h3>';
				
				echo '<div class="sportic-toolbar" style="padding: 8px 0; margin-bottom: 10px; display: flex; gap: 8px;">
						<button type="button" class="button lock-button" title="Bloquejar cel·les seleccionades">
							<span class="dashicons dashicons-lock"></span> Bloquejar
						</button>
						<button type="button" class="button unlock-button" title="Desbloquejar cel·les seleccionades">
							<span class="dashicons dashicons-unlock"></span> Desbloquejar
						</button>
					  </div>';
		
					// =========================================================================
					// INICI DEL CANVI CLAU: ELIMINEM EL BLOC DE CODI REDUNDANT
					// =========================================================================
					//
					// Les dades que arriben a $dadesPiscina ja han estat processades per
					// sportic_llegir_de_taula_sense_prefix(), que ja ha aplicat els recurrents
					// i ha respectat les excepcions. No cal tornar a aplicar-los aquí.
					//
					// AQUEST BLOC DE CODI SENCER S'HA ELIMINAT:
					//
					// $recurrent_events_map = sportic_get_all_recurrent_events_map();
					// if (isset($recurrent_events_map[$dateStr][$slug])) { ... }
					//
					$scheduleForThisDay = $dadesPiscina[$dateStr] ?? sportic_unfile_crear_programacio_default($slug);
					//
					// =========================================================================
					// FI DEL CANVI CLAU
					// =========================================================================
					
					$horesDisplay = array_keys($scheduleForThisDay);
					sort($horesDisplay);
					$horesFiltrades = array_filter($horesDisplay, 'sportic_unfile_is_time_in_open_range');
			
					$tableClass = ($numCarrils > 6) ? 'sportic-wide' : 'sportic-narrow';
					?>
					<div class="sportic-taula-container">
						<div class="sportic-table-header-wrapper">
							<table class="widefat striped sportic-table <?php echo $tableClass; ?>">
								<thead>
									<tr>
										<th>Hora</th>
										<?php foreach ($lane_labels as $label): ?>
											<th><?php echo esc_html($label); ?></th>
										<?php endforeach; ?>
									</tr>
								</thead>
							</table>
						</div>
						<div class="sportic-table-body-wrapper">
							<table class="widefat striped sportic-table <?php echo $tableClass; ?>" data-piscina="<?php echo esc_attr($slug); ?>" data-dia="<?php echo esc_attr($dateStr); ?>">
								<tbody>
								<?php
								$rowIndex = 0;
								foreach ($horesFiltrades as $hora) {
									$arrVals = $scheduleForThisDay[$hora] ?? array_fill(0, $numCarrils, 'l');
									if (count($arrVals) !== $numCarrils) { $arrVals = array_pad(array_slice($arrVals, 0, $numCarrils), $numCarrils, 'l'); }
									
									echo '<tr><td>' . esc_html($hora) . '</td>';
									for ($c = 0; $c < $numCarrils; $c++) {
										
										$valorRaw = $arrVals[$c] ?? 'l';
										// ARA la lògica de detecció és més simple, perquè les dades ja venen processades.
										$isLocked = strpos((string)$valorRaw, '!') === 0;
										$isRecurrent = strpos((string)$valorRaw, '@') === 0;
										$valorBase = preg_replace('/^[@!]/', '', (string)$valorRaw);
			
										if ($valorBase === false || $valorBase === '') $valorBase = 'l';
										
										$color = $activitiesMap[$valorBase] ?? '#ffffff';
										$displayText = ($valorBase === 'l' || $valorBase === 'b') ? '' : $valorBase;
										
										$tdClasses = 'sportic-cell';
										if ($isLocked) $tdClasses .= ' sportic-locked';
										elseif ($isRecurrent) $tdClasses .= ' sportic-recurrent';
										
										echo '<td class="' . $tdClasses . '" data-row="' . esc_attr($rowIndex) . '" data-col="' . esc_attr($c) . '" data-valor="' . esc_attr($valorBase) . '" style="background-color:'.esc_attr($color).' !important;"' . ($isLocked ? ' data-locked="1"' : '') . ($isRecurrent ? ' data-recurrent="1"' : '') . '>';
										echo '<span class="sportic-text">' . esc_html($displayText) . '</span>';
										if ($isLocked) echo '<span class="sportic-lock-icon dashicons dashicons-lock"></span>';
										elseif ($isRecurrent) echo '<span class="sportic-recurrent-icon fas fa-sync-alt"></span>';
										echo '</td>';
									}
									echo '</tr>';
									$rowIndex++;
								}
								?>
								</tbody>
							</table>
						</div>
					</div>
					<?php
				echo '</div>'; 
			}
			echo '</div>';
		}
		
						
	/**
	* Mostra la taula editable de Condicions Ambientals a l'admin
	*/
	function sportic_unfile_mostrar_condicions_ambientals() {
		$condicions = get_option( 'sportic_unfile_condicions_custom', array(
			'50x25'     => array( '', '', '', '', '', '', '' ),
			'25x12'     => array( '', '', '', '', '', '', '' ),
			'25x8'      => array( '', '', '', '', '', '', '' ),
			'triangular'=> array( '', '', '', '', '', '', '' )
		) );
		?>
		<table id="taula-qualitat-aire">
			<thead>
				<tr>
					<th class="s1" style="vertical-align: bottom !important;">PAVELLONS</th>
					<th class="s2" style="text-align: center;">Temperatura<br>(°C)</th>
					<th class="s3" style="text-align: center;">Humitat relativa<br>(%)</th>
					<th class="s2" style="text-align: center;">Co2 (mg/L)</th>
					<th class="s2" style="text-align: center; border-left: 5px solid black;">PH (upH)</th>
					<th class="s2" style="text-align: center;">Nivell <br>de <br>desinfectant</th>
					<th class="s2" style="text-align: center;">Temperatura (ºC)</th>
					<th class="s2" style="text-align: center;">Terbolesa (U.N.T.)</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td class="s2">50x25</td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[50x25][0]" value="<?php echo esc_attr( $condicions['50x25'][0] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[50x25][1]" value="<?php echo esc_attr( $condicions['50x25'][1] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[50x25][2]" value="<?php echo esc_attr( $condicions['50x25'][2] ); ?>" /></td>
					<td class="s7" style="border-left: 5px solid black;"><input type="text" name="__dummy_condicions_ambientals[50x25][3]" value="<?php echo esc_attr( $condicions['50x25'][3] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[50x25][4]" value="<?php echo esc_attr( $condicions['50x25'][4] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[50x25][5]" value="<?php echo esc_attr( $condicions['50x25'][5] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[50x25][6]" value="<?php echo esc_attr( $condicions['50x25'][6] ); ?>" /></td>
				</tr>
				<tr>
					<td class="s2">25x12</td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x12][0]" value="<?php echo esc_attr( $condicions['25x12'][0] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x12][1]" value="<?php echo esc_attr( $condicions['25x12'][1] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x12][2]" value="<?php echo esc_attr( $condicions['25x12'][2] ); ?>" /></td>
					<td class="s7" style="border-left: 5px solid black;"><input type="text" name="__dummy_condicions_ambientals[25x12][3]" value="<?php echo esc_attr( $condicions['25x12'][3] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x12][4]" value="<?php echo esc_attr( $condicions['25x12'][4] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x12][5]" value="<?php echo esc_attr( $condicions['25x12'][5] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x12][6]" value="<?php echo esc_attr( $condicions['25x12'][6] ); ?>" /></td>
				</tr>
				<tr>
					<td class="s2">25x8</td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x8][0]" value="<?php echo esc_attr( $condicions['25x8'][0] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x8][1]" value="<?php echo esc_attr( $condicions['25x8'][1] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x8][2]" value="<?php echo esc_attr( $condicions['25x8'][2] ); ?>" /></td>
					<td class="s7" style="border-left: 5px solid black;"><input type="text" name="__dummy_condicions_ambientals[25x8][3]" value="<?php echo esc_attr( $condicions['25x8'][3] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x8][4]" value="<?php echo esc_attr( $condicions['25x8'][4] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x8][5]" value="<?php echo esc_attr( $condicions['25x8'][5] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[25x8][6]" value="<?php echo esc_attr( $condicions['25x8'][6] ); ?>" /></td>
				</tr>
				<tr>
					<td class="s2">Triangular</td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[triangular][0]" value="<?php echo esc_attr( $condicions['triangular'][0] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[triangular][1]" value="<?php echo esc_attr( $condicions['triangular'][1] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[triangular][2]" value="<?php echo esc_attr( $condicions['triangular'][2] ); ?>" /></td>
					<td class="s7" style="border-left: 5px solid black;"><input type="text" name="__dummy_condicions_ambientals[triangular][3]" value="<?php echo esc_attr( $condicions['triangular'][3] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[triangular][4]" value="<?php echo esc_attr( $condicions['triangular'][4] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[triangular][5]" value="<?php echo esc_attr( $condicions['triangular'][5] ); ?>" /></td>
					<td class="s7"><input type="text" name="__dummy_condicions_ambientals[triangular][6]" value="<?php echo esc_attr( $condicions['triangular'][6] ); ?>" /></td>
				</tr>
			</tbody>
		</table>
		<?php
	}
	
	/**
	* ****************************************************************************
	* MOSTRA LA PÀGINA D'ADMINISTRACIÓ PRINCIPAL (SporTIC - Gestió de Piscines),
	* amb pestanyes per a piscines i condicions, + calendari i "Aplicar Plantilla".
	* ****************************************************************************
	*/
	
function sportic_unfile_mostrar_pagina() {
		// ========================================================================
		// NOU: Funcions auxiliars per separar la lògica de visualització
		// ========================================================================
	
		/**
		 * [MODIFICADA] Renderitza la pantalla de selecció de Llocs.
		 * Ara inclou el botó de Pantalla Completa i carrega els scripts necessaris.
		 */
		function sportic_render_lloc_selector_view() {
			if (!function_exists('sportllocs_get_llocs')) {
				echo '<div class="wrap"><div class="notice notice-error"><p>Error: El plugin de configuració de Llocs no està actiu. Si us plau, activa\'l per continuar.</p></div></div>';
				return;
			}
	
			$tots_els_llocs = sportllocs_get_llocs();
			$tots_els_pavellons = function_exists('sportllocs_get_all_pavellons') ? sportllocs_get_all_pavellons() : [];
			
			$pavellons_per_lloc = [];
			foreach ($tots_els_pavellons as $pavello) {
				$lloc_slug = $pavello['lloc_slug'] ?? 'sense_lloc';
				if (!isset($pavellons_per_lloc[$lloc_slug])) {
					$pavellons_per_lloc[$lloc_slug] = 0;
				}
				$pavellons_per_lloc[$lloc_slug]++;
			}
	
			$camí_imatge_logo = plugin_dir_url(__FILE__) . 'imatges/logo.png';
			?>
			<div class="wrap sportic-lloc-selector-page" style="position: relative;">
				
				<!-- NOU BOTÓ MODE ZEN (FULLSCREEN) PER A LA PANTALLA DE SELECCIÓ -->
				<button type="button" id="sportic-fullscreen-toggle" class="sportic-action-btn-icon" title="Mode Zen (Pantalla Completa)" style="position: absolute; top: 0; right: 0; z-index: 10;">
					<span class="dashicons dashicons-editor-expand" style="line-height: inherit; font-size: 20px;"></span>
				</button>
	
				<header class="sportic-selector-header">
					<img src="<?php echo esc_url($camí_imatge_logo); ?>" alt="Logo SporTIC" class="sportic-selector-logo"/>
					<h1>Selector d'Instal·lacions</h1>
					<p class="sportic-selector-subtitle">Selecciona un lloc per començar a gestionar els seus horaris i pavellons.</p>
				</header>
	
				<?php if (empty($tots_els_llocs)): ?>
					<div class="sportic-no-llocs-message">
						<span class="dashicons dashicons-warning"></span>
						<p><strong>No s'han trobat llocs configurats.</strong></p>
						<p>Per començar, ves a <a href="<?php echo esc_url(admin_url('admin.php?page=sportllocs-config-manage')); ?>">Configuració de Llocs i Pavellons</a> per afegir el teu primer lloc.</p>
					</div>
				<?php else: ?>
					<main class="sportic-lloc-grid">
						<?php foreach ($tots_els_llocs as $slug => $data): 
							$url_lloc = admin_url('admin.php?page=sportic-onefile-menu&lloc=' . esc_attr($slug));
							$pavellons_count = $pavellons_per_lloc[$slug] ?? 0;
							$image_id = $data['image_id'] ?? 0;
							$image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
						?>
							<a href="<?php echo esc_url($url_lloc); ?>" class="sportic-lloc-card">
								<div class="sportic-lloc-card-image">
									<?php if ($image_url): ?>
										<img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($data['name']); ?>">
									<?php else: ?>
										<span class="dashicons dashicons-admin-multisite"></span>
									<?php endif; ?>
								</div>
								<div class="sportic-lloc-card-content">
									<h2><?php echo esc_html($data['name']); ?></h2>
									<p><?php echo esc_html($pavellons_count); ?> pavellons gestionats</p>
								</div>
								<div class="sportic-lloc-card-arrow">
									<span class="dashicons dashicons-arrow-right-alt2"></span>
								</div>
							</a>
						<?php endforeach; ?>
					</main>
				<?php endif; ?>
			</div>
			<style>
				#wpbody-content {
					background-color: #f0f2f5;
				}
				.sportic-lloc-selector-page {
					max-width: 1200px;
					margin: 40px auto;
					padding: 20px;
				}
				/* Estil pel botó zen en aquesta pantalla */
				.sportic-lloc-selector-page .sportic-action-btn-icon {
					background-color: #fff;
					width: 44px; 
					height: 44px; 
					color: #4b5563; 
					border: 1px solid #e2e8f0;
					border-radius: 50%;
					box-shadow: 0 2px 5px rgba(0,0,0,0.05);
					cursor: pointer;
					display: flex;
					align-items: center;
					justify-content: center;
					transition: all 0.2s ease;
				}
				.sportic-lloc-selector-page .sportic-action-btn-icon:hover {
					background-color: #f8fafc;
					color: #4061aa;
					border-color: #4061aa;
					transform: scale(1.05);
				}
	
				.sportic-selector-header {
					text-align: center;
					margin-bottom: 40px;
				}
				.sportic-selector-logo {
					height: 60px;
					width: auto;
					margin-bottom: 15px;
				}
				.sportic-selector-header h1 {
					font-family: 'Barlow Condensed', sans-serif;
					font-size: 3em;
					font-weight: 600;
					font-style: italic;
					color: #1e293b;
					margin: 0 0 10px;
				}
				.sportic-selector-subtitle {
					font-family: 'Space Grotesk', sans-serif;
					font-size: 1.1em;
					color: #64748b;
					margin: 0;
				}
				.sportic-lloc-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
					gap: 2rem;
				}
				.sportic-lloc-card {
					background: #ffffff;
					border-radius: 16px;
					display: flex;
					flex-direction: column;
					text-decoration: none;
					color: inherit;
					border: 1px solid #e2e8f0;
					box-shadow: 0 4px 15px rgba(0,0,0,0.05);
					transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out, border-color 0.2s ease-in-out;
					overflow: hidden;
				}
				.sportic-lloc-card:hover {
					transform: translateY(-5px);
					box-shadow: 0 10px 25px rgba(0,0,0,0.08);
					border-color: #4061aa;
				}
				.sportic-lloc-card-image {
					width: 100%;
					height: 160px;
					background-color: #f1f5f9;
					display: flex;
					align-items: center;
					justify-content: center;
					border-bottom: 1px solid #e2e8f0;
				}
				.sportic-lloc-card-image img {
					width: 100%;
					height: 100%;
					object-fit: cover;
				}
				.sportic-lloc-card-image .dashicons {
					font-size: 64px;
					width: 64px;
					height: 64px;
					color: #94a3b8;
				}
				.sportic-lloc-card-content {
					padding: 20px 25px;
					display: flex;
					flex-direction: column;
					flex-grow: 1;
				}
				.sportic-lloc-card-content h2 {
					font-family: 'Space Grotesk', sans-serif;
					font-size: 1.3em;
					font-weight: 700;
					color: #1e293b;
					margin: 0 0 5px;
				}
				.sportic-lloc-card-content p {
					margin: 0;
					font-size: 0.9em;
					color: #64748b;
				}
				.sportic-lloc-card-arrow {
					margin-top: auto;
					padding: 0 25px 20px;
					align-self: flex-end;
				}
				.sportic-lloc-card-arrow .dashicons {
					font-size: 28px;
					color: #94a3b8;
					transition: color 0.2s;
				}
				.sportic-lloc-card:hover .sportic-lloc-card-arrow .dashicons {
					color: #4061aa;
				}
				.sportic-no-llocs-message {
					background-color: #fff;
					border-radius: 12px;
					padding: 40px;
					text-align: center;
					border: 1px solid #e2e8f0;
				}
				.sportic-no-llocs-message .dashicons {
					font-size: 40px;
					color: #f59e0b;
					width: 40px;
					height: 40px;
					margin-bottom: 15px;
				}
				.sportic-no-llocs-message p {
					margin: 5px 0 0;
					font-size: 1.1em;
					color: #475569;
				}
			</style>
			<?php
			// IMPORTANT: Carreguem els estils i JS globals per tal que el mode zen funcioni també aquí
			sportic_unfile_output_inline_css(); 
			sportic_unfile_output_inline_js();
		}
	
		/**
		 * Renderitza la vista de gestió d'horaris (amb calendari, graella, etc.)
		 * Aquesta funció conté la lògica principal un cop seleccionat el lloc.
		 */
		function sportic_render_schedule_view() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
	
			if ( isset( $_GET['error_msg'] ) && ! empty( $_GET['error_msg'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( urldecode($_GET['error_msg']) ) . '</p></div>';
			}
			if ( isset( $_GET['warning_msg'] ) && ! empty( $_GET['warning_msg'] ) ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( urldecode($_GET['warning_msg']) ) . '</p></div>';
			}
			if ( isset( $_GET['info_msg'] ) && ! empty( $_GET['info_msg'] ) ) {
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( urldecode($_GET['info_msg']) ) . '</p></div>';
			}
			if ( isset( $_GET['status'] ) && $_GET['status'] === 'ok' && !isset($_GET['info_msg']) && !isset($_GET['warning_msg']) && !isset($_GET['error_msg'])) {
			}
			if ( isset( $_GET['status'] ) && $_GET['status'] === 'template_applied' && !isset($_GET['warning_msg'])) {
				echo '<div class="updated notice is-dismissible"><p>Plantilla aplicada correctament!</p></div>';
			}
			if ( isset($_GET['status']) && $_GET['status'] === 'undo_ok' ) {
				echo '<div class="updated notice is-dismissible"><p>S\'ha desfet l\'últim canvi!</p></div>';
			}
			if ( isset($_GET['status']) && $_GET['status'] === 'redo_ok' ) {
				echo '<div class="updated notice is-dismissible"><p>S\'ha refet l\'últim canvi desfet!</p></div>';
			}
	
			$lloc_actiu_slug = isset($_GET['lloc']) ? sanitize_key($_GET['lloc']) : '';
			$lloc_actiu_nom = 'Lloc desconegut';
			if (function_exists('sportllocs_get_llocs')) {
				$tots_els_llocs = sportllocs_get_llocs();
				if (isset($tots_els_llocs[$lloc_actiu_slug])) {
					$lloc_actiu_nom = $tots_els_llocs[$lloc_actiu_slug]['name'];
				}
			}
			
			$dades = get_option('sportic_unfile_dades', array());
	
			$any = isset( $_GET['cal_year'] ) ? intval( $_GET['cal_year'] ) : intval( date( 'Y', current_time( 'timestamp' ) ) );
			$mes = isset( $_GET['cal_month'] ) ? intval( $_GET['cal_month'] ) : intval( date( 'n', current_time( 'timestamp' ) ) );
			if ( isset( $_GET['selected_date'] ) ) {
				$data_seleccionada = sanitize_text_field( $_GET['selected_date'] );
				if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_seleccionada)) {
					$data_seleccionada = current_time('Y-m-d');
				}
				try { $dataObj = new DateTime( $data_seleccionada ); } catch ( Exception $e ) { $dataObj = new DateTime(current_time( 'Y-m-d' )); $data_seleccionada = $dataObj->format('Y-m-d'); }
			} else {
				$dataObj = new DateTime( current_time( 'Y-m-d' ) );
				$data_seleccionada = $dataObj->format( 'Y-m-d' );
			}
	
			$diaSetmana   = (int) $dataObj->format( 'N' );
			$setmanaInici = clone $dataObj;
			$setmanaInici->modify( '-' . ( $diaSetmana - 1 ) . ' days' );
			$setmanaDates = array();
			$diesNoms     = array( 'dilluns', 'dimarts', 'dimecres', 'dijous', 'divendres', 'dissabte', 'diumenge' );
			for ( $i = 0; $i < 7; $i++ ) {
				$diaTemp = clone $setmanaInici;
				$diaTemp->modify( "+$i days" );
				$setmanaDates[ $diesNoms[ $i ] ] = $diaTemp;
			}
			$mappingDies = array( 1 => 'dilluns', 2 => 'dimarts', 3 => 'dimecres', 4 => 'dijous', 5 => 'divendres', 6 => 'dissabte', 7 => 'diumenge' );
			if ( isset($_GET['active_subday']) && in_array(sanitize_text_field($_GET['active_subday']), $diesNoms) ) {
				$diaSeleccionatKey = sanitize_text_field($_GET['active_subday']);
			} else {
				$currentDayOfWeekNum = (int) $dataObj->format('N');
				$diaSeleccionatKey = isset($mappingDies[$currentDayOfWeekNum]) ? $mappingDies[$currentDayOfWeekNum] : 'dilluns';
			}
	
			$etiquetesPiscines = sportic_unfile_get_pool_labels_sorted();
			
			$activeTab  = isset( $_GET['active_tab'] ) ? sanitize_text_field( $_GET['active_tab'] ) : '';
			if (empty($activeTab) && !empty($etiquetesPiscines)) {
				reset($etiquetesPiscines);
				$firstPoolSlug = key($etiquetesPiscines);
				$activeTab = '#' . $firstPoolSlug;
			}
			
			$activePool = '';
			if ( ! empty($activeTab) ) {
				$activePool = ltrim($activeTab, '#');
			}
	
			?>
			<div id="sportic_loader_overlay_admin" style="display:block; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(249, 250, 251, 0.9); z-index:999999; text-align:center;">
				<div style="position:relative; top:50%; transform:translateY(-50%);">
					<div class="sportic-loader" style="margin:0 auto;"></div>
					<div class="sportic-loader-text" style="margin-top:20px; font-size:18px; font-weight:bold; color:#333;">Carregant...</div>
				</div>
			</div>
			
			<?php
			$camí_imatge_logo = plugin_dir_url(__FILE__) . 'imatges/logo.png';
	
			echo '<div class="wrap sportic-unfile-admin" id="sportic_main_container" style="display:none; padding-top: 15px;">';
	
			echo '<div class="sportic-main-header">';
				echo '<div class="sportic-header-left">';
					echo '<a href="' . esc_url(admin_url('admin.php?page=sportic-onefile-menu')) . '" class="sportic-back-to-llocs" title="Tornar al selector de llocs">';
						echo '<span class="dashicons dashicons-arrow-left-alt"></span>';
					echo '</a>';
					echo '<div class="sportic-logo-wrapper">';
						echo '<img src="' . esc_url($camí_imatge_logo) . '" alt="Logo SporTIC" />';
					echo '</div>';
	
					echo '<div class="sportic-lloc-display-wrapper">';
						echo '<span class="dashicons dashicons-location-alt"></span>';
						echo '<span class="sportic-lloc-display-name">' . esc_html($lloc_actiu_nom) . '</span>';
					echo '</div>';
				echo '</div>'; 
	
				echo '<div class="sportic-actions-wrapper">';
					?>
					<form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="undo-redo-form">
						<input type="hidden" name="action" value="sportic_undo_change">
						<?php wp_nonce_field( 'sportic_undo_action', 'sportic_undo_nonce' ); ?>
						<input type="hidden" name="sportic_active_tab"    value="<?php echo esc_attr($activeTab); ?>">
						<input type="hidden" name="sportic_active_subday" value="<?php echo esc_attr($diaSeleccionatKey); ?>">
						<input type="hidden" name="selected_date"         value="<?php echo esc_attr($data_seleccionada); ?>">
						<input type="hidden" name="cal_year"              value="<?php echo intval($any); ?>">
						<input type="hidden" name="cal_month"             value="<?php echo intval($mes); ?>">
						<input type="hidden" name="lloc"                  value="<?php echo esc_attr($lloc_actiu_slug); ?>">
						<button type="submit" class="sportic-action-btn-icon" title="Desfer">
							<img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'imatges/icon-undo.png'); ?>" alt="Desfer" style="width:20px; height:20px;" />
						</button>
					</form>
					<form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="undo-redo-form">
						<input type="hidden" name="action" value="sportic_redo_change">
						<?php wp_nonce_field( 'sportic_redo_action', 'sportic_redo_nonce' ); ?>
						<input type="hidden" name="sportic_active_tab"    value="<?php echo esc_attr($activeTab); ?>">
						<input type="hidden" name="sportic_active_subday" value="<?php echo esc_attr($diaSeleccionatKey); ?>">
						<input type="hidden" name="selected_date"         value="<?php echo esc_attr($data_seleccionada); ?>">
						<input type="hidden" name="cal_year"              value="<?php echo intval($any); ?>">
						<input type="hidden" name="cal_month"             value="<?php echo intval($mes); ?>">
						<input type="hidden" name="lloc"                  value="<?php echo esc_attr($lloc_actiu_slug); ?>">
						<button type="submit" class="sportic-action-btn-icon" title="Refer">
							<img src="<?php echo esc_url(plugin_dir_url(__FILE__) . 'imatges/icon-redo.png'); ?>" alt="Refer" style="width:20px; height:20px;" />
						</button>
					</form>
					
					<!-- BOTÓ MODE ZEN (FULLSCREEN) -->
					<button type="button" id="sportic-fullscreen-toggle" class="sportic-action-btn-icon" title="Mode Zen (Pantalla Completa)">
						<span class="dashicons dashicons-editor-expand" style="line-height: inherit; font-size: 20px;"></span>
					</button>
					
					<?php
					echo '<button type="submit" class="sportic-action-btn-save" form="sportic-main-form">';
						echo '<img src="' . esc_url(plugin_dir_url(__FILE__) . 'imatges/icon-save.png') . '" alt="Desar" style="width:18px; height:18px; margin-right: 8px;" />';
						echo 'DESAR CANVIS';
					echo '</button>';
				echo '</div>';
			echo '</div>';
	
			?>
			<style>
				body.toplevel_page_sportic-onefile-menu { background-color: #ffffff !important; }
				.sportic-unfile-admin { background-color: #ffffff; }
				.sportic-loader { border: 8px solid #e5e7eb; border-top: 8px solid #3b82f6; border-radius: 50%; width: 60px; height: 60px; animation: sportic_spin 1s linear infinite; }
				@keyframes sportic_spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
				.sportic-main-header { position: sticky; top: 32px; z-index: 999; background: rgba(255, 255, 255, 0.97); padding: 10px 20px; margin: -10px -20px 25px -20px; border-radius: 50px; display: flex; align-items: center; justify-content: space-between; transition: top 0.3s ease; }
				.sportic-header-left { display: flex; align-items: center; gap: 15px; }
				.sportic-back-to-llocs { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; background-color: #f3f4f6; border-radius: 50%; text-decoration: none; transition: background-color 0.2s; }
				.sportic-back-to-llocs:hover { background-color: #e5e7eb; }
				.sportic-back-to-llocs .dashicons { font-size: 24px; color: #4b5563; line-height: 1; }
				.sportic-logo-wrapper img { height: 56px; width: auto; }
				.sportic-lloc-display-wrapper { display: flex; align-items: center; gap: 8px; background-color: #f3f4f6; padding: 8px 15px; border-radius: 30px; border: 1px solid #e5e7eb; }
				.sportic-lloc-display-wrapper .dashicons { color: #6b7280; font-size: 22px; }
				.sportic-lloc-display-name { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 1.1em; color: #1f2937; }
				.sportic-actions-wrapper { display: flex; align-items: center; gap: 12px; }
				.sportic-action-btn-icon, .sportic-action-btn-save { display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 21px; font-weight: 600; cursor: pointer; transition: background-color 0.2s, box-shadow 0.2s; }
				.sportic-action-btn-icon { background-color: #4061aa; width: 44px; height: 44px; color: #4b5563; box-shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06); }
				.sportic-action-btn-icon:hover { background-color: #f3f4f6; }
				.sportic-action-btn-icon img { vertical-align: middle; }
				.sportic-action-btn-save { background-color: #4061aa; color: white; padding: 0 20px; height: 44px; font-size: 14px; }
				.sportic-action-btn-save:hover { background-color: #1d4ed8; }
				button.sportic-action-btn-icon { width: auto !important; min-width: 0 !important; max-width: none !important; height: 40px !important; border-radius: 9999px !important; padding-left: 0px !important; padding-right: 0px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; line-height: 1 !important; box-sizing: border-box !important; width: 40px !important; }
				button.sportic-action-btn-icon:hover, button.sportic-action-btn:hover { background-color: #00b2ca !important; }
				button.sportic-action-btn-icon:hover, button.sportic-action-btn-save:hover, button.sportic-action-btn:hover { background-color: #00b2ca !important; }
				.top-row { display: grid; grid-template-columns: 50% 1fr; gap: 25px; margin-bottom: 25px; align-items: start; }
				a.nav-tab.sportic-folder-tab.sportic-main-tab { margin-left: -10px !important; margin-right: 15px !important;}
				button.sportic-folder-tab.sportic-secondary-tab { margin-left: -10px !important; margin-right: 10px !important }
				.sportic-column { display: flex; flex-direction: column; }
				.sportic-column-header { display: flex; align-items: center; gap: 8px; margin-bottom: 15px; padding-left: 10px; }
				.sportic-column-header h2 { font-size: 1.3em; margin: 0; font-weight: 600; color: #374151; }
				.sportic-column-header img { width: 20px; height: 20px; }
				.sportic-content-box { flex-grow: 1; display: flex; flex-direction: column; background: #fff; border-radius: 30px; padding: 25px; box-shadow: 0 4px 31px 0 rgba(0,0,0,.15), 0 2px 4px -2px rgba(0,0,0,.07); }
				.top-card { padding: 0; box-shadow: none; background: transparent; border-radius: 0; }
				.top-card .sportic-folder-tabs-wrapper { position: relative; padding-left: 10px; }
				.sportic-folder-tab.sportic-main-tab:not(.nav-tab-active):hover { background-color: #eaeef6 !important; z-index: 11; }
				.top-card .sportic-folder-tab { display: inline-flex; align-items: center; background-color: #f3f4f6; color: #4b5563; padding: 15px 25px; border: 1px solid #e5e7eb; border-top-left-radius: 15px; border-top-right-radius: 15px; font-weight: 600; cursor: pointer; position: relative; margin-left: 4px; margin-bottom: 0; z-index: 1; font-size: 1.1em; }
				.top-card .sportic-folder-tab.active { background-color: #eaeef6; color: #111827; border-bottom-color: #eaeef6; z-index: 3; }
				.top-card .sportic-folder-content { display: grid; position: relative; border: 1px solid #e5e7eb; border-radius: 30px; padding: 21px 25px; margin-top: -12px; border-top-left-radius: 0px; z-index: 2; background: #eaeef6; }
				.top-card .sportic-folder-content .content-pane { grid-column: 1; grid-row: 1; opacity: 0; pointer-events: none; transition: opacity 0.15s ease-in-out; }
				.top-card .sportic-folder-content .content-pane.active { opacity: 1; pointer-events: auto; }
				.sportic-action-btn-save span.dashicons { color: #ffffff !important; margin-right: 7px;; }
				.sportic-action-btn-icon span.dashicons { color: #ffffff !important; font-size: 20px; width: 20px; height: 20px; line-height: 20px; }
			</style>
			<?php
	
			echo '<div class="top-row">';
				echo '<div class="sportic-column">';
					echo '<div class="sportic-column-header">';
						echo '<img src="' . esc_url(plugin_dir_url(__FILE__) . 'imatges/icon-calendar.png') . '" alt="Calendari" />';
						echo '<h2>CALENDARI</h2>';
					echo '</div>';
					echo '<div class="sportic-content-box sportic-calendari">';
						sportic_unfile_mostrar_calendari();
					echo '</div>';
				echo '</div>';
				echo '<div class="sportic-column top-card">';
					echo '<div class="sportic-folder-tabs-wrapper">';
						echo '<button id="tab-llegenda" class="sportic-folder-tab active">';
							echo '<img src="' . esc_url(plugin_dir_url(__FILE__) . 'imatges/icon-legend.png') . '" alt="Llegenda" style="width:20px; height:20px; margin-right: 8px;" />';
							echo 'LLEGENDA</button>';
						echo '<button id="tab-plant" class="sportic-folder-tab">';
							echo '<img src="' . esc_url(plugin_dir_url(__FILE__) . 'imatges/icon-templates.png') . '" alt="Plantilles" style="width:20px; height:20px; margin-right: 8px;" />';
							echo 'PLANTILLES</button>';
					echo '</div>';
					echo '<div class="sportic-content-box sportic-folder-content">';
						echo '<div id="carpeta-llegenda" class="content-pane active">';
							echo sportic_unfile_mostrar_llegenda();
						echo '</div>';
						echo '<div id="carpeta-plantilles" class="content-pane">';
							?>
							<form id="form-aplicar-plantilla" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
								<input type="hidden" name="action" value="sportic_unfile_aplicar_plantilla" />
								<?php wp_nonce_field( 'sportic_apply_template_action', 'sportic_apply_template_nonce' ); ?>
								<div style="margin-bottom:10px;"><label for="template_option" style="display:block; margin-bottom:5px; font-weight: 600;">Plantilla:</label><select name="template_option" id="template_option" required style="width: 100%;"><?php
								$plantilles = sportic_unfile_get_plantilles($lloc_actiu_slug);
								if ( ! is_array( $plantilles ) ) $plantilles = array();
								echo '<option value="">-- Escull una plantilla --</option>';
								$plantillesDia = array(); $plantillesSetmana = array(); $plantillesSingle = array();
								foreach ( $plantilles as $tmplId => $tmpl ) { if ( ! is_array($tmpl) ) continue; if ( isset( $tmpl['type'] ) ) { if ( $tmpl['type'] === 'week' ) $plantillesSetmana[ $tmplId ] = $tmpl; elseif ( $tmpl['type'] === 'single' ) $plantillesSingle[ $tmplId ] = $tmpl; else $plantillesDia[ $tmplId ] = $tmpl; } else $plantillesDia[ $tmplId ] = $tmpl; }
								if (!empty($plantillesDia)) { echo '<optgroup label="Plantilles Diàries">'; foreach ($plantillesDia as $pid => $pt) echo '<option value="' . esc_attr($pid . '|day') . '">' . esc_html($pt['name']) . '</option>'; echo '</optgroup>'; }
								if (!empty($plantillesSetmana)) { echo '<optgroup label="Plantilles Setmanals">'; foreach ($plantillesSetmana as $pid => $pt) echo '<option value="' . esc_attr($pid . '|week') . '">' . esc_html($pt['name']) . '</option>'; echo '</optgroup>'; }
								if (!empty($plantillesSingle)) { echo '<optgroup label="Plantilles Individuals">'; $poolLabelsForOpts = sportic_unfile_get_pool_labels_sorted(); foreach ($plantillesSingle as $pid => $pt) { $poolSlugForOpt = isset($pt['piscina']) ? $pt['piscina'] : 'infantil'; $poolLabelForOpt = isset($poolLabelsForOpts[$poolSlugForOpt]) ? $poolLabelsForOpts[$poolSlugForOpt]['label'] : ucfirst($poolSlugForOpt); echo '<option value="' . esc_attr($pid . '|single|' . $poolSlugForOpt) . '">' . esc_html($pt['name'] . ' (' . $poolLabelForOpt . ')') . '</option>'; } echo '</optgroup>'; }
								?></select></div>
								<div style="margin-bottom:10px;"><label for="rang_data_inici" style="display:block; margin-bottom:5px; font-weight: 600;">Data inici:</label><input type="date" id="rang_data_inici" name="rang_data_inici" required style="width: 100%;"/></div>
								<div style="margin-bottom:10px;"><label for="rang_data_fi" style="display:block; margin-bottom:5px; font-weight: 600;">Data fi:</label><input type="date" id="rang_data_fi" name="rang_data_fi" required style="width: 100%;"/></div>
								<div style="margin-bottom:10px;"><label style="display:block; margin-bottom:5px; font-weight: 600;">Dies d'aplicació (opcional):</label><div>
									<input type="checkbox" name="dia_filter[]" value="1" id="dia1_sub"> <label for="dia1_sub">Dl</label> 
									<input type="checkbox" name="dia_filter[]" value="2" id="dia2_sub"> <label for="dia2_sub">Dt</label> 
									<input type="checkbox" name="dia_filter[]" value="3" id="dia3_sub"> <label for="dia3_sub">Dc</label> 
									<input type="checkbox" name="dia_filter[]" value="4" id="dia4_sub"> <label for="dia4_sub">Dj</label> 
									<input type="checkbox" name="dia_filter[]" value="5" id="dia5_sub"> <label for="dia5_sub">Dv</label> 
									<input type="checkbox" name="dia_filter[]" value="6" id="dia6_sub"> <label for="dia6_sub">Ds</label> 
									<input type="checkbox" name="dia_filter[]" value="7" id="dia7_sub"> <label for="dia7_sub">Dg</label>
								</div></div>
								<div class="sportic-ignore-lock-wrapper" style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #eee;"><label for="ignore_lock_template"><input type="checkbox" id="ignore_lock_template" name="ignore_lock_template" value="1"> Ignorar bloqueig de cel·les</label></div>
								<input type="hidden" name="sportic_active_tab" value="<?php echo esc_attr($activeTab); ?>"><input type="hidden" name="sportic_active_subday" value="<?php echo esc_attr($diaSeleccionatKey); ?>"><input type="hidden" name="selected_date" value="<?php echo esc_attr($data_seleccionada); ?>"><input type="hidden" name="cal_year" value="<?php echo intval($any); ?>"><input type="hidden" name="cal_month" value="<?php echo intval($mes); ?>">
								<input type="hidden" name="lloc" value="<?php echo esc_attr($lloc_actiu_slug); ?>">
								<div style="text-align:center; margin-top:25px;"><button type="submit" name="apply_template" class="sportic-action-btn-save" style="width: 100%;"><span class="dashicons dashicons-yes-alt"></span>APLICAR PLANTILLA</button></div>
							</form>
							<?php
						echo '</div>';
					echo '</div>';
				echo '</div>';
			echo '</div>';
	
			echo '<form id="sportic-main-form" method="post" action="' . esc_url( admin_url('admin-post.php') ) . '">';
				wp_nonce_field( 'sportic_save_action', 'sportic_save_nonce' );
				echo '<input type="hidden" name="sportic_active_tab" id="sportic_active_tab_input" value="' . esc_attr($activeTab) . '" />';
				echo '<input type="hidden" name="sportic_active_subday" id="sportic_active_subday_input" value="' . esc_attr($diaSeleccionatKey) . '" />';
				echo '<input type="hidden" name="action" value="sportic_unfile_guardar" />';
				echo '<input type="hidden" name="sportic_dades_json" id="sportic_dades_json" value="" />';
				echo '<input type="hidden" id="selected_date_input" name="selected_date" value="' . esc_attr( $data_seleccionada ) . '" />';
				echo '<input type="hidden" name="cal_year"  value="' . esc_attr( $any ) . '" />';
				echo '<input type="hidden" name="cal_month" value="' . esc_attr( $mes ) . '" />';
				echo '<input type="hidden" name="lloc" value="' . esc_attr($lloc_actiu_slug) . '"/>';
				echo '<h2 class="nav-tab-wrapper sportic-main-tabs-wrapper">';
					if (!empty($etiquetesPiscines)) { 
						foreach ( $etiquetesPiscines as $slug => $pinfo ) { 
							$hash = '#' . $slug; 
							$classeActiva = ($hash === $activeTab) ? 'nav-tab-active' : '';
							echo '<a href="' . esc_attr( $hash ) . '" class="nav-tab sportic-folder-tab sportic-main-tab ' . $classeActiva . '">' . esc_html( $pinfo['label'] ) . '</a>'; 
						} 
					} else {
						echo '<div class="sportic-no-pavilions-message" style="padding: 20px; text-align: center; background: #fff5f5; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b;">No hi ha pavellons configurats per a aquest lloc. Ves a "Configurar Pavellons" per afegir-ne.</div>';
					}
				echo '</h2>';
				echo '<div class="sportic-main-content-box">';
				if (!empty($etiquetesPiscines)) { 
					foreach ( $etiquetesPiscines as $slug => $pinfo ) { 
						$display = ('#' . $slug == $activeTab) ? 'display:block;' : 'display:none;';
						echo '<div id="' . esc_attr( $slug ) . '" class="sportic-tab-content" style="' . $display . '">'; 
							$data_piscina_actual = isset( $dades[ $slug ] ) ? $dades[ $slug ] : array(); 
							sportic_unfile_mostrar_dies( $slug, $data_piscina_actual, $setmanaDates, $diaSeleccionatKey ); 
						echo '</div>'; 
					} 
				}
				echo '</div>';
			echo '</form>';
			sportic_unfile_output_inline_css(); 
			sportic_unfile_output_inline_js();
			?>
			<script>
			document.addEventListener('DOMContentLoaded', function() {
				function updateUndoRedoForms() {
					var activeTabValue = document.getElementById('sportic_active_tab_input')?.value || '';
					var activeSubdayValue = document.getElementById('sportic_active_subday_input')?.value || '';
					var selectedDateValue = document.getElementById('selected_date_input')?.value || '';
					var calYearValue = document.querySelector('form#sportic-main-form input[name="cal_year"]')?.value || '';
					var calMonthValue = document.querySelector('form#sportic-main-form input[name="cal_month"]')?.value || '';
					var llocValue = document.querySelector('form#sportic-main-form input[name="lloc"]')?.value || '';
					document.querySelectorAll('.undo-redo-form').forEach(function(frm){
						frm.querySelector('input[name="sportic_active_tab"]').value = activeTabValue;
						frm.querySelector('input[name="sportic_active_subday"]').value = activeSubdayValue;
						frm.querySelector('input[name="selected_date"]').value = selectedDateValue;
						frm.querySelector('input[name="cal_year"]').value = calYearValue;
						frm.querySelector('input[name="cal_month"]').value = calMonthValue;
						frm.querySelector('input[name="lloc"]').value = llocValue;
					});
				}
				document.querySelectorAll('.nav-tab-wrapper a, .sportic-dies-tabs-nav a').forEach(el => el.addEventListener('click', () => setTimeout(updateUndoRedoForms, 50)));
				updateUndoRedoForms();
				var tabPlant = document.getElementById('tab-plant');
				var tabLlegenda = document.getElementById('tab-llegenda');
				var divPlant = document.getElementById('carpeta-plantilles');
				var divLlegenda = document.getElementById('carpeta-llegenda');
				if(tabPlant && divPlant && tabLlegenda && divLlegenda){
					tabPlant.addEventListener('click', function(){
						tabPlant.classList.add('active');
						tabLlegenda.classList.remove('active');
						divPlant.classList.add('active');
						divLlegenda.classList.remove('active');
					});
					tabLlegenda.addEventListener('click', function(){
						tabLlegenda.classList.add('active');
						tabPlant.classList.remove('active');
						divLlegenda.classList.add('active');
						divPlant.classList.remove('active');
					});
				}
				const calTitleClickable = document.getElementById('sportic-cal-title-clickable');
				const datePickerPopup = document.getElementById('sportic-date-picker-popup');
				if (calTitleClickable && datePickerPopup) {
					const yearInputPopup = datePickerPopup.querySelector('#year-input-popup'), prevYearBtn = datePickerPopup.querySelector('#prev-year-popup'), nextYearBtn = datePickerPopup.querySelector('#next-year-popup'), monthGridInPopup = datePickerPopup.querySelector('.month-grid'), applyBtnPopup = datePickerPopup.querySelector('#apply-date-popup'), cancelBtnPopup = datePickerPopup.querySelector('#cancel-date-popup');
					const initialYearFromPHP = <?php echo isset($any) ? $any : date('Y'); ?>; const initialMonthFromPHP = <?php echo isset($mes) ? $mes : date('n'); ?>; const baseUrlForCalendar = '<?php echo esc_js(admin_url( 'admin.php?page=sportic-onefile-menu' )); ?>';
					let currentPopupYear = initialYearFromPHP; let currentPopupMonth = initialMonthFromPHP;
					function initPopupValues() { yearInputPopup.value = currentPopupYear; monthGridInPopup.querySelectorAll('button').forEach(btn => { btn.classList.remove('selected'); if (parseInt(btn.dataset.month) === currentPopupMonth) btn.classList.add('selected'); }); }
					calTitleClickable.addEventListener('click', function(event) { event.stopPropagation(); const urlParams = new URLSearchParams(window.location.search); currentPopupYear = parseInt(urlParams.get('cal_year')) || initialYearFromPHP; currentPopupMonth = parseInt(urlParams.get('cal_month')) || initialMonthFromPHP; initPopupValues(); datePickerPopup.style.display = datePickerPopup.style.display === 'block' ? 'none' : 'block'; if (datePickerPopup.style.display === 'block') { const popupRect = datePickerPopup.getBoundingClientRect(); if (popupRect.right > window.innerWidth) { datePickerPopup.style.left = 'auto'; datePickerPopup.style.right = '0px'; datePickerPopup.style.transform = 'translateX(0)'; } else if (popupRect.left < 0) { datePickerPopup.style.left = '0px'; datePickerPopup.style.right = 'auto'; datePickerPopup.style.transform = 'translateX(0)'; } else { datePickerPopup.style.left = '50%'; datePickerPopup.style.right = 'auto'; datePickerPopup.style.transform = 'translateX(-50%)'; } } });
					document.addEventListener('click', function(event) { if (datePickerPopup.style.display === 'block' && !datePickerPopup.contains(event.target) && !calTitleClickable.contains(event.target)) datePickerPopup.style.display = 'none'; });
					prevYearBtn.addEventListener('click', () => { currentPopupYear--; yearInputPopup.value = currentPopupYear; });
					nextYearBtn.addEventListener('click', () => { currentPopupYear++; yearInputPopup.value = currentPopupYear; });
					yearInputPopup.addEventListener('input', () => { let yrVal = parseInt(yearInputPopup.value); if (!isNaN(yrVal) && yrVal >= 1900 && yrVal <= 2100) currentPopupYear = yrVal; else yearInputPopup.value = currentPopupYear; });
					monthGridInPopup.querySelectorAll('button').forEach(btn => { btn.addEventListener('click', () => { currentPopupMonth = parseInt(btn.dataset.month); monthGridInPopup.querySelectorAll('button').forEach(b => b.classList.remove('selected')); btn.classList.add('selected'); }); });
					cancelBtnPopup.addEventListener('click', () => { datePickerPopup.style.display = 'none'; });
					applyBtnPopup.addEventListener('click', () => { const newYear = parseInt(yearInputPopup.value); const newMonth = currentPopupMonth; if (isNaN(newYear) || newYear < 1900 || newYear > 2100) { alert("Si us plau, introdueix un any vàlid (1900-2100)."); yearInputPopup.focus(); return; } const newSelectedDate = `${newYear}-${String(newMonth).padStart(2, '0')}-01`; let newUrl = `${baseUrlForCalendar}&cal_year=${newYear}&cal_month=${newMonth}&selected_date=${newSelectedDate}`; const currentUrlParams = new URLSearchParams(window.location.search); const activeTab = currentUrlParams.get('active_tab') || (document.getElementById('sportic_active_tab_input') ? document.getElementById('sportic_active_tab_input').value : ''); const activeSubday = currentUrlParams.get('active_subday') || (document.getElementById('sportic_active_subday_input') ? document.getElementById('sportic_active_subday_input').value : '');
					const activeLloc = '<?php echo esc_js($lloc_actiu_slug); ?>'; if (activeLloc) newUrl += `&lloc=${encodeURIComponent(activeLloc)}`;
					if (activeTab) newUrl += `&active_tab=${encodeURIComponent(activeTab)}`; if (activeSubday) newUrl += `&active_subday=${encodeURIComponent(activeSubday)}`; var sporticLoaderOverlay = document.getElementById('sportic_loader_overlay_admin'); if (sporticLoaderOverlay) { window.SPORTIC_LOADER_MSG = 'Carregant calendari...'; sportic_show_loader(window.SPORTIC_LOADER_MSG); } window.location.href = newUrl; });
					initPopupValues();
				}
			});
			</script>
			<?php
			echo '</div>'; 
			?>
			<script>
			function sportic_show_loader(msg){ var overlay = document.getElementById('sportic_loader_overlay_admin'); if(!overlay) return; var txt = overlay.querySelector('.sportic-loader-text'); if(txt) txt.textContent = msg || 'Carregant...'; overlay.style.display = 'block'; }
			function sportic_hide_loader(){ var overlay = document.getElementById('sportic_loader_overlay_admin'); if(overlay) overlay.style.display = 'none'; }
			window.SPORTIC_LOADER_MSG = 'Carregant...';
			document.addEventListener('DOMContentLoaded', function(){
				var mainForm = document.getElementById('sportic-main-form'); if(mainForm) mainForm.addEventListener('submit', function(){ window.SPORTIC_LOADER_MSG = 'Desant canvis...'; sportic_show_loader(window.SPORTIC_LOADER_MSG); });
				var formPlantilla = document.getElementById('form-aplicar-plantilla'); if(formPlantilla) formPlantilla.addEventListener('submit', function(){ window.SPORTIC_LOADER_MSG = 'Aplicant plantilla...'; sportic_show_loader(window.SPORTIC_LOADER_MSG); });
				document.querySelectorAll('.undo-redo-form').forEach(function(frm){ frm.addEventListener('submit', function(){ var actionField = frm.querySelector('input[name="action"]'); if(!actionField) return; if(actionField.value === 'sportic_undo_change') window.SPORTIC_LOADER_MSG = 'Desfent canvis...'; else if(actionField.value === 'sportic_redo_change') window.SPORTIC_LOADER_MSG = 'Refent canvis...'; else window.SPORTIC_LOADER_MSG = 'Processant...'; sportic_show_loader(window.SPORTIC_LOADER_MSG); }); });
				document.querySelectorAll('.sportic-calendari a').forEach(function(link){ link.addEventListener('click', function(e){ 
					e.preventDefault(); 
					const newUrl = new URL(link.href);
					const activeLloc = '<?php echo esc_js($lloc_actiu_slug); ?>';
					if (activeLloc) {
						newUrl.searchParams.set('lloc', activeLloc);
					}
					window.SPORTIC_LOADER_MSG = 'Carregant calendari...'; 
					sportic_show_loader(window.SPORTIC_LOADER_MSG); 
					window.location.href = newUrl.toString();
				}); });
				window.addEventListener('beforeunload', function(event){ let isInternalSubmit = false; if (event.target?.activeElement?.form) { const formId = event.target.activeElement.form.id; if (formId === 'sportic-main-form' || formId === 'form-aplicar-plantilla' || event.target.activeElement.form.classList.contains('undo-redo-form')) isInternalSubmit = true; } let isCalendarLink = false; if (event.target?.activeElement?.closest('.sportic-calendari')) isCalendarLink = true; if (isInternalSubmit || isCalendarLink) sportic_show_loader(window.SPORTIC_LOADER_MSG || 'Carregant...'); });
				setTimeout(() => { sportic_hide_loader(); const mainContainer = document.getElementById('sportic_main_container'); if (mainContainer) mainContainer.style.display = 'block'; }, 200);
			});
			</script>
			<?php
		}
	
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tens permisos per accedir a aquesta pàgina.' );
			return;
		}
	
		$lloc_seleccionat = isset($_GET['lloc']) ? sanitize_key($_GET['lloc']) : '';
	
		if ( ! empty($lloc_seleccionat) ) {
			sportic_render_schedule_view();
		} else {
			sportic_render_lloc_selector_view();
		}
	}
   /**
	* GUARDAR LES DADES: processament del formulari (via JSON)
	*/
	add_action( 'admin_post_sportic_unfile_guardar', 'sportic_unfile_guardar_handler' );
function sportic_unfile_guardar_handler() {
		check_admin_referer('sportic_save_action', 'sportic_save_nonce');
	
		if (!current_user_can('manage_options')) {
			wp_die('No tens permisos suficients per realitzar aquesta acció.');
		}
		
		$oldState = sportic_carregar_tot_com_array();
	
		$customActivities = get_option('sportic_unfile_custom_letters', array());
		$valid_descriptions = ['l', 'b'];
		if(is_array($customActivities)){
			foreach($customActivities as $act){
				if(!empty($act['description'])){
					$valid_descriptions[] = trim($act['description']);
				}
			}
		}
		
		if (isset($_POST['sportic_dades_json']) && !empty($_POST['sportic_dades_json'])) {
			$jsonPiscines = stripslashes($_POST['sportic_dades_json']);
			$dataPiscines = json_decode($jsonPiscines, true);
	
			if (is_array($dataPiscines)) {
				$dades = $oldState;
				foreach ($dataPiscines as $piscinaSlug => $diesData) {
					if (!is_array($diesData)) continue;
					if (!isset($dades[$piscinaSlug])) { $dades[$piscinaSlug] = array(); }
					
					foreach ($diesData as $dia => $hores) {
						if (!is_array($hores)) continue;
						if (!isset($dades[$piscinaSlug][$dia])) { $dades[$piscinaSlug][$dia] = array(); }
	
						foreach ($hores as $hora => $valorsCarrils) {
							if (!is_array($valorsCarrils)) continue;
	
							$carrilsNet = array();
							foreach ($valorsCarrils as $valRaw) {
								$valRaw = stripslashes(trim($valRaw));
								$isLocked = strpos($valRaw, '!') === 0;
								$isRecurrent = strpos($valRaw, '@') === 0;
								
								$valorBase = preg_replace('/^[@!]/', '', $valRaw);
	
								if (!in_array($valorBase, $valid_descriptions, true)) {
									$valorBase = 'l';
								}
	
								$valorFinal = $valorBase;
								if ($isRecurrent) { $valorFinal = '@' . $valorBase; }
								elseif ($isLocked) { $valorFinal = '!' . $valorBase; }
								
								$carrilsNet[] = $valorFinal;
							}
							$dades[$piscinaSlug][$dia][$hora] = $carrilsNet;
						}
					}
				}
	
				$diff = sportic_extract_diff($oldState, $dades);
				if (!empty($diff['old_partial']) || !empty($diff['new_partial'])) {
					sportic_save_undo_entry('sportic_unfile_dades', $diff);
					sportic_clear_redo('sportic_unfile_dades');
				}
	
				sportic_emmagatzemar_tot_com_array($dades);
			}
		}
		
		// Recollim tots els paràmetres per a la redirecció
		$active_tab = !empty($_POST['sportic_active_tab']) ? sanitize_text_field($_POST['sportic_active_tab']) : '';
		$active_subday = !empty($_POST['sportic_active_subday']) ? sanitize_text_field($_POST['sportic_active_subday']) : '';
		$selected_date = !empty($_POST['selected_date']) ? sanitize_text_field($_POST['selected_date']) : '';
		$cal_year = !empty($_POST['cal_year']) ? intval($_POST['cal_year']) : 0;
		$cal_month= !empty($_POST['cal_month'])? intval($_POST['cal_month']): 0;
		
		// ================================================================
		// CANVI: Afegim el paràmetre 'lloc' a la redirecció
		// ================================================================
		$lloc_actiu = !empty($_POST['lloc']) ? sanitize_key($_POST['lloc']) : '';
	
		$redirect_url = admin_url('admin.php?page=sportic-onefile-menu&status=ok');
		
		if ($lloc_actiu) { $redirect_url .= '&lloc=' . urlencode($lloc_actiu); }
		if ($selected_date) { $redirect_url .= '&selected_date=' . urlencode($selected_date); }
		if ($cal_year && $cal_month) { $redirect_url .= '&cal_year=' . $cal_year . '&cal_month=' . $cal_month; }
		if ($active_tab) { $redirect_url .= '&active_tab=' . urlencode($active_tab); }
		if ($active_subday) { $redirect_url .= '&active_subday=' . urlencode($active_subday); }
	
		wp_redirect($redirect_url);
		exit;
	}
	/**
	 * ============================================================================
	 * NOVA FUNCIÓ OPTIMITZADA PER CARREGAR DADES PER A UN SOL DIA I PISCINA
	 * Aquesta funció substitueix la crida a `sportic_carregar_tot_com_array`
	 * dins del gestor de CSV, fent-lo molt més ràpid i eficient.
	 * Utilitza transients per a la memòria cau.
	 * ============================================================================
	 */
function sportic_carregar_dades_per_dia_i_piscina($piscina_slug, $dia_str) {
		   global $wpdb;
	   
		   // 1. Clau única per a la memòria cau (transient)
		   $transient_key = 'sportic_csv_data_' . $piscina_slug . '_' . $dia_str;
		   $cached_data = get_transient($transient_key);
	   
		   if ($cached_data !== false && is_array($cached_data)) {
			   return $cached_data;
		   }
	   
		   // --- Si no hi ha dades a la cau, les calculem ---
	   
		   $t_prog = $wpdb->prefix . 'sportic_programacio';
		   $t_lock = defined('SPORTIC_LOCK_TABLE') ? $wpdb->prefix . SPORTIC_LOCK_TABLE : $wpdb->prefix . 'sportic_bloqueig';
		   
		   // 2. Obtenim la programació base només per a aquest dia i piscina
		   $hores_programacio = [];
		   $row_prog = $wpdb->get_row($wpdb->prepare("SELECT hores_serial FROM $t_prog WHERE piscina_slug = %s AND dia_data = %s", $piscina_slug, $dia_str), ARRAY_A);
		   if ($row_prog && !empty($row_prog['hores_serial'])) {
			   $hores_programacio = @maybe_unserialize($row_prog['hores_serial']);
		   }
		   
		   if (!is_array($hores_programacio) || empty($hores_programacio)) {
			   $hores_programacio = sportic_unfile_crear_programacio_default($piscina_slug);
		   }
	   
		   // NOTA: Hem eliminat la consulta a la taula d'excepcions i el bucle de recurrents.
	   
		   // 4. Obtenim els bloquejos manuals (només per a aquest dia i piscina)
		   $locks = $wpdb->get_results($wpdb->prepare("SELECT hora, carril_index FROM $t_lock WHERE piscina_slug = %s AND dia_data = %s", $piscina_slug, $dia_str), ARRAY_A);
		   if ($locks) {
			   foreach ($locks as $lock) {
				   $hora = $lock['hora'];
				   $carril_idx = (int)$lock['carril_index'];
				   if (isset($hores_programacio[$hora][$carril_idx])) {
					   $valor_actual = $hores_programacio[$hora][$carril_idx];
					   if (strpos($valor_actual, '!') !== 0) {
						   $hores_programacio[$hora][$carril_idx] = '!' . $valor_actual;
					   }
				   }
			   }
		   }
	   
		   // 5. Guardem el resultat a la memòria cau (transient) durant 5 minuts
		   set_transient($transient_key, $hores_programacio, 5 * MINUTE_IN_SECONDS);
	   
		   return $hores_programacio;
	   }		 
	
	


	/**
 * ============================================================================
 * NOVA FUNCIÓ AUXILIAR PER CARREGAR DADES D'UNA SETMANA SENCERA
 * Aquesta funció carrega de manera eficient la programació, esdeveniments
 * i bloquejos per a un rang de dates i un conjunt de piscines específics.
 * ============================================================================
 */
function sportic_carregar_dades_setmana_per_piscines($start_week_day, $end_week_day, $pool_slugs_a_carregar) {
	   global $wpdb;
	   $nomTaulaProg = $wpdb->prefix . 'sportic_programacio';
	   $nomTaulaLock = defined('SPORTIC_LOCK_TABLE') ? ($wpdb->prefix . SPORTIC_LOCK_TABLE) : ($wpdb->prefix . 'sportic_bloqueig');
   
	   $configured_pools = sportic_unfile_get_pool_labels_sorted();
	   $data_setmana_final = array();
   
	   // 1. Inicialitzem estructura
	   $period = new DatePeriod(new DateTime($start_week_day), new DateInterval('P1D'), (new DateTime($end_week_day))->modify('+1 day'));
	   foreach ($pool_slugs_a_carregar as $slug_p) {
		   if (!isset($configured_pools[$slug_p])) continue;
		   $data_setmana_final[$slug_p] = array();
		   foreach ($period as $day_obj) {
			   $diaFormatat = $day_obj->format('Y-m-d');
			   $data_setmana_final[$slug_p][$diaFormatat] = [];
		   }
	   }
   
	   // 2. Carreguem la programació base
	   $placeholders = implode(', ', array_fill(0, count($pool_slugs_a_carregar), '%s'));
	   $sql_params = array_merge([$start_week_day, $end_week_day], $pool_slugs_a_carregar);
	   $sql_prog = $wpdb->prepare("SELECT piscina_slug, dia_data, hores_serial FROM $nomTaulaProg WHERE dia_data BETWEEN %s AND %s AND piscina_slug IN ($placeholders)", $sql_params);
	   $rowsProg = $wpdb->get_results($sql_prog, ARRAY_A);
   
	   if ($rowsProg) {
		   foreach ($rowsProg as $fila) {
			   $slug_db = $fila['piscina_slug'];
			   $dia_db = $fila['dia_data'];
			   if (!in_array($slug_db, $pool_slugs_a_carregar)) continue;
			   $hores = (!empty($fila['hores_serial'])) ? @maybe_unserialize($fila['hores_serial']) : false;
			   if ($hores === false || !is_array($hores)) {
				   $hores = sportic_unfile_crear_programacio_default($slug_db);
			   }
			   $data_setmana_final[$slug_db][$dia_db] = $hores;
		   }
	   }
   
	   // 3. Omplim dies buits amb default
	   foreach ($pool_slugs_a_carregar as $slug_p) {
		   foreach ($data_setmana_final[$slug_p] as $dia => $hores_data) {
			   if (empty($hores_data)) {
				   $data_setmana_final[$slug_p][$dia] = sportic_unfile_crear_programacio_default($slug_p);
			   }
		   }
	   }
   
	   // NOTA: Eliminada la consulta d'excepcions i el bucle de recurrents.
   
	   // 5. Apliquem bloquejos manuals
	   $sql_lock = $wpdb->prepare("SELECT piscina_slug, dia_data, hora, carril_index FROM $nomTaulaLock WHERE dia_data BETWEEN %s AND %s AND piscina_slug IN ($placeholders)", $sql_params);
	   $rowsLock = $wpdb->get_results($sql_lock, ARRAY_A);
	   if ($rowsLock) {
		   foreach ($rowsLock as $lockInfo) {
			   $slug = $lockInfo['piscina_slug'];
			   $dia = $lockInfo['dia_data'];
			   $hora = $lockInfo['hora'];
			   $carril = intval($lockInfo['carril_index']);
			   if (isset($data_setmana_final[$slug][$dia][$hora][$carril])) {
				   $valorActual = $data_setmana_final[$slug][$dia][$hora][$carril];
				   if (strpos($valorActual, '!') !== 0) {
					   $data_setmana_final[$slug][$dia][$hora][$carril] = '!' . $valorActual;
				   }
			   }
		   }
	   }
   
	   return $data_setmana_final;
   }
   	/**
	 * ============================================================================
	 * ENDPOINT CSV -> action=sportic_unfile_csv (VERSIÓ MODIFICADA I OPTIMITZADA)
	 * Aquesta versió utilitza la nova funció `sportic_carregar_dades_per_dia_i_piscina`
	 * per a un rendiment òptim.
	 * ============================================================================
	 */

	add_action('wp_ajax_sportic_unfile_csv', 'sportic_unfile_csv_handler');
add_action('wp_ajax_nopriv_sportic_unfile_csv', 'sportic_unfile_csv_handler');

function sportic_unfile_csv_handler() {
	global $wpdb;

	// ========================================================================
	// NOU BLOC: LÒGICA PER A LA DESCÀRREGA SETMANAL
	// ========================================================================
	if (isset($_GET['setmana'])) {
		$data_setmana_req = sanitize_text_field($_GET['setmana']);

		// Validació de la data de la setmana
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_setmana_req)) {
			header('Content-Type: text/plain; charset=UTF-8');
			echo "Error: El format de la data per a la setmana és invàlid. Utilitza YYYY-MM-DD.";
			wp_die();
		}

		try {
			$dObj = new DateTime($data_setmana_req);
		} catch (Exception $e) {
			$dObj = new DateTime(current_time('Y-m-d'));
		}

		$startObj = clone $dObj; $startObj->modify('monday this week');
		$endObj   = clone $startObj; $endObj->modify('sunday this week');
		$start_of_week = $startObj->format('Y-m-d');
		$end_of_week   = $endObj->format('Y-m-d');

		$configured_pools = sportic_unfile_get_pool_labels_sorted();
		$piscines_a_processar = [];

		// Comprovem si l'usuari ha seleccionat piscines específiques
		if (isset($_GET['seleccio']) && !empty($_GET['seleccio'])) {
			$seleccio_slugs = array_map('sanitize_text_field', explode(',', $_GET['seleccio']));
			// Filtrem per assegurar-nos que només processem piscines vàlides i configurades
			foreach ($seleccio_slugs as $slug) {
				if (isset($configured_pools[$slug])) {
					$piscines_a_processar[] = $slug;
				}
			}
		} else {
			// Si no hi ha selecció, processem TOTES les piscines configurades
			$piscines_a_processar = array_keys($configured_pools);
		}

		if (empty($piscines_a_processar)) {
			header('Content-Type: text/plain; charset=UTF-8');
			echo "Error: No s'ha seleccionat cap pavelló vàlid per a la descàrrega.";
			wp_die();
		}
		
		// Cridem la nova funció per carregar totes les dades necessàries d'una sola vegada
		$dades_setmana = sportic_carregar_dades_setmana_per_piscines($start_of_week, $end_of_week, $piscines_a_processar);
		
		$filename = "Setmana_" . $start_of_week . ".csv";
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		$output = fopen('php://output', 'w');

		// Escrivim la capçalera del fitxer
		fputcsv($output, ['INICI PROGRAMACIÓ SETMANA: ' . $start_of_week . ' - ' . $end_of_week]);
		if (isset($_GET['seleccio'])) {
			fputcsv($output, ['PAVELLONS SELECCIONATS: ' . implode(', ', $piscines_a_processar)]);
		} else {
			fputcsv($output, ['PAVELLONS SELECCIONATS: Tots']);
		}
		fputcsv($output, []); // Línia en blanc

		$period = new DatePeriod($startObj, new DateInterval('P1D'), $endObj->modify('+1 day'));

		foreach ($period as $day_obj) {
			$dia_actual_str = $day_obj->format('Y-m-d');
			
			foreach ($piscines_a_processar as $slug_piscina) {
				// Títol per a cada taula dins del CSV
				fputcsv($output, ['PAVELLÓ: ' . $configured_pools[$slug_piscina]['label'] . ' | DIA: ' . $dia_actual_str]);
				
				$num_carrils = $configured_pools[$slug_piscina]['lanes'] ?? 0;
				$header_carrils = ['Hora'];
				for ($i = 1; $i <= $num_carrils; $i++) {
					$header_carrils[] = 'Carril_' . $i;
				}
				fputcsv($output, $header_carrils);

				$dades_del_dia = $dades_setmana[$slug_piscina][$dia_actual_str] ?? [];
				
				// Ordenem les hores per assegurar un ordre consistent
				uksort($dades_del_dia, 'strnatcmp');

				foreach ($dades_del_dia as $hora => $arrCarrils) {
					if (!sportic_unfile_is_time_in_open_range($hora)) {
						continue;
					}
					$fila = [$hora];
					if (!is_array($arrCarrils)) $arrCarrils = array_fill(0, $num_carrils, 'l');
					
					// Assegurem que el número de carrils és correcte
					$count_carrils_data = count($arrCarrils);
					if ($count_carrils_data < $num_carrils) $arrCarrils = array_pad($arrCarrils, $num_carrils, 'l');
					elseif ($count_carrils_data > $num_carrils) $arrCarrils = array_slice($arrCarrils, 0, $num_carrils);

					foreach ($arrCarrils as $valRaw) {
						// Neteja de prefixos ('!' o '@') per a la descàrrega
						$valorNet = preg_replace('/^[@!]/', '', $valRaw);
						$valorNet = ($valorNet === false || $valorNet === '') ? 'l' : $valorNet;
						if (!is_string($valorNet)) $valorNet = 'l';

						$val_final = strtolower($valorNet);
						if ($val_final === 'l') $fila[] = 'l';
						elseif ($val_final === 'b') $fila[] = 'b';
						else $fila[] = 'o';
					}
					fputcsv($output, $fila);
				}
				fputcsv($output, []); // Línia en blanc entre piscines/dies
			}
		}
		
		fclose($output);
		wp_die();

	// ========================================================================
	// LÒGICA ORIGINAL PER A DESCÀRREGA DIÀRIA (SENSE CAP CANVI)
	// Aquesta part només s'executa si no es demana una setmana sencera.
	// ========================================================================
	} else {
		$piscina_slug_req = isset($_GET['piscina']) ? sanitize_text_field($_GET['piscina']) : '';
		$dia_req          = isset($_GET['dia'])     ? sanitize_text_field($_GET['dia'])     : '';
	
		// *** VALIDACIÓ PISCINA I DIA ***
		$configured_pools = sportic_unfile_get_pool_labels_sorted();
		$piscines_valides_slugs = array_keys($configured_pools);
	
		if (!in_array($piscina_slug_req, $piscines_valides_slugs)) {
			header('Content-Type: text/plain; charset=UTF-8');
			echo "Error: Pavelló no vàlid o no configurat.";
			wp_die();
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia_req)) {
			header('Content-Type: text/plain; charset=UTF-8');
			echo "Error: Format de dia invàlid.";
			wp_die();
		}
		
		// <-- Utilitzem la funció optimitzada per a un sol dia -->
		$dades_del_dia = sportic_carregar_dades_per_dia_i_piscina($piscina_slug_req, $dia_req);
	
		// Nombre de carrils per la capçalera (des de la configuració)
		$num_carrils = isset($configured_pools[$piscina_slug_req]['lanes']) ? intval($configured_pools[$piscina_slug_req]['lanes']) : 0;
		if ($num_carrils === 0) { // Error final si no podem determinar carrils
			header('Content-Type: text/plain; charset=UTF-8');
			echo "Error: No s'ha pogut determinar el nombre de carrils per al pavelló '{$piscina_slug_req}'.";
			wp_die();
		}
	
		$csvData = array();
		$header = array('Hora', $piscina_slug_req);
		for ($i = 1; $i <= $num_carrils; $i++) {
			$header[] = 'Carril_' . $i;
		}
		$csvData[] = $header;
	
		// La resta de la lògica per generar el CSV es manté igual,
		// ja que opera sobre `$dades_del_dia`
		foreach ($dades_del_dia as $hora => $arrCarrils) {
			if (!sportic_unfile_is_time_in_open_range($hora)) {
				continue;
			}
			$fila = array($hora, $piscina_slug_req);
	
			if (!is_array($arrCarrils)) { $arrCarrils = array_fill(0, $num_carrils, 'l'); }
			if (count($arrCarrils) < $num_carrils) { $arrCarrils = array_pad($arrCarrils, $num_carrils, 'l'); }
			elseif (count($arrCarrils) > $num_carrils) { $arrCarrils = array_slice($arrCarrils, 0, $num_carrils); }
	
			foreach ($arrCarrils as $valRaw) {
				// Traiem prefixos per a la descàrrega
				$valorReal = preg_replace('/^[@!]/', '', $valRaw);
				if ($valorReal === false || $valorReal === '') $valorReal = 'l';
				if (!is_string($valorReal)) $valorReal = 'l';
				
				$val = strtolower($valorReal);
				if ($val === 'l') {
					$fila[] = 'l';
				} elseif ($val === 'b') {
					$fila[] = 'b';
				} else {
					$fila[] = 'o';
				}
			}
			$csvData[] = $fila;
		}
	
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="Dia_' . $dia_req . '_' . $piscina_slug_req . '.csv"');
		$output = fopen('php://output', 'w');
		foreach ($csvData as $row) {
			fputcsv($output, $row);
		}
		fclose($output);
		wp_die();
	}
}
	
	
	/**
		* CSS inline per l'administració (principal i subpàgina) + classes personalitzades.
		*/
function sportic_unfile_output_inline_css() {
			?>
			<style>
			body.wp-admin .sportic-unfile-admin .sportic-tab-content,
			body.wp-admin .sportic-unfile-admin .sportic-dia-content {
				margin: 0; padding: 0; border: none; outline: none; box-shadow: none;
				line-height: normal; vertical-align: baseline; box-sizing: border-box;
			}
		
			/* ======================================================================== */
			/* === INICI DE LA CORRECCIÓ DEFINITIVA: Regles per al modal obert === */
			/* ======================================================================== */
			
			/* Aquesta és la regla clau: quan el modal està obert, el contenidor principal es torna no-interactiu */
			body.sportic-modal-is-open #sportic_main_container {
				pointer-events: none; /* Desactiva clics, hover, etc. */
				filter: blur(4px) brightness(0.8); /* Efecte visual per indicar que està desactivat */
				transition: filter 0.2s ease-out;
			}
			
			.sportic-mix-modal-overlay {
				display: none; /* Amagat per defecte */
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background-color: rgba(15, 23, 42, 0.4); /* Fons semi-transparent */
				z-index: 10000;
				justify-content: center;
				align-items: center;
				backdrop-filter: blur(2px); /* Efecte de vidre esmerilat al fons */
			}
			.sportic-mix-modal-content {
				background-color: #f8fafc;
				border-radius: 12px;
				width: 90%;
				max-width: 500px;
				max-height: 90vh;
				display: flex;
				flex-direction: column;
				box-shadow: 0 10px 30px -5px rgba(0,0,0,0.3);
				pointer-events: auto; /* Re-activa els events només per al contingut del modal */
			}
			.sportic-mix-modal-content .modal-header { padding: 1rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
			.sportic-mix-modal-content .modal-header h3 { margin: 0; font-size: 1.25rem; }
			.sportic-mix-modal-content .modal-close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; }
			.sportic-mix-modal-content .modal-body { padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; overflow-y: auto; }
			.sportic-mix-modal-content .modal-body p { margin: 0; }
			.sportic-mix-modal-content .modal-search-wrapper input { width: 100%; box-sizing: border-box; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; }
			.sportic-mix-modal-content .modal-team-list { list-style: none; margin: 0; padding: 0; max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
			.sportic-mix-modal-content .modal-team-list li label { display: block; padding: 10px 15px; cursor: pointer; }
			.sportic-mix-modal-content .modal-team-list li:hover { background-color: #f1f5f9; }
			.sportic-mix-modal-content .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 0.75rem; background: #fff; border-radius: 0 0 12px 12px; }
		
			/* ======================================================================== */
			/* === ESTILS PER AL MODE PANTALLA COMPLETA (ZEN) === */
			/* ======================================================================== */
			body.sportic-fullscreen-mode #adminmenumain,
			body.sportic-fullscreen-mode #wpadminbar,
			body.sportic-fullscreen-mode #wpfooter,
			body.sportic-fullscreen-mode .update-nag,
			body.sportic-fullscreen-mode .notice:not(.sportic-loader-text) {
				display: none !important;
			}
		
			body.sportic-fullscreen-mode #wpcontent {
				margin-left: 0 !important;
				padding-top: 0 !important;
			}
		
			body.sportic-fullscreen-mode .auto-fold #wpcontent {
				padding-left: 20px !important;
			}
		
			/* Ajustar la capçalera sticky quan no hi ha admin bar */
			body.sportic-fullscreen-mode .sportic-main-header {
				top: 10px !important;
			}
		
			/* ======================================================================== */
			/* === FI DE LA CORRECCIÓ === */
			/* ======================================================================== */
			
			
			body.wp-admin .sportic-unfile-admin .sportic-tab-content,
			body.wp-admin .sportic-unfile-admin .sportic-dia-content {
				margin: 0; padding: 0; border: none; outline: none; box-shadow: none;
				line-height: normal; vertical-align: baseline; box-sizing: border-box;
			}
		
			
		
			.sportic-main-tabs-wrapper, .sportic-secondary-tabs-wrapper, .sportic-dies-tabs-nav {
				position: relative;
				z-index: 10;
				padding-left: 10px !important;
				border-bottom: none !important;
				margin-bottom: 0 !important;
			}
			.sportic-folder-tab.sportic-main-tab:not(.nav-tab-active) {
				background-color: #ffffff !important;
			}
			
			.sportic-secondary-tabs-wrapper, .sportic-dies-tabs-nav {
				margin-top: 20px;
			}
			
			.sportic-folder-tab {
				display: inline-flex !important;
				align-items: center;
				gap: 8px;
				background-color: #ffffff !important;
				color: #4b5563 !important;
				border-bottom: none !important;
				border-top-left-radius: 40px !important;
				border-top-right-radius: 40px !important;
				font-weight: 600 !important;
				cursor: pointer;
				position: relative;
				margin: 0 4px 0 0 !important;
				text-decoration: none !important;
				box-shadow: none !important;
				border: 0.1px solid #c4c4c4;
			}
			
			button#tab-llegenda {
				margin-bottom: 11px !important;
				margin-left: -10px !important;
			}
			
			.sportic-folder-tab:hover {
				background-color: #eaeef6 !important;
				z-index: 11;
			}
		
			.sportic-main-tab { padding: 20px 25px !important; font-size: 1em !important; }
			.sportic-secondary-tab { padding: 20px 25px !important; font-size: 0.95em !important; }
			
			.sportic-folder-tab.active,
			.sportic-folder-tab.nav-tab-active {
				background-color: #eaeef6 !important;
				color: #111827 !important;
				border-bottom-color: #eaeef6 !important;
				z-index: 12 !important;
			}
		
			.sportic-main-content-box, .sportic-secondary-content-box {
				border-radius: 0 40px 40px 40px;
				padding: 25px;
				background-color: #eaeef6;
				position: relative;
				z-index: 9; 
				box-shadow: 0 4px 15px rgba(0,0,0,0.05);
			}
			
			.sportic-secondary-content-box {
				border: 2px solid #00b2ca !important;
				background: white !important;
				margin-top: -2px !important;
				z-index: 9 !important;
				border-radius: 0 30px 30px 30px;
				padding: 15px;
			}
		
			.sportic-secondary-tab {
				border: 2px solid #00b2ca !important;
			}
			
			.sportic-secondary-tab.active {
				background: white !important;
				border-bottom: none !important;
				margin-bottom: -2px !important;
				padding-bottom: 22px !important;
				z-index: 11 !important;
			}
			
			span.dashicons.dashicons-unlock { color: #00b2ca; }
			span.dashicons.dashicons-lock { color: #00b2ca; }
			
			.sportic-dies-tabs-nav { list-style: none !important; padding: 0 0 0 10px !important; border-bottom: 2px solid #ccd0d4 !important; }
			.sportic-dies-tabs-nav li { display: inline-block !important; margin: 0 !important; padding: 0 !important; }
			.sportic-dies-tabs-nav li a { margin-left: 4px; }
			
			.sportic-wide { width: 100%; table-layout: fixed; }
			.sportic-narrow { width: 800px; table-layout: fixed; }
			.sportic-unfile-admin table.widefat { border-collapse: collapse; }
			.sportic-unfile-admin table.widefat th,
			.sportic-unfile-admin table.widefat td { border: 1px solid #000; text-align: left; vertical-align: middle; padding: 6px; white-space: normal; overflow-wrap: break-word; position: relative; }
			.sportic-l { background-color: #ffffff !important; }
			.sportic-o { background-color: #ff9999 !important; }
			.sportic-b { background-color: #b9b9b9 !important; }
			.sportic-selected { box-shadow: inset 0 0 0 2px #1d6eab !important; }
			.sportic-cell { cursor: pointer; min-width: 30px; position: relative; }
			.sportic-text { pointer-events: none; display: inline-block; white-space: nowrap; overflow: hidden; width: 100%; height: 100%; font-size: 12px; transform-origin: left top; }
			.sportic-taula-container { border: 1px solid #ccc; margin-bottom: 20px; }
			.sportic-table-header-wrapper { overflow: hidden; }
			.sportic-table-body-wrapper { overflow-y: auto; max-height: 600px; }
			.week-header { font-size: 16px; font-weight: bold; margin-bottom: 5px !important; }
			.sportic-calendari { margin-bottom: 0px; min-height: 397px; }
			.sportic-calendari .cal-header { text-align: center; margin-bottom: 10px; }
			.sportic-calendari .cal-header a { text-decoration: none; margin: 0 10px; }
			.sportic-calendari .cal-title { font-weight: bold; font-size: 20px; }
			.sportic-calendari .cal-table { width: 100%; border-collapse: collapse; min-height: 261px; }
			.sportic-calendari .cal-table th, .sportic-calendari .cal-table td { border: 1px solid #ccc; text-align: center; padding: 5px; }
			.sportic-calendari .cal-day.selected { background-color: #ffa500; }
			.sportic-calendari .cal-day.today { border: 2px solid #4CAF50; }
			.sportic-col-highlight { background-color: #ffdd99 !important; }
			.sportic-row-highlight { background-color: #ffdd99 !important; }
			.sportic-wide td.sportic-cell { min-width: 40px; }
			.wrap.sportic-unfile-admin table.widefat { border: 1px solid #000 !important; }
			td.cal-day { font-size: 18px; }
			td.cal-day a { text-decoration: none !important; }
			.top-card.caixa-aplicar-plantilla { min-height: 451px; }
			.week-header { font-style: italic; margin-top: 10px; margin-bottom: 15px; font-weight: normal; font-size: 18px !important; color: rgb(75, 82, 89) !important; margin-left: 1.5px; margin-top: 0px !important; }
			.sportic-dia-content h3 { font-weight: normal !important; }
			.sportic-taula-container { border: none !important; }
			.sportic-unfile-admin table.widefat th, .sportic-unfile-admin table.widefat td { border: 1px solid #626262; }
			input[type=checkbox],input[type=radio] { margin-top: 0px !important; }
			.sportic-toolbar { padding: 6px 0px !important; margin-bottom: 15px !important; display: flex; gap: 8px; align-items: center; }
			.sportic-toolbar .button { display: inline-flex; align-items: center; justify-content: center; border: 1px solid transparent; border-radius: 21px; font-weight: 600; cursor: pointer; transition: background-color 0.2s, box-shadow 0.2s; background-color: #4061aa; color: white; padding: 0 20px; height: 44px; font-size: 14px; }
			.sportic-toolbar .button:hover, .sportic-toolbar .button:active { background-color: #1a91bd !important; border-color: #1a91bd !important; color: white !important; }
			.sportic-toolbar .button:focus { background-color: #4061aa !important; border-color: #4061aa !important; color: white !important; box-shadow: none !important; outline: none !important; }
			.sportic-toolbar .button .dashicons { color: white !important; margin-right: 8px; font-size: 20px; line-height: 1; vertical-align: middle; }
			.sportic-legend-core > div[style*="overflow-y: auto"] > :first-child { margin-top: 0px !important; }
			.sportic-cell.sportic-recurrent { background-image: repeating-linear-gradient(-45deg, transparent, transparent 4px, rgba(75, 0, 130, 0.05) 4px, rgba(75, 0, 130, 0.05) 8px); }
			.sportic-unfile-admin .sportic-cell span.sportic-lock-icon { position: absolute; left: 3px; bottom: -4px; font-size: 14px; color: rgba(0, 0, 0, 0.5); line-height: 1; pointer-events: none; z-index: 10; }
			.sportic-unfile-admin .sportic-cell span.sportic-recurrent-icon { position: absolute; left: 3px; bottom: 2px; font-size: 11px; font-family: "Font Awesome 6 Free"; font-weight: 900; color: rgba(75, 0, 130, 0.7); line-height: 1; pointer-events: none; z-index: 10; }
			#sportic-cal-title-clickable:hover { background-color: #f0f0f0; }
			.cal-title-container { position: relative; display: inline-block; }
			.sportic-date-picker-popup { display: none; position: absolute; background-color: #fff; border: 1px solid #ccc; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-radius: 6px; padding: 15px; z-index: 10001; width: 280px; top: 100%; left: 50%; transform: translateX(-50%); margin-top: 8px; }
			.sportic-date-picker-popup .year-selector { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
			.sportic-date-picker-popup .year-selector button { background: #f0f0f0; border: 1px solid #ddd; padding: 5px 8px; cursor: pointer; border-radius: 4px; line-height: 1; }
			.sportic-date-picker-popup .year-selector input[type="number"] { width: 70px; text-align: center; border: 1px solid #ddd; padding: 5px; border-radius: 4px; -moz-appearance: textfield; }
			.sportic-date-picker-popup .year-selector input[type="number"]::-webkit-outer-spin-button, .sportic-date-picker-popup .year-selector input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
			.sportic-date-picker-popup .month-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5px; margin-bottom: 15px; }
			.sportic-date-picker-popup .month-grid button { background: #fff; border: 1px solid #ddd; padding: 8px 5px; cursor: pointer; border-radius: 4px; font-size: 0.9em; transition: background-color 0.2s, color 0.2s; }
			.sportic-date-picker-popup .month-grid button:hover { background-color: #e9e9e9; }
			.sportic-date-picker-popup .month-grid button.selected { background-color: #0073aa; color: #fff; border-color: #0073aa; }
			.sportic-date-picker-popup .popup-actions { display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #eee; padding-top: 10px; }
			.sportic-date-picker-popup .popup-actions button { padding: 6px 12px; border-radius: 4px; cursor: pointer; }
			.sportic-date-picker-popup .popup-actions .apply-btn { background-color: #0073aa; color: #fff; border: 1px solid #0073aa; }
			.sportic-date-picker-popup .popup-actions .cancel-btn { background-color: #f0f0f1; color: #333; border: 1px solid #ccc; }
			.dashicons, .dashicons-before:before { color: #4061aa }
			.sportic-content-box.sportic-calendari {
				flex-grow: 0;
			}
			input#rang_data_inici, input#rang_data_fi { width: 51% !important; }
			</style>
			<?php
			$customLetters = get_option('sportic_unfile_custom_letters', array());
			if (!empty($customLetters)) {
				echo '<style>';
				foreach ($customLetters as $info) {
					$letter = isset($info['letter']) ? strtolower($info['letter']) : '';
					$color  = isset($info['color']) ? $info['color'] : '#dddddd';
					if ($letter !== '' && !in_array($letter, ['l', 'o', 'b'])) {
						echo ".sportic-custom-".esc_attr($letter)." { background-color: ".esc_attr($color)." !important; }\n";
					}
				}
				echo '</style>';
			}
		}
	   /** 
		* JS inline per l'administració (principal i subpàgina).
		*/
	/**
			* JS inline per l'administració (principal i subpàgina).
			* Versió que corregeix el bloqueig/desbloqueig i el problema amb el botó '+' de sub-ítems.
			*/
function sportic_unfile_output_inline_js() {
				$customActivities = get_option('sportic_unfile_custom_letters', array());
				$activitiesMap = [];
				if (is_array($customActivities)) {
					foreach ($customActivities as $activity) {
						if (!empty($activity['description']) && !empty($activity['color'])) {
							$activitiesMap[trim($activity['description'])] = trim($activity['color']);
						}
					}
				}
				$activitiesMap[''] = '#ffffff';
				$activitiesMap['l'] = '#ffffff';
				$activitiesMap['b'] = '#b9b9b9';
			
				?>
				<script>
				document.addEventListener('DOMContentLoaded', function() {
					let isModalActive = false;
					let elementThatOpenedModal = null;
					
					// =================== INICI DEL CANVI DEFINITIU ===================
					// Aquesta és la "memòria" que recordarà els equips seleccionats.
					let mixModalSelectedTeams = new Set();
					// =================== FI DEL CANVI DEFINITIU ===================
			
					const sporticActivities = <?php echo json_encode($activitiesMap); ?>;
					const sporticActivityNames = Object.keys(sporticActivities).filter(name => name && name !== 'l' && name !== 'b');
					
					let cellesSeleccionades = [];
					let seleccionant = false, anchorCell = null, celdaInici = null;
			
					// ========================================================================
					// INICI DE LA CORRECCIÓ: Noves funcions JS per a la barreja de colors
					// ========================================================================
					function hexToRgb(hex) {
						if (!hex) return null;
						let hexVal = hex.startsWith('#') ? hex.substring(1) : hex;
						if (hexVal.length === 3) {
							hexVal = hexVal.split('').map(char => char + char).join('');
						}
						if (hexVal.length !== 6) return null;
						const r = parseInt(hexVal.substring(0, 2), 16);
						const g = parseInt(hexVal.substring(2, 4), 16);
						const b = parseInt(hexVal.substring(4, 6), 16);
						if (isNaN(r) || isNaN(g) || isNaN(b)) return null;
						return [r, g, b];
					}
			
					function rgbToHex(r, g, b) {
						return "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1).padStart(6, '0');
					}
			
					function blendHexColors(colors) {
						const total = colors.length;
						if (total === 0) return '#cccccc';
						if (total === 1) return colors[0];
			
						let totalR = 0, totalG = 0, totalB = 0;
						colors.forEach(hex => {
							const rgb = hexToRgb(hex);
							if (rgb) {
								totalR += rgb[0];
								totalG += rgb[1];
								totalB += rgb[2];
							}
						});
			
						const avgR = Math.round(totalR / total);
						const avgG = Math.round(totalG / total);
						const avgB = Math.round(totalB / total);
			
						return rgbToHex(avgR, avgG, avgB);
					}
					// ========================================================================
					// FI DE LA CORRECCIÓ
					// ========================================================================
					
					let autocomplete = {
						wrapper: null, input: null, list: null, active: false,
						ignoreNextClick: false,
						init: function() {
							this.wrapper = document.createElement('div'); this.wrapper.id = 'sportic-autocomplete'; this.wrapper.style.cssText = 'position:absolute;z-index:10000;display:none;border:1px solid #ccc;background:white;box-shadow:0 5px 15px rgba(0,0,0,0.1);';
							this.input = document.createElement('input'); this.input.type = 'text'; this.input.style.cssText = 'width:100%;padding:8px;border:none;border-bottom:1px solid #eee;box-sizing:border-box;';
							this.list = document.createElement('ul'); this.list.style.cssText = 'list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto;';
							this.wrapper.appendChild(this.input); this.wrapper.appendChild(this.list); document.body.appendChild(this.wrapper);
							this.input.addEventListener('keyup', (e) => this.filter(e));
							this.input.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.hide(); if (e.key === 'Enter') { const firstResult = this.list.querySelector('li'); if (firstResult) { this.apply(firstResult.textContent); e.preventDefault(); } } });
							
							document.addEventListener('click', (e) => { 
								if (this.ignoreNextClick) {
									this.ignoreNextClick = false;
									return;
								}
								if (this.active && !this.wrapper.contains(e.target)) { 
									this.hide(); 
								} 
							});
						},
						show: function(targetCell, initialValue = '') {
							if (!targetCell) return;
							this.ignoreNextClick = true;
							const rect = targetCell.getBoundingClientRect();
							this.wrapper.style.left = rect.left + 'px';
							this.wrapper.style.top = (rect.top + window.scrollY) + 'px';
							this.wrapper.style.minWidth = rect.width + 'px';
							this.wrapper.style.display = 'block';
							this.input.value = initialValue;
							this.filter();
			
							setTimeout(() => {
								this.input.focus();
								this.input.setSelectionRange(this.input.value.length, this.input.value.length);
							}, 0);
			
							this.active = true;
						},
						hide: function() { this.wrapper.style.display = 'none'; this.active = false; },
						filter: function() {
							const query = this.input.value.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
							const queryWords = query.split(' ').filter(w => w);
							const results = sporticActivityNames.filter(name => { const normalizedName = name.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, ""); return queryWords.every(word => normalizedName.includes(word)); });
							this.list.innerHTML = '';
							results.forEach(name => {
								const li = document.createElement('li');
								li.textContent = name;
								li.style.cssText = 'padding:8px 10px;cursor:pointer;';
								
								const bgColor = sporticActivities[name] || '#ffffff';
								li.style.backgroundColor = bgColor;
			
								const rgb = parseRGBColor(bgColor);
								const textColor = rgb ? getTextColorForBackground(rgb[0], rgb[1], rgb[2]) : '#000000';
								li.style.color = textColor;
								
								li.addEventListener('mouseenter', () => {
									li.style.backgroundColor = '#f0f0f0';
									li.style.color = '#000000';
								});
								li.addEventListener('mouseleave', () => {
									li.style.backgroundColor = bgColor;
									li.style.color = textColor;
								});
									
								li.addEventListener('click', () => this.apply(name)); 
								this.list.appendChild(li);
							});
						},
						apply: function(description) { cellesSeleccionades.forEach(c => assignaValor(c, description)); this.hide(); addFillHandleToSelection(); }
					};
					autocomplete.init();
			
					function getInitials(teamName) {
						if (!teamName || typeof teamName !== 'string') return '';
						return teamName.split(' ').map(word => word.charAt(0)).join('').toUpperCase();
					}
			
					function assignaValor(cela, newDescription) {
						if (!cela || cela.hasAttribute('data-locked')) return;
			
						let finalDescription = 'l';
						if (typeof newDescription === 'string' && newDescription.startsWith('MIX:')) {
							finalDescription = newDescription;
						} else if (sporticActivities.hasOwnProperty(newDescription)) {
							finalDescription = newDescription;
						}
			
						if (cela.hasAttribute('data-recurrent')) {
							cela.removeAttribute('data-recurrent');
							cela.classList.remove('sportic-recurrent');
							const recurrentIcon = cela.querySelector('.sportic-recurrent-icon');
							if (recurrentIcon) recurrentIcon.remove();
						}
			
						cela.setAttribute('data-valor', finalDescription);
						cela.classList.remove('sportic-mixed');
						
						let color = '#ffffff';
						let displayText = '';
			
						if (finalDescription.startsWith('MIX:')) {
							const teams = finalDescription.substring(4).split('|').map(t => t.trim());
							displayText = teams.map(getInitials).join('+');
							
							// ========================================================================
							// INICI DE LA CORRECCIÓ: Càlcul dinàmic del color barrejat
							// ========================================================================
							const colorsToBlend = teams.map(teamName => sporticActivities[teamName] || '#ffffff');
							color = blendHexColors(colorsToBlend); // <-- Ús de la nova funció JS
							// ========================================================================
							// FI DE LA CORRECCIÓ
							// ========================================================================
			
							cela.classList.add('sportic-mixed');
						} else {
							color = sporticActivities[finalDescription] || '#ffffff';
							displayText = (finalDescription === 'l' || finalDescription === 'b') ? '' : finalDescription;
						}
			
						cela.style.setProperty('background-color', color, 'important');
						cela.className = 'sportic-cell' + (cela.classList.contains('sportic-mixed') ? ' sportic-mixed' : '') + (cellesSeleccionades.includes(cela) ? ' sportic-selected' : '');
						
						const sp = cela.querySelector('.sportic-text');
						if (sp) { sp.textContent = displayText; }
						autoScaleText(cela); 
						updateTextContrast(cela);
					}
			
					let tooltip = {
						element: null,
						timer: null,
						show: function(cell) {
							this.hide();
							if (!cell.classList.contains('sportic-mixed')) return;
			
							const valor = cell.getAttribute('data-valor');
							const teams = valor.substring(4).split('|');
			
							this.element = document.createElement('div');
							this.element.id = 'sportic-mixt-tooltip';
							this.element.innerHTML = teams.join('<br>');
							this.element.style.cssText = 'position:fixed; background: #333; color:white; padding: 8px 12px; border-radius:6px; z-index:100002; font-size:12px; line-height:1.5; pointer-events:none;';
							document.body.appendChild(this.element);
			
							const rect = cell.getBoundingClientRect();
							this.element.style.left = (rect.left) + 'px';
							this.element.style.top = (rect.bottom + 5) + 'px';
						},
						hide: function() {
							if (this.timer) clearTimeout(this.timer);
							if (this.element) {
								this.element.remove();
								this.element = null;
							}
						}
					};
			
					document.addEventListener('mouseover', function(e) {
						const cell = e.target.closest('.sportic-cell');
						if (cell && cell.classList.contains('sportic-mixed')) {
							tooltip.timer = setTimeout(() => {
								tooltip.show(cell);
							}, 500);
						}
					});
			
					document.addEventListener('mouseout', function(e) {
						const cell = e.target.closest('.sportic-cell');
						if (cell && cell.classList.contains('sportic-mixed')) {
							tooltip.hide();
						}
					});
			
					function clearSelection(){ tooltip.hide(); cellesSeleccionades.forEach(function(c){ unhighlightCell(c, 'sportic-selected'); removeFillHandle(c); }); cellesSeleccionades=[]; updateHeaderHighlights(); }
					function highlightCell(cell, cssClass) { cell.classList.add(cssClass); cell.style.outline = '2px dotted #1d6eab'; cell.style.outlineOffset = '-2px'; }
					function unhighlightCell(cell, cssClass) { cell.classList.remove(cssClass); cell.style.outline = ''; cell.style.outlineOffset = ''; }
					function updateHeaderHighlights(){ document.querySelectorAll('.sportic-taula-container').forEach(function(container){ var headerTable = container.querySelector('.sportic-table-header-wrapper table'), bodyTable = container.querySelector('.sportic-table-body-wrapper table'); if(headerTable) headerTable.querySelectorAll('th').forEach(th => th.classList.remove('sportic-col-highlight')); if(bodyTable) bodyTable.querySelectorAll('tr td:first-child').forEach(td => td.classList.remove('sportic-row-highlight')); let colsHigh = new Set(), rowsHigh = new Set(); cellesSeleccionades.forEach(cell => { colsHigh.add(cell.getAttribute('data-col')); rowsHigh.add(cell.getAttribute('data-row')); }); if(headerTable) colsHigh.forEach(colIndex => { let th = headerTable.querySelector(`th:nth-child(${parseInt(colIndex, 10) + 2})`); if(th) th.classList.add('sportic-col-highlight'); }); if(bodyTable) rowsHigh.forEach(rowIndex => { let row = bodyTable.querySelector(`tr:nth-child(${parseInt(rowIndex, 10) + 1})`); if(row) { let firstTd = row.querySelector('td:first-child'); if(firstTd) firstTd.classList.add('sportic-row-highlight'); } }); }); }
					function addToSelection(taula, filaMin, colMin, filaMax, colMax){ if (!taula) return; taula.querySelectorAll('.sportic-cell.sportic-selected').forEach(function(cell){ unhighlightCell(cell, 'sportic-selected'); removeFillHandle(cell); }); cellesSeleccionades=[]; taula.querySelectorAll('.sportic-cell').forEach(function(cela){ var r=parseInt(cela.getAttribute('data-row'),10), c=parseInt(cela.getAttribute('data-col'),10); if(r>=filaMin && r<=filaMax && c>=colMin && c<=colMax){ highlightCell(cela, 'sportic-selected'); cellesSeleccionades.push(cela); } }); updateHeaderHighlights(); addFillHandleToSelection(); }
					
					function parseRGBColor(str) {
						if (!str) return [255, 255, 255];
						let m = str.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/);
						if (m) return [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
						let hex = str.replace('#', '').trim();
						if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
						if (hex.length === 6) {
							const r = parseInt(hex.substring(0, 2), 16), g = parseInt(hex.substring(2, 4), 16), b = parseInt(hex.substring(4, 6), 16);
							if (!isNaN(r) && !isNaN(g) && !isNaN(b)) return [r, g, b];
						}
						return [255, 255, 255];
					}
			
					function getTextColorForBackground(r,g,b){ var brightness = (0.299*r + 0.587*g + 0.114*b); return (brightness < 130) ? '#ffffff' : '#000000'; }
					function updateTextContrast(cela) { if (!cela) return; var bg = window.getComputedStyle(cela).backgroundColor; var rgb = parseRGBColor(bg); var colorText = getTextColorForBackground(rgb[0], rgb[1], rgb[2]); var span = cela.querySelector('.sportic-text'); if (span) { span.style.setProperty('color', colorText, 'important'); } }
					function autoScaleText(cela) { var textEl = cela.querySelector('.sportic-text'); if (!textEl) return; textEl.style.transform = 'scale(1)'; textEl.style.fontSize = '12px'; textEl.style.padding = '0 2px'; if (cela.clientWidth <= 0 || cela.clientHeight <= 0) return; textEl.style.whiteSpace = 'nowrap'; var tw = textEl.scrollWidth; var th = textEl.scrollHeight; textEl.style.whiteSpace = ''; if (tw <= 0 || th <= 0) return; var ratioW = cela.clientWidth / (tw + 4); var ratioH = cela.clientHeight / (th + 2); var ratio  = Math.min(ratioW, ratioH) * 0.95; if (ratio > 1) ratio = 1; if (ratio < 0.1) ratio = 0.1; textEl.style.transform = 'scale('+ratio+')'; updateTextContrast(cela); }
					function addFillHandle(cell){ if(!cell || cell.querySelector('.fill-handle') || cell.hasAttribute('data-locked')) return; cell.style.position='relative'; var handle=document.createElement('div'); handle.className='fill-handle'; handle.style.cssText="position:absolute;bottom:0px;right:0px;width:10px;height:10px;background-color:rgba(0, 0, 0, 0.7);border:1px solid white;cursor:crosshair;z-index:60;"; cell.appendChild(handle); }
					function removeFillHandle(cell){ if (!cell) return; var fh = cell.querySelector('.fill-handle'); if(fh) fh.remove(); }
					function addFillHandleToSelection() { document.querySelectorAll('.fill-handle').forEach(h => h.remove()); if (cellesSeleccionades.length > 0) { let bottomRightCell = null, maxRow = -Infinity, maxCol = -Infinity; cellesSeleccionades.forEach(cell => { let r=parseInt(cell.getAttribute('data-row'),10), c=parseInt(cell.getAttribute('data-col'),10); if (r > maxRow || (r === maxRow && c > maxCol)) { maxRow = r; maxCol = c; bottomRightCell = cell; } }); if (bottomRightCell && !bottomRightCell.hasAttribute('data-locked')) { const val = bottomRightCell.getAttribute('data-valor') || 'l'; if (val !== 'l' && val !== 'b') addFillHandle(bottomRightCell); } } }
					
					document.addEventListener('mousedown',function(e){ 
						if (isModalActive || e.target.closest('.sportic-toolbar') || e.target.classList.contains('fill-handle') || autocomplete.active || e.target.closest('#sportic-mix-modal')) return; 
						tooltip.hide();
						if(!e.target.closest('.sportic-cell')) { clearSelection(); return; }
						const currentTable = e.target.closest('table'); if (anchorCell && currentTable !== anchorCell.closest('table')) clearSelection(); e.preventDefault(); var taula = currentTable, fila = parseInt(e.target.closest('.sportic-cell').getAttribute('data-row'),10), col = parseInt(e.target.closest('.sportic-cell').getAttribute('data-col'),10); var isMac = (navigator.platform.toUpperCase().indexOf('MAC')>=0); var ctrlOrCmd = isMac ? e.metaKey : e.ctrlKey; var shiftPressed = e.shiftKey; if(shiftPressed){ if(!anchorCell) anchorCell=e.target.closest('.sportic-cell'); const anchorTaula = anchorCell.closest('table'); if (anchorTaula !== taula) { clearSelection(); anchorCell = e.target.closest('.sportic-cell'); highlightCell(anchorCell, 'sportic-selected'); cellesSeleccionades.push(anchorCell); } else { const filaAnchor=parseInt(anchorCell.getAttribute('data-row'),10), colAnchor =parseInt(anchorCell.getAttribute('data-col'),10); const filaMin=Math.min(filaAnchor,fila), filaMax=Math.max(filaAnchor,fila), colMin =Math.min(colAnchor,col), colMax =Math.max(colAnchor,col); addToSelection(taula,filaMin,colMin,filaMax,colMax); } seleccionant=false; celdaInici=null; } else if(ctrlOrCmd){ const targetCell = e.target.closest('.sportic-cell'); if(targetCell.classList.contains('sportic-selected')){ unhighlightCell(targetCell, 'sportic-selected'); removeFillHandle(targetCell); cellesSeleccionades = cellesSeleccionades.filter(c => c !== targetCell); } else { highlightCell(targetCell, 'sportic-selected'); cellesSeleccionades.push(targetCell); } anchorCell = targetCell; updateHeaderHighlights(); addFillHandleToSelection(); seleccionant=false; celdaInici=null; } else { clearSelection(); const targetCell = e.target.closest('.sportic-cell'); highlightCell(targetCell, 'sportic-selected'); cellesSeleccionades.push(targetCell); anchorCell=targetCell; seleccionant=true; celdaInici=targetCell; updateHeaderHighlights(); addFillHandleToSelection(); } });
					document.addEventListener('mouseover',function(e){ if(!seleccionant || !celdaInici || !e.target.classList.contains('sportic-cell')) return; var taula=e.target.closest('table'); if (taula !== celdaInici.closest('table')) return; var filaIni=parseInt(celdaInici.getAttribute('data-row'),10), colIni =parseInt(celdaInici.getAttribute('data-col'),10), filaAct=parseInt(e.target.getAttribute('data-row'),10), colAct =parseInt(e.target.getAttribute('data-col'),10); var filaMin=Math.min(filaIni,filaAct), filaMax=Math.max(filaIni,filaAct), colMin=Math.min(colIni,colAct), colMax=Math.max(colIni,colAct); addToSelection(taula,filaMin,colMin,filaMax,colMax); });
					document.addEventListener('mouseup',function(){ if (seleccionant) { seleccionant=false; celdaInici=null; setTimeout(() => { addFillHandleToSelection(); }, 50); } });
					
					document.addEventListener('keydown',function(e){
						if (isModalActive) return;
						tooltip.hide();
						if (autocomplete.active) return;
						if(cellesSeleccionades.length > 0){
							if (e.key === 'Delete' || e.key === 'Backspace') {
								e.preventDefault();
								let isAnyLocked = cellesSeleccionades.some(c => c.hasAttribute('data-locked'));
								if (!isAnyLocked) {
									cellesSeleccionades.forEach(c => assignaValor(c, 'l'));
								}
							} else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
								e.preventDefault();
								let isAnyLocked = cellesSeleccionades.some(c => c.hasAttribute('data-locked'));
								if (!isAnyLocked) {
									autocomplete.show(cellesSeleccionades[0], e.key);
								}
							}
						}
					});
			
					document.addEventListener('copy',function(e){ if(cellesSeleccionades.length>0){ var minFila=Infinity, maxFila=-Infinity, minCol=Infinity, maxCol=-Infinity; cellesSeleccionades.forEach(cell => { let r=parseInt(cell.getAttribute('data-row'),10), c=parseInt(cell.getAttribute('data-col'),10); if(r<minFila)minFila=r; if(r>maxFila)maxFila=r; if(c<minCol)minCol=c; if(c>maxCol)maxCol=c; }); var taula=cellesSeleccionades[0].closest('table'); var dades=[]; for(var rr=minFila; rr<=maxFila; rr++){ var filaDades=[]; for(var cc=minCol; cc<=maxCol; cc++){ var cela=taula.querySelector(`.sportic-cell[data-row="${rr}"][data-col="${cc}"]`); if(cela && cellesSeleccionades.includes(cela)){ var val = cela.getAttribute('data-valor')||'l'; filaDades.push(val); } else { filaDades.push(''); } } dades.push(filaDades.join('\t')); } var text=dades.join('\n'); if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).catch(err => console.error('Error copia:', err)); } else if (e.clipboardData) { e.clipboardData.setData('text/plain', text); } e.preventDefault(); } });
					
					document.addEventListener('paste',function(e){
						if(cellesSeleccionades.length === 1){
							var anchorCellPaste = cellesSeleccionades[0], minFila=parseInt(anchorCellPaste.getAttribute('data-row'),10), minCol =parseInt(anchorCellPaste.getAttribute('data-col'),10), taula=anchorCellPaste.closest('table');
							var cd = (e.clipboardData || window.clipboardData)?.getData('text') || '';
							if(!cd)return;
							e.preventDefault();
							var lines=cd.split(/\r?\n/).filter(ln => ln.trim() !== ''), dataArr=lines.map(ln => ln.split('\t'));
							let canPaste = true;
							for(let i=0; i<dataArr.length; i++){
								for(let j=0; j<dataArr[i].length; j++){
									let celaTarget=taula.querySelector(`.sportic-cell[data-row="${minFila+i}"][data-col="${minCol+j}"]`);
									if(celaTarget && celaTarget.hasAttribute('data-locked')){
										canPaste = false;
										break;
									}
								}
								if (!canPaste) break;
							}
							if (canPaste) {
								clearSelection();
								let pastedCells = [];
								for(let i=0; i<dataArr.length; i++){
									for(let j=0; j<dataArr[i].length; j++){
										let celaTarget=taula.querySelector(`.sportic-cell[data-row="${minFila+i}"][data-col="${minCol+j}"]`);
										if(celaTarget){
											let nouVal = dataArr[i][j].trim();
											assignaValor(celaTarget,nouVal);
											pastedCells.push(celaTarget);
										}
									}
								}
								cellesSeleccionades = pastedCells;
								cellesSeleccionades.forEach(c => highlightCell(c, 'sportic-selected'));
								anchorCell = anchorCellPaste;
								updateHeaderHighlights();
								addFillHandleToSelection();
							} else {
								alert("No es pot enganxar sobre cel·les bloquejades manualment.");
							}
						} else if (cellesSeleccionades.length > 1) {
							e.preventDefault();
						}
					});
			
					var dragFillActive=false, dragFillOriginCell=null, dragFillIndicator=null;
					function createDragFillIndicator(text, bgColor){ var oldIndicator = document.getElementById('dragfill-indicator'); if(oldIndicator) oldIndicator.remove(); var indicator=document.createElement('div'); indicator.id='dragfill-indicator'; indicator.style.cssText="position:fixed;pointer-events:none;padding:4px 8px;border:2px dashed #555;border-radius:4px;z-index:10000;"; indicator.style.backgroundColor=bgColor; indicator.textContent = text; var rgb = parseRGBColor(bgColor); indicator.style.color = getTextColorForBackground(rgb[0], rgb[1], rgb[2]); document.body.appendChild(indicator); return indicator; }
					function clearDragFillSelection(table){ if (!table) return; table.querySelectorAll('.sportic-cell.dragfill-selected').forEach(function(cell){ unhighlightCell(cell, 'dragfill-selected'); cell.style.boxShadow = ''; }); }
					
					function updateDragFillSelection(targetCell){
						if(!dragFillOriginCell || !targetCell) return;
						if (dragFillOriginCell.hasAttribute('data-locked')) {
							clearDragFillSelection(dragFillOriginCell.closest('table'));
							return;
						}
						var originRow=parseInt(dragFillOriginCell.getAttribute('data-row'),10), originCol=parseInt(dragFillOriginCell.getAttribute('data-col'),10), targetRow=parseInt(targetCell.getAttribute('data-row'),10), targetCol=parseInt(targetCell.getAttribute('data-col'),10);
						var rowMin=Math.min(originRow,targetRow), rowMax=Math.max(originRow,targetRow), colMin=Math.min(originCol,targetCol), colMax=Math.max(originCol,targetCol);
						var table=dragFillOriginCell.closest('table');
						clearDragFillSelection(table);
						let blockDrag = false;
						table.querySelectorAll('.sportic-cell').forEach(function(cell){
							var r=parseInt(cell.getAttribute('data-row'),10), c=parseInt(cell.getAttribute('data-col'),10);
							if(r>=rowMin && r<=rowMax && c>=colMin && c<=colMax){
								if (cell !== dragFillOriginCell && cell.hasAttribute('data-locked')) blockDrag = true;
								highlightCell(cell, 'dragfill-selected');
							}
						});
						if (blockDrag) table.querySelectorAll('.dragfill-selected').forEach(c => c.style.boxShadow = 'inset 0 0 0 2px red');
						else table.querySelectorAll('.dragfill-selected').forEach(c => c.style.boxShadow = 'inset 0 0 0 2px #d4a200');
					}
					function dragFillMouseMove(e){ if(!dragFillActive || !dragFillIndicator)return; dragFillIndicator.style.top = (e.clientY+10)+'px'; dragFillIndicator.style.left = (e.clientX+10)+'px'; var elem = document.elementFromPoint(e.clientX, e.clientY); if(elem && elem.classList.contains('sportic-cell')) updateDragFillSelection(elem); }
					
					function dragFillMouseUp(e){
						if(!dragFillActive)return;
						const table=dragFillOriginCell?.closest('table');
						const cellsToCleanAndFill = table ? Array.from(table.querySelectorAll('.sportic-cell.dragfill-selected')) : [];
						if (!table || !dragFillOriginCell) {
							if(dragFillIndicator?.parentElement) dragFillIndicator.remove();
							dragFillIndicator=null; dragFillActive=false; dragFillOriginCell=null;
							document.removeEventListener('mousemove',dragFillMouseMove); document.removeEventListener('mouseup',dragFillMouseUp);
							return;
						}
						let blockDrag = false;
						cellsToCleanAndFill.forEach(cell => {
							if (cell !== dragFillOriginCell && cell.hasAttribute('data-locked')) blockDrag = true;
						});
						if (!blockDrag) {
							var valorOrigen = dragFillOriginCell.getAttribute('data-valor')||'l';
							cellsToCleanAndFill.forEach(cell => { if (cell !== dragFillOriginCell) assignaValor(cell, valorOrigen); });
						} else console.log("Drag-fill cancel·lat per bloqueig.");
						cellsToCleanAndFill.forEach(function(cell) { unhighlightCell(cell, 'dragfill-selected'); cell.style.boxShadow = ''; });
						if(dragFillIndicator?.parentElement) dragFillIndicator.remove();
						dragFillIndicator=null; dragFillActive=false; dragFillOriginCell=null;
						document.removeEventListener('mousemove',dragFillMouseMove); document.removeEventListener('mouseup',dragFillMouseUp);
						addFillHandleToSelection();
					}
			
					document.addEventListener('mousedown',function(e){ if(e.target.classList.contains('fill-handle')){ e.preventDefault(); e.stopPropagation(); dragFillOriginCell = e.target.closest('.sportic-cell'); if (!dragFillOriginCell || dragFillOriginCell.hasAttribute('data-locked')) { return; } dragFillActive=true; var realVal = dragFillOriginCell.getAttribute('data-valor')||'l'; var cs = window.getComputedStyle(dragFillOriginCell); var bgColor = cs.backgroundColor||'#ccc'; dragFillIndicator = createDragFillIndicator(realVal, bgColor); document.addEventListener('mousemove',dragFillMouseMove); document.addEventListener('mouseup',dragFillMouseUp); } });
					document.addEventListener('mouseup',function(){ if (!seleccionant && !dragFillActive) setTimeout(addFillHandleToSelection, 50); });
					function lockCell(cell) { if (!cell || cell.hasAttribute('data-locked')) return; cell.setAttribute('data-locked', '1'); cell.classList.add('sportic-locked'); let lockIcon = cell.querySelector('.sportic-lock-icon'); if (!lockIcon) { lockIcon = document.createElement('span'); lockIcon.className = 'sportic-lock-icon dashicons dashicons-lock'; cell.style.position = 'relative'; cell.appendChild(lockIcon); } removeFillHandle(cell); }
					function unlockCell(cell) { if (!cell || !cell.hasAttribute('data-locked')) return; cell.removeAttribute('data-locked'); cell.classList.remove('sportic-locked'); let lockIcon = cell.querySelector('.sportic-lock-icon'); if (lockIcon) lockIcon.remove(); const val = cell.getAttribute('data-valor') || 'l'; if (val !== 'l' && val !== 'b') { addFillHandle(cell); } }
					document.querySelectorAll('.sportic-toolbar .lock-button').forEach(button => { button.addEventListener('click', function() { if (cellesSeleccionades.length === 0) { alert("Selecciona cel·les a bloquejar."); return; } const isAnyRecurrent = cellesSeleccionades.some(c => c.hasAttribute('data-recurrent')); if (isAnyRecurrent) { alert("No es poden bloquejar cel·les d'un esdeveniment recurrent."); return; } cellesSeleccionades.forEach(lockCell); addFillHandleToSelection(); }); });
					document.querySelectorAll('.sportic-toolbar .unlock-button').forEach(button => { button.addEventListener('click', function() { if (cellesSeleccionades.length === 0) { alert("Selecciona cel·les a desbloquejar."); return; } cellesSeleccionades.forEach(unlockCell); addFillHandleToSelection(); }); });
					
					const mixModal = document.getElementById('sportic-mix-modal');
					const mixTeamList = document.getElementById('sportic-mix-team-list');
					const mixSearch = document.getElementById('sportic-mix-search');
					const mixCancel = document.getElementById('sportic-mix-cancel');
					const mixApply = document.getElementById('sportic-mix-apply');
					const mixClose = document.getElementById('sportic-mix-close');
			
					document.querySelectorAll('.sportic-toolbar .mix-button').forEach(button => {
						button.addEventListener('click', function(e) {
							elementThatOpenedModal = e.target.closest('button');
							if (cellesSeleccionades.length === 0) {
								alert("Selecciona primer les cel·les per a l'entrenament mixt.");
								return;
							}
							if (cellesSeleccionades.some(c => c.hasAttribute('data-locked') || c.hasAttribute('data-recurrent'))) {
								alert("No es poden crear sessions mixtes en cel·les bloquejades o que pertanyen a un esdeveniment recurrent.");
								return;
							}
							// =================== INICI DEL CANVI DEFINITIU ===================
							// Buidem la memòria de seleccions cada cop que obrim el modal
							mixModalSelectedTeams.clear();
							// =================== FI DEL CANVI DEFINITIU ===================
							populateMixModal();
							if (mixModal) {
								mixModal.style.display = 'flex';
								document.body.classList.add('sportic-modal-is-open');
								isModalActive = true;
								setTimeout(() => mixSearch.focus(), 50);
							}
						});
					});
			
					function populateMixModal() {
						if (!mixTeamList || !mixSearch) return;
						mixTeamList.innerHTML = '';
						const searchTerm = mixSearch.value.toLowerCase();
						sporticActivityNames.forEach(team => {
							if (team.toLowerCase().includes(searchTerm)) {
								const li = document.createElement('li');
								// =================== INICI DEL CANVI DEFINITIU ===================
								// En construir la llista, comprovem la "memòria" per marcar els checkboxes
								const isChecked = mixModalSelectedTeams.has(team) ? 'checked' : '';
								li.innerHTML = `<label><input type="checkbox" value="${team}" ${isChecked}> ${team}</label>`;
								// =================== FI DEL CANVI DEFINITIU ===================
								mixTeamList.appendChild(li);
							}
						});
					}
					
					function closeMixModal() {
						if (mixModal) {
							mixModal.style.display = 'none';
							document.body.classList.remove('sportic-modal-is-open');
							isModalActive = false;
							if (elementThatOpenedModal) {
								elementThatOpenedModal.focus();
								elementThatOpenedModal = null;
							}
						}
					}
			
					if (mixSearch) mixSearch.addEventListener('input', populateMixModal);
					if (mixCancel) mixCancel.addEventListener('click', closeMixModal);
					if (mixClose) mixClose.addEventListener('click', closeMixModal);
					
					if (mixModal) {
						mixModal.addEventListener('click', function(e) {
							if (e.target === mixModal) {
								closeMixModal();
							}
						});
						mixModal.addEventListener('keydown', function(e) {
							if (e.key === 'Escape') {
								closeMixModal();
							}
						});
					}
			
					// =================== INICI DEL CANVI DEFINITIU ===================
					// Afegim un listener a la llista per actualitzar la "memòria" quan es marca/desmarca un checkbox
					if (mixTeamList) {
						mixTeamList.addEventListener('change', function(e) {
							if (e.target.type === 'checkbox') {
								const teamName = e.target.value;
								if (e.target.checked) {
									mixModalSelectedTeams.add(teamName);
								} else {
									mixModalSelectedTeams.delete(teamName);
								}
							}
						});
					}
			
					if (mixApply) {
						mixApply.addEventListener('click', function() {
							// Ara llegim les dades directament de la "memòria"
							const selectedTeams = Array.from(mixModalSelectedTeams);
			
							if (selectedTeams.length < 2) {
								alert("Has de seleccionar almenys dos equips per crear una sessió mixta.");
								return;
							}
			
							// ========================================================================
							// === INICI DE LA MODIFICACIÓ SOL·LICITADA ===
							// ========================================================================
							const valorMixt = 'MIX: ' + selectedTeams.join(' | ');
							// ========================================================================
							// === FI DE LA MODIFICACIÓ SOL·LICITADA ===
							// ========================================================================
							
							cellesSeleccionades.forEach(cell => {
								assignaValor(cell, valorMixt);
							});
			
							closeMixModal();
						});
					}
					// =================== FI DEL CANVI DEFINITIU ===================
			
					const mainForm = document.getElementById('sportic-main-form');
					const activeTabInput = document.getElementById('sportic_active_tab_input');
					const subDayInput = document.getElementById('sportic_active_subday_input');
			
					if(mainForm && activeTabInput) {
						document.querySelectorAll('.sportic-main-tabs-wrapper .nav-tab').forEach(function(tab){
							tab.addEventListener('click', function(e){
								e.preventDefault();
								const targetSelector = tab.getAttribute('href');
								document.querySelectorAll('.sportic-main-tabs-wrapper .nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
								document.querySelectorAll('.sportic-main-content-box .sportic-tab-content').forEach(tc => tc.style.display = 'none');
								tab.classList.add('nav-tab-active');
								const contentDiv = document.querySelector(targetSelector);
								if(contentDiv) { contentDiv.style.display = 'block'; }
								activeTabInput.value = targetSelector;
								clearSelection();
							});
						});
						const params = new URLSearchParams(window.location.search);
						const tabFromUrl = params.get('active_tab');
						if(tabFromUrl) {
							const wantedTab = document.querySelector(`.nav-tab[href="${tabFromUrl}"]`);
							if(wantedTab) wantedTab.click();
						} else {
							let firstTab = document.querySelector('.sportic-main-tabs-wrapper .nav-tab');
							if(firstTab) firstTab.click();
						}
					}
			
					document.querySelectorAll('.sportic-secondary-tabs-wrapper .sportic-secondary-tab').forEach(function(tabButton){
						tabButton.addEventListener('click', function(e){
							e.preventDefault();
							const parentContainer = tabButton.closest('.sportic-tab-content');
							if (!parentContainer) return;
							parentContainer.querySelectorAll('.sportic-secondary-tab').forEach(t => t.classList.remove('active'));
							parentContainer.querySelectorAll('.sportic-dia-content').forEach(dc => dc.style.display = 'none');
							tabButton.classList.add('active');
							const targetSelector = tabButton.getAttribute('data-target');
							const targetContent = document.querySelector(targetSelector);
							if (targetContent) { targetContent.style.display = 'block'; }
							const dayKey = tabButton.getAttribute('data-daykey');
							if (subDayInput && dayKey) { subDayInput.value = dayKey; }
							clearSelection();
						});
					});
			
					if(mainForm) {
						mainForm.addEventListener('submit', function(e){
							var hiddenJsonPiscines = document.getElementById('sportic_dades_json');
							var condHidden = document.getElementById('condicions_ambientals_json');
							if(hiddenJsonPiscines){
								var objectPiscines={};
								document.querySelectorAll('.sportic-table-body-wrapper table').forEach(function(tbl){
									var piscinaSlug=tbl.getAttribute('data-piscina'), dia=tbl.getAttribute('data-dia');
									if(!piscinaSlug||!dia)return;
									if(!objectPiscines[piscinaSlug]) objectPiscines[piscinaSlug]={};
									if(!objectPiscines[piscinaSlug][dia]) objectPiscines[piscinaSlug][dia]={};
									tbl.querySelectorAll('tbody tr').forEach(function(tr){
										var horaCell=tr.querySelector('td:first-child'); if(!horaCell)return;
										var hora=horaCell.textContent.trim(); if(!hora)return;
										var carrils=[];
										tr.querySelectorAll('.sportic-cell').forEach(function(cela){
											var valorBase = cela.getAttribute('data-valor') || 'l';
											var valorFinal = valorBase;
											if (cela.hasAttribute('data-recurrent') && !cela.hasAttribute('data-locked')) { 
												valorFinal = '@' + valorBase;
											} 
											else if (cela.hasAttribute('data-locked')) { 
												valorFinal = '!' + valorBase;
											}
											carrils.push(valorFinal);
										});
										objectPiscines[piscinaSlug][dia][hora]=carrils;
									});
								});
								hiddenJsonPiscines.value=JSON.stringify(objectPiscines);
							}
							if(condHidden){ var data={}; document.querySelectorAll('#taula-qualitat-aire input[name^="__dummy_condicions_ambientals"]').forEach(function(input){ var matches=input.getAttribute('name').match(/\[([^\]]+)\]\[([^\]]+)\]$/); if(matches) { if(!data[matches[1]]) data[matches[1]]=[]; data[matches[1]][parseInt(matches[2])] = input.value; } }); condHidden.value=JSON.stringify(data); }
						});
					}
					
					// ========================================================================
					// === FUNCIONALITAT PANTALLA COMPLETA (MODIFICADA: AMB SEGURETAT) ===
					// ========================================================================
					const fullscreenBtn = document.getElementById('sportic-fullscreen-toggle');
					
					// Funció per actualitzar l'estat visual
					function updateFullscreenState() {
						// SEGURETAT: Si no hi ha botó en aquesta pàgina, no apliquem el mode Zen.
						// Això evita quedar atrapat en pàgines sense botó de sortida.
						if (!fullscreenBtn) {
							document.body.classList.remove('sportic-fullscreen-mode');
							return;
						}
			
						const isZen = localStorage.getItem('sportic_zen_mode') === 'true';
						
						if (isZen) {
							document.body.classList.add('sportic-fullscreen-mode');
							// Actualitzar icona a "Contract"
							const icon = fullscreenBtn.querySelector('span');
							if (icon) {
								icon.classList.remove('dashicons-editor-expand');
								icon.classList.add('dashicons-editor-contract');
							}
							fullscreenBtn.title = 'Sortir del Mode Zen';
						} else {
							document.body.classList.remove('sportic-fullscreen-mode');
							// Actualitzar icona a "Expand"
							const icon = fullscreenBtn.querySelector('span');
							if (icon) {
								icon.classList.remove('dashicons-editor-contract');
								icon.classList.add('dashicons-editor-expand');
							}
							fullscreenBtn.title = 'Mode Zen (Pantalla Completa)';
						}
					}
			
					// 1. Comprovar l'estat a l'inici (immediatament)
					updateFullscreenState();
			
					// 2. Gestionar el clic
					if (fullscreenBtn) {
						fullscreenBtn.addEventListener('click', function(e) {
							e.preventDefault();
							// Alternar l'estat a la memòria
							const currentlyZen = localStorage.getItem('sportic_zen_mode') === 'true';
							localStorage.setItem('sportic_zen_mode', !currentlyZen);
							
							// Aplicar canvis
							updateFullscreenState();
						});
					}
					// ========================================================================
					// === FI DE LA FUNCIONALITAT DE PANTALLA COMPLETA ===
					// ========================================================================
			
					setTimeout(() => { document.querySelectorAll('.sportic-cell').forEach(cela => { autoScaleText(cela); updateTextContrast(cela); }); addFillHandleToSelection(); }, 250);
				});
				</script>
				<?php
			}									
   /**
	* SHORTCODE FRONTEND per mostrar Condicions Ambientals
	*/
	// Funció que mostra la taula de condicions ambientals
	if ( ! function_exists( 'sportic_unfile_mostrar_condicions_frontend_integrat' ) ) {
		function sportic_unfile_mostrar_condicions_frontend_integrat() {
			ob_start();
			?>
			<!-- Estils CSS específics per a la taula amb id "sportic-condicions" -->
			<style>
			/* Estils per a la taula de condicions ambientals, identificada per #sportic-condicions */
			#sportic-condicions {
				border-collapse: separate !important;
				border-spacing: 0 9px !important;
				width: auto !important;
				margin: 0 auto !important;
				text-align: center !important;
				table-layout: fixed !important;
			}
	
			/* Forcem que totes les cel·les comptin amb el mateix box-sizing */
			#sportic-condicions thead th,
			#sportic-condicions tbody td {
				box-sizing: border-box !important;
			}
	
			/* Capçalera: fons blanc, mida fixa i línies verticals als th (excepte el primer) */
			#sportic-condicions thead th {
				background: white !important;
				border: none !important;
				height: 40px !important;
				text-align: center !important;
				vertical-align: middle !important;
				padding: 0 !important;
				font-size: 15px !important;
				position: relative !important;
				width: 80px !important; /* Amplada fixa per a totes les cel·les de la capçalera */
			}
			#sportic-condicions thead th:not(:first-child)::before {
				content: "" !important;
				position: absolute !important;
				left: 0 !important;
				top: 50% !important;
				transform: translateY(-50%) !important;
				width: 0.5px !important;
				height: 10px !important;
				background-color: grey !important;
			}
	
			/* Cos de la taula: estil per a les cel·les amb mida fixa */
			#sportic-condicions tbody td {
				border: 1px solid white !important;
				height: 40px !important;
				text-align: center !important;
				vertical-align: middle !important;
				padding: 0 !important;
				font-size: 14px !important;
				width: 80px !important; /* Amplada fixa per a totes les cel·les del cos */
			}
	
			/* Primera columna: fons blanc i text en negre en negreta */
			#sportic-condicions tbody td:first-child {
				background: white !important;
				color: black !important;
				font-weight: bold !important;
			}
	
			/* Cel·les del mig: fons gris amb text en gris fosc sense negreta */
			#sportic-condicions tbody td:not(:first-child) {
				background: rgb(228, 228, 228) !important;
				color: rgb(57, 57, 57) !important;
				font-weight: normal !important;
			}
	
			/* Regla per separar les 4 primeres columnes de la resta */
			#sportic-condicions thead th:nth-child(5),
			#sportic-condicions tbody td:nth-child(5) {
				border-left: 18px solid white !important;
				padding-left: 0px !important;
			}
	
			/* Eliminem el costat superior de la primera fila del cos */
			#sportic-condicions tbody tr:first-child td {
				border-top: none !important;
			}
			/* Eliminem el costat dret de l'última cel·la de cada fila */
			#sportic-condicions tbody tr td:last-child {
				border-right: none !important;
			}
			/* Eliminem el costat inferior de l'última fila */
			#sportic-condicions tbody tr:last-child td {
				border-bottom: none !important;
			}
	
			/* Estils per al loader */
			.sportic-loading-container {
			  display: flex;
			  justify-content: center;
			  align-items: center;
			  min-height: 150px;
			  width: 100%;
			}
			.sportic-loader {
			  border: 8px solid #f3f3f3;
			  border-top: 8px solid #3498db;
			  border-radius: 50%;
			  width: 40px;
			  height: 40px;
			  animation: sportic-spin 2s linear infinite;
			}
			@keyframes sportic-spin {
			  0% { transform: rotate(0deg); }
			  100% { transform: rotate(360deg); }
			}
			
			a .sportic-tab-content h3 {
				color: #4A5259 !important;
				font-weight: normal;
			}
			</style>
	
			<!-- Loader visible mentre es carrega la taula -->
			<div id="sportic-loader-conditions" class="sportic-loading-container">
				<div class="sportic-loader"></div>
			</div>
	
			<!-- Taula de condicions ambientals (el cos s'omplirà amb JS) -->
			<table id="sportic-condicions" style="display:none;">
				<thead>
					<tr>
						<th></th>
						<th>Temp.<br>(°C)</th>
						<th>Hum. rel.<br>(%)</th>
						<th>Co2<br>(mg/L)</th>
						<th>PH<br>(upH)</th>
						<th>Niv.<br>desinf.</th>
						<th>Temp.<br>(ºC)</th>
						<th>Terbol. <br>(U.N.T.)</th>
					</tr>
				</thead>
				<tbody id="sportic-condicions-body">
					<!-- Les files s'afegiran aquí per JavaScript -->
				</tbody>
			</table>
	
			<script type="text/javascript">
			//<![CDATA[
			(function($) { // jQuery noConflict wrapper i IIFE
				
				var sportic_ajax_url = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
				var sportic_ajax_nonce = <?php echo wp_json_encode(wp_create_nonce('sportic_get_conditions_nonce')); ?>; // Nonce per a aquesta acció específica
				var sportic_action_name = 'get_sportic_ambient_conditions'; // Nom únic per a l'acció AJAX
	
				function loadSporticConditions() {
					var loader = $('#sportic-loader-conditions');
					var table = $('#sportic-condicions');
					var tableBody = $('#sportic-condicions-body');
	
					if (!loader.length || !table.length || !tableBody.length) {
						console.error('SPORTIC: Elements del DOM per a la taula de condicions no trobats.');
						if(loader.length) loader.text('Error: Elements de la taula no trobats.');
						return;
					}
	
					loader.show();
					table.hide();
					tableBody.empty(); 
	
					$.ajax({
						url: sportic_ajax_url,
						type: 'POST',
						data: {
							action: sportic_action_name,
							nonce: sportic_ajax_nonce
						},
						dataType: 'json',
						success: function(response) {
							if (response && response.success && response.data) {
								var condicions = response.data;
								var piscines = ['50x25', '25x12', '25x8', 'triangular'];
	
								piscines.forEach(function(nomPiscina) {
									if (condicions[nomPiscina] && Array.isArray(condicions[nomPiscina])) {
										var filaData = condicions[nomPiscina];
										var tr = $('<tr></tr>');
										
										var tdNomPiscina = $('<td></td>').text(nomPiscina);
										tr.append(tdNomPiscina);
	
										filaData.forEach(function(valor) {
											var tdValor = $('<td></td>').text(valor !== null && valor !== undefined ? valor : '-'); // Mostra '-' si el valor és null/undefined
											tr.append(tdValor);
										});
										tableBody.append(tr);
									} else {
										console.warn('SPORTIC: Dades no trobades o en format incorrecte per al pavelló: ' + nomPiscina);
										// Opcionalment, pots afegir una fila d'error per a aquesta piscina
										var trError = $('<tr></tr>');
										trError.append($('<td></td>').text(nomPiscina));
										trError.append($('<td colspan="7"></td>').text('Dades no disponibles'));
										tableBody.append(trError);
									}
								});
	
								loader.hide();
								table.css('display', 'table');
							} else {
								var errorMessage = 'SPORTIC: Error en rebre les dades de condicions.';
								if(response && response.data && typeof response.data === 'string') {
									errorMessage += ' Missatge del servidor: ' + response.data;
								} else if (response && response.data && response.data.message) {
									errorMessage += ' Missatge: ' + response.data.message;
								}
								console.error(errorMessage, response);
								loader.empty().html('<p style="color:red; text-align:center;">' + errorMessage.replace('SPORTIC: ', '') + '</p>');
							}
						},
						error: function(jqXHR, textStatus, errorThrown) {
							var errorDetail = 'SPORTIC: Error AJAX en carregar condicions. ';
							errorDetail += 'Estat: ' + textStatus + '. Error: ' + errorThrown + '.';
							if(jqXHR.responseText) {
								 errorDetail += ' Resposta del servidor: ' + jqXHR.responseText.substring(0, 300) + '...';
							}
							console.error(errorDetail, jqXHR);
							loader.empty().html('<p style="color:red; text-align:center;">Error de connexió o del servidor. Intenta-ho més tard.</p>');
						}
					});
				}
	
				$(document).ready(function() {
					if (typeof jQuery == 'undefined') {
						console.error("SPORTIC: jQuery no està carregat. L'script de Condicions Ambientals no s'executarà.");
						var loaderElement = document.getElementById('sportic-loader-conditions');
						if(loaderElement) loaderElement.innerHTML = '<p style="color:red; text-align:center;">Error crític: Falta jQuery.</p>';
						return;
					}
					loadSporticConditions();
	
					// Opcional: Refrescar cada X segons/minuts
					// setInterval(loadSporticConditions, 30 * 1000); // Cada 30 segons
				});
	
			})(jQuery);
			//]]>
			</script>
			<?php
			return ob_get_clean();
		}
	}
	
	if ( ! shortcode_exists( 'sportic_condicions_ambientals' ) ) {
		add_shortcode( 'sportic_condicions_ambientals', 'sportic_unfile_mostrar_condicions_frontend_integrat' );
	}
	
	
	// Funció per gestionar la petició AJAX
	if ( ! function_exists( 'sportic_handle_get_ambient_conditions_ajax' ) ) {
		function sportic_handle_get_ambient_conditions_ajax() {
			// Verificar el nonce per seguretat
			if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sportic_get_conditions_nonce' ) ) {
				wp_send_json_error( 'Fallada de verificació de seguretat (nonce).', 403 ); // No necessita wp_die()
				return; 
			}
	
			$condicions_default = array(
				'50x25'     => array_fill( 0, 7, '-' ),
				'25x12'     => array_fill( 0, 7, '-' ),
				'25x8'      => array_fill( 0, 7, '-' ),
				'triangular'=> array_fill( 0, 7, '-' )
			);
			$condicions_from_db = get_option( 'sportic_unfile_condicions_custom', false );
	
			$condicions_output = array();
	
			if ( $condicions_from_db === false ) { // Si l'opció no existeix, utilitza els valors per defecte
				$condicions_output = $condicions_default;
			} else { // L'opció existeix, valida-la
				foreach ( $condicions_default as $piscina_key => $default_values ) {
					if ( isset( $condicions_from_db[$piscina_key] ) && is_array( $condicions_from_db[$piscina_key] ) && count( $condicions_from_db[$piscina_key] ) === 7 ) {
						$condicions_output[$piscina_key] = array_map(function($value) {
							return ($value === '' || $value === null) ? '-' : esc_html($value); // Neteja i posa '-' si està buit
						}, $condicions_from_db[$piscina_key]);
					} else {
						$condicions_output[$piscina_key] = $default_values; // Si la dada és incorrecta, utilitza el valor per defecte
					}
				}
			}
	
			wp_send_json_success( $condicions_output ); // No necessita wp_die()
		}
	}
	
	// Hook per a usuaris loguejats
	if ( ! has_action('wp_ajax_get_sportic_ambient_conditions') ) {
		add_action( 'wp_ajax_get_sportic_ambient_conditions', 'sportic_handle_get_ambient_conditions_ajax' );
	}
	// Hook per a usuaris NO loguejats (important per a la TV)
	if ( ! has_action('wp_ajax_nopriv_get_sportic_ambient_conditions') ) {
		add_action( 'wp_ajax_nopriv_get_sportic_ambient_conditions', 'sportic_handle_get_ambient_conditions_ajax' );
	}
	
	
	/**
	* GESTIÓ DE PLANTILLES (submenú)
	*/
	
	/**
	* Helper: Obté l'array complet de plantilles
	*/
function sportic_unfile_get_plantilles($lloc_slug) {
		if (empty($lloc_slug)) {
			return [];
		}
		$all_templates_per_lloc = get_option('sportic_unfile_plantilles', array());
		if (!is_array($all_templates_per_lloc)) {
			$all_templates_per_lloc = array();
		}
		
		// Lògica de migració automàtica per a plantilles antigues (globals)
		$old_templates_found = false;
		foreach (array_keys($all_templates_per_lloc) as $key) {
			if (strpos($key, 'tmpl_') === 0) {
				$old_templates_found = true;
				break;
			}
		}
	
		if ($old_templates_found) {
			$migrated_templates = [];
			$first_lloc_slug = '';
			if (function_exists('sportllocs_get_llocs')) {
				$llocs = sportllocs_get_llocs();
				if (!empty($llocs)) {
					$first_lloc_slug = key($llocs);
				}
			}
			if (empty($first_lloc_slug)) {
				// Si no hi ha llocs, no podem migrar, retornem buit per al lloc demanat.
				return isset($all_templates_per_lloc[$lloc_slug]) && is_array($all_templates_per_lloc[$lloc_slug]) ? $all_templates_per_lloc[$lloc_slug] : [];
			}
	
			$old_structure_data = [];
			foreach($all_templates_per_lloc as $key => $data) {
				if (strpos($key, 'tmpl_') === 0) {
					$old_structure_data[$key] = $data;
				} else {
					// Preservem les dades que ja estan en el format nou
					$migrated_templates[$key] = $data;
				}
			}
			
			// Assignem totes les plantilles antigues al primer lloc disponible
			if (!isset($migrated_templates[$first_lloc_slug])) {
				$migrated_templates[$first_lloc_slug] = [];
			}
			$migrated_templates[$first_lloc_slug] = array_merge($migrated_templates[$first_lloc_slug], $old_structure_data);
			
			// Desem la nova estructura i actualitzem la variable local
			update_option('sportic_unfile_plantilles', $migrated_templates);
			$all_templates_per_lloc = $migrated_templates;
		}
	
		// Retornem les plantilles del lloc sol·licitat
		return isset($all_templates_per_lloc[$lloc_slug]) && is_array($all_templates_per_lloc[$lloc_slug]) ? $all_templates_per_lloc[$lloc_slug] : [];
	}	
	/**
	* Helper: Desa l'array complet de plantilles
	*/
function sportic_unfile_save_plantilles($lloc_slug, $templates_for_lloc) {
		if (empty($lloc_slug) || !is_array($templates_for_lloc)) {
			return false;
		}
		$all_templates_per_lloc = get_option('sportic_unfile_plantilles', array());
		if (!is_array($all_templates_per_lloc)) {
			$all_templates_per_lloc = array();
		}
		
		// Actualitzem només la part del lloc corresponent
		$all_templates_per_lloc[$lloc_slug] = $templates_for_lloc;
	
		update_option('sportic_unfile_plantilles', $all_templates_per_lloc);
		return true;
	}	
	/**
	* Pàgina principal de Plantilles: Llista i botons
	*/
function sportic_unfile_plantilles_page() {
		if (!current_user_can('manage_options')) {
			wp_die("No tens permisos suficients");
		}
	
		$lloc_actiu_slug = isset($_GET['lloc']) ? sanitize_key($_GET['lloc']) : '';
	
		// Si no hi ha cap lloc seleccionat, mostrem el selector de llocs
		if (empty($lloc_actiu_slug)) {
			if (!function_exists('sportllocs_get_llocs')) {
				echo '<div class="wrap"><div class="notice notice-error"><p>Error: El plugin de configuració de Llocs no està actiu. Si us plau, activa\'l per continuar.</p></div></div>';
				return;
			}
	
			$tots_els_llocs = sportllocs_get_llocs();
			$camí_imatge_logo = plugin_dir_url(__FILE__) . 'imatges/logo.png';
			?>
			<div class="wrap sportic-lloc-selector-page">
				<header class="sportic-selector-header">
					<img src="<?php echo esc_url($camí_imatge_logo); ?>" alt="Logo SporTIC" class="sportic-selector-logo"/>
					<h1>Gestió de Plantilles</h1>
					<p class="sportic-selector-subtitle">Selecciona un lloc per gestionar les seves plantilles d'horaris.</p>
				</header>
	
				<?php if (empty($tots_els_llocs)): ?>
					<div class="sportic-no-llocs-message">
						<span class="dashicons dashicons-warning"></span>
						<p><strong>No s'han trobat llocs configurats.</strong></p>
						<p>Per poder crear plantilles, primer has de configurar un lloc a <a href="<?php echo esc_url(admin_url('admin.php?page=sportllocs-config-manage')); ?>">Configuració de Llocs i Pavellons</a>.</p>
					</div>
				<?php else: ?>
					<main class="sportic-lloc-grid">
						<?php foreach ($tots_els_llocs as $slug => $data): 
							$url_lloc = admin_url('admin.php?page=sportic-onefile-templates&lloc=' . esc_attr($slug));
							$image_id = $data['image_id'] ?? 0;
							$image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
						?>
							<a href="<?php echo esc_url($url_lloc); ?>" class="sportic-lloc-card">
								<div class="sportic-lloc-card-image">
									<?php if ($image_url): ?>
										<img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($data['name']); ?>">
									<?php else: ?>
										<span class="dashicons dashicons-admin-multisite"></span>
									<?php endif; ?>
								</div>
								<div class="sportic-lloc-card-content">
									<h2><?php echo esc_html($data['name']); ?></h2>
								</div>
								<div class="sportic-lloc-card-arrow">
									<span class="dashicons dashicons-arrow-right-alt2"></span>
								</div>
							</a>
						<?php endforeach; ?>
					</main>
				<?php endif; ?>
			</div>
			<style>
				#wpbody-content { background-color: #f0f2f5; }
				.sportic-lloc-selector-page { max-width: 1200px; margin: 40px auto; padding: 20px; }
				.sportic-selector-header { text-align: center; margin-bottom: 40px; }
				.sportic-selector-logo { height: 60px; width: auto; margin-bottom: 15px; }
				.sportic-selector-header h1 { font-family: 'Barlow Condensed', sans-serif; font-size: 3em; font-weight: 600; font-style: italic; color: #1e293b; margin: 0 0 10px; }
				.sportic-selector-subtitle { font-family: 'Space Grotesk', sans-serif; font-size: 1.1em; color: #64748b; margin: 0; }
				.sportic-lloc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 2rem; }
				.sportic-lloc-card { background: #ffffff; border-radius: 16px; display: flex; flex-direction: column; text-decoration: none; color: inherit; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.05); transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out, border-color 0.2s ease-in-out; overflow: hidden; }
				.sportic-lloc-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); border-color: #4061aa; }
				.sportic-lloc-card-image { width: 100%; height: 160px; background-color: #f1f5f9; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid #e2e8f0; }
				.sportic-lloc-card-image img { width: 100%; height: 100%; object-fit: cover; }
				.sportic-lloc-card-image .dashicons { font-size: 64px; width: 64px; height: 64px; color: #94a3b8; }
				.sportic-lloc-card-content { padding: 20px 25px; display: flex; flex-direction: column; flex-grow: 1; }
				.sportic-lloc-card-content h2 { font-family: 'Space Grotesk', sans-serif; font-size: 1.3em; font-weight: 700; color: #1e293b; margin: 0 0 5px; }
				.sportic-lloc-card-arrow { margin-top: auto; padding: 0 25px 20px; align-self: flex-end; }
				.sportic-lloc-card-arrow .dashicons { font-size: 28px; color: #94a3b8; transition: color 0.2s; }
				.sportic-lloc-card:hover .sportic-lloc-card-arrow .dashicons { color: #4061aa; }
				.sportic-no-llocs-message { background-color: #fff; border-radius: 12px; padding: 40px; text-align: center; border: 1px solid #e2e8f0; }
				.sportic-no-llocs-message .dashicons { font-size: 40px; color: #f59e0b; width: 40px; height: 40px; margin-bottom: 15px; }
				.sportic-no-llocs-message p { margin: 5px 0 0; font-size: 1.1em; color: #475569; }
			</style>
			<?php
			return; // Aturem l'execució aquí
		}
	
		// Si SÍ que tenim un lloc seleccionat, continuem amb la lògica de gestió
		$lloc_actiu_nom = 'Lloc desconegut';
		if (function_exists('sportllocs_get_llocs')) {
			$tots_els_llocs = sportllocs_get_llocs();
			if (isset($tots_els_llocs[$lloc_actiu_slug])) {
				$lloc_actiu_nom = $tots_els_llocs[$lloc_actiu_slug]['name'];
			}
		}
		
		$action = isset($_GET['tmpl_action']) ? sanitize_text_field($_GET['tmpl_action']) : '';
		$plantilles = sportic_unfile_get_plantilles($lloc_actiu_slug);
	
		if ($action === 'delete' && isset($_GET['tmpl_id'])) {
			$tmpl_id_to_delete = sanitize_text_field($_GET['tmpl_id']);
			if (isset($plantilles[$tmpl_id_to_delete])) {
				unset($plantilles[$tmpl_id_to_delete]);
				sportic_unfile_save_plantilles($lloc_actiu_slug, $plantilles);
				$redirect_url = add_query_arg(['deleted' => '1', 'lloc' => $lloc_actiu_slug], admin_url('admin.php?page=sportic-onefile-templates'));
				if (!headers_sent()) { wp_redirect($redirect_url); exit; }
				else { echo '<script>window.location.href="'. esc_url_raw($redirect_url) .'";</script>'; exit; }
			}
		}
	
		if ($action === 'duplicate' && isset($_GET['tmpl_id'])) {
			$tmpl_id_to_duplicate = sanitize_text_field($_GET['tmpl_id']);
			if (isset($plantilles[$tmpl_id_to_duplicate])) {
				$original = $plantilles[$tmpl_id_to_duplicate];
				$new_id = uniqid('tmpl_');
				$new_data = $original;
				$nomOriginal = $original['name'];
				$nouNom = $nomOriginal;
				$pattern = '/\(duplicat(\d*)\)$/i';
				if (preg_match($pattern, $nomOriginal, $matches)) {
					$num = !empty($matches[1]) ? (int)$matches[1] + 1 : 2;
					$nouNom = preg_replace($pattern, '(duplicat'.$num.')', $nomOriginal);
				} else { $nouNom .= ' (duplicat)'; }
				$new_data['name'] = $nouNom;
				$new_data['created_at'] = current_time('mysql');
				$plantilles[$new_id] = $new_data;
				sportic_unfile_save_plantilles($lloc_actiu_slug, $plantilles);
				$redirect_url = add_query_arg(['duplicated' => '1', 'lloc' => $lloc_actiu_slug], admin_url('admin.php?page=sportic-onefile-templates'));
				if (!headers_sent()) { wp_redirect($redirect_url); exit; }
				else { echo '<script>window.location.href="'. esc_url_raw($redirect_url) .'";</script>'; exit; }
			}
		}
	
		echo '<div class="wrap sportic-unfile-admin sportic-templates-page">';
	
		echo '<div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">';
			echo '<a href="' . esc_url(admin_url('admin.php?page=sportic-onefile-templates')) . '" class="sportic-back-to-llocs" title="Tornar al selector de llocs" style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; background-color: #f3f4f6; border-radius: 50%; text-decoration: none;"><span class="dashicons dashicons-arrow-left-alt" style="font-size: 22px; color: #4b5563; line-height: 1;"></span></a>';
			echo '<div style="font-size: 1.1em; color: #555;">Gestionant plantilles per al lloc: <strong style="color: #111;">' . esc_html($lloc_actiu_nom) . '</strong></div>';
		echo '</div>';
	
	
		if (isset($_GET['created']) && $_GET['created'] === '1') {
			echo '<div class="notice notice-success is-dismissible sportic-dismissible-notice"><p><strong>Plantilla creada correctament!</strong> Ara pots omplir els horaris i desar els canvis per completar-la.</p></div>';
		} elseif (isset($_GET['saved']) && $_GET['saved'] === '1') {
			echo '<div class="notice notice-success is-dismissible sportic-dismissible-notice"><p>Plantilla desada correctament.</p></div>';
		}
		if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
			echo '<div class="notice notice-success is-dismissible sportic-dismissible-notice"><p>Plantilla eliminada correctament.</p></div>';
		}
		if (isset($_GET['duplicated']) && $_GET['duplicated'] === '1') {
			echo '<div class="notice notice-success is-dismissible sportic-dismissible-notice"><p>Plantilla duplicada correctament.</p></div>';
		}
		if (isset($_GET['error_msg']) && !empty($_GET['error_msg'])) {
			echo '<div class="notice notice-error is-dismissible sportic-dismissible-notice"><p>' . esc_html(urldecode($_GET['error_msg'])) . '</p></div>';
		}
	
		if (($action === 'edit' && isset($_GET['tmpl_id']) && isset($plantilles[$_GET['tmpl_id']])) || $action === 'new') {
			$tmpl_id_edit = ($action === 'edit') ? sanitize_text_field($_GET['tmpl_id']) : '';
			$tmpl_data_edit = ($action === 'edit' && isset($plantilles[$tmpl_id_edit])) ? $plantilles[$tmpl_id_edit] : null;
			sportic_unfile_mostrar_form_plantilla($tmpl_id_edit, $tmpl_data_edit); 
			sportic_unfile_output_inline_css(); 
			sportic_unfile_output_inline_js();
			echo '</div>'; 
			return; 
		}
	
		echo '<h1 class="sportic-title">📄 Gestió de Plantilles per a ' . esc_html($lloc_actiu_nom) . '</h1>';
		echo '<p style="margin-bottom: 25px;">
		<a href="' . esc_url(admin_url('admin.php?page=sportic-onefile-templates&tmpl_action=new&lloc=' . $lloc_actiu_slug)) . '" class="button button-primary" style="font-size: 1.1em; padding: 8px 15px;">
			<span style="display: inline-block; transform: scale(1.4); font-weight: bold; margin-right: 6px; line-height: 1;">+</span>Crear Nova Plantilla
		</a>
	</p>';
	
		$plantilles_setmana = [];
		$plantilles_dia = [];
		$plantilles_individual = [];
		foreach ($plantilles as $id => $data) {
			$type = $data['type'] ?? 'day'; 
			if ($type === 'week') { $plantilles_setmana[$id] = $data; }
			elseif ($type === 'single') { $plantilles_individual[$id] = $data; }
			else { $plantilles_dia[$id] = $data; }
		}
	
		if (function_exists('_sportic_unfile_render_template_table')) {
			_sportic_unfile_render_template_table('🗓️ Plantilles Setmanals', $plantilles_setmana, 'sportic-onefile-templates', $lloc_actiu_slug);
			_sportic_unfile_render_template_table('☀️ Plantilles Diàries', $plantilles_dia, 'sportic-onefile-templates', $lloc_actiu_slug);
			_sportic_unfile_render_template_table('🏟 Plantilles de Pavelló Individual', $plantilles_individual, 'sportic-onefile-templates', $lloc_actiu_slug);
		} else {
			echo '<div class="notice notice-error sportic-dismissible-notice"><p>Error: La funció _sportic_unfile_render_template_table no està disponible.</p></div>';
		}
		
		?>
		<div id="sportic_dina3_from_template" style="display:none; position:absolute; left:-9999px; top:0; background:#fff; font-family:Arial,sans-serif; width:auto; height:auto; padding:0; margin:0;"></div>
	
		<div id="sportic_loader_overlay_admin" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.8); z-index:999999; text-align:center;">
			<div style="position:relative; top:50%; transform:translateY(-50%);">
				<div class="sportic-loader" style="margin:0 auto; border:16px solid #f3f3f3; border-top:16px solid #3498db; border-radius:50%; width:80px; height:80px; animation: sportic_spin 1s linear infinite;"></div>
				<div id="sportic_loader_overlay_text" style="margin-top:20px; font-size:18px; font-weight:bold; color:#333;">Carregant...</div>
			</div>
		</div>
	
		<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
		
		<style>
			.sportic-templates-page .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 30px; padding: 30px 35px; max-width: 1000px; margin-left: auto; margin-right: auto; }
			.sportic-templates-page .title { font-size: 22px; margin: 0 0 20px 0; color: #1a1a1a; padding-bottom: 15px; border-bottom: 2px solid #f0f0f1; }
			.sportic-templates-page .wp-list-table th { font-size: 14px; font-weight: 600; }
			.sportic-templates-page .wp-list-table td { vertical-align: middle; }
			.sportic-templates-page .button { margin-right: 6px; vertical-align: middle; }
			.sportic-templates-page .button-danger { background: #d63638 !important; border-color: #d63638 !important; color: #fff !important; }
			.sportic-templates-page .button-danger:hover { background: #c82333 !important; border-color: #bd2130 !important; }
			.sportic-templates-page .button-secondary { background-color: #6c757d !important; border-color: #6c757d !important; color: white !important; }
			.sportic-templates-page .button-secondary:hover { background-color: #5a6268 !important; border-color: #545b62 !important; }
			.sportic-title { font-size: 28px; font-weight: 600; margin-top: 10px; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid #ccd0d4; color: #1d2327; }
			.sportic-templates-page .notice { margin-bottom: 20px; }
			.sportic-templates-page .notice.is-dismissible { cursor: pointer; }
			@keyframes sportic_spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
			
			#sportic_dina3_from_template .dina3-row{display:flex;gap:100px;margin-bottom:40px;justify-content:flex-start;}
			#sportic_dina3_from_template .dina3-row > div{flex:0 0 calc( (100% - 200px) / 3 );} 
			#sportic_dina3_from_template .logo2-dina3{position:absolute;bottom:1200px;left:65%;transform:translateX(-50%);width:3000px;z-index:10;opacity:1;pointer-events:none;}
			#sportic_dina3_from_template .logo3-dina3{position:absolute;bottom:400px;right:320px !important;width:1000px;z-index:10;}
			#sportic_dina3_from_template table th, #sportic_dina3_from_template table td { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
		</style>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var dismissibleNotices = document.querySelectorAll('.sportic-templates-page .notice.is-dismissible');
			dismissibleNotices.forEach(function(notice) {
				notice.addEventListener('click', function(event) {
					if (event.target.classList.contains('notice-dismiss')) return;
					var dismissButton = notice.querySelector('.notice-dismiss');
					if (dismissButton) dismissButton.click();
					else notice.style.display = 'none';
				});
			});
	
			var pdfButtons = document.querySelectorAll('.sportic-generate-template-pdf-btn');
			var renderArea = document.getElementById('sportic_dina3_from_template');
			var loaderOverlay = document.getElementById('sportic_loader_overlay_admin');
			var loaderText = document.getElementById('sportic_loader_overlay_text');
	
			if (!renderArea || !loaderOverlay || !loaderText) {
				console.error('SPORTIC: No s\'han trobat els elements necessaris per a la generació de PDF.');
				return;
			}
	
			function showLoader(message) {
				loaderText.textContent = message;
				loaderOverlay.style.display = 'block';
			}
	
			function hideLoader() {
				loaderOverlay.style.display = 'none';
			}
	
			pdfButtons.forEach(function(button) {
				button.addEventListener('click', function() {
					var templateId = this.dataset.templateId;
					
					showLoader('Carregant dades...');
	
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'sportic_get_template_for_dina3_pdf_v7',
							template_id: templateId,
							nonce: '<?php echo wp_create_nonce("sportic_get_template_pdf_nonce"); ?>'
						},
						success: function(response) {
							if (response.success) {
								showLoader('Generant DINA3...');
								renderArea.innerHTML = response.data.html;
								
								var box = renderArea;
								box.style.display = 'inline-block';
								box.style.left = '-9999px';
								box.style.zIndex = '9999';
	
								setTimeout(function() {
									const W = box.scrollWidth;
									const H = box.scrollHeight; 
									
									html2canvas(box, {
										scale:1.0, 
										useCORS:true,
										backgroundColor:'#fff',
										width: W, 
										height: H, 
										windowWidth: W, 
										windowHeight: H,
										imageTimeout:20000
									})
									.then(cv => {
										const a = document.createElement('a');
										a.download = response.data.filename || 'plantilla-dina3.jpg';
										a.href     = cv.toDataURL('image/jpeg', 0.9);
										document.body.appendChild(a); a.click(); document.body.removeChild(a);
									})
									.catch(error => {
										console.error("Error en html2canvas:", error);
										alert("Hi ha hagut un error capturant la plantilla.");
									})
									.finally(() => {
										box.style.display = 'none';
										box.style.left = '-9999px';
										box.style.zIndex = '';
										renderArea.innerHTML = '';
										hideLoader();
									});
								}, 700);
							} else {
								alert('Error del servidor: ' + response.data);
								hideLoader();
							}
						},
						error: function(jqXHR, textStatus, errorThrown) {
							console.error('Error AJAX:', textStatus, errorThrown);
							alert('Hi ha hagut un error de connexió en carregar la plantilla.');
							hideLoader();
						}
					});
				});
			});
		});
		</script>
		<?php
	
		sportic_unfile_output_inline_css();
		sportic_unfile_output_inline_js();
		echo '</div>';
	}
			
	/**
	* Formulari (CREAR / EDITAR) bàsic (Nom, Tipus i, si escau, piscina).
	*/
function sportic_unfile_mostrar_form_plantilla($tmpl_id = '', $tmpl_data = null) {
		$action = isset($_GET['tmpl_action']) ? sanitize_text_field($_GET['tmpl_action']) : '';
		$lloc_slug = isset($_GET['lloc']) ? sanitize_key($_GET['lloc']) : '';
	
		if (empty($lloc_slug)) {
			echo '<div class="wrap"><div class="notice notice-error"><p>Error: No s\'ha especificat un lloc per a aquesta plantilla. Si us plau, torna al selector.</p><p><a href="' . esc_url(admin_url('admin.php?page=sportic-onefile-templates')) . '" class="button">Tornar al selector de llocs</a></p></div></div>';
			return;
		}
		
		if (empty($tmpl_id) && $action !== 'new') { $action = 'new'; }
	
		if ( isset($_GET['error_msg']) && ! empty($_GET['error_msg']) ) {
			echo '<div class="notice notice-error is-dismissible sportic-dismissible-notice"><p>' . esc_html(urldecode($_GET['error_msg'])) . '</p></div>';
		}
		if (isset($_GET['saved']) && $_GET['saved'] === '1' && empty($_GET['created'])) {
			echo '<div class="notice notice-success is-dismissible sportic-dismissible-notice"><p>Plantilla desada correctament.</p></div>';
		}
		if (isset($_GET['created']) && $_GET['created'] === '1') {
			echo '<div class="notice notice-success is-dismissible sportic-dismissible-notice"><p><strong>Plantilla creada!</strong> Pots continuar editant els horaris o tornar al llistat.</p></div>';
		}
	
		$isEdit = (!empty($tmpl_id) && is_array($tmpl_data));
		$name = ($isEdit && isset($tmpl_data['name'])) ? $tmpl_data['name'] : (isset($_GET['tmpl_name_val']) ? sanitize_text_field(urldecode($_GET['tmpl_name_val'])) : '');
		$type_on_load = ($isEdit && isset($tmpl_data['type'])) ? $tmpl_data['type'] : (isset($_GET['tmpl_type_val']) && !empty($_GET['tmpl_type_val']) ? sanitize_text_field($_GET['tmpl_type_val']) : 'day'); 
		$piscina_single_selected_on_load = ($isEdit && $type_on_load === 'single' && isset($tmpl_data['piscina'])) ? $tmpl_data['piscina'] : 'infantil'; 
	
		?>
		<style>
			.sportic-edit-template-page .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 30px; padding: 35px 40px; max-width: 1000px; margin-left: auto; margin-right: auto; transition: max-width 0.3s ease-in-out; }
			.sportic-edit-template-page .card.card-full-width-mode { max-width: none; }
			.sportic-edit-template-page .title { font-size: 22px; margin: 0 0 25px 0; color: #1a1a1a; padding-bottom: 15px; border-bottom: 2px solid #f0f0f1; }
			.sportic-edit-template-page .form-table th { width: 150px; padding-top: 15px; }
			.sportic-edit-template-page .form-table td { padding-top: 10px; }
			.sportic-edit-template-page .form-table input[type="text"],
			.sportic-edit-template-page .form-table select { min-width: 350px; }
			.sportic-edit-template-page .nav-tab-wrapper { margin-bottom: 0 !important; }
			.sportic-edit-template-page #template-schedule-editor-content-area .sportic-tab-content { border-top-left-radius: 0; border-top-right-radius: 0; }
			.sportic-edit-template-page #template-schedule-editor-content-area #sportic-plantilla-day-editor-content > .sportic-tab-content { border-top-left-radius: 0; border-top-right-radius: 0; }
			.sportic-edit-template-page #template-schedule-editor-content-area .sportic-week-day-pane .nav-tab-wrapper { margin-top: 15px; }
			.sportic-edit-template-page #template-schedule-editor-content-area .sportic-week-day-pane .sportic-sub-tabs + .sportic-subtabs-container .sportic-tab-content { border-top-left-radius: 0; border-top-right-radius: 0; }
			.sportic-edit-template-page .submit-final-container { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: right; }
			.sportic-edit-template-page .submit-final-container .button-primary { font-size: 1.1em; padding: 10px 20px; }
			.sportic-edit-template-page .notice.is-dismissible { cursor: pointer; }
		</style>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var dismissibleNotices = document.querySelectorAll('.sportic-edit-template-page .notice.is-dismissible');
			dismissibleNotices.forEach(function(notice) {
				notice.addEventListener('click', function(event) {
					if (event.target.classList.contains('notice-dismiss')) { return; }
					var dismissButton = notice.querySelector('.notice-dismiss');
					if (dismissButton) { dismissButton.click(); }
					else { notice.style.display = 'none'; }
				});
			});
		});
		</script>
		<?php
	
		echo '<div class="wrap sportic-unfile-admin sportic-edit-template-page">'; 
		
		echo '<a href="' . esc_url(admin_url('admin.php?page=sportic-onefile-templates&lloc=' . $lloc_slug)) . '" style="text-decoration: none; margin-bottom: 20px; display: inline-block;">&larr; Torna al llistat de plantilles</a>';
		
		echo '<h1 class="sportic-title">' . (($isEdit) ? '✏️ Editar Plantilla' : '➕ Crear Nova Plantilla') . '</h1>';
		
		echo '<form id="sportic-template-full-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			echo '<input type="hidden" name="action" value="sportic_unfile_guardar_plantilla_completa" />';
			echo '<input type="hidden" name="lloc" value="' . esc_attr($lloc_slug) . '" />';
			if($isEdit) {
				echo '<input type="hidden" name="tmpl_id" value="' . esc_attr($tmpl_id) . '" />';
			}
			wp_nonce_field('sportic_save_full_template_action', 'sportic_save_full_template_nonce');
	
			echo '<div class="card">';
				echo '<h2 class="title">Informació Bàsica de la Plantilla</h2>';
				echo '<table class="form-table">';
					echo '<tr>';
						echo '<th><label for="tmpl_name">Nom de la plantilla:</label></th>';
						echo '<td><input type="text" id="tmpl_name" name="tmpl_name" value="' . esc_attr(stripslashes($name)) . '" required /></td>';
					echo '</tr>';
					echo '<tr>';
						echo '<th><label for="tmpl_type">Tipus de plantilla:</label></th>';
						echo '<td>';
							echo '<select id="tmpl_type" name="tmpl_type" required>';
								echo '<option value="day" ' . selected($type_on_load, 'day', false) . '>Dia (1 sol dia)</option>';
								echo '<option value="week" ' . selected($type_on_load, 'week', false) . '>Setmana (7 dies)</option>';
								echo '<option value="single" ' . selected($type_on_load, 'single', false) . '>Pavelló Individual</option>';
							echo '</select>';
						echo '</td>';
					echo '</tr>';
					$piscina_row_style = ($type_on_load === 'single') ? '' : 'display:none;';
					echo '<tr id="tmpl_piscina_row" style="' . $piscina_row_style . '">';
						echo '<th><label for="tmpl_piscina">Pavelló (per tipus "Individual"):</label></th>';
						echo '<td>';
							echo '<select id="tmpl_piscina" name="tmpl_piscina">';
							
							// Important: Mostrem només els pavellons del LLOC ACTIU
							$configured_pools_options = function_exists('sportllocs_get_pavellons_by_lloc') ? sportllocs_get_pavellons_by_lloc($lloc_slug) : sportic_unfile_get_pool_labels_sorted();
							
							if (!empty($configured_pools_options)) {
								foreach($configured_pools_options as $slug_opt => $pool_info_opt) {
									echo '<option value="' . esc_attr($slug_opt) . '" data-lanes="'.esc_attr($pool_info_opt['lanes'] ?? 4).'" ' . selected($piscina_single_selected_on_load, $slug_opt, false) . '>' . esc_html($pool_info_opt['label']) . '</option>';
								}
							} else {
								echo '<option value="">-- No hi ha pavellons per a aquest lloc --</option>';
							}
							echo '</select>';
						echo '</td>';
					echo '</tr>';
				echo '</table>';
			echo '</div>'; 
	
			echo '<input type="hidden" id="sportic_tmpl_day_json" name="sportic_tmpl_day_json" value="" />';
			echo '<input type="hidden" id="sportic_tmpl_week_json" name="sportic_tmpl_week_json" value="" />';
			echo '<input type="hidden" id="sportic_tmpl_single_json" name="sportic_tmpl_single_json" value="" />';
	
			echo '<div class="card" id="template-schedule-editor-card-wrapper" style="display:none;" data-needs-full-width="false">'; 
				echo '<h2 class="title" id="template-schedule-editor-title">Configuració d\'Horaris</h2>';
				echo '<div id="template-schedule-editor-content-area">';
				echo '</div>'; 
			echo '</div>'; 
	
			echo '<div id="editor-container-day" class="editor-source-container" style="display:none !important;">';
			if (function_exists('sportic_unfile_mostrar_plantilla_day_tables')) {
				sportic_unfile_mostrar_plantilla_day_tables($tmpl_id, $tmpl_data);
			} else { echo '<!-- ERROR: sportic_unfile_mostrar_plantilla_day_tables no definida -->';}
			echo '</div>';
	
			echo '<div id="editor-container-week" class="editor-source-container" style="display:none !important;">';
			if (function_exists('sportic_unfile_mostrar_plantilla_week_tables')) {
				sportic_unfile_mostrar_plantilla_week_tables($tmpl_id, $tmpl_data);
			} else { echo '<!-- ERROR: sportic_unfile_mostrar_plantilla_week_tables no definida -->';}
			echo '</div>';
	
			echo '<div id="editor-container-single" class="editor-source-container" style="display:none !important;">';
			if (function_exists('sportic_unfile_mostrar_plantilla_single_table')) {
				sportic_unfile_mostrar_plantilla_single_table($tmpl_id, $tmpl_data);
			} else { echo '<!-- ERROR: sportic_unfile_mostrar_plantilla_single_table no definida -->';}
			echo '</div>';
	
			echo '<div class="submit-final-container">';
				echo '<input type="submit" class="button button-primary" value="💾 Desar Plantilla Completa" />';
			echo '</div>';
	
		echo '</form>'; 
		?>
		<script>
		window.initializeDayEditorPoolTabsGlobal = function() {
			var dayEditorContent = document.getElementById('template-schedule-editor-content-area').querySelector('#sportic-plantilla-day-editor-content');
			if (dayEditorContent) {
				if (dayEditorContent.dataset.tabsInitialized === 'true') return;
				var dayPoolTabs = dayEditorContent.querySelectorAll('.nav-tab-wrapper .nav-tab');
				var activePoolSlug = null;
				dayPoolTabs.forEach(function(tab){
					tab.addEventListener('click', function(e){
						e.preventDefault();
						var wrapper = tab.closest('.nav-tab-wrapper');
						if(wrapper) { wrapper.querySelectorAll('.nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });}
						dayEditorContent.querySelectorAll('.sportic-tab-content').forEach(function(content){ content.style.display = 'none'; });
						tab.classList.add('nav-tab-active');
						activePoolSlug = tab.getAttribute('href').replace('#tmpl_day_pool_', '');
						var targetSelector = tab.getAttribute('href');
						var targetContent = dayEditorContent.querySelector(targetSelector);
						if(targetContent) { targetContent.style.display = 'block'; }
						updateCardWidthMode('day', activePoolSlug);
					});
				});
				var firstPoolTabDay = dayEditorContent.querySelector('.nav-tab-wrapper .nav-tab:first-child');
				if (firstPoolTabDay && !dayEditorContent.querySelector('.nav-tab-wrapper .nav-tab.nav-tab-active')) {
					setTimeout(() => { if(firstPoolTabDay) firstPoolTabDay.click(); }, 0);
				} else {
					var activeTabInitial = dayEditorContent.querySelector('.nav-tab-wrapper .nav-tab.nav-tab-active');
					if(activeTabInitial) {
						activePoolSlug = activeTabInitial.getAttribute('href').replace('#tmpl_day_pool_', '');
						updateCardWidthMode('day', activePoolSlug);
					}
				}
				dayEditorContent.dataset.tabsInitialized = 'true';
			}
		};
	
		window.initializeWeekTabsGlobal = function() {
			var weekEditorContent = document.getElementById('template-schedule-editor-content-area').querySelector('#sportic-plantilla-week-editor-content');
			if (!weekEditorContent) return;
			if (weekEditorContent.dataset.tabsInitialized === 'true') return;
	
			var activeDayIndexForWeek = 0;
			var activePoolSlugForWeek = null;
	
			var topTabLinks = weekEditorContent.querySelectorAll('.sportic-top-tabs > .nav-tab');
			topTabLinks.forEach(function(tab){
				tab.addEventListener('click', function(e){
					e.preventDefault();
					activeDayIndexForWeek = parseInt(tab.dataset.dayIndex, 10);
					var parentWrapper = tab.closest('.sportic-top-tabs');
					if(parentWrapper) parentWrapper.querySelectorAll('.nav-tab').forEach(function(x){ x.classList.remove('nav-tab-active'); });
					weekEditorContent.querySelectorAll('.sportic-week-day-pane').forEach(function(dc){ dc.style.display = 'none'; });
					tab.classList.add('nav-tab-active');
					var targetId = tab.getAttribute('href'); 
					var targetEl = weekEditorContent.querySelector(targetId); 
					if (targetEl) {
						targetEl.style.display = 'block';
						var firstSubTabInDay = targetEl.querySelector('.sportic-sub-tabs > .nav-tab:first-child');
						if (firstSubTabInDay) { 
							setTimeout(() => { 
								if(firstSubTabInDay) {
									firstSubTabInDay.click();
								}
							}, 0); 
						}
					}
				});
			});
			var subTabLinks = weekEditorContent.querySelectorAll('.sportic-sub-tabs > .nav-tab');
			subTabLinks.forEach(function(tab){
				tab.addEventListener('click', function(e){
					e.preventDefault();
					activePoolSlugForWeek = tab.dataset.poolSlug;
					var subWrapper = tab.closest('.sportic-sub-tabs');
					if(subWrapper) subWrapper.querySelectorAll('.nav-tab').forEach(function(x){ x.classList.remove('nav-tab-active'); });
					var dayPane = tab.closest('.sportic-week-day-pane'); 
					if (dayPane) { dayPane.querySelectorAll('.sportic-week-pool-pane').forEach(function(stc){ stc.style.display = 'none'; }); }
					tab.classList.add('nav-tab-active');
					var subTargetId = tab.getAttribute('href'); 
					var subTargetEl = weekEditorContent.querySelector(subTargetId); 
					if (subTargetEl) { subTargetEl.style.display = 'block'; }
					updateCardWidthMode('week', activePoolSlugForWeek);
				});
			});
			
			var firstTopTab = weekEditorContent.querySelector('.sportic-top-tabs > .nav-tab:first-child');
			if (firstTopTab && !weekEditorContent.querySelector('.sportic-top-tabs > .nav-tab.nav-tab-active')) {
				 setTimeout(() => { if(firstTopTab) firstTopTab.click(); }, 0);
			} else {
				var activeTopTabLink = weekEditorContent.querySelector('.sportic-top-tabs > .nav-tab.nav-tab-active');
				if (activeTopTabLink) {
					activeDayIndexForWeek = parseInt(activeTopTabLink.dataset.dayIndex, 10);
					var activeTopTabContentId = activeTopTabLink.getAttribute('href');
					if (activeTopTabContentId) {
						var activeDayPane = weekEditorContent.querySelector(activeTopTabContentId);
						if (activeDayPane) {
							var firstSubTabInActiveDay = activeDayPane.querySelector('.sportic-sub-tabs > .nav-tab:first-child');
							if (firstSubTabInActiveDay && !activeDayPane.querySelector('.sportic-sub-tabs > .nav-tab.nav-tab-active')) {
								 setTimeout(() => { if(firstSubTabInActiveDay) firstSubTabInActiveDay.click(); }, 0);
							} else {
								var activeSubTab = activeDayPane.querySelector('.sportic-sub-tabs > .nav-tab.nav-tab-active');
								if(activeSubTab) {
									activePoolSlugForWeek = activeSubTab.dataset.poolSlug;
									updateCardWidthMode('week', activePoolSlugForWeek);
								}
							}
						}
					}
				}
			}
			weekEditorContent.dataset.tabsInitialized = 'true';
		};
	
		window.updateSinglePoolEditorViewGlobal = function(selectedPoolSlug) {
			var singleEditorWrapper = document.getElementById('template-schedule-editor-content-area').querySelector('#sportic-plantilla-single-editor-content');
			if (singleEditorWrapper) {
				var allPoolTables = singleEditorWrapper.querySelectorAll('.single-pool-schedule-editor');
				allPoolTables.forEach(function(tableDiv) {
					if (tableDiv.dataset.poolSlugEditor === selectedPoolSlug) {
						tableDiv.style.display = 'block';
					} else {
						tableDiv.style.display = 'none';
					}
				});
				 updateCardWidthMode('single', selectedPoolSlug);
			}
		};
	
		function updateCardWidthMode(currentEditorType, activePoolSlug) {
			var scheduleEditorCard = document.getElementById('template-schedule-editor-card-wrapper');
			if (!scheduleEditorCard) return;
	
			var needsFullWidth = false;
			if (activePoolSlug) {
				var lanes = 4;
				
				var allPoolOptions = document.querySelectorAll('#tmpl_piscina option');
				var poolOption = Array.from(allPoolOptions).find(opt => opt.value === activePoolSlug);
				
				if (!poolOption) {
					var dayEditorTables = document.querySelectorAll('#sportic-plantilla-day-editor-content table[data-piscina]');
					var weekEditorTables = document.querySelectorAll('#sportic-plantilla-week-editor-content table[data-piscina]');
					var allTables = [...dayEditorTables, ...weekEditorTables];
					
					var foundTable = allTables.find(tbl => tbl.dataset.piscina.endsWith('_' + activePoolSlug));
					if (foundTable) {
						var headerCells = foundTable.querySelectorAll('thead th');
						lanes = headerCells.length > 1 ? headerCells.length - 1 : 4;
					}
				} else if (poolOption.dataset.lanes) {
					lanes = parseInt(poolOption.dataset.lanes, 10);
				}
	
				if (lanes > 6) {
					needsFullWidth = true;
				}
			}
			
			scheduleEditorCard.dataset.needsFullWidth = needsFullWidth.toString();
			if (needsFullWidth) {
				scheduleEditorCard.classList.add('card-full-width-mode');
			} else {
				scheduleEditorCard.classList.remove('card-full-width-mode');
			}
		}
	
	
		document.addEventListener('DOMContentLoaded', function() {
			var tmplTypeSelect = document.getElementById('tmpl_type');
			var piscinaSelect = document.getElementById('tmpl_piscina');
			var piscinaRow = document.getElementById('tmpl_piscina_row');
			var scheduleEditorCardWrapper = document.getElementById('template-schedule-editor-card-wrapper');
			var scheduleEditorContentArea = document.getElementById('template-schedule-editor-content-area');
			var scheduleEditorTitle = document.getElementById('template-schedule-editor-title');
	
			var editorDayContainer = document.getElementById('editor-container-day');
			var editorWeekContainer = document.getElementById('editor-container-week');
			var editorSingleContainer = document.getElementById('editor-container-single');
			
			var currentEditorType = null; 
	
			function showCorrectEditor(selectedType) {
				if (currentEditorType) {
					var editorToRemove = null;
					if (currentEditorType === 'day' && editorDayContainer) {
						editorToRemove = scheduleEditorContentArea.querySelector('#sportic-plantilla-day-editor-content');
						if (editorToRemove) editorDayContainer.appendChild(editorToRemove);
					} else if (currentEditorType === 'week' && editorWeekContainer) {
						editorToRemove = scheduleEditorContentArea.querySelector('#sportic-plantilla-week-editor-content');
						if (editorToRemove) editorWeekContainer.appendChild(editorToRemove);
					} else if (currentEditorType === 'single' && editorSingleContainer) {
						 editorToRemove = scheduleEditorContentArea.querySelector('#sportic-plantilla-single-editor-content');
						if (editorToRemove) editorSingleContainer.appendChild(editorToRemove);
					}
				}
				scheduleEditorContentArea.innerHTML = ''; 
	
				var editorSourceDiv = null;
				var titleText = 'Configuració d\'Horaris';
				var initFunction = null;
				var editorWrapperIdToMove = null;
				var initialPoolSlugForWidthCheck = null;
	
	
				if (selectedType === 'week') {
					titleText = 'Configuració d\'Horaris (Setmana)';
					editorSourceDiv = editorWeekContainer;
					editorWrapperIdToMove = 'sportic-plantilla-week-editor-content';
					initFunction = window.initializeWeekTabsGlobal;
				} else if (selectedType === 'single') {
					titleText = 'Configuració d\'Horaris (Pavelló Individual)';
					editorSourceDiv = editorSingleContainer;
					editorWrapperIdToMove = 'sportic-plantilla-single-editor-content';
					initialPoolSlugForWidthCheck = piscinaSelect ? piscinaSelect.value : null;
					initFunction = function() { 
						if (piscinaSelect) { window.updateSinglePoolEditorViewGlobal(piscinaSelect.value); }
					};
				} else { // day
					titleText = 'Configuració d\'Horaris (Dia)';
					editorSourceDiv = editorDayContainer;
					editorWrapperIdToMove = 'sportic-plantilla-day-editor-content';
					initFunction = window.initializeDayEditorPoolTabsGlobal;
				}
	
				if (scheduleEditorTitle) scheduleEditorTitle.textContent = titleText;
	
				if (editorSourceDiv && editorWrapperIdToMove) {
					var actualEditorWrapper = editorSourceDiv.querySelector('#' + editorWrapperIdToMove);
					if (actualEditorWrapper) {
						scheduleEditorContentArea.appendChild(actualEditorWrapper); 
						currentEditorType = selectedType; 
						if (typeof initFunction === 'function') {
							setTimeout(initFunction, 50); 
						}
						if (initialPoolSlugForWidthCheck) {
							updateCardWidthMode(selectedType, initialPoolSlugForWidthCheck);
						} else if (selectedType === 'day' || selectedType === 'week') {
							var firstActivePoolTab;
							if (selectedType === 'day') {
								firstActivePoolTab = actualEditorWrapper.querySelector('.nav-tab-wrapper .nav-tab');
								if(firstActivePoolTab) updateCardWidthMode('day', firstActivePoolTab.getAttribute('href').replace('#tmpl_day_pool_', ''));
							}
						}
	
					} else {
						scheduleEditorContentArea.innerHTML = '<p>Error intern: No s\'ha trobat el contingut de l\'editor ('+editorWrapperIdToMove+') dins del seu contenidor font.</p>';
					}
				} else {
					scheduleEditorContentArea.innerHTML = '<p>No s\'ha pogut carregar l\'editor per a aquest tipus de plantilla ('+selectedType+'). El contenidor font no existeix.</p>';
				}
				if (scheduleEditorCardWrapper) scheduleEditorCardWrapper.style.display = 'block';
			}
	
			function handleTypeChange() {
				var selectedType = tmplTypeSelect.value;
				if (piscinaRow) {
					piscinaRow.style.display = (selectedType === 'single') ? '' : 'none';
				}
				if (scheduleEditorCardWrapper) {
					if (selectedType) {
						showCorrectEditor(selectedType);
					} else {
						scheduleEditorCardWrapper.style.display = 'none';
						 currentEditorType = null; 
						 scheduleEditorCardWrapper.classList.remove('card-full-width-mode');
						 scheduleEditorCardWrapper.dataset.needsFullWidth = "false";
					}
				}
			}
	
			if (tmplTypeSelect) {
				tmplTypeSelect.addEventListener('change', handleTypeChange);
				showCorrectEditor('<?php echo esc_js($type_on_load); ?>'); 
			}
	
			if (piscinaSelect) { 
				 piscinaSelect.addEventListener('change', function() {
					if (tmplTypeSelect && tmplTypeSelect.value === 'single') { 
						window.updateSinglePoolEditorViewGlobal(this.value);
					}
				 });
			}
	
			var fullForm = document.getElementById('sportic-template-full-form');
			if (fullForm) {
				fullForm.addEventListener('submit', function(e){
					var currentType = tmplTypeSelect ? tmplTypeSelect.value : 'day';
					var dataStructDayOrSingle = {}; 
					var weekData = {};   
					var activeEditorContent = scheduleEditorContentArea; 
	
					if (currentType === 'day') {
						activeEditorContent.querySelectorAll('#sportic-plantilla-day-editor-content .sportic-table-body-wrapper table[data-piscina]').forEach(function(tbl){
							var piscina_slug_taula = tbl.getAttribute('data-piscina');
							if(!piscina_slug_taula) return;
							if(!dataStructDayOrSingle[piscina_slug_taula]) dataStructDayOrSingle[piscina_slug_taula] = {};
							tbl.querySelectorAll('tbody tr').forEach(function(r){
								var horaCell = r.querySelector('td:first-child');
								if(!horaCell) return;
								var hora = horaCell.textContent.trim();
								var carrils = [];
								r.querySelectorAll('td.sportic-cell').forEach(function(cell){
									carrils.push(cell.getAttribute('data-valor') || 'l');
								});
								dataStructDayOrSingle[piscina_slug_taula][hora] = carrils;
							});
						});
						var hiddenInputDay = document.getElementById('sportic_tmpl_day_json');
						if(hiddenInputDay) hiddenInputDay.value = JSON.stringify(dataStructDayOrSingle);
	
					} else if (currentType === 'week') {
						activeEditorContent.querySelectorAll('#sportic-plantilla-week-editor-content .sportic-table-body-wrapper table[data-piscina]').forEach(function(tbl){
							var piscinaFullAttr = tbl.getAttribute('data-piscina'); 
							if (!piscinaFullAttr) return;
							var matches = piscinaFullAttr.match(/^week_(\d+)_(.+)$/);
							if (!matches) return;
							var dayIndex = parseInt(matches[1], 10);
							var shortPiscinaSlug = matches[2];
							if(typeof weekData[dayIndex] === 'undefined') weekData[dayIndex] = {};
							if(typeof weekData[dayIndex][shortPiscinaSlug] === 'undefined') weekData[dayIndex][shortPiscinaSlug] = {};
							tbl.querySelectorAll('tbody tr').forEach(function(r){
								var horaCell = r.querySelector('td:first-child');
								if (!horaCell) return;
								var hora = horaCell.textContent.trim();
								var carrils = [];
								r.querySelectorAll('.sportic-cell').forEach(function(cell){
									carrils.push(cell.getAttribute('data-valor') || 'l');
								});
								weekData[dayIndex][shortPiscinaSlug][hora] = carrils;
							});
						});
						var hiddenInputWeek = document.getElementById('sportic_tmpl_week_json');
						if(hiddenInputWeek) hiddenInputWeek.value = JSON.stringify(weekData);
	
					} else if (currentType === 'single') {
						var piscinaSeleccionadaActualment = piscinaSelect ? piscinaSelect.value : '';
						var singleEditorNode = activeEditorContent.querySelector('#sportic-plantilla-single-editor-content');
						if (singleEditorNode) {
							var taulaPiscinaVisible = singleEditorNode.querySelector('#single-pool-editor-' + piscinaSeleccionadaActualment + ' .sportic-table-body-wrapper table[data-piscina="' + piscinaSeleccionadaActualment + '"]');
							if (taulaPiscinaVisible) {
								taulaPiscinaVisible.querySelectorAll('tbody tr').forEach(function(r){
									var horaCell = r.querySelector('td:first-child');
									if(!horaCell) return;
									var hora = horaCell.textContent.trim();
									var carrils = [];
									r.querySelectorAll('td.sportic-cell').forEach(function(cell){
										carrils.push(cell.getAttribute('data-valor') || 'l');
									});
									dataStructDayOrSingle[hora] = carrils; 
								});
							}
						}
						var hiddenInputSingle = document.getElementById('sportic_tmpl_single_json');
						if(hiddenInputSingle) hiddenInputSingle.value = JSON.stringify(dataStructDayOrSingle);
					}
				});
			}
		}); 
		</script>
		<?php
		echo '</div>'; 
	}	
	
	/**
	* Formulari intern per a "day".
	* $tmpl_data['data'] = array( 'infantil'=>..., 'p4'=>..., 'p6'=>..., 'p12_20'=>... )
	*/
function sportic_unfile_mostrar_form_plantilla_day($tmpl_id, $tmpl_data) {
		$dayDataFromTemplate = isset($tmpl_data['data']) && is_array($tmpl_data['data']) ? $tmpl_data['data'] : array();
		
		$configured_pools = sportic_unfile_get_pool_labels_sorted();
		$finalDayDataToShow = array(); // Aquest array contindrà les dades a mostrar per cada piscina configurada
	
		foreach ($configured_pools as $slug => $p_info) {
			if (isset($dayDataFromTemplate[$slug]) && is_array($dayDataFromTemplate[$slug])) {
				// La plantilla ja té dades per aquesta piscina, les usem
				$finalDayDataToShow[$slug] = $dayDataFromTemplate[$slug];
			} else {
				// La plantilla no té dades (o és una piscina nova), creem default
				$finalDayDataToShow[$slug] = sportic_unfile_crear_programacio_default($slug);
			}
		}
		?>
		<form id="sportic-plantilla-day-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="sportic_unfile_guardar_plantilla_day_data" />
			<input type="hidden" name="tmpl_id" value="<?php echo esc_attr($tmpl_id); ?>" />
			<input type="hidden" id="sportic_tmpl_day_json" name="sportic_tmpl_day_json" value="" />
	
			<h2 class="nav-tab-wrapper">
			<?php
			$first = true;
			foreach ($configured_pools as $slug => $pinfo) {
				$label = $pinfo['label'];
				$activeClass = $first ? 'nav-tab-active' : '';
				echo '<a href="#tmpl_day_' . esc_attr($slug) . '" class="nav-tab ' . $activeClass . '">'
					. esc_html($label) . '</a>';
				$first = false;
			}
			?>
			</h2>
	
			<?php
			$first = true;
			foreach ($configured_pools as $slug => $pinfo) {
				$displayStyle = $first ? 'display:block;' : 'display:none;';
				echo '<div id="tmpl_day_' . esc_attr($slug) . '" class="sportic-tab-content" style="' . $displayStyle . '">';
				// Mostrem la taula per a cada piscina configurada
				// $finalDayDataToShow[$slug] conté les dades correctes (de plantilla o default)
				sportic_unfile_mostrar_plantilla_day_table($slug, $finalDayDataToShow[$slug]);
				echo '</div>';
				$first = false;
			}
			?>
			<p>
				<input type="submit" class="button button-primary" value="Desar horaris de plantilla (dia)" />
			</p>
		</form>
	
		<script>
		document.addEventListener('DOMContentLoaded', function(){
			var dayTabs = document.querySelectorAll('#sportic-plantilla-day-form .nav-tab-wrapper .nav-tab');
			dayTabs.forEach(function(tab){
				tab.addEventListener('click', function(e){
					e.preventDefault();
					var wrapper = tab.closest('.nav-tab-wrapper');
					if(wrapper) {
						wrapper.querySelectorAll('.nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });
					}
					var dayForm = document.getElementById('sportic-plantilla-day-form');
					if(dayForm) {
						dayForm.querySelectorAll('.sportic-tab-content').forEach(function(content){ content.style.display = 'none'; });
					}
					tab.classList.add('nav-tab-active');
					var target = tab.getAttribute('href');
					var targetContent = document.querySelector(target);
					if(targetContent) {
						targetContent.style.display = 'block';
						 // Re-escalar text al mostrar la pestanya
						targetContent.querySelectorAll('.sportic-cell .sportic-text').forEach(function(span){
							var cell = span.closest('.sportic-cell');
							if(cell) {
								// Aquesta part és un placeholder, necessites la teva funció real autoScaleText
								// exemple: autoScaleText(cell); 
							}
						});
					}
				});
			});
	
			var dayForm = document.getElementById('sportic-plantilla-day-form');
			if(dayForm) {
				dayForm.addEventListener('submit', function(e){
					var dataStruct = {};
					// S'ha d'assegurar que els slugs utilitzats per recollir dades coincideixen
					// amb els slugs de les piscines per les quals s'han mostrat taules.
					// Els atributs data-piscina a les taules generades per 
					// sportic_unfile_mostrar_plantilla_day_table ja són els slugs correctes.
	
					dayForm.querySelectorAll('.sportic-table-body-wrapper table').forEach(function(tbl){
						var piscina_slug_taula = tbl.getAttribute('data-piscina');
						if(!piscina_slug_taula) return;
						
						if(!dataStruct[piscina_slug_taula]) dataStruct[piscina_slug_taula] = {};
	
						var rows = tbl.querySelectorAll('tbody tr');
						rows.forEach(function(r){
							var horaCell = r.querySelector('td:first-child');
							if(!horaCell) return;
							var hora = horaCell.textContent.trim();
							var carrils = [];
							r.querySelectorAll('td.sportic-cell').forEach(function(cell){
								var val = cell.getAttribute('data-valor') || 'l';
								carrils.push(val);
							});
							dataStruct[piscina_slug_taula][hora] = carrils;
						});
					});
					var hiddenInput = document.getElementById('sportic_tmpl_day_json');
					if(hiddenInput) {
						hiddenInput.value = JSON.stringify(dataStruct);
					}
				});
			}
		});
		</script>
		<?php
	}	
	/* =========================================================================
	 * INICI FUNCIÓ CORREGIDA – SUBSTITUEIX L'ORIGINAL
	 * Aquesta versió corregeix la visualització dels colors a l'editor
	 * de plantilles, fent que funcioni amb descripcions completes.
	 * =========================================================================*/
	function sportic_unfile_mostrar_plantilla_day_table($piscina, $data) {
		// =========================================================================
		//  1. OBTENIR CONFIGURACIÓ AUTORITÀRIA (PART SENSE CANVIS)
		// =========================================================================
		$real_slug_piscina_per_default = $piscina;
		if (strpos($piscina, 'week_') === 0) {
			$parts = explode('_', $piscina, 3);
			if (count($parts) >= 3) {
				$real_slug_piscina_per_default = $parts[2];
			}
		}
		$pool_config = sportic_unfile_get_pool_labels_sorted();
		$numCarrils = $pool_config[$real_slug_piscina_per_default]['lanes'] ?? 4;
		if ($numCarrils < 1) $numCarrils = 1;
	
		// =========================================================================
		//  2. PREPARAR I AJUSTAR DADES DE LA PLANTILLA (PART SENSE CANVIS)
		// =========================================================================
		if (empty($data) || !is_array($data)) {
			$data = sportic_unfile_crear_programacio_default($real_slug_piscina_per_default);
		}
		foreach ($data as $hora => &$carrils_array) {
			if (is_array($carrils_array)) {
				$count_actual = count($carrils_array);
				if ($count_actual < $numCarrils) {
					$carrils_array = array_pad($carrils_array, $numCarrils, 'l');
				} elseif ($count_actual > $numCarrils) {
					$carrils_array = array_slice($carrils_array, 0, $numCarrils);
				}
			} else {
				$carrils_array = array_fill(0, $numCarrils, 'l');
			}
		}
		unset($carrils_array);
	
		// =========================================================================
		//  3. RENDERITZAR LA TAULA (AQUÍ ESTÀ LA CORRECCIÓ)
		// =========================================================================
	
		// <-- INICI DE LA CORRECCIÓ CLAU -->
		// Creem un mapa de [descripció] => [color] per buscar els colors correctament.
		$activitiesMap = [];
		$customActivities = get_option('sportic_unfile_custom_letters', array());
		if (is_array($customActivities)) {
			foreach ($customActivities as $activity) {
				if (!empty($activity['description']) && !empty($activity['color'])) {
					$activitiesMap[trim($activity['description'])] = trim($activity['color']);
				}
			}
		}
		// Afegim els colors estàndard al mapa per assegurar que sempre existeixen.
		$activitiesMap['l'] = '#ffffff';
		$activitiesMap['b'] = '#b9b9b9';
		// <-- FI DE LA CORRECCIÓ CLAU -->
	
		$totesHores = array_keys($data);
		sort($totesHores);
		$tableClass = ($numCarrils > 6) ? 'sportic-wide' : 'sportic-narrow';
		$containerStyle = ($tableClass === 'sportic-wide') ? 'max-width: none; width: 100%;' : '';
		?>
		<div class="sportic-taula-container" style="<?php echo esc_attr($containerStyle); ?>">
			<div class="sportic-table-header-wrapper">
				<table class="widefat striped sportic-table <?php echo $tableClass; ?>">
					<thead>
						<tr>
							<th>Hora</th>
							<?php for ($i = 1; $i <= $numCarrils; $i++): ?>
								<th>C. <?php echo $i; ?></th>
							<?php endfor; ?>
						</tr>
					</thead>
				</table>
			</div>
			<div class="sportic-table-body-wrapper">
				<table class="widefat striped sportic-table <?php echo $tableClass; ?>"
						data-piscina="<?php echo esc_attr($piscina); ?>">
					<tbody>
					<?php
					$rowIndex = 0;
					foreach ($totesHores as $hora) {
						if (! sportic_unfile_is_time_in_open_range($hora)) {
							continue;
						}
						$arrVals = $data[$hora];
	
						echo '<tr>';
						echo '<td>' . esc_html($hora) . '</td>';
						for($c = 0; $c < $numCarrils; $c++){
							$valCel = $arrVals[$c] ?? 'l';
	
							// <-- INICI DE LA CORRECCIÓ CLAU -->
							// Ara, en lloc de generar una classe CSS, busquem el color directament.
							$valorBase = is_string($valCel) ? trim($valCel) : 'l';
							$partPrincipal = strpos($valorBase, ':') !== false ? trim(explode(':', $valorBase, 2)[0]) : $valorBase;
							
							// Busquem el color al mapa que hem creat. Si no el troba, serà blanc per defecte.
							$colorFons = $activitiesMap[$partPrincipal] ?? $activitiesMap['l'];
							
							// El text a mostrar és el valor complet (incloent sub-ítem)
							// excepte per 'l' i 'b', que no mostren text.
							$displayText = ($valorBase === 'l' || $valorBase === 'b') ? '' : $valorBase;
							
							// Apliquem el color directament amb un estil inline.
							// La classe 'sportic-cell' es manté per a la interactivitat del JS.
							?>
							<td class="sportic-cell"
								data-row="<?php echo $rowIndex; ?>"
								data-col="<?php echo $c; ?>"
								data-valor="<?php echo esc_attr($valorBase); ?>"
								style="background-color: <?php echo esc_attr($colorFons); ?> !important;">
								<span class="sportic-text"><?php echo esc_html($displayText); ?></span>
							</td>
							<?php
							// <-- FI DE LA CORRECCIÓ CLAU -->
						}
						echo '</tr>';
						$rowIndex++;
					}
					?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}


	/**
	* Formulari per a "week": $tmpl_data['data'] = array(0..6 => [ 'infantil'=>..., 'p4'=>..., 'p6'=>..., 'p12_20'=>... ])
	*/
function sportic_unfile_mostrar_form_plantilla_week($tmpl_id, $tmpl_data) {
	// Si $tmpl_data és null (nova plantilla), inicialitzem weekDataFromTemplate com a array buit.
	// Si no, intentem obtenir 'data'. Si no existeix o no és array, també array buit.
	$weekDataFromTemplate = (isset($tmpl_data['data']) && is_array($tmpl_data['data'])) ? $tmpl_data['data'] : array_fill(0, 7, []);

	$configured_pools = sportic_unfile_get_pool_labels_sorted();
	// Assegurem que finalWeekDataToShow tingui 7 entrades, cadascuna un array per a les piscines.
	$finalWeekDataToShow = array_fill(0, 7, []); 

	for ($i = 0; $i < 7; $i++) { 
		// Assegurem que cada dia dins de finalWeekDataToShow és un array
		if (!is_array($finalWeekDataToShow[$i])) {
			$finalWeekDataToShow[$i] = [];
		}
		foreach ($configured_pools as $slug => $p_info) {
			// Comprovem si existeixen dades per a aquest dia i piscina a la plantilla.
			// Si $weekDataFromTemplate[$i] no existeix o no és array, o si $weekDataFromTemplate[$i][$slug] no existeix o no és array.
			if (isset($weekDataFromTemplate[$i]) && is_array($weekDataFromTemplate[$i]) && isset($weekDataFromTemplate[$i][$slug]) && is_array($weekDataFromTemplate[$i][$slug])) {
				$finalWeekDataToShow[$i][$slug] = $weekDataFromTemplate[$i][$slug];
			} else {
				// Si no hi ha dades, creem la programació per defecte.
				$finalWeekDataToShow[$i][$slug] = sportic_unfile_crear_programacio_default($slug);
			}
		}
	}

	$diasSetmanaLabels = array('Dilluns', 'Dimarts', 'Dimecres', 'Dijous', 'Divendres', 'Dissabte', 'Diumenge');
	
	// No necessitem el <form> aquí perquè ja està englobat pel formulari principal
	// en sportic_unfile_mostrar_form_plantilla
	?>
	
	<div id="sportic-plantilla-week-editor-wrapper">
		<h2 class="nav-tab-wrapper sportic-top-tabs">
		<?php
		for ($i = 0; $i < 7; $i++) {
			$activeClass = ($i === 0) ? 'nav-tab-active' : '';
			// L'atribut href ara apunta a un ID que podem gestionar amb JS per mostrar/ocultar
			echo '<a href="#tmpl_week_day_content_' . $i . '" class="nav-tab ' . $activeClass . '" data-day-index="' . $i . '">'
				. esc_html($diasSetmanaLabels[$i]) . '</a>';
		}
		?>
		</h2>

		<?php
		// Contingut per a cada dia de la setmana
		foreach ($finalWeekDataToShow as $day_index => $piscines_del_dia):
			$displayStyle = ($day_index === 0) ? 'display:block;' : 'display:none;';
			?>
			<div id="tmpl_week_day_content_<?php echo $day_index; ?>" class="sportic-tab-content sportic-week-day-pane" style="<?php echo $displayStyle; ?>">
				<?php /* No necessitem el <h3> amb el nom del dia aquí, ja que la pestanya fa aquesta funció. 
						Si el vols mantenir, pots fer-ho. */ ?>
				<?php // echo '<h3>' . esc_html($diasSetmanaLabels[$day_index]) . '</h3>'; ?>

				<h2 class="nav-tab-wrapper sportic-sub-tabs">
				<?php
				$firstSub = true;
				foreach ($configured_pools as $slug => $pinfo) {
					$label = $pinfo['label'];
					$activeClass2 = $firstSub ? 'nav-tab-active' : '';
					// L'atribut href per a les sub-pestanyes també apunta a un ID manejable
					echo '<a href="#tmpl_week_day_' . $day_index . '_pool_' . esc_attr($slug) . '" class="nav-tab ' . $activeClass2 . '" data-pool-slug="' . esc_attr($slug) . '">'
						. esc_html($label) . '</a>';
					$firstSub = false;
				}
				?>
				</h2>

				<div class="sportic-subtabs-container">
				<?php
				$firstSub = true;
				foreach ($configured_pools as $slug => $pinfo) {
					$displayStyle2 = $firstSub ? 'display:block;' : 'display:none;';
					$data_piscina_attr = "week_{$day_index}_{$slug}"; 
					
					echo '<div id="tmpl_week_day_' . $day_index . '_pool_' . esc_attr($slug) . '" class="sportic-subtab-content sportic-week-pool-pane" style="' . $displayStyle2 . '">';
					// Assegurem que passem les dades correctes a la funció que mostra la taula
					$dades_piscina_dia_actual = $piscines_del_dia[$slug] ?? sportic_unfile_crear_programacio_default($slug);
					sportic_unfile_mostrar_plantilla_day_table(
						$data_piscina_attr, 
						$dades_piscina_dia_actual 
					);
					echo '</div>';
					$firstSub = false;
				}
				?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	// El botó de desar i els camps JSON ocults ja estan al formulari principal.
	// El JavaScript per gestionar les pestanyes setmanals s'afegirà a sportic_unfile_mostrar_form_plantilla
}
	/**
	* Formulari per a una plantilla de Piscina Individual
	*/
	function sportic_unfile_mostrar_form_plantilla_single($tmpl_id, $tmpl_data) {
		// $tmpl_data['piscina'] = 'infantil' (o 'p4','p6','p12_20')
		// $tmpl_data['data'] = programació per a aquesta piscina
	
		$piscina = isset($tmpl_data['piscina']) ? $tmpl_data['piscina'] : 'infantil';
		if (!isset($tmpl_data['data']) || !is_array($tmpl_data['data']) || empty($tmpl_data['data'])) {
			$tmpl_data['data'] = sportic_unfile_crear_programacio_default($piscina);
		}
		$data = $tmpl_data['data'];
	
		// Carreguem etiquetes per saber el "label"
		$allPools = sportic_unfile_get_pool_labels_sorted();
		$poolLabel = isset($allPools[$piscina]) ? $allPools[$piscina]['label'] : $piscina;
	
		?>
		<form id="sportic-plantilla-single-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="sportic_unfile_guardar_plantilla_single_data" />
			<input type="hidden" name="tmpl_id" value="<?php echo esc_attr($tmpl_id); ?>" />
			<input type="hidden" name="tmpl_piscina" value="<?php echo esc_attr($piscina); ?>" />
			<input type="hidden" id="sportic_tmpl_single_json" name="sportic_tmpl_single_json" value="" />
	
			<h2>
				Plantilla de Pavelló Individual:
				<?php echo esc_html($poolLabel); ?>
			</h2>
	
			<?php
			// Mostrem la taula (tipus day) però només per la piscina concreta
			sportic_unfile_mostrar_plantilla_day_table($piscina, $data);
			?>
	
			<p>
				<input type="submit" class="button button-primary"
						value="Desar horaris de plantilla (pavelló individual)" />
			</p>
		</form>
	
		<script>
		document.addEventListener('DOMContentLoaded', function(){
			var singleForm = document.getElementById('sportic-plantilla-single-form');
			if(singleForm) {
				singleForm.addEventListener('submit', function(e){
					var dataStruct = {};
					singleForm.querySelectorAll('.sportic-table-body-wrapper table').forEach(function(tbl){
						var rows = tbl.querySelectorAll('tbody tr');
						rows.forEach(function(r){
							var horaCell = r.querySelector('td:first-child');
							if(!horaCell) return;
							var hora = horaCell.textContent.trim();
							var carrils = [];
							r.querySelectorAll('.sportic-cell').forEach(function(cell){
								var val = cell.getAttribute('data-valor') || 'l';
								carrils.push(val);
							});
							dataStruct[hora] = carrils;
						});
					});
					var hiddenInput = document.getElementById('sportic_tmpl_single_json');
					if(hiddenInput) {
						hiddenInput.value = JSON.stringify(dataStruct);
					}
				});
			}
		});
		</script>
		<?php
	}
	
	
	
	/**
	* APLICAR PLANTILLA A DIA O SETMANA O PISCINA INDIVIDUAL
	*/
	// Afegim/actualitzem el hook existent:
	add_action('admin_post_sportic_unfile_aplicar_plantilla','sportic_unfile_aplicar_plantilla_cb');
	
	/**
	* Aplica plantilla tenint en compte bloqueig (llegit del mega-array amb '!')
	* i el checkbox ignore_lock. Desa usant la funció que separa a les dues taules.
	*/
add_action('admin_post_sportic_unfile_aplicar_plantilla','sportic_unfile_aplicar_plantilla_cb');
function sportic_unfile_aplicar_plantilla_cb() {
	// 1. Validació de Nonce i Permisos
	if ( ! isset( $_POST['sportic_apply_template_nonce'] ) || ! wp_verify_nonce( $_POST['sportic_apply_template_nonce'], 'sportic_apply_template_action' ) ) {
		wp_die('Error de seguretat (Nonce invàlid). Si us plau, torna enrere i intenta-ho de nou.');
	}

	if (!current_user_can('manage_options')) {
		wp_die('No tens permisos per realitzar aquesta acció.');
	}

	// ================================================================
	// INICI DE LA CORRECCIÓ
	// ================================================================
	// Obtenim el 'lloc' des del formulari. Aquest pas és crucial.
	$lloc_actiu_slug = isset($_POST['lloc']) ? sanitize_key($_POST['lloc']) : '';

	// Preparem els paràmetres per a la redirecció, incloent el lloc
	$redirect_params = ['lloc' => $lloc_actiu_slug];
	if (!empty($_POST['selected_date'])) $redirect_params['selected_date'] = sanitize_text_field($_POST['selected_date']);
	if (!empty($_POST['cal_year']))      $redirect_params['cal_year'] = intval($_POST['cal_year']);
	if (!empty($_POST['cal_month']))     $redirect_params['cal_month'] = intval($_POST['cal_month']);
	if (!empty($_POST['sportic_active_tab'])) $redirect_params['active_tab'] = sanitize_text_field($_POST['sportic_active_tab']);
	if (!empty($_POST['sportic_active_subday'])) $redirect_params['active_subday'] = sanitize_text_field($_POST['sportic_active_subday']);
	
	if (empty($lloc_actiu_slug)) {
		$redirect_params['error_msg'] = urlencode('Error crític: No s\'ha pogut determinar el lloc per aplicar la plantilla.');
		wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=sportic-onefile-menu')));
		exit;
	}
	// ================================================================
	// FI DE LA CORRECCIÓ
	// ================================================================

	// 2. Recollida i Validació de Dades del Formulari
	$ignore_lock     = isset($_POST['ignore_lock_template']) && $_POST['ignore_lock_template'] === '1';
	$template_option = isset($_POST['template_option']) ? sanitize_text_field($_POST['template_option']) : '';
	$data_inici      = isset($_POST['rang_data_inici']) ? sanitize_text_field($_POST['rang_data_inici']) : '';
	$data_fi         = isset($_POST['rang_data_fi'])    ? sanitize_text_field($_POST['rang_data_fi'])    : '';
	$dia_filter      = isset($_POST['dia_filter']) && is_array($_POST['dia_filter']) ? array_map('intval', $_POST['dia_filter']) : array();
	
	if (empty($template_option) || empty($data_inici) || empty($data_fi)) {
		$redirect_params['error_msg'] = urlencode('Falten dades: plantilla, data inici o data fi.');
		wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=sportic-onefile-menu')));
		exit;
	}

	$parts = explode('|', $template_option);
	$tmpl_id = $parts[0] ?? '';
	$plantilla_type_from_option = $parts[1] ?? '';
	$selected_pool_for_single = ($plantilla_type_from_option === 'single' && isset($parts[2])) ? $parts[2] : '';

	// ================================================================
	// INICI DE LA CORRECCIÓ (aquí estava l'error)
	// ================================================================
	// Ara passem el 'lloc_actiu_slug' a la funció per carregar les plantilles correctes.
	$plantilles_guardades = sportic_unfile_get_plantilles($lloc_actiu_slug);
	// ================================================================
	// FI DE LA CORRECCIÓ
	// ================================================================

	if (!isset($plantilles_guardades[$tmpl_id])) {
		$redirect_params['error_msg'] = urlencode('La plantilla seleccionada no existeix en aquest lloc.');
		wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=sportic-onefile-menu')));
		exit;
	}
	$plantilla_a_aplicar = $plantilles_guardades[$tmpl_id];
	$tipus_real_plantilla = $plantilla_a_aplicar['type'] ?? 'day';

	if ($plantilla_type_from_option !== $tipus_real_plantilla) {
		$redirect_params['error_msg'] = urlencode('Inconsistència en el tipus de plantilla. Contacta amb l\'administrador.');
		wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=sportic-onefile-menu')));
		exit;
	}

	if (!isset($plantilla_a_aplicar['data']) || !is_array($plantilla_a_aplicar['data']) || empty($plantilla_a_aplicar['data'])) {
		$redirect_params['error_msg'] = urlencode("La plantilla '{$plantilla_a_aplicar['name']}' no conté dades de programació vàlides o està buida.");
		wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=sportic-onefile-menu')));
		exit;
	}

	// 3. Preparació de Dades per Aplicar Plantilla
	$dades_actuals_carregades_inicialment = sportic_carregar_tot_com_array();
	
	$oldState_json = json_encode($dades_actuals_carregades_inicialment);
	$oldState = json_decode($oldState_json, true);
	if ($oldState === null && json_last_error() !== JSON_ERROR_NONE) {
		$oldState = $dades_actuals_carregades_inicialment; 
	}

	$dades_a_modificar_amb_plantilla_json = json_encode($dades_actuals_carregades_inicialment);
	$dades_a_modificar_amb_plantilla = json_decode($dades_a_modificar_amb_plantilla_json, true);
	if ($dades_a_modificar_amb_plantilla === null && json_last_error() !== JSON_ERROR_NONE) {
		$dades_a_modificar_amb_plantilla = $dades_actuals_carregades_inicialment; 
	}

	$configured_pools = sportic_unfile_get_pool_labels_sorted();

	try {
		$start_date_obj = new DateTime($data_inici);
		$end_date_obj   = new DateTime($data_fi);
		if ($start_date_obj > $end_date_obj) {
			$redirect_params['error_msg'] = urlencode('La data d’inici és posterior a la data fi.');
			wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=sportic-onefile-menu')));
			exit;
		}
	} catch (Exception $e) {
		$redirect_params['error_msg'] = urlencode('Error en el format de les dates.');
		wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=sportic-onefile-menu')));
		exit;
	}

	// 4. Bucle Principal d'Aplicació de Plantilla
	$current_loop_date = clone $start_date_obj;
	$canvis_realitzats_flag = false; 

	while ($current_loop_date <= $end_date_obj) {
		$current_date_str = $current_loop_date->format('Y-m-d');
		$day_of_week_num  = intval($current_loop_date->format('N')); 

		if (!empty($dia_filter) && !in_array($day_of_week_num, $dia_filter)) {
			$current_loop_date->modify('+1 day');
			continue;
		}

		$piscines_a_processar_avui = array_keys($configured_pools);
		$dades_plantilla_per_aquest_dia = null;

		if ($tipus_real_plantilla === 'day') {
			$dades_plantilla_per_aquest_dia = $plantilla_a_aplicar['data']; 
		} elseif ($tipus_real_plantilla === 'week') {
			$index_plantilla_setmana = $day_of_week_num - 1; 
			if (isset($plantilla_a_aplicar['data'][$index_plantilla_setmana]) && is_array($plantilla_a_aplicar['data'][$index_plantilla_setmana])) {
				$dades_plantilla_per_aquest_dia = $plantilla_a_aplicar['data'][$index_plantilla_setmana]; 
			}
		} elseif ($tipus_real_plantilla === 'single') {
			$slug_piscina_plantilla = $selected_pool_for_single ?: ($plantilla_a_aplicar['piscina'] ?? null);
			if ($slug_piscina_plantilla && isset($configured_pools[$slug_piscina_plantilla])) {
				$piscines_a_processar_avui = [$slug_piscina_plantilla]; 
				$dades_plantilla_per_aquest_dia = [$slug_piscina_plantilla => $plantilla_a_aplicar['data']]; 
			} else {
				$current_loop_date->modify('+1 day');
				continue;
			}
		}

		if (is_array($dades_plantilla_per_aquest_dia) && !empty($dades_plantilla_per_aquest_dia)) {
			foreach ($piscines_a_processar_avui as $slug_piscina_actual) {
				if (!isset($configured_pools[$slug_piscina_actual])) {
					continue;
				}
				$num_carrils_actuals_config = $configured_pools[$slug_piscina_actual]['lanes'];

				if (!isset($dades_plantilla_per_aquest_dia[$slug_piscina_actual])) {
					continue;
				}
				$hores_plantilla_per_piscina = $dades_plantilla_per_aquest_dia[$slug_piscina_actual]; 

				if (is_array($hores_plantilla_per_piscina) && !empty($hores_plantilla_per_piscina)) {
					if (!isset($dades_a_modificar_amb_plantilla[$slug_piscina_actual])) {
						$dades_a_modificar_amb_plantilla[$slug_piscina_actual] = [];
					}
					if (!isset($dades_a_modificar_amb_plantilla[$slug_piscina_actual][$current_date_str]) || 
						!is_array($dades_a_modificar_amb_plantilla[$slug_piscina_actual][$current_date_str])) {
						$dades_a_modificar_amb_plantilla[$slug_piscina_actual][$current_date_str] = sportic_unfile_crear_programacio_default($slug_piscina_actual);
					}

					foreach ($hores_plantilla_per_piscina as $hora_plantilla => $carrils_plantilla_valors) {
						if (!sportic_unfile_is_time_in_open_range($hora_plantilla) || !is_array($carrils_plantilla_valors)) {
							continue;
						}

						if (!isset($dades_a_modificar_amb_plantilla[$slug_piscina_actual][$current_date_str][$hora_plantilla]) ||
							!is_array($dades_a_modificar_amb_plantilla[$slug_piscina_actual][$current_date_str][$hora_plantilla])) {
							$dades_a_modificar_amb_plantilla[$slug_piscina_actual][$current_date_str][$hora_plantilla] = array_fill(0, $num_carrils_actuals_config, 'l');
						}
						
						for ($c = 0; $c < $num_carrils_actuals_config; $c++) {
							$valor_plantilla_per_carril = $carrils_plantilla_valors[$c] ?? 'l'; 
							$valor_actual_raw = $dades_a_modificar_amb_plantilla[$slug_piscina_actual][$current_date_str][$hora_plantilla][$c] ?? 'l';
							
							$actual_is_locked = (is_string($valor_actual_raw) && strpos($valor_actual_raw, '!') === 0);
							$valor_actual_sense_prefix = $actual_is_locked ? substr($valor_actual_raw, 1) : $valor_actual_raw;
							if ($valor_actual_sense_prefix === false || $valor_actual_sense_prefix === '') $valor_actual_sense_prefix = 'l';

							if (!$actual_is_locked || $ignore_lock) {
								$nou_valor_final_per_carril = $valor_plantilla_per_carril;
								if ($actual_is_locked && !$ignore_lock) {
									if ($valor_actual_sense_prefix !== $valor_plantilla_per_carril) {
										$nou_valor_final_per_carril = '!' . $valor_plantilla_per_carril;
									} else {
										$nou_valor_final_per_carril = $valor_actual_raw;
									}
								}
								if ($nou_valor_final_per_carril !== $valor_actual_raw) {
									$dades_a_modificar_amb_plantilla[$slug_piscina_actual][$current_date_str][$hora_plantilla][$c] = $nou_valor_final_per_carril;
									$canvis_realitzats_flag = true;
								}
							}
						}
					}
				}
			}
		}
		$current_loop_date->modify('+1 day');
	}

	// 5. Desat de Dades i Neteja de Cache
	if ($canvis_realitzats_flag) {
		$diff = sportic_extract_diff($oldState, $dades_a_modificar_amb_plantilla); 
		
		$undo_entry_saved = false; 

		if (!empty($diff['old_partial']) || !empty($diff['new_partial'])) {
			if (sportic_save_undo_entry('sportic_unfile_dades', $diff)) {
				sportic_clear_redo('sportic_unfile_dades');
				$undo_entry_saved = true;
			} else {
				$redirect_params['warning_msg'] = urlencode('La plantilla s\'ha aplicat, però l\'operació ha sigut massa gran per desar un punt de restauració (Desfer).');
			}
		} else {
			$undo_entry_saved = true;
		}
		
		sportic_emmagatzemar_tot_com_array($dades_a_modificar_amb_plantilla); 
		
		if (!isset($redirect_params['warning_msg'])) {
			$redirect_params['status'] = 'template_applied';
		}
		
	} else {
		if (!isset($redirect_params['error_msg'])) { 
			$redirect_params['status'] = 'ok';
			$redirect_params['info_msg'] = urlencode('La plantilla no ha produït canvis en el rang de dates especificat (potser les dades ja eren iguals o totes les cel·les afectades estaven bloquejades i no s\'ha ignorat el bloqueig).');
		}
	}

	// 6. Redirecció
	wp_redirect(add_query_arg($redirect_params, admin_url('admin.php?page=sportic-onefile-menu')));
	exit;
}		
		/**
		* SHORTCODE FRONTEND [sportic_frontend_custom_dayview]
		*/
		function sportic_frontend_custom_dayview_shortcode() {
	$diesSetmanaCat = ['Diumenge','Dilluns','Dimarts','Dimecres','Dijous','Divendres','Dissabte'];
	$data_seleccionada = isset($_GET['sc_date']) ? sanitize_text_field($_GET['sc_date']) : date('Y-m-d');
	try {
		$objecteData = new DateTime($data_seleccionada);
	} catch (Exception $e) {
		$objecteData = new DateTime();
	}
	$data_seleccionada = $objecteData->format('Y-m-d');
	$piscines = sportic_unfile_get_pool_labels_sorted();
	if (empty($piscines)) {
		return '<div class="sportic-week-shell">' . esc_html__('Ara mateix no hi ha pavellons configurats.', 'sportic') . '</div>';
	}
	$schedule_window = sportic_carregar_finestra_bd($data_seleccionada, 6, 0);
	$palette = sportic_get_activity_palette();
	$dies = [];
	$legend_keys = ['l','b'];
	$temp = clone $objecteData;
	for ($i = 0; $i < 7; $i++) {
		$dia_actual = $temp->format('Y-m-d');
		$sessions_per_piscina = sportic_build_day_sessions($schedule_window, $piscines, $dia_actual);
		$fitxa_dia = [
			'date'    => $dia_actual,
			'day_name'=> $diesSetmanaCat[(int) $temp->format('w')],
			'label'   => $temp->format('d/m'),
			'pools'   => [],
		];
		foreach ($piscines as $slug => $info) {
			$pool_sessions = $sessions_per_piscina[$slug] ?? [];
			$target_sessions = [];
			foreach ($pool_sessions as $sessio) {
				$legend_key = array_key_exists($sessio['value'], $palette) ? $sessio['value'] : $sessio['label'];
				$legend_keys[] = $legend_key;
				if (strtolower($sessio['value']) === 'l') {
					continue;
				}
				$target_sessions[] = $sessio;
			}
			$fitxa_dia['pools'][$slug] = [
				'label'    => $info['label'],
				'sessions' => $target_sessions,
			];
		}
		$dies[] = $fitxa_dia;
		$temp->modify('+1 day');
	}
	$legend_keys = array_values(array_unique(array_filter($legend_keys)));
	$legend_entries = [];
	foreach ($legend_keys as $key) {
		if (isset($palette[$key])) {
			$legend_entries[$key] = $palette[$key];
		}
	}
	if (empty($legend_entries)) {
		$legend_entries = $palette;
	}
	ob_start();
	?>
	<style>
	.sportic-week-shell{font-family:'Inter',system-ui,-apple-system,'Segoe UI',sans-serif;display:flex;flex-direction:column;gap:24px;}
	.sportic-week-header{display:flex;flex-direction:column;gap:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:20px;}
	.sportic-week-header form{display:flex;flex-wrap:wrap;gap:12px;align-items:center;}
	.sportic-week-header label{font-weight:600;color:#1f2937;}
	.sportic-week-header input[type='date']{border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:0.95rem;}
	.sportic-week-header button[type='submit']{background:#2563eb;color:#fff;border:none;padding:9px 16px;border-radius:8px;font-weight:600;cursor:pointer;}
	.sportic-week-header button[type='submit']:hover{background:#1d4ed8;}
	.sportic-day-filter{display:flex;flex-wrap:wrap;gap:8px;}
	.sportic-day-filter button{border:1px solid #cbd5e1;background:#fff;color:#1e293b;border-radius:999px;padding:7px 14px;font-size:0.85rem;font-weight:600;cursor:pointer;transition:all 0.15s;}
	.sportic-day-filter button.active,.sportic-day-filter button:hover{background:#1e40af;color:#fff;border-color:#1e40af;}
	.sportic-day-grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));}
	.sportic-day-card{background:#fff;border-radius:18px;box-shadow:0 20px 50px rgba(15,23,42,0.08);padding:24px;display:flex;flex-direction:column;gap:18px;border:1px solid #e2e8f0;}
	.sportic-day-card__header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:8px;}
	.sportic-day-card__header .day-name{font-weight:700;color:#0f172a;font-size:1rem;}
	.sportic-day-card__header .day-date{color:#475569;font-size:0.9rem;}
	.sportic-pool-card{border:1px solid #e2e8f0;border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:12px;background:#f9fafb;}
	.sportic-pool-card h3{margin:0;font-size:0.95rem;font-weight:700;color:#1f2937;}
	.sportic-session-wrapper{display:flex;flex-direction:column;gap:12px;}
	.sportic-session-list{display:grid;gap:12px;}
	.sportic-session-card{background:#fff;border-left:5px solid var(--session-color,#1d4ed8);border-radius:12px;padding:14px;box-shadow:0 12px 30px rgba(15,23,42,0.08);display:flex;flex-direction:column;gap:10px;}
	.sportic-session-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(30,64,175,0.08);color:#1e3a8a;border-radius:999px;padding:4px 12px;font-size:0.75rem;font-weight:600;text-transform:uppercase;}
	.sportic-session-time{font-weight:700;font-size:1rem;color:#0f172a;}
	.sportic-session-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:0.85rem;color:#334155;}
	.sportic-session-flags{display:flex;gap:8px;font-size:0.8rem;color:#475569;}
	.sportic-session-pagination{display:none;align-items:center;justify-content:space-between;background:#fff;border-radius:999px;padding:6px 12px;border:1px solid #cbd5e1;}
	.sportic-session-pagination button{border:none;background:none;font-weight:600;color:#1e3a8a;cursor:pointer;}
	.sportic-session-pagination button:disabled{color:#94a3b8;cursor:not-allowed;}
	.sportic-session-pagination.visible{display:flex;}
	.sportic-session-empty{background:#ffffff;border:1px dashed #cbd5e1;border-radius:12px;padding:20px;text-align:center;font-size:0.9rem;color:#475569;}
	.sportic-legend{border-top:1px solid #e2e8f0;padding-top:16px;}
	.sportic-legend h4{margin:0 0 10px;font-size:0.95rem;font-weight:700;color:#0f172a;}
	.sportic-legend-grid{display:flex;flex-wrap:wrap;gap:10px;}
	.sportic-legend-chip{display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;font-size:0.85rem;}
	.sportic-legend-chip .color{width:16px;height:16px;border-radius:50%;display:block;}
	@media(max-width:720px){.sportic-week-header{padding:16px;} .sportic-day-grid{grid-template-columns:1fr;} .sportic-pool-card{gap:16px;}}
	</style>
	<div class="sportic-week-shell">
		<div class="sportic-week-header">
			<form method="get">
				<label for="sc_date"><?php echo esc_html__('Data inicial', 'sportic'); ?></label>
				<input type="date" id="sc_date" name="sc_date" value="<?php echo esc_attr($data_seleccionada); ?>" />
				<?php foreach ($_GET as $key => $value) : if ($key === 'sc_date') continue; ?>
				<input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" />
				<?php endforeach; ?>
				<button type="submit"><?php echo esc_html__('Actualitza', 'sportic'); ?></button>
			</form>
			<div class="sportic-day-filter">
				<button type="button" class="active" data-day="all"><?php echo esc_html__('Tots els dies', 'sportic'); ?></button>
				<?php foreach ($dies as $day_info) : ?>
				<button type="button" data-day="<?php echo esc_attr($day_info['date']); ?>"><?php echo esc_html($day_info['day_name'] . ' ' . $day_info['label']); ?></button>
				<?php endforeach; ?>
			</div>
			<p style="margin:0;color:#475569;font-size:0.85rem;"><?php echo esc_html__('Filtra per dia i revisa totes les sessions programades de cada pavelló.', 'sportic'); ?></p>
		</div>
		<div class="sportic-day-grid">
		<?php foreach ($dies as $day_info) : ?>
			<article class="sportic-day-card" data-day="<?php echo esc_attr($day_info['date']); ?>">
				<header class="sportic-day-card__header">
					<span class="day-name"><?php echo esc_html($day_info['day_name']); ?></span>
					<span class="day-date"><?php echo esc_html($day_info['label']); ?></span>
				</header>
				<div class="sportic-day-card__content">
				<?php foreach ($piscines as $slug => $info) : $pool_sessions = $day_info['pools'][$slug]['sessions']; ?>
					<section class="sportic-pool-card">
						<h3><?php echo esc_html($info['label']); ?></h3>
						<?php if (!empty($pool_sessions)) : ?>
							<div class="sportic-session-wrapper">
								<div class="sportic-session-list" data-page-size="6">
								<?php foreach ($pool_sessions as $sessio) : ?>
									<div class="sportic-session-card" style="--session-color: <?php echo esc_attr($sessio['color']); ?>;">
										<span class="sportic-session-chip"><?php echo esc_html($sessio['badge']); ?></span>
										<div class="sportic-session-time"><?php echo esc_html($sessio['start'] . ' - ' . $sessio['end']); ?> · <?php echo esc_html(sportic_format_duration_label($sessio['duration'])); ?></div>
										<div class="sportic-session-meta">
											<span><?php echo esc_html__('Pista', 'sportic'); ?>: <?php echo esc_html($sessio['lane']); ?></span>
											<?php if (!empty($sessio['sub_label'])) : ?><span><?php echo esc_html__('Subgrup', 'sportic'); ?>: <?php echo esc_html($sessio['sub_label']); ?></span><?php endif; ?>
										</div>
										<?php if ($sessio['locked'] || $sessio['recurrent']) : ?>
										<div class="sportic-session-flags">
											<?php if ($sessio['recurrent']) : ?><span>⟳ <?php echo esc_html__('Esdeveniment recurrent', 'sportic'); ?></span><?php endif; ?>
											<?php if ($sessio['locked']) : ?><span>🔒 <?php echo esc_html__('Bloqueig manual', 'sportic'); ?></span><?php endif; ?>
										</div>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
								</div>
								<div class="sportic-session-pagination" aria-hidden="true">
									<button type="button" data-action="prev"><?php echo esc_html__('Anterior', 'sportic'); ?></button>
									<span class="current-page">1 / 1</span>
									<button type="button" data-action="next"><?php echo esc_html__('Següent', 'sportic'); ?></button>
								</div>
							</div>
						<?php else : ?>
							<div class="sportic-session-empty"><?php echo esc_html__('Cap sessió programada. Totes les pistes lliures.', 'sportic'); ?></div>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
		<div class="sportic-legend">
			<h4><?php echo esc_html__('Referències', 'sportic'); ?></h4>
			<div class="sportic-legend-grid">
			<?php foreach ($legend_entries as $entry) : ?>
				<span class="sportic-legend-chip"><span class="color" style="background:<?php echo esc_attr($entry['color']); ?>;"></span><?php echo esc_html($entry['label']); ?></span>
			<?php endforeach; ?>
			</div>
		</div>
	</div>
	<script>
	(function(){
	const dayButtons = document.querySelectorAll('.sportic-day-filter button');
	const dayCards = document.querySelectorAll('.sportic-day-card');
	dayButtons.forEach(btn => {
		btn.addEventListener('click', () => {
			dayButtons.forEach(b => b.classList.remove('active'));
			btn.classList.add('active');
			const target = btn.dataset.day;
			dayCards.forEach(card => {
				card.style.display = (target === 'all' || card.dataset.day === target) ? '' : 'none';
			});
		});
	});
	document.querySelectorAll('.sportic-session-wrapper').forEach(wrapper => {
		const list = wrapper.querySelector('.sportic-session-list');
		const nav = wrapper.querySelector('.sportic-session-pagination');
		if (!list || !nav) return;
		const cards = Array.from(list.querySelectorAll('.sportic-session-card'));
		const pageSize = parseInt(list.dataset.pageSize || '6');
		if (cards.length <= pageSize) {
			nav.classList.remove('visible');
			return;
		}
		let page = 0;
		const totalPages = Math.ceil(cards.length / pageSize);
		const prevBtn = nav.querySelector('[data-action="prev"]');
		const nextBtn = nav.querySelector('[data-action="next"]');
		const counter = nav.querySelector('.current-page');
		function renderPage() {
			cards.forEach((card, idx) => {
				const visible = idx >= page * pageSize && idx < (page + 1) * pageSize;
				card.style.display = visible ? '' : 'none';
			});
			counter.textContent = (page + 1) + ' / ' + totalPages;
			prevBtn.disabled = page === 0;
			nextBtn.disabled = page >= totalPages - 1;
		}
		prevBtn.addEventListener('click', () => { if (page > 0) { page--; renderPage(); } });
		nextBtn.addEventListener('click', () => { if (page < totalPages - 1) { page++; renderPage(); } });
		nav.classList.add('visible');
		renderPage();
	});
	})();
	</script>
	<?php
	return ob_get_clean();
}
add_shortcode('sportic_frontend_custom_dayview', 'sportic_frontend_custom_dayview_shortcode');
	
	
	
	
	
	/**
	* Obté les etiquetes i l'ordre de les piscines, amb valors per defecte.
	* Retorna un array associatiu:
	*   slug => [ 'label' => '...', 'order' => int ]
	* I les ordena per 'order'.
	*/
function sportic_unfile_get_pool_labels_sorted() {
		// Comprovem si la funció del plugin de configuració de Llocs existeix.
		if (!function_exists('sportllocs_get_llocs')) {
			// --- PLA DE CONTINGÈNCIA (FALLBACK) ---
			// Si el plugin de Llocs no està actiu, retornem a la lògica antiga
			// per garantir que el sistema no es trenqui.
			if (function_exists('sportpavellons_get_pools')) {
				return sportpavellons_get_pools();
			}
			
			// Si cap dels dos plugins de configuració està actiu, retornem uns valors per defecte.
			$camps_handbol = [
				'pavello_tmr' => [
					'slug'  => 'pavello_tmr', 'label' => 'PAVELLÓ TERESA MARIA ROCA', 'lanes' => 5, 'order' => 10,
					'lane_labels' => ['Pista 1 A', 'Pista 1 B', 'Pista 2 A', 'Pista 2 B', 'Gimnàs']
				],
				'escola_pia' => [
					'slug'  => 'escola_pia', 'label' => 'ESCOLA PIA SANTA ANNA', 'lanes' => 2, 'order' => 20,
					'lane_labels' => ['Pista A', 'Pista B']
				],
				'escola_marta' => [
					'slug'  => 'escola_marta', 'label' => 'ESCOLA MARTA MATA', 'lanes' => 2, 'order' => 30,
					'lane_labels' => ['Pista A', 'Pista B']
				],
			];
			uasort($camps_handbol, function($a, $b) {
				return ($a['order'] ?? 999) <=> ($b['order'] ?? 999);
			});
			return $camps_handbol;
		}
	
		// --- LÒGICA PRINCIPAL AMB "LLOCS" ---
		$tots_els_llocs = sportllocs_get_llocs();
		
		// Si no hi ha cap lloc configurat, retornem un array buit per evitar errors.
		if (empty($tots_els_llocs)) {
			return [];
		}
	
		// Determinem quin és el "Lloc" actiu.
		$lloc_actiu_slug = '';
	
		// ========================================================================
		// INICI DE LA CORRECCIÓ
		// ========================================================================
		// PAS 0: Prioritat màxima per a accions de desat (POST).
		// Quan es desa un formulari, la informació del lloc ve per $_POST.
		// Hem de comprovar-ho abans que res per assegurar-nos que desfem i carreguem
		// les dades del context correcte.
		if (isset($_POST['lloc']) && !empty($_POST['lloc'])) {
			$lloc_candidat_post = sanitize_key($_POST['lloc']);
			if (isset($tots_els_llocs[$lloc_candidat_post])) {
				$lloc_actiu_slug = $lloc_candidat_post;
			}
		}
		// ========================================================================
		// FI DE LA CORRECCIÓ
		// ========================================================================
	
		// 1. Prioritat mitjana: Paràmetre 'lloc' a la URL (per a enllaços directes).
		// Aquesta comprovació només s'executarà si no hem trobat un lloc a $_POST.
		if (empty($lloc_actiu_slug) && isset($_GET['lloc']) && !empty($_GET['lloc'])) {
			$lloc_candidat = sanitize_key($_GET['lloc']);
			if (isset($tots_els_llocs[$lloc_candidat])) {
				$lloc_actiu_slug = $lloc_candidat;
			}
		}
	
		// 2. Si no ve per URL, mirem si hi ha una cookie guardada de l'última selecció de l'usuari.
		if (empty($lloc_actiu_slug) && isset($_COOKIE['sportic_active_lloc'])) {
			$lloc_candidat_cookie = sanitize_key($_COOKIE['sportic_active_lloc']);
			if (isset($tots_els_llocs[$lloc_candidat_cookie])) {
				$lloc_actiu_slug = $lloc_candidat_cookie;
			}
		}
		
		// 3. Si encara no tenim un lloc actiu, seleccionem el primer de la llista per defecte.
		if (empty($lloc_actiu_slug)) {
			// Com que sportllocs_get_llocs() ja retorna els llocs ordenats, el primer és el correcte.
			$lloc_actiu_slug = key($tots_els_llocs);
		}
		
		// Finalment, obtenim els pavellons associats al lloc actiu.
		// La funció sportllocs_get_pavellons_by_lloc s'encarregarà de retornar-los ja ordenats.
		if (function_exists('sportllocs_get_pavellons_by_lloc')) {
			return sportllocs_get_pavellons_by_lloc($lloc_actiu_slug);
		}
		
		// Si la funció per obtenir pavellons per lloc no existís, retornem un array buit.
		return [];
	}		
			
	/**
	* Evita que es mostrin notices d’altres plugins/WordPress
	* a la pàgina de configuració de SporTIC.
	*/
	function sportic_unfile_disable_admin_notices_settings_page() {
		if ( isset($_GET['page']) && $_GET['page'] === 'sportic-onefile-settings' ) {
			remove_all_filters( 'admin_notices' );
			remove_all_filters( 'all_admin_notices' );
			remove_all_filters( 'user_admin_notices' );
			remove_all_filters( 'network_admin_notices' );
		}
	}
	add_action( 'admin_head', 'sportic_unfile_disable_admin_notices_settings_page', 1 );
	
	
	
	
	/********************************************
		* 
		* [sportic_custom_dina3_button]
		* Shortcode
		* 
		********************************************/
	if ( ! function_exists('_sportic_construir_html_dia_petit_custom') ) {
		function _sportic_construir_html_dia_petit_custom( $dia, $dades, $piscines ) {
		ob_start();
		$dataBonica = ( new DateTime( $dia ) )->format( 'd-m-Y' );
		?>
		<div class="sportic-day-container" data-day="<?php echo esc_attr( $dia ); ?>">
			<h3 style="margin-bottom:15px; font-size:1rem; text-align:center;">Dia: <?php echo esc_html( $dataBonica ); ?></h3>
			<div style="display:flex; justify-content:center; white-space:nowrap;">
				<?php foreach ( $piscines as $slug => $pinfo ) : ?>
					<?php
						$programacio = $dades[ $slug ][ $dia ] ?? sportic_unfile_crear_programacio_default( $slug );
						$hores = array_keys( $programacio );
						sort( $hores );
						if ( empty( $hores ) ) continue;
						$numCarrils = count( $programacio[ $hores[0] ] );
					?>
					<div style="display:inline-block; border:1px solid #ccc; margin:10px; padding:10px; vertical-align:top;">
						<h4 style="margin:0 0 10px; text-align:center; font-size:0.9rem;"><?php echo esc_html( $pinfo['label'] ); ?></h4>
						<table style="border-collapse:collapse; margin:0 auto; font-size:0.8rem;">
							<thead><tr style="background:#eee;"><th style="border:1px solid #999; padding:3px;">Hora</th><?php for ( $c = 1; $c <= $numCarrils; $c++ ) : ?><th style="border:1px solid #999; padding:3px;">C<?php echo $c; ?></th><?php endfor; ?></tr></thead>
							<tbody>
								<?php foreach ( $hores as $h ) :
									if ( ! sportic_unfile_is_time_in_open_range( $h ) ) continue; 
									echo '<tr><td style="border:1px solid #999; padding:3px;">' . esc_html( $h ) . '</td>';
									foreach ( $programacio[ $h ] as $val ) {
										// <-- INICI MODIFICACIÓ -->
										$valorBase = $val;
										if (is_string($val) && (strpos($val, '@') === 0 || strpos($val, '!') === 0)) {
											$valorBase = substr($val, 1);
										}
										$lletra = strtolower(trim($valorBase));
										$lletra = strpos($lletra, ':') !== false ? explode(':', $lletra, 2)[0] : $lletra;
										
										if ($lletra === 'l') {
											$color = '#b3d9ff';
										} elseif ($lletra === 'b') {
											$color = '#ffffff';
										} else {
											$color = '#ff0000';
										}
										echo '<td style="border:1px solid #999; padding:3px; background-color:' . esc_attr($color) . ';"></td>';
										// <-- FI MODIFICACIÓ -->
									}
									echo '</tr>';
								endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
	
	// 2) AJAX per obtenir la programació actualitzada (si ho voleu fer servir), etc.
	//    -- Codi sencer de l'exemple que tenies.
	
	function sportic_get_schedule_ajax() {
		// Sempre data actual
		$data_seleccionada = date( 'Y-m-d' );
		$objecteData = DateTime::createFromFormat( 'Y-m-d', $data_seleccionada );
		if ( ! $objecteData ) {
			$objecteData = new DateTime();
		}
		$data_seleccionada = $objecteData->format( 'Y-m-d' );
	
		$dades = get_option( 'sportic_unfile_dades', array() );
		$etiquetesPiscinesOrdenades = sportic_unfile_get_pool_labels_sorted();
	
		$htmlArray = array();
		$tempObj = clone $objecteData;
		for ( $i = 0; $i < 7; $i++ ) {
			$diaStr = $tempObj->format( 'Y-m-d' );
			$html = _sportic_construir_html_dia_petit_custom( $diaStr, $dades, $etiquetesPiscinesOrdenades );
			$htmlArray[] = array( 'day' => $diaStr, 'html' => $html );
			$tempObj->modify( '+1 day' );
		}
		wp_send_json_success( $htmlArray );
		wp_die();
	}
	add_action( 'wp_ajax_sportic_get_schedule', 'sportic_get_schedule_ajax' );
	add_action( 'wp_ajax_nopriv_sportic_get_schedule', 'sportic_get_schedule_ajax' );
	
	// 3) Shortcode per al botó que genera DIN A3
	function sportic_custom_dina3_button_shortcode() {
		// Data actual
		$data_seleccionada = date( 'Y-m-d' );
		$objecteData = DateTime::createFromFormat( 'Y-m-d', $data_seleccionada );
		if ( ! $objecteData ) {
			$objecteData = new DateTime();
		}
		$data_seleccionada = $objecteData->format( 'Y-m-d' );
	
		ob_start();
		?>
		<!-- Botó per descarregar el DIN A3 -->
		<button id="sportic_download_dina3" style="padding:16px 25px; background:#0073aa; color:#fff; border:none; border-radius:21px; cursor:pointer; text-transform: uppercase;">
			Descarregar disponibilitat 7 dies
		</button>
	
		<!-- Loader overlay -->
		<div id="sportic_loader_overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.8); z-index:9999; text-align:center;">
			<div style="position:relative; top:50%; transform:translateY(-50%);">
				<div class="sportic_loader" style="margin:0 auto;"></div>
				<div id="sportic_loader_text" style="margin-top:20px; font-size:18px; font-weight:bold; color:#333;"></div>
			</div>
		</div>
	
		<!-- html2canvas -->
		<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
	
		<script>
		(function(){
			var styleEl = document.createElement('style');
			styleEl.textContent =
				'.sportic_loader {' +
				'  border:16px solid #f3f3f3;' +
				'  border-top:16px solid #3498db;' +
				'  border-radius:50%;' +
				'  width:80px; height:80px;' +
				'  animation: sportic_spin 1s linear infinite;' +
				'}' +
				'@keyframes sportic_spin {' +
				'  0% { transform: rotate(0deg); }' +
				'  100% { transform: rotate(360deg); }' +
				'}';
			document.head.appendChild(styleEl);
		})();
	
		function mostrarLoader(msg) {
			document.getElementById('sportic_loader_text').textContent = msg;
			document.getElementById('sportic_loader_overlay').style.display = 'block';
		}
		function amagarLoader() {
			document.getElementById('sportic_loader_overlay').style.display = 'none';
		}
	
		function capturarElement(element) {
			return new Promise(function(resolve, reject) {
				var container = document.createElement('div');
				container.style.position = 'absolute';
				container.style.top = '0';
				container.style.left = '-9999px';
				container.style.background = '#fff';
				container.style.padding = '20px';
				container.style.pointerEvents = 'none';
	
				var clone = element.cloneNode(true);
				clone.style.display = 'block';
				container.appendChild(clone);
				document.body.appendChild(container);
	
				html2canvas(container, { scale: 3 }).then(function(canvas) {
					var dataURL = canvas.toDataURL('image/jpeg', 1.0);
					document.body.removeChild(container);
					resolve(dataURL);
				}).catch(function(err) {
					document.body.removeChild(container);
					reject(err);
				});
			});
		}
	
		document.getElementById('sportic_download_dina3').addEventListener('click', function(e) {
			e.preventDefault();
			mostrarLoader("Generant Setmana");
			var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
			var dataSeleccionada = '<?php echo esc_js($data_seleccionada); ?>';
	
			// Petició AJAX
			var xhr = new XMLHttpRequest();
			xhr.open("POST", ajaxurl, true);
			xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
			xhr.onreadystatechange = function() {
				if (xhr.readyState === 4) {
					if (xhr.status === 200) {
						try {
							var response = JSON.parse(xhr.responseText);
							if (response.success) {
								var schedule = response.data;
								var container = document.createElement('div');
								container.style.display = 'none';
								container.id = 'sportic_hidden_schedule';
								document.body.appendChild(container);
	
								var dies = [];
								schedule.forEach(function(item) {
									dies.push(item.day);
									var dayDiv = document.createElement('div');
									dayDiv.id = 'custom_smalltable-' + item.day;
									dayDiv.innerHTML = item.html;
									container.appendChild(dayDiv);
								});
	
								var promises = dies.map(function(d){
									var elem = document.getElementById('custom_smalltable-' + d);
									if(!elem) return Promise.resolve(null);
									return capturarElement(elem);
								});
								Promise.all(promises).then(function(imagesData){
									var dayImagesData = imagesData.filter(function(img){ return img !== null; });
									if(dayImagesData.length < 7) {
										amagarLoader();
										alert("No s'han capturat tots els 7 dies.");
										return;
									}
									// Convertim dataURL en objectes Image
									var loadedImages = [];
									var loadPromises = dayImagesData.map(function(dataURL, idx){
										return new Promise(function(r){
											var img = new Image();
											img.onload = function() {
												loadedImages[idx] = img;
												r();
											};
											img.onerror = function(){ r(); };
											img.src = dataURL;
										});
									});
									Promise.all(loadPromises).then(function(){
										// DIN A3
										var canvasWidth = 4961, canvasHeight = 3508;
										var margeExtern = 40, margeInterH = 20, margeInterV = 20;
										var canvasDINA3 = document.createElement('canvas');
										canvasDINA3.width = canvasWidth;
										canvasDINA3.height = canvasHeight;
										var ctx = canvasDINA3.getContext('2d');
										ctx.fillStyle = "#fff";
										ctx.fillRect(0,0,canvasWidth,canvasHeight);
	
										var aspect = loadedImages[0].naturalWidth / loadedImages[0].naturalHeight;
										var availableWidth = canvasWidth - (2*margeExtern);
										var columns = 3;
										var maxCellWidthHoriz = Math.floor((availableWidth - ((columns - 1)*margeInterH))/columns);
										var availableHeight = canvasHeight - (2*margeExtern + margeInterV*2);
										var maxCellHeight = Math.floor(availableHeight / 3);
										var maxCellWidthVert = Math.floor(maxCellHeight*aspect);
										var cellWidth = Math.min(maxCellWidthHoriz, maxCellWidthVert);
										var cellHeight = Math.floor(cellWidth / aspect);
	
										// Fila1 (0,1,2)
										var row1Width = 3*cellWidth + 2*margeInterH;
										var row1X = Math.floor((canvasWidth - row1Width)/2);
										var row1Y = margeExtern;
										for(var i=0; i<3; i++){
											var xPos = row1X + i*(cellWidth+margeInterH);
											ctx.drawImage(loadedImages[i], 0,0, loadedImages[i].naturalWidth, loadedImages[i].naturalHeight,
												xPos, row1Y, cellWidth, cellHeight);
										}
										// Fila2 (3,4,5)
										var row2Y = row1Y + cellHeight + margeInterV;
										for(var j=3; j<6; j++){
											var xPos2 = row1X + (j-3)*(cellWidth+margeInterH);
											ctx.drawImage(loadedImages[j], 0,0, loadedImages[j].naturalWidth, loadedImages[j].naturalHeight,
												xPos2, row2Y, cellWidth, cellHeight);
										}
										// Fila3 (dia 6 + logo)
										var row3Y = row2Y + cellHeight + margeInterV;
										var day6 = loadedImages[6];
										var logoImg = new Image();
										logoImg.onload = function(){
											var desiredLogoHeight = Math.floor(cellHeight*0.7);
											var logoAspect = logoImg.naturalWidth / logoImg.naturalHeight;
											var logoWidth = Math.floor(desiredLogoHeight * logoAspect);
	
											var totalRow3Width = cellWidth + margeInterH + logoWidth;
											var row3X = Math.floor((canvasWidth - totalRow3Width)/2);
	
											ctx.drawImage(day6, 0,0, day6.naturalWidth, day6.naturalHeight,
												row3X, row3Y, cellWidth, cellHeight);
	
											var logoX = row3X + cellWidth + margeInterH;
											var logoY = row3Y + Math.floor((cellHeight - desiredLogoHeight)/2);
											ctx.drawImage(logoImg, 0,0, logoImg.naturalWidth, logoImg.naturalHeight,
												logoX, logoY, logoWidth, desiredLogoHeight);
	
											var finalURL = canvasDINA3.toDataURL('image/jpeg', 1.0);
											amagarLoader();
											var link = document.createElement('a');
											link.download = "DIN_A3_" + dataSeleccionada + ".jpg";
											link.href = finalURL;
											link.click();
											// Esborrar contenidor temporal
											container.parentNode.removeChild(container);
										};
										logoImg.onerror = function(){
											amagarLoader();
											alert("Error carregant el logo");
										};
										logoImg.src = "<?php echo plugin_dir_url(__FILE__); ?>imatges/logo.jpg";
									});
								});
							} else {
								amagarLoader();
								alert("Error: " + response.data);
							}
						} catch(e) {
							amagarLoader();
							alert("Error en la resposta del servidor.");
						}
					} else {
						amagarLoader();
						alert("Error de connexió amb el servidor.");
					}
				}
			};
			xhr.send("action=sportic_get_schedule&sc_date=" + encodeURIComponent(dataSeleccionada));
		});
		</script>
		<?php
		return ob_get_clean();
	}
	add_shortcode( 'sportic_custom_dina3_button', 'sportic_custom_dina3_button_shortcode' );
	
	/**
		* Comentari en català:
		* Forcem un CSS al final de l'administració per sobreescriure 
		* el color predefinit de .widefat td, .widefat th.
		*/
	function sportic_forcar_color_sportic_table() {
		?>
		<style>
		/* Comentari en català:
			Donem més especificitat i usem !important 
			per fer que la nostra configuració guanyi. 
		*/
		html body #wpwrap .sportic-table.widefat td,
		html body #wpwrap .sportic-table.widefat th {
			color: #000000; /* color base (canvia si vols un altre) */
		}
		</style>
		<?php
	}
	add_action('admin_head', 'sportic_forcar_color_sportic_table', 999);
	
	/* =========================================================================
	 * INICI FUNCIÓ CORREGIDA – SUBSTITUEIX L'ORIGINAL
	 * Aquesta versió corregeix la validació per desar correctament les
	 * activitats a les plantilles.
	 * =========================================================================*/
	function sportic_unfile_parse_plantilla_data($data) {
		// 1) Carreguem les descripcions vàlides (incloent les personalitzades).
		$validDescriptions = array('l', 'b'); // Estats base sempre permesos.
		$customActivities = get_option('sportic_unfile_custom_letters', array());
		
		if (is_array($customActivities)) {
			foreach ($customActivities as $activity) {
				// Comprovem contra la 'description', que és el que realment s'utilitza.
				if (!empty($activity['description'])) {
					$validDescriptions[] = trim($activity['description']);
				}
			}
		}
	
		// 2) Recorrem les dades d'entrada i les processem.
		$cleanData = array();
		if (!is_array($data)) {
			return $cleanData;
		}
	
		foreach ($data as $piscinaSlug => $horesArr) {
			if (!is_array($horesArr)) {
				continue;
			}
			$cleanData[$piscinaSlug] = array();
	
			foreach ($horesArr as $hora => $carrilsArr) {
				if (!is_array($carrilsArr)) {
					continue;
				}
	
				$cleanCarrils = array();
				foreach ($carrilsArr as $val) {
					// El valor que ve de l'editor és la descripció directa.
					$valorOriginal = is_string($val) ? trim($val) : 'l';
					$partPrincipal = $valorOriginal;
	
					// En cas que el valor inclogui un sub-ítem (ex: "Equip A:Subgrup 1"),
					// només validarem la part principal ("Equip A").
					if (strpos($valorOriginal, ':') !== false) {
						$parts = explode(':', $valorOriginal, 2);
						$partPrincipal = trim($parts[0]);
					}
	
					// Comprovem si la descripció principal és a la llista de valors permesos.
					if (in_array($partPrincipal, $validDescriptions, true)) {
						// Si és vàlida, mantenim el valor original complet.
						$cleanCarrils[] = $valorOriginal;
					} else {
						// Si la descripció no és vàlida, la substituïm per 'l' (lliure) per seguretat.
						$cleanCarrils[] = 'l';
					}
				}
				$cleanData[$piscinaSlug][$hora] = $cleanCarrils;
			}
		}
	
		return $cleanData;
	}	
	
	
	
	// El shorcut comença aqui... [sportic_frontend_custom_dayview_subitems]
	
	/**
	* ----------------------------------------------------------------------------
	* SHORTCODE: [sportic_frontend_custom_dayview_subitems]
	* ----------------------------------------------------------------------------
	*/
	if ( ! function_exists( 'sportic_frontend_custom_dayview_subitems_shortcode' ) ) {
		
			
function sportic_frontend_custom_dayview_subitems_shortcode() {
	$diesSetmanaCat = ['Diumenge','Dilluns','Dimarts','Dimecres','Dijous','Divendres','Dissabte'];
	$data_seleccionada = isset($_GET['sc_date']) ? sanitize_text_field($_GET['sc_date']) : date('Y-m-d');
	try {
		$objecteData = new DateTime($data_seleccionada);
	} catch (Exception $e) {
		$objecteData = new DateTime();
	}
	$data_seleccionada = $objecteData->format('Y-m-d');
	$nom_dia_actual = $diesSetmanaCat[(int) $objecteData->format('w')];
	$piscines = sportic_unfile_get_pool_labels_sorted();
	if (empty($piscines)) {
		return '<div class="sportic-week-shell">' . esc_html__('Ara mateix no hi ha pavellons configurats.', 'sportic') . '</div>';
	}
	$schedule_window = sportic_carregar_finestra_bd($data_seleccionada, 6, 0);
	$palette = sportic_get_activity_palette();
	$dies = [];
	$legend_keys = ['l', 'b'];
	$temp = clone $objecteData;
	for ($i = 0; $i < 7; $i++) {
		$dia_actual = $temp->format('Y-m-d');
		$sessions_per_piscina = sportic_build_day_sessions($schedule_window, $piscines, $dia_actual);
		$fitxa_dia = [
			'date'    => $dia_actual,
			'day_name'=> $diesSetmanaCat[(int) $temp->format('w')],
			'label'   => $temp->format('d/m'),
			'pools'   => [],
		];
		foreach ($piscines as $slug => $info) {
			$pool_sessions = $sessions_per_piscina[$slug] ?? [];
			$target_sessions = [];
			foreach ($pool_sessions as $sessio) {
				if (strtolower($sessio['value']) === 'l') {
					continue;
				}
				$legend_key = array_key_exists($sessio['value'], $palette) ? $sessio['value'] : $sessio['label'];
				$legend_keys[] = $legend_key;
				$target_sessions[] = $sessio;
			}
			$fitxa_dia['pools'][$slug] = [
				'label'    => $info['label'],
				'sessions' => $target_sessions,
			];
		}
		$dies[] = $fitxa_dia;
		$temp->modify('+1 day');
	}
	$legend_keys = array_values(array_unique(array_filter($legend_keys)));
	$legend_entries = [];
	foreach ($legend_keys as $key) {
		if (isset($palette[$key])) {
			$legend_entries[$key] = $palette[$key];
		}
	}
	if (empty($legend_entries)) {
		$legend_entries = $palette;
	}
	ob_start();
	?>
	<style>
	.sportic-week-shell{font-family:'Inter',system-ui,-apple-system,'Segoe UI',sans-serif;display:flex;flex-direction:column;gap:24px;}
	.sportic-week-header{display:flex;flex-direction:column;gap:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:20px;}
	.sportic-week-header form{display:flex;flex-wrap:wrap;gap:12px;align-items:center;}
	.sportic-week-header label{font-weight:600;color:#1f2937;}
	.sportic-week-header input[type='date']{border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:0.95rem;}
	.sportic-week-header button[type='submit']{background:#2563eb;color:#fff;border:none;padding:9px 16px;border-radius:8px;font-weight:600;cursor:pointer;}
	.sportic-week-header button[type='submit']:hover{background:#1d4ed8;}
	.sportic-day-filter{display:flex;flex-wrap:wrap;gap:8px;}
	.sportic-day-filter button{border:1px solid #cbd5e1;background:#fff;color:#1e293b;border-radius:999px;padding:7px 14px;font-size:0.85rem;font-weight:600;cursor:pointer;transition:all 0.15s;}
	.sportic-day-filter button.active,.sportic-day-filter button:hover{background:#1e40af;color:#fff;border-color:#1e40af;}
	.sportic-day-grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));}
	.sportic-day-card{background:#fff;border-radius:18px;box-shadow:0 20px 50px rgba(15,23,42,0.08);padding:24px;display:flex;flex-direction:column;gap:18px;border:1px solid #e2e8f0;}
	.sportic-day-card__header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:8px;}
	.sportic-day-card__header .day-name{font-weight:700;color:#0f172a;font-size:1rem;}
	.sportic-day-card__header .day-date{color:#475569;font-size:0.9rem;}
	.sportic-pool-card{border:1px solid #e2e8f0;border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:12px;background:#f9fafb;}
	.sportic-pool-card h3{margin:0;font-size:0.95rem;font-weight:700;color:#1f2937;}
	.sportic-session-wrapper{display:flex;flex-direction:column;gap:12px;}
	.sportic-session-list{display:grid;gap:12px;}
	.sportic-session-card{background:#fff;border-left:5px solid var(--session-color,#1d4ed8);border-radius:12px;padding:14px;box-shadow:0 12px 30px rgba(15,23,42,0.08);display:flex;flex-direction:column;gap:10px;}
	.sportic-session-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(30,64,175,0.08);color:#1e3a8a;border-radius:999px;padding:4px 12px;font-size:0.75rem;font-weight:600;text-transform:uppercase;}
	.sportic-session-time{font-weight:700;font-size:1rem;color:#0f172a;}
	.sportic-session-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:0.85rem;color:#334155;}
	.sportic-session-flags{display:flex;gap:8px;font-size:0.8rem;color:#475569;}
	.sportic-session-pagination{display:none;align-items:center;justify-content:space-between;background:#fff;border-radius:999px;padding:6px 12px;border:1px solid #cbd5e1;}
	.sportic-session-pagination button{border:none;background:none;font-weight:600;color:#1e3a8a;cursor:pointer;}
	.sportic-session-pagination button:disabled{color:#94a3b8;cursor:not-allowed;}
	.sportic-session-pagination.visible{display:flex;}
	.sportic-session-empty{background:#ffffff;border:1px dashed #cbd5e1;border-radius:12px;padding:20px;text-align:center;font-size:0.9rem;color:#475569;}
	.sportic-legend{border-top:1px solid #e2e8f0;padding-top:16px;}
	.sportic-legend h4{margin:0 0 10px;font-size:0.95rem;font-weight:700;color:#0f172a;}
	.sportic-legend-grid{display:flex;flex-wrap:wrap;gap:10px;}
	.sportic-legend-chip{display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:#fff;border:1px solid #e2e8f0;font-size:0.85rem;}
	.sportic-legend-chip .color{width:16px;height:16px;border-radius:50%;display:block;}
	@media(max-width:720px){.sportic-week-header{padding:16px;} .sportic-day-grid{grid-template-columns:1fr;} .sportic-pool-card{gap:16px;}}
	</style>
	<div class="sportic-week-shell">
		<div class="sportic-week-header">
			<form method="get">
				<label for="sc_date"><?php echo esc_html__('Data inicial', 'sportic'); ?></label>
				<input type="date" id="sc_date" name="sc_date" value="<?php echo esc_attr($data_seleccionada); ?>" />
				<?php foreach ($_GET as $key => $value) : if ($key === 'sc_date') continue; ?>
				<input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" />
				<?php endforeach; ?>
				<button type="submit"><?php echo esc_html__('Actualitza', 'sportic'); ?></button>
			</form>
			<div class="sportic-day-filter">
				<button type="button" class="active" data-day="all"><?php echo esc_html__('Tots els dies', 'sportic'); ?></button>
				<?php foreach ($dies as $day_info) : ?>
				<button type="button" data-day="<?php echo esc_attr($day_info['date']); ?>"><?php echo esc_html($day_info['day_name'] . ' ' . $day_info['label']); ?></button>
				<?php endforeach; ?>
			</div>
			<p style="margin:0;color:#475569;font-size:0.85rem;"><?php echo esc_html__('Fes servir els botons per filtrar per dia i consulta cada pavelló amb claredat.', 'sportic'); ?></p>
		</div>
		<div class="sportic-day-grid">
		<?php foreach ($dies as $day_info) : ?>
			<article class="sportic-day-card" data-day="<?php echo esc_attr($day_info['date']); ?>">
				<header class="sportic-day-card__header">
					<span class="day-name"><?php echo esc_html($day_info['day_name']); ?></span>
					<span class="day-date"><?php echo esc_html($day_info['label']); ?></span>
				</header>
				<div class="sportic-day-card__content">
				<?php foreach ($piscines as $slug => $info) : $pool_sessions = $day_info['pools'][$slug]['sessions']; ?>
					<section class="sportic-pool-card">
						<h3><?php echo esc_html($info['label']); ?></h3>
						<?php if (!empty($pool_sessions)) : ?>
							<div class="sportic-session-wrapper">
								<div class="sportic-session-list" data-page-size="6">
								<?php foreach ($pool_sessions as $sessio) : ?>
									<div class="sportic-session-card" style="--session-color: <?php echo esc_attr($sessio['color']); ?>;">
										<span class="sportic-session-chip"><?php echo esc_html($sessio['badge']); ?></span>
										<div class="sportic-session-time"><?php echo esc_html($sessio['start'] . ' - ' . $sessio['end']); ?> · <?php echo esc_html(sportic_format_duration_label($sessio['duration'])); ?></div>
										<div class="sportic-session-meta">
											<span><?php echo esc_html__('Pista', 'sportic'); ?>: <?php echo esc_html($sessio['lane']); ?></span>
											<?php if (!empty($sessio['sub_label'])) : ?><span><?php echo esc_html__('Subgrup', 'sportic'); ?>: <?php echo esc_html($sessio['sub_label']); ?></span><?php endif; ?>
										</div>
										<?php if ($sessio['locked'] || $sessio['recurrent']) : ?>
										<div class="sportic-session-flags">
											<?php if ($sessio['recurrent']) : ?><span>⟳ <?php echo esc_html__('Esdeveniment recurrent', 'sportic'); ?></span><?php endif; ?>
											<?php if ($sessio['locked']) : ?><span>🔒 <?php echo esc_html__('Bloqueig manual', 'sportic'); ?></span><?php endif; ?>
										</div>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
								</div>
								<div class="sportic-session-pagination" aria-hidden="true">
									<button type="button" data-action="prev"><?php echo esc_html__('Anterior', 'sportic'); ?></button>
									<span class="current-page">1 / 1</span>
									<button type="button" data-action="next"><?php echo esc_html__('Següent', 'sportic'); ?></button>
								</div>
							</div>
						<?php else : ?>
							<div class="sportic-session-empty"><?php echo esc_html__('Cap sessió programada. Totes les pistes lliures.', 'sportic'); ?></div>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
		<div class="sportic-legend">
			<h4><?php echo esc_html__('Referències', 'sportic'); ?></h4>
			<div class="sportic-legend-grid">
			<?php foreach ($legend_entries as $entry) : ?>
				<span class="sportic-legend-chip"><span class="color" style="background:<?php echo esc_attr($entry['color']); ?>;"></span><?php echo esc_html($entry['label']); ?></span>
			<?php endforeach; ?>
			</div>
		</div>
	</div>
	<script>
	(function(){
	const dayButtons = document.querySelectorAll('.sportic-day-filter button');
	const dayCards = document.querySelectorAll('.sportic-day-card');
	dayButtons.forEach(btn => {
		btn.addEventListener('click', () => {
			dayButtons.forEach(b => b.classList.remove('active'));
			btn.classList.add('active');
			const target = btn.dataset.day;
			dayCards.forEach(card => {
				card.style.display = (target === 'all' || card.dataset.day === target) ? '' : 'none';
			});
		});
	});
	document.querySelectorAll('.sportic-session-wrapper').forEach(wrapper => {
		const list = wrapper.querySelector('.sportic-session-list');
		const nav = wrapper.querySelector('.sportic-session-pagination');
		if (!list || !nav) return;
		const cards = Array.from(list.querySelectorAll('.sportic-session-card'));
		const pageSize = parseInt(list.dataset.pageSize || '6');
		if (cards.length <= pageSize) {
			nav.classList.remove('visible');
			return;
		}
		let page = 0;
		const totalPages = Math.ceil(cards.length / pageSize);
		const prevBtn = nav.querySelector('[data-action="prev"]');
		const nextBtn = nav.querySelector('[data-action="next"]');
		const counter = nav.querySelector('.current-page');
		function renderPage() {
			cards.forEach((card, idx) => {
				const visible = idx >= page * pageSize && idx < (page + 1) * pageSize;
				card.style.display = visible ? '' : 'none';
			});
			counter.textContent = (page + 1) + ' / ' + totalPages;
			prevBtn.disabled = page === 0;
			nextBtn.disabled = page >= totalPages - 1;
		}
		prevBtn.addEventListener('click', () => { if (page > 0) { page--; renderPage(); } });
		nextBtn.addEventListener('click', () => { if (page < totalPages - 1) { page++; renderPage(); } });
		nav.classList.add('visible');
		renderPage();
	});
	})();
	</script>
	<?php
	return ob_get_clean();
}
add_shortcode('sportic_frontend_custom_dayview_subitems', 'sportic_frontend_custom_dayview_subitems_shortcode');
		}
		
	
	
	
	/**
		* SHORTCODE: [sportic_dina3_html]
		*
		* Aquest shortcode construeix un document HTML complet amb la disposició
		* dels 7 dies (utilitzant la funció _sportic_construir_html_dia_petit_custom) 
		* i inclou el logo, tot amb dimensions fixes (4961x3508 px).
		*
		* Pots crear una pàgina de WordPress on posar aquest shortcode, i des d'un CRONJOB
		* utilitzar wkhtmltoimage per capturar aquesta URL i generar la imatge final.
		*/
		function sportic_dina3_html_shortcode() {
			// Obté la data actual (per iniciar el recorregut dels 7 dies)
			$data_seleccionada = date('Y-m-d');
			$objecteData = DateTime::createFromFormat('Y-m-d', $data_seleccionada);
			if (!$objecteData) { $objecteData = new DateTime(); }
			$data_seleccionada = $objecteData->format('Y-m-d');
		
			// Obté la programació guardada i les etiquetes de les piscines
			$dades = get_option('sportic_unfile_dades', array());
			$etiquetesPiscinesOrdenades = sportic_unfile_get_pool_labels_sorted();
		
			// Per cada dia dels 7 dies, crida la funció que construeix el contingut HTML
			$dias = array();
			$tempObj = clone $objecteData;
			for($i = 0; $i < 7; $i++){
				$diaStr = $tempObj->format('Y-m-d');
				$dias[] = _sportic_construir_html_dia_petit_custom($diaStr, $dades, $etiquetesPiscinesOrdenades);
				$tempObj->modify('+1 day');
			}
		
			// Ara construïm una pàgina HTML completa amb dimensions DIN A3.
			// Aquest layout és un exemple: es divideix en tres files:
			//  - Fila 1: dies 0, 1, 2
			//  - Fila 2: dies 3, 4, 5
			//  - Fila 3: dia 6 i, a la dreta, el logo.
			// Ajusta les dimensions segons el teu disseny.
			$html = '<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8">
			<title>Disponibilitat 7 dies DIN A3</title>
			<style>
				body { margin: 0; padding: 0; }
				.dina3-wrapper {
					width: 4961px;
					height: 3508px;
					position: relative;
					background: #fff;
					font-family: Arial, sans-serif;
				}
				.day-block {
					position: absolute;
					box-sizing: border-box;
					/* Afegeix aquí altres estils que vulguis (marges, fons, etc.) */
				}
			</style>
		</head>
		<body>
			<div class="dina3-wrapper">';
			
			// Definim dimensions i posicions (exemple)
			$margin_extern = 40;
			$margin_inter = 20;
			// Suposem que volem cada bloc de dia amb ample 1500px i alt 1100px
			$blockWidth = 1500;
			$blockHeight = 1100;
			
			// Fila 1: dies 0, 1, 2
			for ($i = 0; $i < 3; $i++) {
				$x = $margin_extern + $i * ($blockWidth + $margin_inter);
				$y = $margin_extern;
				$html .= '<div class="day-block" style="width:' . $blockWidth . 'px; height:' . $blockHeight . 'px; left:' . $x . 'px; top:' . $y . 'px;">';
				$html .= $dias[$i];
				$html .= '</div>';
			}
			// Fila 2: dies 3, 4, 5
			for ($i = 3; $i < 6; $i++) {
				$x = $margin_extern + ($i - 3) * ($blockWidth + $margin_inter);
				$y = $margin_extern + $blockHeight + $margin_inter;
				$html .= '<div class="day-block" style="width:' . $blockWidth . 'px; height:' . $blockHeight . 'px; left:' . $x . 'px; top:' . $y . 'px;">';
				$html .= $dias[$i];
				$html .= '</div>';
			}
			// Fila 3: dia 6 i el logo a la dreta
			// Dia 7 (index 6)
			$x = $margin_extern;
			$y = $margin_extern + 2 * ($blockHeight + $margin_inter);
			$html .= '<div class="day-block" style="width:' . $blockWidth . 'px; height:' . $blockHeight . 'px; left:' . $x . 'px; top:' . $y . 'px;">';
			$html .= $dias[6];
			$html .= '</div>';
			// Logo: posat a la dreta del dia 7
			$logoWidth = 500;
			$logoHeight = 500;
			$logoX = $margin_extern + $blockWidth + $margin_inter;
			$logoY = $y; // mateix nivell que el dia 7
			$html .= '<div class="day-block" style="width:' . $logoWidth . 'px; height:' . $logoHeight . 'px; left:' . $logoX . 'px; top:' . $logoY . 'px;">';
			$html .= '<img src="' . plugin_dir_url(__FILE__) . 'imatges/logo.jpg" style="width:100%; height:100%;" />';
			$html .= '</div>';
			
			$html .= '
			</div>
		</body>
		</html>';
			
			return $html;
		}
		add_shortcode('sportic_dina3_html', 'sportic_dina3_html_shortcode');
	
	// Aquesta funció compara dos arrays (l'estat antic i nou) i retorna un array associatiu
		// amb 'old_partial' i 'new_partial' amb les parts modificades.
		function sportic_extract_diff($valorAntic, $valorNou) {
	if (!is_array($valorAntic)) $valorAntic = array();
	if (!is_array($valorNou))   $valorNou   = array();
	
	$old_partial = array();
	$new_partial = array();

	$all_keys = array_unique(array_merge(array_keys($valorAntic), array_keys($valorNou)));

	foreach ($all_keys as $key) {
		$in_old = array_key_exists($key, $valorAntic);
		$in_new = array_key_exists($key, $valorNou);

		if ($in_old && !$in_new) {
			// Clau ELIMINADA: existia a l'antic, no al nou.
			$old_partial[$key] = $valorAntic[$key]; // Guardem el valor que tenia.
			$new_partial[$key] = null;              // Indiquem que al nou estat, aquesta clau és null (absent).
		} elseif (!$in_old && $in_new) {
			// Clau AFEGIDA: no existia a l'antic, sí al nou.
			$old_partial[$key] = null;              // Indiquem que a l'antic estat, aquesta clau era null (absent).
			$new_partial[$key] = $valorNou[$key];   // Guardem el valor nou.
		} elseif ($in_old && $in_new) {
			// Clau present en ambdós: comprovem si ha canviat.
			$oldVal = $valorAntic[$key];
			$newVal = $valorNou[$key];

			if (is_array($oldVal) && is_array($newVal)) {
				$sub_diff = sportic_extract_diff($oldVal, $newVal);
				// Només afegim al diff si hi ha sub-diferències reals
				// i només les parts que tenen canvis.
				if (!empty($sub_diff['old_partial'])) $old_partial[$key] = $sub_diff['old_partial'];
				if (!empty($sub_diff['new_partial'])) $new_partial[$key] = $sub_diff['new_partial'];
				
			} elseif ($oldVal !== $newVal) {
				$old_partial[$key] = $oldVal;
				$new_partial[$key] = $newVal;
			}
			// Si $oldVal === $newVal i no són arrays, no hi ha diff per aquesta clau.
		}
	}
	
	return array('old_partial' => $old_partial, 'new_partial' => $new_partial);
}
		
		// Aquesta funció aplica el "partial" (diff) sobre un array base
		// i retorna el nou array resultant.
		function sportic_apply_partial($base_state, $changes_to_apply) {
	$final_state = $base_state; // Comencem amb l'estat base

	if (!is_array($changes_to_apply)) { // Si no hi ha canvis o el format és incorrecte
		return $final_state;
	}

	foreach ($changes_to_apply as $key => $value_in_changes) {
		if ($value_in_changes === null) {
			// Si el canvi indica 'null', aquesta clau s'ha d'eliminar de l'estat final,
			// si existia.
			if (array_key_exists($key, $final_state)) {
				unset($final_state[$key]);
			}
		} elseif (is_array($value_in_changes) && array_key_exists($key, $final_state) && is_array($final_state[$key])) {
			// Recursió si ambdós són arrays i la clau existeix
			$final_state[$key] = sportic_apply_partial($final_state[$key], $value_in_changes);
		} else {
			// Si el valor no és null (i no és per recursió), l'assignem.
			// Això afegeix noves claus o sobreescriu existents.
			$final_state[$key] = $value_in_changes;
		}
	}
	return $final_state;
}
	
	// Guarda una entrada d'undo al registre (per a una opció determinada)
		function sportic_save_undo_entry($option_name, $diff) {
	global $wpdb;
	$table_undo = $wpdb->prefix . SPORTIC_UNDO_TABLE;
	$user = wp_get_current_user();
	$user_id = $user->ID;

	$json_diff = wp_json_encode($diff, JSON_UNESCAPED_UNICODE);
	if ($json_diff === false) {
		error_log("SporTIC Undo: Error wp_json_encode. No es pot desar l'entrada d'undo.");
		return false;
	}
	
	$compressed_diff = gzcompress($json_diff);
	if ($compressed_diff === false) {
		error_log("SporTIC Undo: Error gzcompress. No es pot desar l'entrada d'undo comprimida. JSON original (longitud " . strlen($json_diff) . ") no es desarà.");
		return false; 
	}
	
	// Codifiquem en Base64 les dades comprimides
	$base64_encoded_diff = base64_encode($compressed_diff);
	if ($base64_encoded_diff === false) {
		error_log("SporTIC Undo: Error base64_encode. No es pot desar l'entrada d'undo codificada. Dades comprimides (longitud " . strlen($compressed_diff) . ") no es desaran.");
		return false;
	}

	// error_log("SporTIC Undo: Mida JSON: " . strlen($json_diff) . " bytes. Comprimit: " . strlen($compressed_diff) . " bytes. Base64: " . strlen($base64_encoded_diff) . " bytes.");

	// Mantenim el control de mida màxima, ara sobre la dada codificada en Base64
	if (strlen($base64_encoded_diff) > (32 * 1024 * 1024)) { // 32 MB
		error_log("SporTIC Undo: El contingut del diff codificat en Base64 a desar (" . strlen($base64_encoded_diff) . " bytes) és massa gran (>32MB). No es desarà l'entrada d'undo.");
		return false;
	}

	$data_to_store_in_db = $base64_encoded_diff; // Això és el que va a la BD

	$data = array(
		'user_id'      => $user_id,
		'option_name'  => $option_name,
		'diff'         => $data_to_store_in_db, // Guardem la cadena Base64
		'date_recorded'=> current_time('mysql', 0)
	);
	$format = array('%d','%s','%s','%s');
	
	$wpdb->suppress_errors(true);
	$inserted = $wpdb->insert($table_undo, $data, $format);
	$wpdb->suppress_errors(false);

	if ($inserted === false) {
		error_log("SporTIC Undo: ERROR WPDB en inserir a $table_undo. Error: " . $wpdb->last_error);
		error_log("SporTIC Undo: Dades que s'intentaven inserir (longitud del diff Base64): " . strlen($data_to_store_in_db));
		return false;
	}
	
	sportic_limit_history($table_undo, $option_name, $user_id, 5);
	return true;
}
		
		// Neteja l’historial redo per a una opció i usuari determinats
		function sportic_clear_redo($option_name) {
			global $wpdb;
			$table_redo = $wpdb->prefix . SPORTIC_REDO_TABLE;
			$user = wp_get_current_user();
			$user_id = $user->ID;
			$wpdb->delete($table_redo, array('user_id' => $user_id, 'option_name' => $option_name), array('%d','%s'));
		}
		
		// Funció per limitar a $limit les entrades d’un historial (ja sigui undo o redo)
		function sportic_limit_history($table, $option_name, $user_id, $limit) {
			global $wpdb;
			$count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE user_id = %d AND option_name = %s",
				$user_id,
				$option_name
			) );
			if ($count > $limit) {
				$excess = $count - $limit;
				$ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT id FROM $table WHERE user_id = %d AND option_name = %s ORDER BY date_recorded ASC LIMIT %d",
					$user_id,
					$option_name,
					$excess
				) );
				if (!empty($ids)) {
					$ids = array_map('intval', $ids);
					$ids_list = implode(',', $ids);
					$wpdb->query("DELETE FROM $table WHERE id IN ($ids_list)");
				}
			}
		}
	
	add_action('admin_post_sportic_undo_change', 'sportic_undo_change_handler');
	function sportic_undo_change_handler() {
		// 1. Verificació de seguretat (Nonce) per prevenir CSRF
		check_admin_referer('sportic_undo_action', 'sportic_undo_nonce');
	
		// 2. Verificació de permisos d'usuari
		if ( ! current_user_can('manage_options') ) {
			wp_die('No tens permisos per desfer canvis.');
		}
	
		global $wpdb;
		// error_log("SporTIC UNDO: Iniciant sportic_undo_change_handler.");
	
		$active_tab    = ! empty($_POST['sportic_active_tab'])    ? sanitize_text_field($_POST['sportic_active_tab'])    : '';
		$active_subday = ! empty($_POST['sportic_active_subday']) ? sanitize_text_field($_POST['sportic_active_subday']) : '';
		$selected_date = ! empty($_POST['selected_date'])         ? sanitize_text_field($_POST['selected_date'])         : '';
		$cal_year      = ! empty($_POST['cal_year'])              ? intval($_POST['cal_year'])                           : 0;
		$cal_month     = ! empty($_POST['cal_month'])             ? intval($_POST['cal_month'])                          : 0;
	
		$table_undo = $wpdb->prefix . SPORTIC_UNDO_TABLE;
		$table_redo = $wpdb->prefix . SPORTIC_REDO_TABLE;
		$option_name = 'sportic_unfile_dades';
		$user_id = get_current_user_id();
	
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_undo 
				WHERE user_id = %d AND option_name = %s
				ORDER BY date_recorded DESC
				LIMIT 1",
				$user_id, $option_name
			)
		);
	
		$redirect_base_url = admin_url('admin.php?page=sportic-onefile-menu');
		$redirect_args = array();
		if ($active_tab) $redirect_args['active_tab'] = urlencode($active_tab);
		if ($active_subday) $redirect_args['active_subday'] = urlencode($active_subday);
		if ($selected_date) $redirect_args['selected_date'] = urlencode($selected_date);
		if ($cal_year && $cal_month) {
			$redirect_args['cal_year'] = $cal_year;
			$redirect_args['cal_month'] = $cal_month;
		}
	
		if ( ! $record ) {
			// error_log("SporTIC UNDO: No hi ha registres a la taula d'undo.");
			$redirect_args['error_msg'] = urlencode('No hi ha més canvis per desfer.');
			wp_redirect(add_query_arg($redirect_args, $redirect_base_url));
			exit;
		}
	
		// error_log("SporTIC UNDO: Registre d'undo trobat (ID: " . $record->id . "). Diff (longitud Base64 llegida de BD): " . strlen($record->diff));
	
		$stored_base64_diff = $record->diff;
		$compressed_diff_data = false;
		$json_diff = false;
	
		if ($stored_base64_diff) {
			$compressed_diff_data = base64_decode($stored_base64_diff, true); // true per a strict mode
			if ($compressed_diff_data === false) {
				// error_log("SporTIC UNDO: Error base64_decode. El contingut del diff de la BD podria no ser Base64 vàlid o estar corrupte. ID: " . $record->id);
				$compressed_diff_data = $stored_base64_diff; 
				// error_log("SporTIC UNDO: Assumint que el diff (ID: " . $record->id . ") no estava en Base64 i intentant descomprimir directament (longitud: " . strlen($compressed_diff_data) . ").");
			} else {
				// error_log("SporTIC UNDO: Diff Base64 descodificat correctament. Longitud comprimida: " . strlen($compressed_diff_data));
			}
		}
	
		if ($compressed_diff_data) {
			set_error_handler(function($errno, $errstr) { /* suprimir warning */ return true; });
			$uncompressed_candidate = gzuncompress($compressed_diff_data);
			restore_error_handler();
	
			if ($uncompressed_candidate !== false) {
				$json_diff = $uncompressed_candidate;
				// error_log("SporTIC UNDO: Diff descomprimit correctament. Longitud JSON: " . strlen($json_diff));
			} else {
				$json_diff = $compressed_diff_data; // Assumim que és el JSON pla.
				// error_log("SporTIC UNDO: gzuncompress ha retornat false. Assumint que el diff era JSON pla. Longitud: " . strlen($json_diff));
			}
		}
		
		if ($json_diff === false) {
			// error_log("SporTIC UNDO: El contingut del diff és invàlid o buit després de tot el processament. ID: " . $record->id);
			$wpdb->delete($table_undo, array('id' => $record->id), array('%d')); 
			$redirect_args['error_msg'] = urlencode('Error en format dades undo (diff buit/invàlid final). Entrada eliminada.');
			wp_redirect(add_query_arg($redirect_args, $redirect_base_url));
			exit;
		}
	
		$diff = json_decode($json_diff, true);
		if (!is_array($diff) || !isset($diff['old_partial'])) {
			// error_log("SporTIC UNDO: Error decodificant el JSON del diff o format incorrecte ('old_partial' no trobat). ID: " . $record->id);
			// error_log("SporTIC UNDO: JSON que es va intentar decodificar: " . substr($json_diff, 0, 500));
			$wpdb->delete($table_undo, array('id' => $record->id), array('%d')); 
			$redirect_args['error_msg'] = urlencode('Error en format dades undo (estructura JSON). Entrada eliminada.');
			wp_redirect(add_query_arg($redirect_args, $redirect_base_url));
			exit;
		}
		// error_log("SporTIC UNDO: Diff JSON decodificat correctament. 'old_partial' (primers 500 bytes): " . substr(print_r($diff['old_partial'], true), 0, 500));
	
		$current_state_after_action = sportic_carregar_tot_com_array();
		// error_log("SporTIC UNDO: Estat actual carregat (primers 500 bytes): " . substr(print_r($current_state_after_action, true), 0, 500));
		
		$state_to_restore = sportic_apply_partial($current_state_after_action, $diff['old_partial']);
		// error_log("SporTIC UNDO: Estat a restaurar calculat (primers 500 bytes): " . substr(print_r($state_to_restore, true), 0, 500));
		
		sportic_emmagatzemar_tot_com_array($state_to_restore);
		// error_log("SporTIC UNDO: Estat restaurat i emmagatzemat.");
	
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('sportic_all_data_with_locks', 'sportic_data');
		}
		if (function_exists('sportic_clear_all_week_transients')) {
			sportic_clear_all_week_transients(); 
		} else {
			error_log("SporTIC UNDO: ERROR - La funció sportic_clear_all_week_transients no existeix.");
		}
	
		$wpdb->insert(
			$table_redo,
			array(
				'user_id'      => $user_id,
				'option_name'  => $option_name,
				'diff'         => $stored_base64_diff, // Guardem la dada original de la BD (Base64)
				'date_recorded'=> current_time('mysql', 0)
			),
			array('%d','%s','%s','%s')
		);
	
		$wpdb->delete($table_undo, array('id' => $record->id), array('%d'));
		sportic_limit_history($table_redo, $option_name, $user_id, 5);
	
		$redirect_args['status'] = 'undo_ok';
		wp_redirect(add_query_arg($redirect_args, $redirect_base_url));
		exit;
	}
	
		
		
		// 3. Endpoint per REFER canvis (redo)
		if ( ! function_exists('sportic_redo_change_handler') ) {
			add_action('admin_post_sportic_redo_change', 'sportic_redo_change_handler');
			function sportic_redo_change_handler() {
				// 1. Verificació de seguretat (Nonce) per prevenir CSRF
				check_admin_referer('sportic_redo_action', 'sportic_redo_nonce');
			
				// 2. Verificació de permisos d'usuari
				if ( ! current_user_can('manage_options') ) {
					wp_die('No tens permisos per refer canvis.');
				}
			
				global $wpdb;
				// error_log("SporTIC REDO: Iniciant sportic_redo_change_handler.");
			
				$active_tab    = ! empty($_POST['sportic_active_tab'])    ? sanitize_text_field($_POST['sportic_active_tab'])    : '';
				$active_subday = ! empty($_POST['sportic_active_subday']) ? sanitize_text_field($_POST['sportic_active_subday']) : '';
				$selected_date = ! empty($_POST['selected_date'])         ? sanitize_text_field($_POST['selected_date'])         : '';
				$cal_year      = ! empty($_POST['cal_year'])              ? intval($_POST['cal_year'])                           : 0;
				$cal_month     = ! empty($_POST['cal_month'])             ? intval($_POST['cal_month'])                          : 0;
			
				$table_undo = $wpdb->prefix . SPORTIC_UNDO_TABLE;
				$table_redo = $wpdb->prefix . SPORTIC_REDO_TABLE;
				$option_name = 'sportic_unfile_dades';
				$user_id = get_current_user_id();
			
				$record = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM $table_redo
						WHERE user_id = %d AND option_name = %s
						ORDER BY date_recorded DESC
						LIMIT 1",
						$user_id, $option_name
					)
				);
			
				$redirect_base_url = admin_url('admin.php?page=sportic-onefile-menu');
				$redirect_args = array();
				if ($active_tab) $redirect_args['active_tab'] = urlencode($active_tab);
				if ($active_subday) $redirect_args['active_subday'] = urlencode($active_subday);
				if ($selected_date) $redirect_args['selected_date'] = urlencode($selected_date);
				if ($cal_year && $cal_month) {
					$redirect_args['cal_year'] = $cal_year;
					$redirect_args['cal_month'] = $cal_month;
				}
			
				if ( ! $record ) {
					// error_log("SporTIC REDO: No hi ha registres a la taula de redo.");
					$redirect_args['error_msg'] = urlencode('No hi ha més canvis per refer.');
					wp_redirect(add_query_arg($redirect_args, $redirect_base_url));
					exit;
				}
			
				// error_log("SporTIC REDO: Registre de redo trobat (ID: " . $record->id . "). Diff (longitud Base64 llegida de BD): " . strlen($record->diff));
				
				$stored_base64_diff = $record->diff;
				$compressed_diff_data = false;
				$json_diff = false;
			
				if ($stored_base64_diff) {
					$compressed_diff_data = base64_decode($stored_base64_diff, true);
					if ($compressed_diff_data === false) {
						// error_log("SporTIC REDO: Error base64_decode. ID: " . $record->id);
						$compressed_diff_data = $stored_base64_diff;
						// error_log("SporTIC REDO: Assumint que el diff (ID: " . $record->id . ") no estava en Base64 i intentant descomprimir directament (longitud: " . strlen($compressed_diff_data) . ").");
					} else {
						// error_log("SporTIC REDO: Diff Base64 descodificat. Longitud comprimida: " . strlen($compressed_diff_data));
					}
				}
			
				if ($compressed_diff_data) {
					set_error_handler(function($errno, $errstr) { /* suprimir warning */ return true; });
					$uncompressed_candidate = gzuncompress($compressed_diff_data);
					restore_error_handler();
			
					if ($uncompressed_candidate !== false) {
						$json_diff = $uncompressed_candidate;
						// error_log("SporTIC REDO: Diff descomprimit. Longitud JSON: " . strlen($json_diff));
					} else {
						$json_diff = $compressed_diff_data;
						// error_log("SporTIC REDO: gzuncompress ha retornat false. Assumint JSON pla. Longitud: " . strlen($json_diff));
					}
				}
				
				if ($json_diff === false) {
					// error_log("SporTIC REDO: El contingut del diff és invàlid o buit després de tot el processament. ID: " . $record->id);
					$wpdb->delete($table_redo, array('id' => $record->id), array('%d'));
					$redirect_args['error_msg'] = urlencode('Error en format dades redo (diff buit/invàlid final). Entrada eliminada.');
					wp_redirect(add_query_arg($redirect_args, $redirect_base_url));
					exit;
				}
			
				$diff = json_decode($json_diff, true);
				if (!is_array($diff) || !isset($diff['new_partial'])) { 
					// error_log("SporTIC REDO: Error decodificant JSON del diff o format incorrecte ('new_partial' no trobat). ID: " . $record->id);
					// error_log("SporTIC REDO: JSON que es va intentar decodificar: " . substr($json_diff, 0, 500));
					$wpdb->delete($table_redo, array('id' => $record->id), array('%d'));
					$redirect_args['error_msg'] = urlencode('Error en format dades redo (estructura JSON). Entrada eliminada.');
					wp_redirect(add_query_arg($redirect_args, $redirect_base_url));
					exit;
				}
				// error_log("SporTIC REDO: Diff JSON decodificat. 'new_partial' (primers 500 bytes): " . substr(print_r($diff['new_partial'], true), 0, 500));
			
				$current_state_after_undo = sportic_carregar_tot_com_array();
				// error_log("SporTIC REDO: Estat actual carregat (primers 500 bytes): " . substr(print_r($current_state_after_undo, true), 0, 500));
			
				$state_to_restore = sportic_apply_partial($current_state_after_undo, $diff['new_partial']);
				// error_log("SporTIC REDO: Estat a restaurar calculat (primers 500 bytes): " . substr(print_r($state_to_restore, true), 0, 500));
			
				sportic_emmagatzemar_tot_com_array($state_to_restore);
				// error_log("SporTIC REDO: Estat restaurat i emmagatzemat.");
			
				if (function_exists('wp_cache_delete')) {
					wp_cache_delete('sportic_all_data_with_locks', 'sportic_data');
				}
				if (function_exists('sportic_clear_all_week_transients')) {
					sportic_clear_all_week_transients();
				} else {
					error_log("SporTIC REDO: ERROR - La funció sportic_clear_all_week_transients no existeix.");
				}
				
				$wpdb->insert(
					$table_undo, 
					array(
						'user_id'      => $user_id,
						'option_name'  => $option_name,
						'diff'         => $stored_base64_diff, 
						'date_recorded'=> current_time('mysql', 0)
					),
					array('%d','%s','%s','%s')
				);
			
				$wpdb->delete($table_redo, array('id' => $record->id), array('%d'));
				sportic_limit_history($table_undo, $option_name, $user_id, 5);
			
				$redirect_args['status'] = 'redo_ok';
				wp_redirect(add_query_arg($redirect_args, $redirect_base_url));
				exit;
			}	
	
	
	/******************************************************************
	* SHORTCODE 1: [sportic_dina3_display show_form="1"]
	* Mostra la presentació DIN A3 amb 7 dies disposats en graella:
	* - Fila 1: dies 0, 1, 2.
	* - Fila 2: dies 3, 4, 5.
	* - Fila 3: dia 6, Llegenda i Logo.
	* L'atribut "show_form" pot ser "0" per ocultar el formulari i mostrar
	* el disseny amb dimensions adaptades al contingut.
	******************************************************************/
	function sportic_dina3_display_shortcode($atts) {
		$atts = shortcode_atts(
			array(
				'show_form' => '1',
			),
			$atts,
			'sportic_dina3_display'
		);
	
		// 1) DATA SELECCIONADA (iniciant per avui)
		$data_seleccionada = isset($_GET['sc_date']) ? sanitize_text_field($_GET['sc_date']) : current_time('Y-m-d');
	
		// 2) DADES I PISCINES + LLEGENDA
		$dades = get_option('sportic_unfile_dades', array());
		$etiquetesPiscinesOrdenades = sportic_unfile_get_pool_labels_sorted();
	
		// Inicialitzem la llegenda segons la lògica:
		// - Si la lletra és 'l' => '#b3d9ff' (lliure)
		// - Si la lletra és 'b' => '#ffffff' (blanc)
		// - En qualsevol altre cas => '#ff0000' (ocupat)
		$mapaLlegenda = array(
			'l'       => array('color' => '#b3d9ff', 'title' => 'Lliure (l)'),
			'b'       => array('color' => '#b9b9b9', 'title' => 'Tancat (b)'),
			'default' => array('color' => '#ff0000', 'title' => 'Ocupat'),
		);
		$lletresPersonalitzades = get_option('sportic_unfile_custom_letters', array());
		if (!empty($lletresPersonalitzades)) {
			foreach ($lletresPersonalitzades as $info) {
				$lletra = strtolower($info['letter']);
				// Si és 'l' o 'b', mantenim la nostra lògica per forçar els colors
				if ($lletra === 'l' || $lletra === 'b') continue;
				$color  = isset($info['color']) ? $info['color'] : '#ff0000';
				$titol  = isset($info['title']) ? $info['title'] : '';
				if (trim($titol) === '') {
					$titol = 'Personalitzada (' . $lletra . ')';
				} else {
					$titol .= ' (' . $lletra . ')';
				}
				$mapaLlegenda[$lletra] = array('color' => $color, 'title' => $titol);
			}
		}
	
		// 3) FUNCIÓ PER CONSTRUIR EL HTML DEL DIA (VERSIÓ PETITA)
		if (!function_exists('_sportic_construir_html_dia_petit')) {
			function _sportic_construir_html_dia_petit($dia, $dades, $piscines, $mapaLlegenda) {
		ob_start();
		$dataBonica = (new DateTime($dia))->format('d-m-Y');
		?>
		<div class="sportic-day-container" style="text-align:center; margin-bottom:30px;" data-day="<?php echo esc_attr($dia); ?>">
			<h3 style="margin-bottom:15px; font-size:1rem;">
				Dia: <?php echo esc_html($dataBonica); ?>
			</h3>
			<div style="display:flex; align-items:flex-start; justify-content:center; white-space:nowrap;">
				<div style="flex:0; margin-right:30px;">
					<?php
					foreach ($piscines as $slug => $pinfo) {
						$programacio = $dades[$slug][$dia] ?? sportic_unfile_crear_programacio_default( $slug );
						$hores = array_keys($programacio);
						sort($hores);
						if (empty($hores)) continue;
						$numCarrils = count($programacio[$hores[0]]);

						echo '<div style="display:inline-block; margin:10px; padding:10px; vertical-align:top;">';
						echo '<h4 style="margin:0 0 10px; text-align:center; font-size:0.9rem;">' . esc_html($pinfo['label']) . '</h4>';
						echo '<table style="border-collapse:collapse; margin:0 auto; font-size:0.8rem;">';
						echo '<thead><tr style="background:#eee;"><th style="border:1px solid #999; padding:3px;">Hora</th>';
						for ($c = 1; $c <= $numCarrils; $c++) {
							echo '<th style="border:1px solid #999; padding:3px;">&nbsp;' . $c . '&nbsp;</th>';
						}
						echo '</tr></thead><tbody>';
						foreach ($hores as $h) {
							if ( ! sportic_unfile_is_time_in_open_range($h) ) continue;
							$fila = $programacio[$h];
							echo '<tr><td style="border:1px solid #999; padding:3px;">' . esc_html($h) . '</td>';
							foreach ($fila as $val) {
								// <-- INICI MODIFICACIÓ -->
								$valorBase = $val;
								if (is_string($val) && (strpos($val, '@') === 0 || strpos($val, '!') === 0)) {
									$valorBase = substr($val, 1);
								}
								$lletraPrincipal = strtolower(trim($valorBase));
								$lletraPrincipal = strpos($lletraPrincipal, ':') !== false ? explode(':', $lletraPrincipal, 2)[0] : $lletraPrincipal;
								$fons = $mapaLlegenda[$lletraPrincipal]['color'] ?? $mapaLlegenda['l']['color'];
								echo '<td style="border:1px solid #999; padding:3px; background-color:' . esc_attr($fons) . ';"></td>';
								// <-- FI MODIFICACIÓ -->
							}
							echo '</tr>';
						}
						echo '</tbody></table></div>';
					}
					?>
				</div>
				<div class="sportic-legend" style="flex:0; display:inline-block; vertical-align:top; text-align:left;">
					<h4 style="margin:0 0 10px; font-size:0.9rem;">Llegenda</h4>
					<?php foreach ($mapaLlegenda as $lletra => $info) : ?>
						<div style="margin-bottom:4px;">
							<span style="display:inline-block;width:20px;height:20px;background:<?php echo esc_attr($info['color']); ?>; margin-right:8px; vertical-align:middle;<?php echo ($lletra==='l' ? ' border:1px solid #000;' : ''); ?>"></span>
							<?php echo esc_html($info['title']); ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
	
		// 4) GENEREM 7 DIES (a partir d'avui)
		$html7Petit = array();
		$tempObj = new DateTime($data_seleccionada);
		for ($i = 0; $i < 7; $i++) {
			$diaStr = $tempObj->format('Y-m-d');
			$html7Petit[] = _sportic_construir_html_dia_petit($diaStr, $dades, $etiquetesPiscinesOrdenades, $mapaLlegenda);
			$tempObj->modify('+1 day');
		}
	
		// 5) SORTIDA FINAL: PRESENTACIÓ DIN A3
		// Si "show_form" és "1": contenidor responsiu amb marge intern i extern.
		// Si és "0": contenidor adaptat al contingut, amb marges constants.
		if ($atts['show_form'] === '1') {
			$containerStyle = "display: grid; grid-template-columns: repeat(3, 1fr); gap:80px; grid-auto-rows:min-content; align-items:start; max-width:700px; margin:40px auto; background:#fff; padding:40px; box-sizing:border-box; font-family:Arial, sans-serif;";
		} else {
			$containerStyle = "display: grid; grid-template-columns: repeat(3, 1fr); gap:80px; grid-auto-rows:min-content; align-items:start; background:#fff; padding:40px; margin:40px; box-sizing:border-box; font-family:Arial, sans-serif;";
		}
		
		$cellLegendStyle = ($atts['show_form'] === '1') ? "padding:0;" : "";
		$cellLogoStyle   = "display:flex; align-items:center; justify-content:center;";
	
		ob_start();
		?>
		<!-- Formulari només si show_form == "1" -->
		<?php if ($atts['show_form'] === '1') : ?>
		<div style="max-width:400px; margin:0 auto 5px; text-align:center; font-family:Arial, sans-serif;">
			<form method="get" style="display:inline-flex; align-items:center; gap:5px;">
				<label for="sc_date" style="font-weight:bold;">Data:</label>
				<input type="date" id="sc_date" name="sc_date" value="<?php echo esc_attr($data_seleccionada); ?>" style="padding:4px 6px;" />
				<input type="submit" value="Mostrar" style="padding:4px 8px; cursor:pointer;" />
			</form>
		</div>
		<?php endif; ?>
	
		<!-- Contenidor de la graella DIN A3 -->
		<div id="sportic_dina3_layout" style="<?php echo $containerStyle; ?>">
			<!-- Fila 1 -->
			<div class="dina3-cell" style="margin:0; padding:0;"><?php echo $html7Petit[0]; ?></div>
			<div class="dina3-cell" style="margin:0; padding:0;"><?php echo $html7Petit[1]; ?></div>
			<div class="dina3-cell" style="margin:0; padding:0;"><?php echo $html7Petit[2]; ?></div>
			<!-- Fila 2 -->
			<div class="dina3-cell" style="margin:0; padding:0;"><?php echo $html7Petit[3]; ?></div>
			<div class="dina3-cell" style="margin:0; padding:0;"><?php echo $html7Petit[4]; ?></div>
			<div class="dina3-cell" style="margin:0; padding:0;"><?php echo $html7Petit[5]; ?></div>
			<!-- Fila 3 -->
			<div class="dina3-cell" style="margin:0; padding:0;"><?php echo $html7Petit[6]; ?></div>
			<div class="dina3-cell" style="margin:0; padding:0; <?php echo $cellLegendStyle; ?>">
				<h4 style="margin:0 0 2px;">Llegenda</h4>
				<?php 
				// Mostrem la llegenda per 'l', 'b' i el valor per defecte (ocupat)
				foreach (array('l', 'b', 'default') as $key) : 
					$info = isset($mapaLlegenda[$key]) ? $mapaLlegenda[$key] : array('color' => '#ff0000', 'title' => 'Ocupat');
				?>
					<div style="margin-bottom:2px;">
						<span style="display:inline-block; width:20px; height:20px; background:<?php echo esc_attr($info['color']); ?>; margin-right:2px; vertical-align:middle;"></span>
						<?php echo esc_html($info['title']); ?>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="dina3-cell" style="margin:0; padding:0; <?php echo $cellLogoStyle; ?>">
				<img src="<?php echo plugin_dir_url(__FILE__); ?>imatges/logo2.jpg" alt="Logo" style="width:1000px; height:auto; display:block; margin-right: 100px; margin-top: 50px; margin-left: -700px" />
				<img src="<?php echo plugin_dir_url(__FILE__); ?>imatges/logo3.jpg" alt="Logo" style="width:300px; height:auto; display:block; margin-right: -0px; margin-top: 700px; margin-left: 200px" />
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
	add_shortcode('sportic_dina3_display', 'sportic_dina3_display_shortcode');
	
	/******************************************************************
	* SHORTCODE 2: [sportic_dina3_capture_hidden]
	* Aquest shortcode mostra un botó per capturar i descarregar la presentació DIN A3.
	* La presentació (sense formulari) es carrega dins d'un contenidor ocult adaptat al contingut,
	* mantenint un marge constant.
	******************************************************************/
	function sportic_dina3_capture_hidden_shortcode($atts) {
		ob_start();
		?>
		<div style="text-align:center; margin:20px 0;">
			<button id="sportic_dina3_capture_btn" style="padding:8px 16px; border:none; background:#0073aa; color:#fff; border-radius:4px; cursor:pointer;">
				DESCARREGAR PLANIFICACIÓ PRÒXIMS 7 DIES
			</button>
		</div>
		<div id="sportic_dina3_hidden_display" style="
			position: absolute;
			left: -9999px;
			top: -9999px;
			overflow: hidden;
			transform: scale(1);
			transform-origin: top left;
		">
			<?php echo do_shortcode('[sportic_dina3_display show_form="0"]'); ?>
		</div>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
		<script>
			(function(){
				// Obtenim el botó i el contenidor ocult
				var btn = document.getElementById('sportic_dina3_capture_btn');
				var hiddenDiv = document.getElementById('sportic_dina3_hidden_display');
				if (!btn || !hiddenDiv) return;
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					html2canvas(hiddenDiv, { scale: 1 }).then(function(canvas) {
						console.log("Dimensions del canvas: " + canvas.width + "x" + canvas.height);
						var dataURL = canvas.toDataURL('image/png', 1.0);
						var link = document.createElement('a');
						link.download = 'Setmana_DINA3.png';
						link.href = dataURL;
						link.click();
					}).catch(function(err) {
						console.error('Error en html2canvas:', err);
						alert('Error en la captura.');
					});
				});
			})();
		</script>
		<?php
		return ob_get_clean();
	}
	add_shortcode('sportic_dina3_capture_hidden', 'sportic_dina3_capture_hidden_shortcode');
	
	
	/**
	* Funció per mostrar la llegenda de les lletres personalitzades.
	* Mostra per cada lletra el seu color, el nom assignat i, si n'hi ha, els sub‐items.
	* La caixa té una alçada fixa de 500px (sense créixer) i, si el contingut és més llarg, apareix el scroll.
	*/
function sportic_unfile_mostrar_llegenda() {
		// Obtenim les activitats amb la nova estructura ('description' i 'color')
		$customActivities = get_option('sportic_unfile_custom_letters', array());
		
		// Funció auxiliar per al contrast del text
		function calcular_contrast($hexcolor) {
			if(empty($hexcolor) || strlen($hexcolor) < 7) return '#000000';
			$r = hexdec(substr($hexcolor, 1, 2));
			$g = hexdec(substr($hexcolor, 3, 2));
			$b = hexdec(substr($hexcolor, 5, 2));
			$luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
			return $luminance > 0.5 ? '#0a0a0a' : '#ffffff';
		}
	
		ob_start();
		?>
		<div class="sportic-legend-core" style="
			font-family: 'Inter', system-ui, sans-serif;
			height: 380px;
			overflow: hidden;
			display: flex;
			flex-direction: column;
		">
			<div style="
				flex-grow: 1;
				overflow-y: auto;
				padding: 0rem 1rem 1rem 1rem;
				scrollbar-width: thin;
				scrollbar-color: #c1c1c1 transparent;
			">
				<?php if (!empty($customActivities) && is_array($customActivities)) : ?>
					<div style="display: grid; gap: 0.8rem; padding-right: 0.4rem;">
						<?php foreach ($customActivities as $activityInfo) : 
							$description = $activityInfo['description'] ?? '';
							$color = $activityInfo['color'] ?? '#e0e0e0';
							if (empty($description)) continue; // Saltem si no hi ha descripció
							$text_color = calcular_contrast($color);
						?>
							<div style="
								background: white;
								border-radius: 0.6rem;
								padding: 1rem;
								box-shadow: 0 2px 8px rgba(0,0,0,0.04);
								border-left: 5px solid <?= esc_attr($color) ?>;
								display: flex;
								align-items: center;
								gap: 1rem;
							">
								<span style="
									display: block;
									width: 20px;
									height: 20px;
									background-color: <?= esc_attr($color) ?>;
									border-radius: 4px;
									flex-shrink: 0;
									border: 1px solid rgba(0,0,0,0.1);
								"></span>
								<p style="
									margin: 0;
									font-size: 0.9rem;
									font-weight: 500;
									color: #151515;
									line-height: 1.4;
								">
									<?= esc_html($description) ?>
								</p>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div style="
						height: 100%;
						display: flex;
						align-items: center;
						justify-content: center;
						padding: 2rem;
						color: #666;
						font-size: 0.95rem;
						text-align: center;
					">
						<div>
							<svg style="width: 2rem; height: 2rem; opacity: 0.6; margin-bottom: 0.5rem;" viewBox="0 0 24 24" fill="none" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
							</svg>
							<p style="margin: 0;">No hi ha activitats configurades.</p>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}	
	add_filter('admin_footer_text', 'sportic_tactic_custom_footer_text');
	function sportic_tactic_custom_footer_text() {
		return 'Creat per <a href="https://tactic.cat/" target="_blank">Tàctic.cat</a>';
	}
	
	// Filtra el peu dret de l'administració (que mostra la versió de WordPress)
	// i l'elimina retornant un text buit.
	add_filter('update_footer', 'sportic_tactic_custom_update_footer', 9999);
	function sportic_tactic_custom_update_footer($text) {
		return '';
	}
	
	
	
	// Afegiu aquest codi dins del vostre plugin, preferiblement en un fitxer separat i inclòs des del vostre plugin principal.
	
	add_action('rest_api_init', function(){
		register_rest_route('sportic/v1', '/csv', array(
			'methods'  => 'GET',
			'callback' => 'sportic_rest_get_csv',
			'args' => array(
				'piscina' => array(
					'required' => true,
				),
				'dia' => array(
					'required' => true,
					'validate_callback' => function($param, $request, $key) {
						return preg_match('/^\d{4}-\d{2}-\d{2}$/', $param);
					}
				)
			),
			// Per a la prova, obrim l'accés amb __return_true; en producció hauries de restringir-ho.
			'permission_callback' => '__return_true',
		));
	});
	
	if ( ! function_exists('sportic_rest_get_csv') ) {
		if ( ! function_exists('sportic_rest_get_csv') ) {
function sportic_rest_get_csv($request) {
			$piscina_slug_req = $request->get_param('piscina');
			$dia_req          = $request->get_param('dia');
		
			// *** VALIDACIÓ PISCINA ***
			$configured_pools = sportic_unfile_get_pool_labels_sorted();
			$piscines_valides_slugs = array_keys($configured_pools);
		
			if (!in_array($piscina_slug_req, $piscines_valides_slugs)) {
				return new WP_Error('rest_invalid_param', "Pavelló no vàlid o no configurat: '{$piscina_slug_req}'.", array('status' => 400));
			}
			// La validació del format del dia ja la fa 'validate_callback' a register_rest_route
		
			// =========================================================================
			// INICI DE LA CORRECCIÓ DEFINITIVA
			// =========================================================================
			// En lloc de reconstruir les dades aquí, cridem la funció que ja hem corregit
			// prèviament (`sportic_carregar_dades_per_dia_i_piscina`). Aquesta funció
			// carrega la programació base, aplica els recurrents i, crucialment,
			// respecta la taula d'excepcions. Això garanteix que les dades que
			// s'envien a través de l'API són exactament les mateixes que es veuen a la graella.
			
			$dades_del_dia = sportic_carregar_dades_per_dia_i_piscina($piscina_slug_req, $dia_req);
			
			// =========================================================================
			// FI DE LA CORRECCIÓ DEFINITIVA
			// =========================================================================
		
			// A partir d'aquí, la resta de la funció per formatar el CSV ja és correcta,
			// ja que treballa sobre les dades ja filtrades i corregides.
			$num_carrils = isset($configured_pools[$piscina_slug_req]['lanes']) ? intval($configured_pools[$piscina_slug_req]['lanes']) : 0;
			 if ($num_carrils === 0 && !empty($dades_del_dia)) { 
				reset($dades_del_dia);
				$primeraHora = key($dades_del_dia);
				if ($primeraHora !== null && isset($dades_del_dia[$primeraHora]) && is_array($dades_del_dia[$primeraHora])) {
					$num_carrils = count($dades_del_dia[$primeraHora]);
				}
			}
			if ($num_carrils === 0) {
				return new WP_Error('schedule_error', "No s'ha pogut determinar el nombre de carrils per al pavelló '{$piscina_slug_req}'.", array('status' => 500));
			}
		
			$csvData = array();
			$header = array('Hora', $piscina_slug_req);
			for ($i = 1; $i <= $num_carrils; $i++) {
				$header[] = 'Carril_' . $i;
			}
			$csvData[] = $header;
		
			// Ordenem les hores per assegurar una sortida consistent
			uksort($dades_del_dia, 'strnatcmp');
		
			foreach ($dades_del_dia as $hora => $arrCarrils) {
				if (!sportic_unfile_is_time_in_open_range($hora)) {
					continue;
				}
				$filaTemp = array($hora, $piscina_slug_req);
		
				if (!is_array($arrCarrils)) { $arrCarrils = array_fill(0, $num_carrils, 'l'); }
				if (count($arrCarrils) < $num_carrils) { $arrCarrils = array_pad($arrCarrils, $num_carrils, 'l'); }
				elseif (count($arrCarrils) > $num_carrils) { $arrCarrils = array_slice($arrCarrils, 0, $num_carrils); }
		
				foreach ($arrCarrils as $valRaw) {
					$valorReal = preg_replace('/^[@!]/', '', $valRaw);
					if ($valorReal === false || $valorReal === '') $valorReal = 'l';
					if (!is_string($valorReal)) $valorReal = 'l';
					
					$val = strtolower($valorReal);
					if ($val === 'l') {
						$filaTemp[] = 'l';
					} elseif ($val === 'b') {
						$filaTemp[] = 'b';
					} else {
						$filaTemp[] = 'o';
					}
				}
				$csvData[] = $filaTemp;
			}
		
			$csvOutput = '';
			$outputBuffer = fopen('php://temp', 'r+');
			if ($outputBuffer === false) {
				return new WP_Error('csv_generation_error', "No s'ha pogut obrir el buffer per generar el CSV.", array('status' => 500));
			}
			foreach ($csvData as $lineArray) {
				if (is_array($lineArray)) {
					fputcsv($outputBuffer, $lineArray, ',');
				}
			}
			rewind($outputBuffer);
			$csvOutput = stream_get_contents($outputBuffer);
			fclose($outputBuffer);
		
			$response = new WP_REST_Response($csvOutput, 200);
			// Important: Retornem com a text pla, que és el que un sistema extern probablement espera per a un CSV.
			$response->header('Content-Type', 'text/plain; charset=UTF-8');
			return $response;
		}	
			}
	
	
	
	
	
	
	
	
	
	
	if ( ! function_exists('sportic_unfile_get_pool_labels_sorted') ) {
		function sportic_unfile_get_pool_labels_sorted() {
			$defaults = array(
				'infantil' => array('label' => 'Pavelló Infantil',     'order' => 4),
				'p4'       => array('label' => 'Pavelló 4 carrils',     'order' => 3),
				'p6'       => array('label' => 'Pavelló 6 carrils',     'order' => 2),
				'p12_20'   => array('label' => 'Pavelló 12/20 carrils', 'order' => 1),
			);
			$saved = get_option('sportic_unfile_pool_labels', array());
			if ( ! is_array($saved) ) $saved = array();
			foreach ($defaults as $slug => $def) {
				if ( ! isset($saved[$slug]) ) $saved[$slug] = $def;
				else {
					if ( ! is_array($saved[$slug]) )       $saved[$slug] = array();
					if ( ! isset($saved[$slug]['label']) ) $saved[$slug]['label'] = $def['label'];
					if ( ! isset($saved[$slug]['order']) ) $saved[$slug]['order'] = $def['order'];
				}
			}
			uasort($saved, fn($a,$b) => intval($a['order']) - intval($b['order']));
			return $saved;
		}
	}
	
	/* ---------------------------------------------------------------------------
	 * CREAR PROGRAMACIÓ PER DEFECTE
	 * ------------------------------------------------------------------------ */
	if ( ! function_exists('sportic_unfile_crear_programacio_default') ) {
		function sportic_unfile_crear_programacio_default($p) {
			$c = get_option('sportic_unfile_opening_hours', array('start' => '06:00', 'end' => '23:30'));
			if ( ! is_array($c) || ! isset($c['start']) || ! isset($c['end']) )
				$c = array('start' => '06:00', 'end' => '23:30');
	
			try { $cur = new DateTime($c['start']); $end = new DateTime($c['end']);
				if ($cur > $end) { $cur = new DateTime('06:00'); $end = new DateTime('23:30'); } }
			catch (Exception $e) { $cur = new DateTime('06:00'); $end = new DateTime('23:30'); }
	
			$base = [];
			while ($cur <= $end) { 
				$base[] = $cur->format('H:i'); 
				$cur->modify('+15 minutes'); // <-- CANVI A 15 MINUTS
			}
	
			$out = [];
			foreach ($base as $h) {
				switch ($p) {
					case 'infantil': $out[$h] = array_fill(0,  1, 'l'); break;
					case 'p4':       $out[$h] = array_fill(0,  4, 'l'); break;
					case 'p6':       $out[$h] = array_fill(0,  6, 'l'); break;
					case 'p12_20':   $out[$h] = array_fill(0, 20, 'l'); break;
					default:         $out[$h] = array_fill(0,  4, 'l');
				}
			}
			return $out;
		}
	}
	
	/* ---------------------------------------------------------------------------
	 * COMPROVACIÓ D’HORARI OBERT
	 * ------------------------------------------------------------------------ */
	if ( ! function_exists('sportic_unfile_is_time_in_open_range') ) {
		function sportic_unfile_is_time_in_open_range($h) {
			$c = get_option('sportic_unfile_opening_hours', array('start' => '06:00', 'end' => '23:30'));
			if ( ! preg_match('/^\d{2}:\d{2}$/', $h) ||
				 ! preg_match('/^\d{2}:\d{2}$/', $c['start'] ?? '') ||
				 ! preg_match('/^\d{2}:\d{2}$/', $c['end']   ?? '') )
				return true;
	
			[$sh,$sm] = explode(':', $c['start']);
			[$eh,$em] = explode(':', $c['end']);
			[$ch,$cm] = explode(':', $h);
			return (60*$ch+$cm) >= (60*$sh+$sm) && (60*$ch+$cm) <= (60*$eh+$em);
		}
	}
	
	/* ---------------------------------------------------------------------------
	 * AMPLADA MÍNIMA DE LA TAULA (cel·les 120 px)
	 * ------------------------------------------------------------------------ */
	if ( ! function_exists('_sportic_calcular_width_px') ) {
		function _sportic_calcular_width_px($n) { return 110 + 120*intval($n); }
	}
	
	/* ---------------------------------------------------------------------------
	 * HTML DIA (PANTALLA) – 2 files
	 * ------------------------------------------------------------------------ */
	if ( ! function_exists('_sportic_preprocess_data_for_merging') ) {
		function _sportic_preprocess_data_for_merging($schedule_data, $hores_ordenades, $cols_count) {
			if (empty($schedule_data) || empty($hores_ordenades) || $cols_count == 0) {
				return ['data' => [], 'processed' => []];
			}
	
			$processed = [];
			$data_with_spans = [];
			$hores_array = array_values($hores_ordenades);
			$rows_count = count($hores_array);
	
			for ($r = 0; $r < $rows_count; $r++) {
				$hora = $hores_array[$r];
				for ($c = 0; $c < $cols_count; $c++) {
					$processed[$r][$c] = false;
					$data_with_spans[$r][$c] = $schedule_data[$hora][$c] ?? 'l';
				}
			}
			
			for ($r = 0; $r < $rows_count; $r++) {
				for ($c = 0; $c < $cols_count; $c++) {
					if ($processed[$r][$c]) {
						continue;
					}
	
					$current_val = $data_with_spans[$r][$c];
					// No unim cel·les buides ('l') o tancades ('b')
					$base_val = is_string($current_val) ? preg_replace('/^[@!]/', '', $current_val) : $current_val;
					if (empty($base_val) || $base_val === 'l' || $base_val === 'b') {
						$processed[$r][$c] = true;
						$data_with_spans[$r][$c] = ['value' => $current_val, 'rowspan' => 1, 'colspan' => 1];
						continue;
					}
					
					// Calcula Colspan
					$colspan = 1;
					for ($c2 = $c + 1; $c2 < $cols_count; $c2++) {
						if (!$processed[$r][$c2] && ($data_with_spans[$r][$c2] ?? 'l') === $current_val) {
							$colspan++;
						} else {
							break;
						}
					}
	
					// Calcula Rowspan
					$rowspan = 1;
					for ($r2 = $r + 1; $r2 < $rows_count; $r2++) {
						$is_row_match = true;
						for ($c2 = $c; $c2 < $c + $colspan; $c2++) {
							if ($processed[$r2][$c2] || ($data_with_spans[$r2][$c2] ?? 'l') !== $current_val) {
								$is_row_match = false;
								break;
							}
						}
						if ($is_row_match) {
							$rowspan++;
						} else {
							break;
						}
					}
					
					// Marca les cel·les com a processades
					for ($r2 = $r; $r2 < $r + $rowspan; $r2++) {
						for ($c2 = $c; $c2 < $c + $colspan; $c2++) {
							$processed[$r2][$c2] = true;
						}
					}
					
					$data_with_spans[$r][$c] = ['value' => $current_val, 'rowspan' => $rowspan, 'colspan' => $colspan];
				}
			}
			return ['data' => $data_with_spans, 'processed_map' => $processed];
		}
	}
	
	/**
	 * NOU: Funció auxiliar que processa les dades per calcular el rowspan i colspan.
	 */
	if ( ! function_exists('_sportic_preprocess_data_for_merging') ) {
		function _sportic_preprocess_data_for_merging($schedule_data, $hores_ordenades, $cols_count) {
			if (empty($schedule_data) || empty($hores_ordenades) || $cols_count == 0) {
				return ['data' => [], 'processed' => []];
			}
	
			$processed = [];
			$data_with_spans = [];
			$hores_array = array_values($hores_ordenades);
			$rows_count = count($hores_array);
	
			for ($r = 0; $r < $rows_count; $r++) {
				$hora = $hores_array[$r];
				for ($c = 0; $c < $cols_count; $c++) {
					$processed[$r][$c] = false;
					$data_with_spans[$r][$c] = $schedule_data[$hora][$c] ?? 'l';
				}
			}
			
			for ($r = 0; $r < $rows_count; $r++) {
				for ($c = 0; $c < $cols_count; $c++) {
					if ($processed[$r][$c]) {
						continue;
					}
	
					$current_val = $data_with_spans[$r][$c];
					$base_val = is_string($current_val) ? preg_replace('/^[@!]/', '', $current_val) : $current_val;
					if (empty($base_val) || $base_val === 'l' || $base_val === 'b') {
						$processed[$r][$c] = true;
						$data_with_spans[$r][$c] = ['value' => $current_val, 'rowspan' => 1, 'colspan' => 1];
						continue;
					}
					
					$colspan = 1;
					for ($c2 = $c + 1; $c2 < $cols_count; $c2++) {
						if (!$processed[$r][$c2] && ($data_with_spans[$r][$c2] ?? 'l') === $current_val) {
							$colspan++;
						} else {
							break;
						}
					}
	
					$rowspan = 1;
					for ($r2 = $r + 1; $r2 < $rows_count; $r2++) {
						$is_row_match = true;
						for ($c2 = $c; $c2 < $c + $colspan; $c2++) {
							if ( !isset($processed[$r2][$c2]) || $processed[$r2][$c2] || !isset($data_with_spans[$r2][$c2]) || $data_with_spans[$r2][$c2] !== $current_val) {
								$is_row_match = false;
								break;
							}
						}
						if ($is_row_match) {
							$rowspan++;
						} else {
							break;
						}
					}
					
					for ($r2 = $r; $r2 < $r + $rowspan; $r2++) {
						for ($c2 = $c; $c2 < $c + $colspan; $c2++) {
							 if (isset($processed[$r2][$c2])) {
								$processed[$r2][$c2] = true;
							}
						}
					}
					
					$data_with_spans[$r][$c] = ['value' => $current_val, 'rowspan' => $rowspan, 'colspan' => $colspan];
				}
			}
			return ['data' => $data_with_spans];
		}
	}
	
	/**
	 * MODIFICADA: Restaura l'estructura de 2 files per a la VISTA EN PANTALLA.
	 */
	if ( ! function_exists('_sportic_day_html_visual') ) {
		function _sportic_day_html_visual($dia, $dades, $pisc, $leg) {
			if (!function_exists('_sportic_contrast_color_helper')) {
				function _sportic_contrast_color_helper($hexcolor){
					if (empty($hexcolor) || strlen($hexcolor) < 7) return '#000000';
					$r = hexdec(substr($hexcolor, 1, 2));
					$g = hexdec(substr($hexcolor, 3, 2));
					$b = hexdec(substr($hexcolor, 5, 2));
					$luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
					return $luminance > 0.5 ? '#000000' : '#ffffff';
				}
			}
	
			if (!function_exists('_sportic_normalize_key')) {
				function _sportic_normalize_key($txt) {
					$txt = strtolower(trim((string)$txt));
					if (function_exists('iconv')) {
						$converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $txt);
						if ($converted !== false) {
							$txt = $converted;
						}
					}
					$txt = preg_replace('/[^a-z0-9]+/i', ' ', $txt);
					$txt = preg_replace('/\s+/', ' ', $txt);
					return trim($txt);
				}
			}
	
			$dies = ['Diumenge','Dilluns','Dimarts','Dimecres','Dijous','Divendres','Dissabte'];
			try { $o = new DateTime($dia); $nice = $o->format('d-m-Y'); $nom = $dies[(int)$o->format('w')]; }
			catch (Exception $e) { $nice = 'Data invàlida'; $nom = ''; }
	
			$pavellons_a_mostrar = array_keys($pisc);
			ob_start(); ?>
			<div class="sw_day" data-day="<?php echo esc_attr($dia); ?>">
			  <h3 style="margin-bottom:15px;font-size:1.25rem;font-weight:bold;text-align:center;border-bottom:1px solid #eee;">
				<?php echo esc_html(($nom ? $nom.', ' : '').$nice); ?>
			  </h3>
			  <div class="sw_row" style="display:flex;justify-content:center;gap:26px;flex-wrap:nowrap;">
				<?php foreach ($pavellons_a_mostrar as $s):
				  if ( ! isset($pisc[$s]) ) continue;
				  
				  $lab = $pisc[$s]['label'] ?? ucfirst($s);
				  $pr  = (isset($dades[$s][$dia]) && is_array($dades[$s][$dia]))
							? $dades[$s][$dia] : sportic_unfile_crear_programacio_default($s);
				  if ( ! $pr ) continue;
				  
				  $hores = array_keys($pr); sort($hores);
				  $hores_filtrades = array_values(array_filter($hores, 'sportic_unfile_is_time_in_open_range'));
				  if (empty($hores_filtrades)) continue;
	
				  $cols = isset($pisc[$s]['lanes']) ? intval($pisc[$s]['lanes']) : 0;
				  if ($cols === 0) continue;
				  
				  $lane_labels = $pisc[$s]['lane_labels'] ?? [];
				  if (empty($lane_labels)) { for ($i = 1; $i <= $cols; $i++) { $lane_labels[] = 'Pista ' . $i; } }
				  
				  $preprocessed = _sportic_preprocess_data_for_merging($pr, $hores_filtrades, $cols);
				  $data_amb_spans = $preprocessed['data'];
				  ?>
				  <div class="sw_pavilion_block" style="display:inline-block;padding:10px 0;vertical-align:top;">
					<h4 style="margin:0 0 8px;text-align:center;font-size:0.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html($lab); ?></h4>
					  <!-- NOU: Classe dinàmica afegida a la taula -->
						<table class="sportic-schedule-table table-cols-<?php echo $cols; ?>" style="border-collapse:collapse;margin:0 auto;font-size:0.95rem;">
						  <thead>
							<tr style="background:#eee;">
							  <th style="border:1px solid #999;padding:5px;">Hora</th>
							  <?php foreach ($lane_labels as $lane_label): ?>
								<th style="border:1px solid #999;padding:5px;"><?php echo esc_html($lane_label); ?></th>
							  <?php endforeach; ?>
							</tr>
						  </thead>
						  <tbody>
							<?php foreach ($hores_filtrades as $r_idx => $h):
							  echo '<tr><td class="sportic-hour-cell" style="border:1px solid #999;padding:5px;text-align:center;"><span class="sportic-text">'.esc_html($h).'</span></td>';
							  
							  for ($c_idx = 0; $c_idx < $cols; $c_idx++) {
								if (!isset($data_amb_spans[$r_idx][$c_idx]['rowspan'])) continue;
	
								$cell_info = $data_amb_spans[$r_idx][$c_idx];
								$v = $cell_info['value'];
								$rowspan = $cell_info['rowspan'];
								$colspan = $cell_info['colspan'];
	
								$isRecurrent = false; $valorBase = $v;
								if (is_string($v) && strpos($v, '@') === 0) { $isRecurrent = true; $valorBase = substr($v, 1); } 
								elseif (is_string($v) && strpos($v, '!') === 0) { $valorBase = substr($v, 1); }
	
								$valorBase = trim($valorBase);
								$bg  = $leg[$valorBase]['color'] ?? $leg['l']['color'];
								$tit = $leg[$valorBase]['title'] ?? '';
								$txt = ($valorBase === 'l' || $valorBase === 'b') ? '' : $valorBase;
								
								$textColor = _sportic_contrast_color_helper($bg);
								$style = 'border:1px solid #999;padding:5px;text-align:center;background-color:'.$bg.';color:'.$textColor.';position:relative;vertical-align:middle;';
	
								$data_attrs = '';
								if ($txt !== '') {
									$team_key = _sportic_normalize_key($txt);
									$data_attrs .= ' data-team-name="' . esc_attr($txt) . '"';
									$data_attrs .= ' data-team-key="' . esc_attr($team_key) . '"';
								}
								$data_attrs .= ' data-original-bg="' . esc_attr($bg) . '"';
								$data_attrs .= ' data-original-color="' . esc_attr($textColor) . '"';
	
								echo '<td class="sportic-cell" rowspan="'.intval($rowspan).'" colspan="'.intval($colspan).'" title="'.esc_attr($tit).'" style="'.$style.'"'.$data_attrs.'><span class="sportic-text-scalable">'.esc_html($txt).'</span>';
								if ($isRecurrent) { echo '<i class="fas fa-sync-alt" style="position:absolute;bottom:1px;left:1px;font-size:9px;color:rgba(0,0,0,0.4);"></i>'; }
								echo '</td>';
							  }
							  echo '</tr>';
							endforeach; ?>
						  </tbody>
						</table>
				  </div>
				<?php endforeach; ?>
			  </div>
			</div><?php
			return ob_get_clean();
		}
	}
	
	if ( ! function_exists('_sportic_day_html_dina3') ) {
		function _sportic_day_html_dina3($dia, $dades, $pisc, $leg, $pools_to_render_slugs = null) {
			if ($pools_to_render_slugs === null) {
				$pools_to_render_slugs = array_keys($pisc);
			}
	
			$dies  = ['Diumenge','Dilluns','Dimarts','Dimecres','Dijous','Divendres','Dissabte'];
	
			try { $o = new DateTime($dia); $nice = $o->format('d-m-Y'); $nom = $dies[(int)$o->format('w')]; }
			catch (Exception $e) { $nice = 'Data invàlida'; $nom = ''; }
	
			ob_start(); ?>
			<div class="sw_day" data-day="<?php echo esc_attr($dia); ?>">
			  <h3 style="margin-bottom:28px;font-size:6rem;font-weight:bold;text-align:center;border-bottom:2px solid #eee;">
				<?php echo esc_html(($nom ? $nom.', ' : '').$nice); ?>
			  </h3>
			  <div style="display:flex;gap:38px;flex-wrap:nowrap;justify-content:flex-start;">
				<?php foreach ($pools_to_render_slugs as $s):
				  if ( ! isset($pisc[$s]) ) continue;
				  $lab = $pisc[$s]['label'] ?? ucfirst($s);
				  $pr  = (isset($dades[$s][$dia]) && is_array($dades[$s][$dia]))
							? $dades[$s][$dia] : sportic_unfile_crear_programacio_default($s);
				  if ( ! $pr ) continue;
				  
				  $hores = array_keys($pr); sort($hores);
				  $hores_filtrades = array_values(array_filter($hores, 'sportic_unfile_is_time_in_open_range'));
				  if (empty($hores_filtrades)) continue;
				  
				  $cols = isset($pisc[$s]['lanes']) ? intval($pisc[$s]['lanes']) : 0;
				  if ($cols === 0) continue;
	
				  $lane_labels = $pisc[$s]['lane_labels'] ?? [];
				  if (empty($lane_labels)) { for ($i = 1; $i <= $cols; $i++) { $lane_labels[] = 'Pista ' . $i; } }
	
				  $w = _sportic_calcular_width_px($cols); 
				  
				  $preprocessed = _sportic_preprocess_data_for_merging($pr, $hores_filtrades, $cols);
				  $data_amb_spans = $preprocessed['data'];
				  ?>
				  <div style="display:inline-block;padding:12px 0;">
					<h4 style="margin:0 0 20px;text-align:center;font-size:1.4rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html($lab); ?></h4>
					<div style="display:inline-block;">
					  <div style="min-width:<?php echo $w; ?>px;">
						<table style="border-collapse:collapse;margin:0 auto;font-size:2.0rem;table-layout:fixed;">
						  <thead>
							<tr style="background:#d8d8d8;">
							  <th style="border:2px solid #555;padding:10px;width:110px;">Hora</th>
							  <?php foreach ($lane_labels as $lane_label): ?>
								<th style="border:2px solid #555;padding:10px;width:120px;"><?php echo esc_html($lane_label); ?></th>
							  <?php endforeach; ?>
							</tr>
						  </thead>
						  <tbody>
							<?php foreach ($hores_filtrades as $r_idx => $h):
							  echo '<tr><td class="sportic-hour-cell" style="border:2px solid #555;padding:10px;text-align:center;width:110px;"><span class="sportic-text">'.esc_html($h).'</span></td>';
							  
							  for ($c_idx = 0; $c_idx < $cols; $c_idx++) {
								if (!isset($data_amb_spans[$r_idx][$c_idx]['rowspan'])) continue;
	
								$cell_info = $data_amb_spans[$r_idx][$c_idx];
								$v = $cell_info['value'];
								$rowspan = $cell_info['rowspan'];
								$colspan = $cell_info['colspan'];
	
								$isRecurrent = false; $valorBase = $v;
								if (is_string($v) && strpos($v, '@') === 0) { $isRecurrent = true; $valorBase = substr($v, 1); } 
								elseif (is_string($v) && strpos($v, '!') === 0) { $valorBase = substr($v, 1); }
	
								$valorBase = trim($valorBase);
								$bg  = $leg[$valorBase]['color'] ?? $leg['l']['color'];
								$tit = $leg[$valorBase]['title'] ?? '';
								$txt = ($valorBase === 'l' || $valorBase === 'b') ? '' : $valorBase;
								
								// =========================================================================
								// INICI DEL CANVI: AFEGIM ELS MATEIXOS ATRIBUTS QUE LA VISTA NORMAL
								// =========================================================================
								$textColor = function_exists('_sportic_contrast_color_helper') ? _sportic_contrast_color_helper($bg) : '#000000';
								$style = 'border:2px solid #555;padding:10px;text-align:center;background-color:'.$bg.';color:'.$textColor.';width:120px;position:relative; vertical-align:middle;';
	
								$data_attrs = '';
								if ($txt !== '') {
									$team_key = function_exists('_sportic_normalize_key') ? _sportic_normalize_key($txt) : strtolower($txt);
									$data_attrs .= ' data-team-name="' . esc_attr($txt) . '"';
									$data_attrs .= ' data-team-key="' . esc_attr($team_key) . '"';
								}
								$data_attrs .= ' data-original-bg="' . esc_attr($bg) . '"';
								$data_attrs .= ' data-original-color="' . esc_attr($textColor) . '"';
	
								echo '<td class="sportic-cell" rowspan="'.$rowspan.'" colspan="'.$colspan.'" title="'.esc_attr($tit).'" style="'.$style.'"'.$data_attrs.'><span class="sportic-text-scalable">'.esc_html($txt).'</span></td>';
								// =========================================================================
								// FI DEL CANVI
								// =========================================================================
							  }
							  echo '</tr>';
							endforeach; ?>
						  </tbody>
						</table>
					  </div>
					</div>
				  </div>
				<?php endforeach; ?>
			  </div>
			</div><?php
			return ob_get_clean();
		}
	}


	if ( ! function_exists('_sportic_normalize_key') ) {
	function _sportic_normalize_key( $text ) {
		// Comentari (en català): Assegurem que és text i traiem espais extrems
		$text = is_string($text) ? $text : '';
		$text = trim($text);

		// Comentari (en català): Passem a minúscules
		$text = mb_strtolower($text, 'UTF-8');

		// Comentari (en català): Eliminem accents/dièresi. Preferim 'intl' i, si no, fem servir iconv.
		if ( function_exists('transliterator_transliterate') ) {
			// NFD: separa accents; [:Nonspacing Mark:]: elimina marques; NFC: recompon
			$text = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $text);
		} else {
			$converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
			if ($converted !== false) {
				$text = $converted;
			}
		}

		// Comentari (en català): Converteix qualsevol cosa que no sigui [a-z0-9] en guions
		$text = preg_replace('/[^a-z0-9]+/', '-', $text);

		// Comentari (en català): Traiem guions al principi i al final i comprimim múltiples guions
		$text = preg_replace('/-+/', '-', $text);
		$text = trim($text, '-');

		return $text;
	}
}
	
if ( ! function_exists('sportic_independent_shortcode_function') ) {
	function sportic_independent_shortcode_function() {
		// Obtenim la data seleccionada o la data actual
		$ds = isset($_GET['sc_date']) ? sanitize_text_field($_GET['sc_date']) : date('Y-m-d');
		try { $o = new DateTime($ds); }
		catch (Exception $e) { $o = new DateTime(); }
		$ds  = $o->format('Y-m-d');

		// Carreguem les dades, piscines i configuració de la llegenda
		$dades = sportic_carregar_finestra_bd( $ds, 7, 6 );
		$piscines = sportic_unfile_get_pool_labels_sorted();

		$leg = [
			'l' => ['color' => '#ffffff', 'title' => 'Lliure'],
			'b' => ['color' => '#b9b9b9', 'title' => 'Tancat'],
		];
		$pers = get_option('sportic_unfile_custom_letters', array());
		$all_teams_for_js = [];
		
		if ( is_array($pers) ) {
			if (!function_exists('_sportic_normalize_key')) {
				function _sportic_normalize_key($txt) {
					$txt = strtolower(trim((string)$txt));
					if (function_exists('iconv')) {
						$converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $txt);
						if ($converted !== false) {
							$txt = $converted;
						}
					}
					$txt = preg_replace('/[^a-z0-9]+/i', ' ', $txt);
					$txt = preg_replace('/\s+/', ' ', $txt);
					return trim($txt);
				}
			}

			foreach ($pers as $ci) {
				if ( ! empty($ci['description']) ) {
					$description = trim($ci['description']);
					$leg[$description] = [
						'color' => sanitize_hex_color($ci['color'] ?? '#dddddd'),
						'title' => $description
					];
					$all_teams_for_js[] = [
						'name' => $description,
						'key'  => _sportic_normalize_key($description)
					];
				}
			}
		}
		
		$htmlWeek = ''; $htmlD3 = []; $tmp = clone $o;
		for ($i=0;$i<7;$i++) { 
			$d = $tmp->format('Y-m-d');
			$htmlWeek .= '<div id="sw_day_'.$d.'" class="sportic-schedule-day-wrapper" style="margin-bottom:32px;border-bottom:2px solid #ccc;padding-bottom:22px;">'
						 . _sportic_day_html_visual($d,$dades,$piscines,$leg).'</div>';
			$pavellons_per_dina3 = array_keys($piscines);
			$htmlD3[$d] = _sportic_day_html_dina3($d, $dades, $piscines, $leg, $pavellons_per_dina3);
			$tmp->modify('+1 day');
		}
		$k = array_keys($htmlD3); 
		
		ob_start(); ?>
	<style>
		/* Estils Base i de disseny */
		.sw_wrap  {width:100%;position:relative;margin:0 auto; overflow: hidden;}
		.sw_scaler{
			width: 100%; 
			max-width: 1650px; 
			margin: 0 auto; 
			transform-origin: top center; 
		}
		.sw_btn{padding:10px 22px;background:#334155;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:1.1em;font-weight:600;display:inline-flex;align-items:center;gap:8px;transition:background-color 0.2s ease;}
		.sw_btn:hover {background-color:#475569;}
		.sw_btn .dashicons {font-size: 20px;line-height: 1;}
		@keyframes spin{0%{transform:rotate(0);}100%{transform:rotate(360deg);}}
		.sw_spin{ border:12px solid #f1f5f9; border-top:12px solid #3b82f6; border-radius:50%; width:70px; height:70px; animation:spin 1.2s linear infinite; margin:0 auto; }
		td[rowspan]:not([rowspan="1"]), td[colspan]:not([colspan="1"]) { vertical-align: middle !important; text-align: center !important; }
		.sportic-text-scalable { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-weight: bold; font-size: 1em; line-height: 1.1; padding: 2px; box-sizing: border-box; }

		td.sportic-cell.dimmed {
			background-color: #E0E0E0 !important;
			opacity: 0.7 !important;
		}
		
		.sportic-main-controls-container {
			margin-bottom: 25px; padding: 1.25rem; background-color: #f8fafc;
			border-radius: 16px; border: 1px solid #e2e8f0;
			box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
		}
		.sportic-controls-row {
			display: flex; flex-wrap: wrap; align-items: center; gap: 1rem;
		}
		.sportic-main-actions {
			justify-content: space-between;
			padding-bottom: 1.25rem;
		}
		.sportic-filters {
			flex-direction: column; 
			align-items: center; 
			padding-top: 1.25rem;
			border-top: 1px solid #e2e8f0;
			gap: 1.5rem;
		}
		.sw_form {display:flex;gap:12px;align-items:center;}
		.sw_form label {font-size: 1.1em;font-weight:600;color:#334155;}
		.sw_form input[type="date"] {font-size: 1.1em;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;}
		.sw_form .sw_btn {padding: 9px 18px;font-size:1.1em;}
		.sportic-download-buttons {display:flex;gap:12px;flex-wrap:wrap;}
		
		.sportic-gender-filters {display:flex;justify-content:center;gap:10px;flex-wrap:wrap;}
		.sportic-filter-btn {font-family: inherit;font-size:1.05em;font-weight:600;padding:9px 20px;border:2px solid transparent;border-radius:50px;cursor:pointer;background-color:#f1f5f9;color:#475569;transition:all 0.15s ease;}
		.sportic-filter-btn:hover {background-color:#e2e8f0; color: #334155;} 
		.sportic-filter-btn.active {background-color:#334155;color:white;border-color:#334155;}
		
		.sportic-team-filter-controls {display:flex;justify-content:center;gap:10px;flex-wrap:wrap; align-items: center;}
		.sportic-team-btn {font-family: inherit;font-size:0.95em;padding:8px 14px;margin:4px;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;background-color:white;color:#334155;transition:all 0.12s ease;}
		.sportic-team-btn:hover {background-color:#f1f5f9;border-color:#94a3b8; color: #1e293b;} 
		.sportic-team-btn.active {background-color:#0ea5e9;color:white;border-color:#0ea5e9;font-weight:700;}
		
		.sportic-team-btn:focus, .sportic-team-btn:active { outline: none; box-shadow: none; }
		.sportic-team-btn:not(.active):focus { background-color: white; border-color: #cbd5e1; color: #334155; }
		
		.sportic-reset-btn {
			background-color: #334155; color: #fff; border: none; padding: 10px 22px; 
			font-size: 1.1em; font-weight: 600; border-radius: 8px; cursor: pointer;
			display: inline-flex; align-items: center; gap: 8px; transition: background-color 0.2s ease;
		}
		.sportic-reset-btn:hover { background-color: #475569; }

		.sportic-search-wrapper { position: relative; display: inline-block; }
		.sportic-search-wrapper .dashicons {
			position: absolute;
			left: 12px;
			top: 50%;
			transform: translateY(-50%);
			color: #94a3b8;
			pointer-events: none;
		}
		#sportic_team_search_input {
			font-family: inherit;
			font-size: 1.05em;
			padding: 9px 15px 9px 40px; 
			border: 1px solid #cbd5e1;
			border-radius: 50px;
			min-width: 280px;
			transition: border-color 0.2s, box-shadow 0.2s;
		}
		#sportic_team_search_input:focus {
			border-color: #3b82f6;
			box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
			outline: none;
		}

		#sw_dina3{ display:none; position:absolute; left:-9999px; top:0; background:#fff; padding:40px 80px; font-family:Arial,sans-serif; width:12840px; box-sizing:border-box; }
		#sw_dina3 .dina3-row{display:flex;gap:100px;margin-bottom:40px;justify-content:flex-start;}
		#sw_dina3 .dina3-row > div{flex:0 0 calc( (100% - 200px) / 3 );}
		.logo2-dina3{position:absolute;bottom:1200px;left:65%;transform:translateX(-50%);width:3000px;z-index:10;opacity:1;pointer-events:none;}
		.logo3-dina3{position:absolute;bottom:400px;right:320px !important;width:1000px;z-index:10;}
		.sw_wrap table th, .sw_wrap table td, #sw_dina3 table th, #sw_dina3 table td { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
		
		.sw_pavilion_block { table-layout: auto; }
		.sportic-schedule-table { table-layout: auto; width: auto; }
		
		.mobile-filter-container { display: none; }
		
		@media (max-width: 768px) {
			.sw_wrap { height: auto !important; }
			.sw_scaler { 
				width: 100% !important; 
				transform: none !important; 
				padding: 0 10px;
				box-sizing: border-box;
				max-width: none;
				margin: 0;
			}

			.sportic-main-actions { flex-direction:column; align-items:stretch; }
			.sw_form { flex-direction: column; width: 100%; align-items: stretch; }
			.sw_form input[type="date"], .sw_form .sw_btn, .sw_form label { width: 100%; box-sizing: border-box; text-align:center; margin-bottom:10px; }
			.sportic-download-buttons, .sportic-filters { justify-content:center; }
			
			.sw_row { flex-direction: column !important; align-items: stretch !important; gap: 35px !important; }
			.sw_pavilion_block { width: 100% !important; }

			.sportic-schedule-table {
				width: 100% !important;
				table-layout: fixed !important;
				border-collapse: collapse;
			}
			.sportic-schedule-table th,
			.sportic-schedule-table td {
				font-size: 0.8rem !important;
				padding: 4px 2px !important;
				overflow-wrap: break-word;
				hyphens: auto;
			}
			.sportic-schedule-table td {
				white-space: normal !important;
			}
			.sportic-schedule-table th {
				white-space: nowrap !important;
				overflow-wrap: normal !important;
				hyphens: none !important;
			}
			
			.table-cols-1 th:first-child, .table-cols-1 td:first-child { width: 40%; }
			.table-cols-1 th:not(:first-child), .table-cols-1 td:not(:first-child) { width: 60%; }

			.table-cols-2 th:first-child, .table-cols-2 td:first-child { width: 34%; }
			.table-cols-2 th:not(:first-child), .table-cols-2 td:not(:first-child) { width: 33%; }

			.table-cols-5 th:first-child, .table-cols-5 td:first-child { width: 18%; }
			.table-cols-5 th:not(:first-child), .table-cols-5 td:not(:first-child) { width: 20.5%; }

			.desktop-filters { display: none !important; }
			.mobile-filter-container { display: block !important; width: 100%; border-top: 1px solid #e2e8f0; padding-top: 1.25rem; }
			#mobile-filter-toggle { width: 100%; background-color: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 15px; font-size: 1.1em; font-weight: 600; color: #334155; cursor: pointer; text-align: left; display: flex; justify-content: space-between; align-items: center; }
			#mobile-filter-toggle::after { content: '▼'; font-size: 0.8em; transition: transform 0.2s ease; }
			.mobile-filter-container.active #mobile-filter-toggle::after { transform: rotate(180deg); }
			#mobile-filter-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease-out; background-color: #f8fafc; border-radius: 0 0 8px 8px; border: 1px solid #e2e8f0; border-top: none; }
			.mobile-filter-container.active #mobile-filter-content { max-height: 480px; }
			.mobile-search-wrapper { padding: 15px; border-bottom: 1px solid #e2e8f0; }
			#mobile_team_search_input {
				width: 100%;
				box-sizing: border-box;
				font-size: 1.1em;
				padding: 10px 15px 10px 40px;
				border: 1px solid #cbd5e1;
				border-radius: 8px;
			}
			#mobile-team-list { padding: 15px; overflow-y: auto; max-height: 230px; }
			.mobile-team-filter-item { display: block; margin-bottom: 10px; }
			.mobile-team-filter-item label { display: flex; align-items: center; gap: 10px; font-size: 1.1em; }
			.mobile-team-filter-item input[type="checkbox"] { width: 20px; height: 20px; flex-shrink: 0; }
		}
	</style>

	<div class="sw_wrap" id="sportic-shortcode-main-container">
	  <div class="sw_scaler">
		<div style="font-family:Arial,sans-serif;margin-bottom:38px;">
		  
		  <div class="sportic-main-controls-container">
			  <div class="sportic-controls-row sportic-main-actions">
				  <form class="sw_form" method="get">
					  <label for="sc_date">Data:</label>
					  <input type="date" id="sc_date" name="sc_date" value="<?php echo esc_attr($ds); ?>"/>
					  <?php
					  foreach ($_GET as $key => $value) {
						  if ($key !== 'sc_date') {
							  echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
						  }
					  }
					  ?>
					  <button type="submit" class="sw_btn">Mostrar</button>
				  </form>
				  <div class="sportic-download-buttons">
					  <button id="sw_export_week" class="sw_btn"><span class="dashicons dashicons-images-alt2"></span> Descarregar Setmana DINA3</button>
				  </div>
			  </div>

			  <div class="desktop-filters">
				<div class="sportic-controls-row sportic-filters">
					<div class="sportic-gender-filters">
						<button class="sportic-filter-btn" data-gender-filter="masculi">Masculí</button>
						<button class="sportic-filter-btn" data-gender-filter="femeni">Femení</button>
						<button class="sportic-filter-btn active" data-gender-filter="tots">Tots</button>
					</div>
					<div class="sportic-search-wrapper">
						<span class="dashicons dashicons-search"></span>
						<input type="text" id="sportic_team_search_input" placeholder="Busca per equip...">
					</div>
					<button id="sw_reset_filters" class="sportic-reset-btn"><span class="dashicons dashicons-undo"></span>Restablir</button>
				</div>
				<div id="sportic-team-filter-container" class="sportic-team-filter-controls" style="margin-top: 1rem;"></div>
			  </div>

			  <div class="mobile-filter-container">
				<button id="mobile-filter-toggle">Filtra per equip</button>
				<div id="mobile-filter-content">
					<div class="mobile-search-wrapper">
						<div class="sportic-search-wrapper">
							<span class="dashicons dashicons-search"></span>
							<input type="text" id="mobile_team_search_input" placeholder="Busca per equip...">
						</div>
					</div>
					<div class="sportic-gender-filters" style="padding: 15px 15px 10px; border-bottom: 1px solid #e2e8f0;">
						<button class="sportic-filter-btn" data-gender-filter="masculi">Masculí</button>
						<button class="sportic-filter-btn" data-gender-filter="femeni">Femení</button>
						<button class="sportic-filter-btn active" data-gender-filter="tots">Tots</button>
					</div>
					<div id="mobile-team-list">
					</div>
					<div style="padding: 10px 15px 15px;">
						<button id="sw_reset_filters_mobile" class="sportic-reset-btn" style="width: 100%; justify-content: center;">
							<span class="dashicons dashicons-undo"></span>Restablir Filtres
						</button>
					</div>
				</div>
			  </div>

		  </div>

		  <div id="sw_table"><?php echo $htmlWeek; ?></div>

		  <div style="margin-top:42px;padding-top:18px;border-top:1px solid #eee;text-align:center;">
			<h4 style="margin-bottom:10px;font-size:1.05rem;">Llegenda</h4>
			<div style="display:inline-flex;gap:16px;flex-wrap:wrap;justify-content:center;">
			  <?php foreach ($leg as $desc => $inf): ?>
				<div style="display:flex;align-items:center;gap:6px;">
				  <span style="width:22px;height:22px;background:<?php echo esc_attr($inf['color']); ?>;<?php echo $desc==='l'?'border:1px solid #ccc;':'';?>"></span>
				  <?php echo esc_html($inf['title']); ?>
				</div>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>
	  </div>
	</div>

	<div id="sw_loader" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.85);z-index:9999;text-align:center;">
	  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);">
		<div class="sw_spin"></div>
		<div id="sw_loader_txt" style="margin-top:20px;font-size:18px;font-weight:bold;"></div>
	  </div>
	</div>

	<?php
	$logo  = plugin_dir_url(__FILE__).'imatges/logo2.jpg';
	$logo3 = plugin_dir_url(__FILE__).'imatges/logo3.jpg';
	?>
	<div id="sw_dina3" style="position:relative;">
	  <img src="<?php echo esc_url($logo); ?>" class="logo2-dina3" alt="Logo2"/>
	  <img src="<?php echo esc_url($logo3); ?>" class="logo3-dina3" alt="Logo3"/>
	  <div class="dina3-row">
		<div><?php echo $htmlD3[$k[0]] ?? ''; ?></div> <div><?php echo $htmlD3[$k[1]] ?? ''; ?></div> <div><?php echo $htmlD3[$k[2]] ?? ''; ?></div>
	  </div>
	  <div class="dina3-row">
		<div><?php echo $htmlD3[$k[3]] ?? ''; ?></div> <div><?php echo $htmlD3[$k[4]] ?? ''; ?></div> <div><?php echo $htmlD3[$k[5]] ?? ''; ?></div>
	  </div>
	  <div class="dina3-row">
		<div><?php echo $htmlD3[$k[6]] ?? ''; ?></div> <div></div><div></div>
	  </div>
	  <div style="margin-top:40px;border-top:5px solid #aaa;padding-top:40px;text-align:center;">
		<h4 style="font-size:4rem;margin-bottom:48px;font-weight:bold;">Llegenda</h4>
		<div style="display:inline-flex;gap:70px 60px;flex-wrap:wrap;justify-content:center;">
		  <?php foreach ($leg as $desc => $inf): ?>
			<div style="display:flex;align-items:center;gap:24px;font-size:5rem;">
			  <span style="width:75px;height:75px;background:<?php echo esc_attr($inf['color']); ?>;border:<?php echo $desc==='l'?'3':'2'; ?>px solid #<?php echo $desc==='l'?'777':'bbb'; ?>;"></span>
			  <?php echo esc_html($inf['title']); ?>
			</div>
		  <?php endforeach; ?>
		</div>
	  </div>
	</div>

	<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
	<script>
	window.addEventListener('load', () => {
		const contenidorPrincipal = document.getElementById('sportic-shortcode-main-container');
		if (!contenidorPrincipal) {
			console.error("SporTic ERROR: No s'ha trobat el contenidor principal '#sportic-shortcode-main-container'.");
			return;
		}

		const totsEquips = <?php echo json_encode($all_teams_for_js); ?>;
		
		const searchInputDesktop = contenidorPrincipal.querySelector('#sportic_team_search_input');
		const searchInputMobile = contenidorPrincipal.querySelector('#mobile_team_search_input');
		
		const sporticFilterState = {
			selectedKeys: new Set(),
			searchTerm: '',
			gender: 'tots'
		};
		
		const mobileFilterContainer = contenidorPrincipal.querySelector('.mobile-filter-container');
		const mobileFilterToggle = contenidorPrincipal.querySelector('#mobile-filter-toggle');
		const mobileTeamList = contenidorPrincipal.querySelector('#mobile-team-list');

		function actualitzarVisibilitatEquips() {
			const normalizedSearch = sporticFilterState.searchTerm.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
			// =========================================================================
			// INICI DEL CANVI: Lògica de cerca millorada
			// =========================================================================
			const searchKeywords = normalizedSearch.split(' ').filter(w => w.length > 0);
			// =========================================================================
			// FI DEL CANVI
			// =========================================================================

			const equipsVisibles = totsEquips.filter(equipObj => {
				const nom = equipObj.name.toLowerCase();
				const nomNormalitzat = nom.normalize("NFD").replace(/[\u0300-\u036f]/g, "");

				// 1. Comprovació de gènere (sense canvis)
				const isMasculi = nom.includes('masculí') || nom.includes('masculi');
				const isFemeni = nom.includes('femení') || nom.includes('femeni');
				const isNeutre = !isMasculi && !isFemeni;
				
				let genereCorrecte = false;
				if (sporticFilterState.gender === 'masculi') genereCorrecte = isMasculi || isNeutre;
				else if (sporticFilterState.gender === 'femeni') genereCorrecte = isFemeni || isNeutre;
				else genereCorrecte = true;

				if (!genereCorrecte) return false;

				// =========================================================================
				// INICI DEL CANVI: Lògica de cerca millorada
				// =========================================================================
				// 2. Comprovació de cerca per paraules clau
				if (searchKeywords.length > 0) {
					const allKeywordsMatch = searchKeywords.every(keyword => nomNormalitzat.includes(keyword));
					if (!allKeywordsMatch) {
						return false;
					}
				}
				// =========================================================================
				// FI DEL CANVI
				// =========================================================================

				return true;
			});

			const clausVisibles = new Set(equipsVisibles.map(e => e.key));

			contenidorPrincipal.querySelectorAll('#sportic-team-filter-container .sportic-team-btn').forEach(boto => {
				boto.style.display = clausVisibles.has(boto.dataset.teamKey) ? '' : 'none';
			});

			if (mobileTeamList) {
				mobileTeamList.querySelectorAll('.mobile-team-filter-item').forEach(item => {
					const checkbox = item.querySelector('input[type="checkbox"]');
					if (checkbox) {
						item.style.display = clausVisibles.has(checkbox.dataset.teamKey) ? '' : 'none';
					}
				});
			}
		}

		function omplirBotonsEquips() {
			const contEquips = contenidorPrincipal.querySelector('#sportic-team-filter-container');
			if (!contEquips) return;
			contEquips.innerHTML = '';
			
			totsEquips.sort((a, b) => a.name.localeCompare(b.name)).forEach(equipObj => {
				const boto = document.createElement('button');
				boto.className = 'sportic-team-btn';
				boto.textContent = equipObj.name;
				boto.dataset.teamKey = equipObj.key;
				boto.style.display = 'none';
				if (sporticFilterState.selectedKeys.has(equipObj.key)) {
					boto.classList.add('active');
				}
				contEquips.appendChild(boto);
			});
		}
		
		function omplirCheckboxesEquips() {
			if (!mobileTeamList) return;
			mobileTeamList.innerHTML = '';
			
			totsEquips.sort((a, b) => a.name.localeCompare(b.name)).forEach(equipObj => {
				const itemDiv = document.createElement('div');
				itemDiv.className = 'mobile-team-filter-item';
				itemDiv.style.display = 'none';
				const label = document.createElement('label');
				const checkbox = document.createElement('input');
				checkbox.type = 'checkbox';
				checkbox.dataset.teamKey = equipObj.key;
				checkbox.checked = sporticFilterState.selectedKeys.has(equipObj.key);
				const textNode = document.createTextNode(equipObj.name);
				label.appendChild(checkbox);
				label.appendChild(textNode);
				itemDiv.appendChild(label);
				mobileTeamList.appendChild(itemDiv);
			});
		}

		if (mobileFilterToggle) {
			mobileFilterToggle.addEventListener('click', () => {
				mobileFilterContainer.classList.toggle('active');
			});
		}

		if (mobileTeamList) {
			mobileTeamList.addEventListener('change', (e) => {
				if (e.target.type === 'checkbox') {
					const clau = e.target.dataset.teamKey;
					if (e.target.checked) {
						sporticFilterState.selectedKeys.add(clau);
					} else {
						sporticFilterState.selectedKeys.delete(clau);
					}
					const botoDesktop = contenidorPrincipal.querySelector(`.sportic-team-btn[data-team-key="${clau}"]`);
					if (botoDesktop) botoDesktop.classList.toggle('active', e.target.checked);
					aplicarEstilsDeFiltre(contenidorPrincipal);
				}
			});
		}

		function aplicarEstilsDeFiltre(container) {
			if (!container) return;
			
			const celes = container.querySelectorAll('td.sportic-cell');
			const filtresActius = sporticFilterState.selectedKeys.size > 0;

			celes.forEach(cela => {
				const bgOriginal = cela.getAttribute('data-original-bg');
				const colorOriginal = cela.getAttribute('data-original-color');
				
				cela.classList.remove('dimmed');
				
				if (bgOriginal) cela.style.setProperty('background-color', bgOriginal);
				if (colorOriginal) {
					const sp = cela.querySelector('.sportic-text-scalable');
					if (sp) sp.style.setProperty('color', colorOriginal);
					else    cela.style.setProperty('color', colorOriginal);
				}

				if (filtresActius) {
					const key = (cela.getAttribute('data-team-key') || '').trim();
					const esSeleccionat = key && sporticFilterState.selectedKeys.has(key);

					if (esSeleccionat) {
						cela.style.setProperty('background-color', '#0ea5e9', 'important');
						const sp = cela.querySelector('.sportic-text-scalable');
						if (sp) sp.style.setProperty('color', '#ffffff', 'important');
						else cela.style.setProperty('color', '#ffffff', 'important');
					} else {
						cela.classList.add('dimmed');
						const sp = cela.querySelector('.sportic-text-scalable');
						const textColor = '#555555';
						if (sp) sp.style.setProperty('color', textColor, 'important');
						else cela.style.setProperty('color', textColor, 'important');
					}
				}
			});
		}
		
		function netejarEstilsDeFiltre(container) {
			if (!container) return;
			container.querySelectorAll('td.sportic-cell').forEach(cela => {
				const bg = cela.getAttribute('data-original-bg');
				const col = cela.getAttribute('data-original-color');
				
				cela.classList.remove('dimmed');
				cela.style.removeProperty('background-color');
				cela.style.removeProperty('color');

				if (bg)  cela.style.setProperty('background-color', bg);
				if (col) {
					const sp = cela.querySelector('.sportic-text-scalable');
					if (sp) sp.style.setProperty('color', col);
					else    cela.style.setProperty('color', col);
				}
			});
		}

		function ferResetComplet() {
			sporticFilterState.selectedKeys.clear();
			sporticFilterState.searchTerm = '';
			sporticFilterState.gender = 'tots';

			netejarEstilsDeFiltre(contenidorPrincipal);

			contenidorPrincipal.querySelectorAll('.desktop-filters .sportic-team-btn').forEach(b => b.classList.remove('active'));
			contenidorPrincipal.querySelectorAll('.desktop-filters .sportic-gender-filters .sportic-filter-btn').forEach(b => b.classList.remove('active'));
			const bTotsDesktop = contenidorPrincipal.querySelector('.desktop-filters .sportic-gender-filters .sportic-filter-btn[data-gender-filter="tots"]');
			if (bTotsDesktop) bTotsDesktop.classList.add('active');
			if (searchInputDesktop) searchInputDesktop.value = '';
			
			contenidorPrincipal.querySelectorAll('#mobile-team-list input[type="checkbox"]').forEach(cb => cb.checked = false);
			contenidorPrincipal.querySelectorAll('.mobile-filter-container .sportic-gender-filters .sportic-filter-btn').forEach(b => b.classList.remove('active'));
			const bTotsMobile = contenidorPrincipal.querySelector('.mobile-filter-container .sportic-gender-filters .sportic-filter-btn[data-gender-filter="tots"]');
			if (bTotsMobile) bTotsMobile.classList.add('active');
			if (searchInputMobile) searchInputMobile.value = '';

			actualitzarVisibilitatEquips();

			if (mobileFilterContainer && mobileFilterContainer.classList.contains('active')) {
				mobileFilterToggle.click();
			}
		}

		contenidorPrincipal.addEventListener('click', (e) => {
			const botoGenere = e.target.closest('.sportic-gender-filters .sportic-filter-btn');
			const botoEquip  = e.target.closest('#sportic-team-filter-container .sportic-team-btn');
			const botoReset  = e.target.closest('#sw_reset_filters, #sw_reset_filters_mobile');

			if (botoReset) {
				ferResetComplet();
				return;
			}

			if (botoGenere) {
				const tipus = botoGenere.dataset.genderFilter;
				sporticFilterState.gender = tipus;
				
				contenidorPrincipal.querySelectorAll('.sportic-gender-filters .sportic-filter-btn').forEach(b => b.classList.remove('active'));
				contenidorPrincipal.querySelectorAll(`.sportic-gender-filters .sportic-filter-btn[data-gender-filter="${tipus}"]`).forEach(b => b.classList.add('active'));
				
				actualitzarVisibilitatEquips();
				return;
			}

			if (botoEquip) {
				botoEquip.classList.toggle('active');
				const clau = botoEquip.dataset.teamKey;

				if (botoEquip.classList.contains('active')) {
					sporticFilterState.selectedKeys.add(clau);
				} else {
					sporticFilterState.selectedKeys.delete(clau);
				}
				aplicarEstilsDeFiltre(contenidorPrincipal);
				
				const mobileCheckbox = mobileTeamList.querySelector(`input[type="checkbox"][data-team-key="${clau}"]`);
				if(mobileCheckbox) mobileCheckbox.checked = botoEquip.classList.contains('active');
				return;
			}
		});
		
		function handleSearchInput(e) {
			sporticFilterState.searchTerm = e.target.value;
			if (searchInputDesktop && searchInputMobile) {
				if (e.target === searchInputDesktop) {
					searchInputMobile.value = sporticFilterState.searchTerm;
				} else {
					searchInputDesktop.value = sporticFilterState.searchTerm;
				}
			}
			actualitzarVisibilitatEquips();
		}

		if (searchInputDesktop) searchInputDesktop.addEventListener('input', handleSearchInput);
		if (searchInputMobile) searchInputMobile.addEventListener('input', handleSearchInput);
		
		function parseRGBColor(str) {
			if (!str) return [255, 255, 255];
			let m = str.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/);
			if (m) return [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
			let hex = str.replace('#', '').trim();
			if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
			if (hex.length === 6) {
				const r = parseInt(hex.substring(0, 2), 16),
					  g = parseInt(hex.substring(2, 4), 16),
					  b = parseInt(hex.substring(4, 6), 16);
				if (!isNaN(r) && !isNaN(g) && !isNaN(b)) return [r, g, b];
			}
			return [255, 255, 255];
		}

		function getTextColorForBackground(r, g, b) {
			var brightness = (0.299 * r + 0.587 * g + 0.114 * b);
			return (brightness < 130) ? '#ffffff' : '#000000';
		}
		function updateTextContrast(cela) {
			if (!cela) return;
			var bg = window.getComputedStyle(cela).backgroundColor;
			var rgb = parseRGBColor(bg);
			if(!rgb) return;
			var colorText = getTextColorForBackground(rgb[0], rgb[1], rgb[2]);
			var span = cela.querySelector('.sportic-text-scalable');
			if (span) { span.style.setProperty('color', colorText, 'important'); }
			else { cela.style.setProperty('color', colorText, 'important'); }
		}
		function scaleTextToFit() {
			const elements = document.querySelectorAll('.sportic-text-scalable');
			elements.forEach(el => {
				if (!el.parentElement) return;
				el.style.transform = 'scale(1)';
				el.style.whiteSpace = 'nowrap';
				const parent = el.parentElement;
				const parentWidth = parent.clientWidth - 4;
				const parentHeight = parent.clientHeight - 4;
				const elWidth = el.scrollWidth;
				const elHeight = el.scrollHeight;
				el.style.whiteSpace = 'normal';
				if (elWidth > parentWidth || elHeight > parentHeight) {
					const scale = Math.min(parentWidth / elWidth, parentHeight / elHeight);
					el.style.transform = 'scale(' + scale + ')';
				}
			});
		}

		const wrap = document.querySelector('.sw_wrap'); 
		const sc = document.querySelector('.sw_scaler');
		if (!wrap || !sc) { console.error('SPORTIC: Elements .sw_wrap o .sw_scaler no trobats.'); return; }

		function fit(){
			if (!wrap || !sc) return;
			if (window.innerWidth <= 768) {
				sc.style.transform = ''; 
				wrap.style.height = 'auto'; 
				return;
			}
			sc.style.transform = '';
			wrap.style.height = sc.getBoundingClientRect().height + 'px';
		}

		window.addEventListener('resize', ()=>{ clearTimeout(fit._t); fit._t = setTimeout(fit,150); });

		function load(msg){
			const loaderTxt = document.getElementById('sw_loader_txt');
			const loader = document.getElementById('sw_loader');
			if(loaderTxt) loaderTxt.textContent = msg;
			if(loader) loader.style.display = 'block';
		}
		function end(){
			const loader = document.getElementById('sw_loader');
			if(loader) loader.style.display = 'none';
		}

		const ds = "<?php echo esc_js($ds); ?>";
		
		const exportWeekButton = document.getElementById('sw_export_week');
		if (exportWeekButton) {
			exportWeekButton.onclick = e => {
				e.preventDefault();
				const box = document.getElementById('sw_dina3');
				if (!box) { alert("Element DINA3 no trobat."); return; }
				load("Generant DINA3…");
				
				aplicarEstilsDeFiltre(box);

				box.style.display = 'block'; box.style.left = '0'; box.style.zIndex = '9999';
				scaleTextToFit();
				setTimeout(() => {
					const marge = 50; const W = box.scrollWidth + marge; const H = box.scrollHeight; 
					box.style.width  = W + 'px'; box.style.height = H + 'px';
					html2canvas(box, { scale:1.0, useCORS:true, backgroundColor:'#fff', width: W, height: H, windowWidth: W, windowHeight: H, imageTimeout:20000 })
					.then(cv => {
						const R = Math.SQRT2; let w_cv = cv.width, h_cv = cv.height, newW, newH;
						if (w_cv/h_cv > R){ newW = w_cv; newH = Math.round(w_cv / R); } else { newH = h_cv; newW = Math.round(h_cv * R); }
						const out = document.createElement('canvas'); out.width  = newW; out.height = newH;
						const ctx  = out.getContext('2d');
						ctx.fillStyle = '#ffffff'; ctx.fillRect(0,0,newW,newH); 
						const s_scale  = Math.min(newW / w_cv, newH / h_cv); 
						const dx = (newW - w_cv*s_scale) / 2; const dy = (newH - h_cv*s_scale) / 2; 
						ctx.drawImage(cv, 0, 0, w_cv, h_cv, dx, dy, w_cv*s_scale, h_cv*s_scale);
						const a = document.createElement('a'); a.download = 'Setmana_'+ds+'.jpg'; a.href = out.toDataURL('image/jpeg',0.8); a.click();
					})
					.catch((error)=>{ console.error("Error en html2canvas (setmana): ", error); alert("Hi ha hagut un error capturant la setmana."); })
					.finally(()=>{
						netejarEstilsDeFiltre(box);
						box.style.display = 'none'; box.style.left = '-9999px'; box.style.zIndex = '-1'; box.style.width = '12840px'; box.style.height = ''; 
						end();
					});
				}, 100);
			};
		}
		
		fit(); 
		scaleTextToFit();
		const totesCelesDades = document.querySelectorAll('.sw_wrap table td, #sw_dina3 table td');
		totesCelesDades.forEach(c => { if(c.cellIndex > 0) { updateTextContrast(c); } });

		omplirBotonsEquips();
		omplirCheckboxesEquips();
		ferResetComplet();

	});
	</script>
	<?php return ob_get_clean();
	}
}
	if ( function_exists('sportic_independent_shortcode_function') ) {
		add_shortcode('sportic_frontend_custom_dayview_subitems_independent',
					  'sportic_independent_shortcode_function');
	}}


// Funció auxiliar per treure el prefix '!' de les dades carregades
// ASSEGURA'T QUE AQUESTA FUNCIÓ ESTIGUI DEFINIDA AL TEU PLUGIN SPORTIC
// I ABANS DE LES FUNCIONS QUE LA UTILITZEN.
if (!function_exists('sportic_remove_lock_prefix_from_data')) {
	function sportic_remove_lock_prefix_from_data($data_amb_prefix) {
		if (!is_array($data_amb_prefix)) {
			return array(); // Retorna array buit si l'entrada no és vàlida
		}

		// Utilitzem referències (&) per modificar l'array directament
		foreach ($data_amb_prefix as $slug => &$diesArr) {
			if (!is_array($diesArr)) continue;
			foreach ($diesArr as $dia => &$horesArr) {
				if (!is_array($horesArr)) continue;
				foreach ($horesArr as $hora => &$carrilsArr) {
					if (!is_array($carrilsArr)) continue;
					$numCarrils = count($carrilsArr);
					for ($c = 0; $c < $numCarrils; $c++) {
						if (isset($carrilsArr[$c]) && is_string($carrilsArr[$c])) {
							if (strpos($carrilsArr[$c], '!') === 0) {
								$valorBase = substr($carrilsArr[$c], 1);
								// Si després de treure '!' queda buit o false, assignem 'l'
								$carrilsArr[$c] = ($valorBase === false || $valorBase === '') ? 'l' : $valorBase;
							}
							// Si no comença amb '!', ja és correcte
						} elseif (isset($carrilsArr[$c])) {
							// Si no és string i existeix, assegurem 'l'
							 if (!is_string($carrilsArr[$c])) { $carrilsArr[$c] = 'l'; }
						} else {
							// Si no existeix, posem 'l'
							 $carrilsArr[$c] = 'l';
						}
					}
				}
				unset($carrilsArr); // Desfem referència
			}
			unset($horesArr); // Desfem referència
		}
		unset($diesArr); // Desfem referència

		return $data_amb_prefix; // Retorna l'array modificat (sense '!')
	}
}
		}

function sportic_clear_all_week_transients() {
			global $wpdb;
			
			// Definim els dos possibles prefixos que WordPress utilitza per als transients a la BD
			$prefix = '_transient_sportic_week_data_';
			$timeout_prefix = '_transient_timeout_sportic_week_data_';
			
			// Escapem els caràcters especials de SQL per a la clàusula LIKE
			$pattern = $wpdb->esc_like($prefix) . '%';
			$timeout_pattern = $wpdb->esc_like($timeout_prefix) . '%';
			
			// Esborrem directament les opcions de la base de dades que coincideixen
			// amb qualsevol dels dos patrons. Aquesta és la manera més robusta i eficient.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
					$pattern,
					$timeout_pattern
				)
			);
		}
		
if (!function_exists('sportic_clear_all_week_transients')) {
	function sportic_clear_all_week_transients() {
		global $wpdb;
		// El prefix real a la base de dades per als transients és _transient_ o _site_transient_
		// Però delete_transient() espera el nom sense aquest prefix.
		// La clau que nosaltres generem és 'sportic_week_data_YYYY-MM-DD'
		// A la BD es desa com '_transient_sportic_week_data_YYYY-MM-DD'
		// o '_transient_timeout_sportic_week_data_YYYY-MM-DD'
		
		$pattern_like = $wpdb->esc_like('_transient_sportic_week_data_') . '%';
		$timeout_pattern_like = $wpdb->esc_like('_transient_timeout_sportic_week_data_') . '%';

		$options = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
			$pattern_like,
			$timeout_pattern_like
		) );

		if ( ! empty($options) ) {
			$cleaned_count = 0;
			foreach ( $options as $option_name_with_db_prefix ) {
				// Traiem el prefix de la BD (_transient_ o _transient_timeout_)
				// per obtenir el nom real del transient que espera delete_transient()
				$transient_name = '';
				if (strpos($option_name_with_db_prefix, '_transient_timeout_') === 0) {
					$transient_name = substr($option_name_with_db_prefix, strlen('_transient_timeout_'));
				} elseif (strpos($option_name_with_db_prefix, '_transient_') === 0) {
					$transient_name = substr($option_name_with_db_prefix, strlen('_transient_'));
				}

				if (!empty($transient_name) && strpos($transient_name, 'sportic_week_data_') === 0) {
					if (delete_transient($transient_name)) {
						$cleaned_count++;
						 // error_log("SporTIC: Transient esborrat: " . $transient_name);
					} else {
						 // error_log("SporTIC: ERROR esborrant transient: " . $transient_name);
					}
				}
			}
			// error_log("SporTIC: S'han netejat $cleaned_count transients setmanals (basat en " . count($options) . " opcions trobades).");
		} else {
			// error_log("SporTIC: No s'han trobat transients setmanals per netejar amb el patró 'sportic_week_data_'.");
		}
	}
}


if ( ! function_exists('_sportic_unfile_render_template_table') ) {
	function _sportic_unfile_render_template_table($title, $templates_array, $page_slug, $lloc_slug) {
		echo '<div class="card" style="margin-bottom: 30px;">'; 
		echo '<h2 class="title" style="margin-bottom: 20px; font-size: 22px;">' . esc_html($title) . '</h2>'; 

		if (empty($templates_array)) {
			echo '<p>No hi ha plantilles d\'aquest tipus per a aquest lloc.</p>';
		} else {
			echo '<table class="wp-list-table widefat striped sportic-templates-table">';
			echo '<thead><tr>';
			echo '<th style="width: 35%;">Nom</th>';
			echo '<th style="width: 20%;">Data de Creació</th>';
			echo '<th style="width: 45%;">Accions</th>';
			echo '</tr></thead>';
			echo '<tbody>';
			foreach ($templates_array as $pid => $p_data) {
				echo '<tr>';
				echo '<td>' . esc_html(stripslashes($p_data['name'])) . '</td>';

				$created_at_display = 'N/D';
				if (isset($p_data['created_at']) && !empty($p_data['created_at'])) {
					try {
						$date_obj = new DateTime($p_data['created_at'], new DateTimeZone(wp_timezone_string()));
						$created_at_display = $date_obj->format('d/m/Y H:i');
					} catch (Exception $e) {
						$created_at_display = esc_html($p_data['created_at']);
					}
				}
				echo '<td>' . $created_at_display . '</td>';

				echo '<td>';
				$edit_url = add_query_arg(['tmpl_action' => 'edit', 'tmpl_id' => $pid, 'lloc' => $lloc_slug], admin_url('admin.php?page=' . $page_slug));
				echo '<a class="button" href="' . esc_url($edit_url) . '">✏️ Editar</a> ';
				
				echo '<button type="button" class="button button-primary sportic-generate-template-pdf-btn" data-template-id="' . esc_attr($pid) . '" title="Generar vista setmanal en PDF/JPG">📄 PDF</button> ';
				
				$duplicate_url = add_query_arg(['tmpl_action' => 'duplicate', 'tmpl_id' => $pid, 'lloc' => $lloc_slug], admin_url('admin.php?page=' . $page_slug));
				echo '<a class="button button-secondary" href="' . esc_url($duplicate_url) . '">📋 Duplicar</a> ';

				$delete_url = add_query_arg(['tmpl_action' => 'delete', 'tmpl_id' => $pid, 'lloc' => $lloc_slug], admin_url('admin.php?page=' . $page_slug));
				echo '<a class="button button-danger" href="' . esc_url($delete_url) . '" onclick="return confirm(\'Segur que vols eliminar aquesta plantilla: ' . esc_js(stripslashes($p_data['name'])) . '?\');">🗑️ Eliminar</a> ';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody>';
			echo '</table>';
		}
		echo '</div>'; 
	}
}

add_action('admin_post_sportic_unfile_guardar_plantilla_completa','sportic_unfile_guardar_plantilla_completa_cb');
function sportic_unfile_guardar_plantilla_completa_cb() {
	// Seguretat: Nonce i Permisos
	if ( ! isset( $_POST['sportic_save_full_template_nonce'] ) || ! wp_verify_nonce( $_POST['sportic_save_full_template_nonce'], 'sportic_save_full_template_action' ) ) {
		wp_die('Error de seguretat (Nonce invàlid). Si us plau, torna enrere i intenta-ho de nou.');
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'No tens permisos per desar plantilles.' );
	}

	$lloc_slug     = isset( $_POST['lloc'] ) ? sanitize_key( $_POST['lloc'] ) : '';
	$tmpl_id       = isset( $_POST['tmpl_id'] ) ? sanitize_text_field( $_POST['tmpl_id'] ) : '';
	$nom_plantilla = isset( $_POST['tmpl_name'] ) ? stripslashes(sanitize_text_field( $_POST['tmpl_name'] )) : 'Sense nom';
	$tipus         = isset( $_POST['tmpl_type'] ) ? sanitize_text_field( $_POST['tmpl_type'] ) : 'day';
	$piscina_single_seleccionada = ($tipus === 'single' && isset($_POST['tmpl_piscina'])) ? sanitize_text_field($_POST['tmpl_piscina']) : 'infantil';
	
	if (empty($lloc_slug)) {
		wp_die('Error crític: No s\'ha pogut determinar el lloc per desar la plantilla.');
	}

	$plantilles = sportic_unfile_get_plantilles($lloc_slug);

	$is_new_template_flag = empty($tmpl_id);
	if ($is_new_template_flag) {
		$tmpl_id = uniqid('tmpl_');
	}
	
	$referer_url_args = ['page' => 'sportic-onefile-templates', 'tmpl_action' => 'edit', 'tmpl_id' => $tmpl_id, 'lloc' => $lloc_slug];
	$referer_url = add_query_arg($referer_url_args, admin_url('admin.php'));

	if (empty(trim($nom_plantilla))) {
		$redirect_url = add_query_arg( 'error_msg', urlencode( 'El nom de la plantilla no pot estar buit.' ), $referer_url );
		wp_redirect( $redirect_url );
		exit;
	}

	foreach ( $plantilles as $id_existent => $info_plantilla_existent ) {
		if (
			$id_existent !== $tmpl_id && 
			isset($info_plantilla_existent['name']) && strcasecmp( stripslashes($info_plantilla_existent['name']), stripslashes($nom_plantilla) ) === 0 &&
			isset( $info_plantilla_existent['type'] ) && $info_plantilla_existent['type'] === $tipus
		) {
			$error_message = 'Ja existeix una plantilla amb el nom "' . esc_html(stripslashes($nom_plantilla)) . '" per al tipus "' . esc_html($tipus) . '" en aquest lloc. Si us plau, utilitza un nom diferent.';
			$redirect_url = add_query_arg( 'error_msg', urlencode( $error_message ), $referer_url );
			if ($is_new_template_flag) {
				$redirect_url = add_query_arg( ['tmpl_name_val' => urlencode($nom_plantilla), 'tmpl_type_val' => $tipus], $redirect_url);
			}
			wp_redirect( $redirect_url );
			exit;
		}
	}

	$data_plantilla_actual = array(
		'name' => $nom_plantilla,
		'type' => $tipus,
		'data' => array() 
	);

	if ($is_new_template_flag) {
		$data_plantilla_actual['created_at'] = current_time('mysql', 0);
	} else {
		$data_plantilla_actual['created_at'] = $plantilles[$tmpl_id]['created_at'] ?? current_time('mysql', 0);
	}

	if ($tipus === 'single') {
		$data_plantilla_actual['piscina'] = $piscina_single_seleccionada;
	}

	$horaris_data_parsed = array();
	if ($tipus === 'day' && isset($_POST['sportic_tmpl_day_json'])) {
		$json_day = stripslashes($_POST['sportic_tmpl_day_json']);
		$data_day = json_decode($json_day, true);
		if (is_array($data_day)) {
			$horaris_data_parsed = sportic_unfile_parse_plantilla_data($data_day);
		}
	} elseif ($tipus === 'week' && isset($_POST['sportic_tmpl_week_json'])) {
		$json_week = stripslashes($_POST['sportic_tmpl_week_json']);
		$data_week = json_decode($json_week, true);
		if (is_array($data_week)) {
			$weekClean = array();
			foreach ($data_week as $dayIndex => $piscinesArr) {
				if (is_array($piscinesArr)) {
					$weekClean[$dayIndex] = sportic_unfile_parse_plantilla_data($piscinesArr);
				}
			}
			$horaris_data_parsed = $weekClean;
		}
	} elseif ($tipus === 'single' && isset($_POST['sportic_tmpl_single_json'])) {
		$json_single = stripslashes($_POST['sportic_tmpl_single_json']);
		$data_single_hores = json_decode($json_single, true);
		if (is_array($data_single_hores)) {
			$temp_data_to_parse = array( $piscina_single_seleccionada => $data_single_hores );
			$parsed_single = sportic_unfile_parse_plantilla_data($temp_data_to_parse);
			$horaris_data_parsed = $parsed_single[$piscina_single_seleccionada] ?? array();
		}
	}

	$data_plantilla_actual['data'] = $horaris_data_parsed;
	$plantilles[$tmpl_id] = $data_plantilla_actual;
	sportic_unfile_save_plantilles( $lloc_slug, $plantilles );

	$redirect_param_key = $is_new_template_flag ? 'created' : 'saved';
	$redirect_url_final = add_query_arg( $redirect_param_key, '1', $referer_url );
	wp_redirect( $redirect_url_final );
	exit;
}

if ( ! function_exists('sportic_unfile_mostrar_plantilla_day_tables') ) {
	function sportic_unfile_mostrar_plantilla_day_tables($tmpl_id, $tmpl_data) {
		// Si $tmpl_data és null (nova plantilla), inicialitzem dayDataFromTemplate com a array buit.
		$dayDataFromTemplate = (isset($tmpl_data['data']) && is_array($tmpl_data['data'])) ? $tmpl_data['data'] : array();
		
		$configured_pools = sportic_unfile_get_pool_labels_sorted();
		$finalDayDataToShow = array(); 
	
		foreach ($configured_pools as $slug => $p_info) {
			if (isset($dayDataFromTemplate[$slug]) && is_array($dayDataFromTemplate[$slug])) {
				$finalDayDataToShow[$slug] = $dayDataFromTemplate[$slug];
			} else {
				$finalDayDataToShow[$slug] = sportic_unfile_crear_programacio_default($slug);
			}
		}
		?>
		<div id="sportic-plantilla-day-editor-content"> <?php // Wrapper per al contingut de l'editor de dia ?>
			<h2 class="nav-tab-wrapper">
			<?php
			$first = true;
			foreach ($configured_pools as $slug => $pinfo) {
				$label = $pinfo['label'];
				$activeClass = $first ? 'nav-tab-active' : '';
				echo '<a href="#tmpl_day_pool_' . esc_attr($slug) . '" class="nav-tab ' . $activeClass . '">'
					. esc_html($label) . '</a>';
				$first = false;
			}
			?>
			</h2>
	
			<?php
			$first = true;
			foreach ($configured_pools as $slug => $pinfo) {
				$displayStyle = $first ? 'display:block;' : 'display:none;';
				echo '<div id="tmpl_day_pool_' . esc_attr($slug) . '" class="sportic-tab-content" style="' . $displayStyle . '">';
				sportic_unfile_mostrar_plantilla_day_table($slug, $finalDayDataToShow[$slug]);
				echo '</div>';
				$first = false;
			}
			?>
		</div>
		<script>
		// Aquest JS és específic per a les pestanyes de les piscines dins de l'editor de PLANTILLA DIA
		document.addEventListener('DOMContentLoaded', function(){
			var dayEditorContent = document.getElementById('sportic-plantilla-day-editor-content');
			if (dayEditorContent) {
				var dayPoolTabs = dayEditorContent.querySelectorAll('.nav-tab-wrapper .nav-tab');
				dayPoolTabs.forEach(function(tab){
					tab.addEventListener('click', function(e){
						e.preventDefault();
						var wrapper = tab.closest('.nav-tab-wrapper');
						if(wrapper) {
							wrapper.querySelectorAll('.nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });
						}
						// Amaga tots els continguts de piscina dins de l'editor de dia
						dayEditorContent.querySelectorAll('.sportic-tab-content').forEach(function(content){ content.style.display = 'none'; });
						
						tab.classList.add('nav-tab-active');
						var targetSelector = tab.getAttribute('href');
						var targetContent = dayEditorContent.querySelector(targetSelector);
						if(targetContent) {
							targetContent.style.display = 'block';
							// Aquí podries afegir re-escalat de text si fos necessari
						}
					});
				});
				// Activa la primera pestanya de piscina si n'hi ha
				var firstPoolTabDay = dayEditorContent.querySelector('.nav-tab-wrapper .nav-tab:first-child');
				if (firstPoolTabDay) {
					setTimeout(() => firstPoolTabDay.click(), 0);
				}
			}
		});
		</script>
		<?php
	}
}


if ( ! function_exists('sportic_unfile_mostrar_plantilla_week_tables') ) {
	function sportic_unfile_mostrar_plantilla_week_tables($tmpl_id, $tmpl_data) {
		$weekDataFromTemplate = (isset($tmpl_data['data']) && is_array($tmpl_data['data'])) ? $tmpl_data['data'] : array_fill(0, 7, []);
		$configured_pools = sportic_unfile_get_pool_labels_sorted();
		$finalWeekDataToShow = array_fill(0, 7, []); 

		for ($i = 0; $i < 7; $i++) { 
			if (!is_array($finalWeekDataToShow[$i])) { $finalWeekDataToShow[$i] = []; }
			foreach ($configured_pools as $slug => $p_info) {
				if (isset($weekDataFromTemplate[$i], $weekDataFromTemplate[$i][$slug]) && is_array($weekDataFromTemplate[$i][$slug])) {
					$finalWeekDataToShow[$i][$slug] = $weekDataFromTemplate[$i][$slug];
				} else {
					$finalWeekDataToShow[$i][$slug] = sportic_unfile_crear_programacio_default($slug);
				}
			}
		}
		$diasSetmanaLabels = array('Dilluns', 'Dimarts', 'Dimecres', 'Dijous', 'Divendres', 'Dissabte', 'Diumenge');
		?>
		<div id="sportic-plantilla-week-editor-content"> <?php // Wrapper per a l'editor de setmana ?>
			<h2 class="nav-tab-wrapper sportic-top-tabs">
			<?php
			for ($i = 0; $i < 7; $i++) {
				$activeClass = ($i === 0) ? 'nav-tab-active' : '';
				echo '<a href="#tmpl_week_day_content_' . $i . '" class="nav-tab ' . $activeClass . '" data-day-index="' . $i . '">'
					. esc_html($diasSetmanaLabels[$i]) . '</a>';
			}
			?>
			</h2>

			<?php
			foreach ($finalWeekDataToShow as $day_index => $piscines_del_dia):
				$displayStyle = ($day_index === 0) ? 'display:block;' : 'display:none;';
				?>
				<div id="tmpl_week_day_content_<?php echo $day_index; ?>" class="sportic-tab-content sportic-week-day-pane" style="<?php echo $displayStyle; ?>">
					<h2 class="nav-tab-wrapper sportic-sub-tabs">
					<?php
					$firstSub = true;
					foreach ($configured_pools as $slug => $pinfo) {
						$label = $pinfo['label'];
						$activeClass2 = $firstSub ? 'nav-tab-active' : '';
						echo '<a href="#tmpl_week_day_' . $day_index . '_pool_' . esc_attr($slug) . '" class="nav-tab ' . $activeClass2 . '" data-pool-slug="' . esc_attr($slug) . '">'
							. esc_html($label) . '</a>';
						$firstSub = false;
					}
					?>
					</h2>
					<div class="sportic-subtabs-container">
					<?php
					$firstSub = true;
					foreach ($configured_pools as $slug => $pinfo) {
						$displayStyle2 = $firstSub ? 'display:block;' : 'display:none;';
						$data_piscina_attr = "week_{$day_index}_{$slug}"; 
						$dades_piscina_dia_actual = $piscines_del_dia[$slug] ?? sportic_unfile_crear_programacio_default($slug);
						echo '<div id="tmpl_week_day_' . $day_index . '_pool_' . esc_attr($slug) . '" class="sportic-subtab-content sportic-week-pool-pane" style="' . $displayStyle2 . '">';
						sportic_unfile_mostrar_plantilla_day_table( $data_piscina_attr, $dades_piscina_dia_actual );
						echo '</div>';
						$firstSub = false;
					}
					?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<script>
		// Aquest JS és específic per a les pestanyes dins de l'editor de PLANTILLA SETMANA
		document.addEventListener('DOMContentLoaded', function(){
			var weekEditorContent = document.getElementById('sportic-plantilla-week-editor-content');
			if (weekEditorContent) {
				function initializeWeekTabs() {
					// Pestanyes superiors (Dies de la setmana)
					var topTabLinks = weekEditorContent.querySelectorAll('.sportic-top-tabs > .nav-tab');
					topTabLinks.forEach(function(tab){
						tab.addEventListener('click', function(e){
							e.preventDefault();
							var parentWrapper = tab.closest('.sportic-top-tabs');
							if(parentWrapper) parentWrapper.querySelectorAll('.nav-tab').forEach(function(x){ x.classList.remove('nav-tab-active'); });
							weekEditorContent.querySelectorAll('.sportic-week-day-pane').forEach(function(dc){ dc.style.display = 'none'; });
							tab.classList.add('nav-tab-active');
							var targetId = tab.getAttribute('href'); 
							var targetEl = weekEditorContent.querySelector(targetId); 
							if (targetEl) {
								targetEl.style.display = 'block';
								var firstSubTabInDay = targetEl.querySelector('.sportic-sub-tabs > .nav-tab');
								if (firstSubTabInDay) { setTimeout(() => firstSubTabInDay.click(), 0); }
							}
						});
					});

					// Sub-Pestanyes (Piscines dins de cada dia)
					var subTabLinks = weekEditorContent.querySelectorAll('.sportic-sub-tabs > .nav-tab');
					subTabLinks.forEach(function(tab){
						tab.addEventListener('click', function(e){
							e.preventDefault();
							var subWrapper = tab.closest('.sportic-sub-tabs');
							if(subWrapper) subWrapper.querySelectorAll('.nav-tab').forEach(function(x){ x.classList.remove('nav-tab-active'); });
							var dayPane = tab.closest('.sportic-week-day-pane'); 
							if (dayPane) { dayPane.querySelectorAll('.sportic-week-pool-pane').forEach(function(stc){ stc.style.display = 'none'; }); }
							tab.classList.add('nav-tab-active');
							var subTargetId = tab.getAttribute('href'); 
							var subTargetEl = weekEditorContent.querySelector(subTargetId); 
							if (subTargetEl) { subTargetEl.style.display = 'block'; }
						});
					});

					var firstTopTab = weekEditorContent.querySelector('.sportic-top-tabs > .nav-tab:first-child');
					if (firstTopTab && !weekEditorContent.querySelector('.sportic-top-tabs > .nav-tab.nav-tab-active')) {
						setTimeout(() => firstTopTab.click(), 0);
					} else {
						var activeTopTabLink = weekEditorContent.querySelector('.sportic-top-tabs > .nav-tab.nav-tab-active');
						if (activeTopTabLink) {
							var activeTopTabContentId = activeTopTabLink.getAttribute('href');
							if (activeTopTabContentId) {
								var activeDayPane = weekEditorContent.querySelector(activeTopTabContentId);
								if (activeDayPane) {
									var firstSubTabInActiveDay = activeDayPane.querySelector('.sportic-sub-tabs > .nav-tab:first-child');
									if (firstSubTabInActiveDay && !activeDayPane.querySelector('.sportic-sub-tabs > .nav-tab.nav-tab-active')) {
										setTimeout(() => firstSubTabInActiveDay.click(), 0);
									}
								}
							}
						}
					}
				}
				initializeWeekTabs(); // Crida la funció per configurar les pestanyes setmanals
			}
		});
		</script>
		<?php
	}
}



if ( ! function_exists('sportic_unfile_mostrar_plantilla_single_table') ) {
	function sportic_unfile_mostrar_plantilla_single_table($tmpl_id, $tmpl_data) {
		$piscina_actualmente_seleccionada_en_plantilla = isset($tmpl_data['piscina']) ? $tmpl_data['piscina'] : 'infantil'; 
		$datos_horarios_plantilla = (isset($tmpl_data['data']) && is_array($tmpl_data['data'])) ? $tmpl_data['data'] : array();
		
		$allConfiguredPools = sportic_unfile_get_pool_labels_sorted();
		?>
		<div id="sportic-plantilla-single-editor-content">  <?php // Wrapper principal per a l'editor 'single' ?>
			<?php
			if (empty($allConfiguredPools)) {
				echo '<p>No hi ha pavellons configurats per mostrar a l\'editor de plantilla individual.</p>';
			} else {
				foreach ($allConfiguredPools as $slug_piscina => $pool_info) {
					$datos_a_usar_para_esta_tabla = array();
					// Si la plantilla que s'està editant correspon a AQUESTA piscina, usem les seves dades.
					if ($slug_piscina === $piscina_actualmente_seleccionada_en_plantilla && !empty($datos_horarios_plantilla) ) {
						$datos_a_usar_para_esta_tabla = $datos_horarios_plantilla;
					} else {
						// Altrament (és una altra piscina, o la plantilla no té dades), generem la default per aquesta.
						$datos_a_usar_para_esta_tabla = sportic_unfile_crear_programacio_default($slug_piscina);
					}

					// La visibilitat inicial depèn de si és la piscina seleccionada en carregar la plantilla
					$display_style_tabla_piscina = ($slug_piscina === $piscina_actualmente_seleccionada_en_plantilla) ? 'display:block;' : 'display:none;';
					?>
					<div class="single-pool-schedule-editor" 
						 id="single-pool-editor-<?php echo esc_attr($slug_piscina); ?>" 
						 data-pool-slug-editor="<?php echo esc_attr($slug_piscina); ?>" 
						 style="<?php echo $display_style_tabla_piscina; ?>">
						
						<h3 style="margin-top: 15px; margin-bottom:10px; font-size: 1.2em;">
							Horaris per al pavelló: <strong><?php echo esc_html($pool_info['label']); ?></strong>
						</h3>
						<?php
						sportic_unfile_mostrar_plantilla_day_table($slug_piscina, $datos_a_usar_para_esta_tabla);
						?>
					</div>
					<?php
				}
			}
			?>
		</div>
		<?php
	}
}


// ---------------------------------------------------------------------------
//  * FUNCIO NOVA: carregar una finestra de dates                               *
//  * ------------------------------------------------------------------------
// if ( ! function_exists( 'sportic_carregar_finestra' ) ) {
// 		/**
// 		 * Torna les dades entre ($data - $dies_enrere) i
// 		 *                     ($data + $dies_endavant)
// 		 *
// 		 * @param string $data_base      YYYY-mm-dd
// 		 * @param int    $dies_endavant  dies futurs   (defecte 7)
// 		 * @param int    $dies_enrere    dies passats  (defecte 6)
// 		 * @return array mateix format que sportic_carregar_tot_com_array()
// 		 */
// 		function sportic_carregar_finestra( $data_base, $dies_endavant = 7, $dies_enrere = 6 ) {
// 				// Reutilitzem la funció existent sense duplicar lògica:
// 				$tot = sportic_carregar_tot_com_array();   // carrega cache/global
// 
// 				// Calculem el rang
// 				$ini = date( 'Y-m-d', strtotime( "-$dies_enrere days", strtotime( $data_base ) ) );
// 				$fi  = date( 'Y-m-d', strtotime( "+$dies_endavant days", strtotime( $data_base ) ) );
// 
// 				// Filtra piscines i dies dins del rang
// 				$out = [];
// 				foreach ( $tot as $slug => $dies ) {
// 						foreach ( $dies as $d => $hores ) {
// 								if ( $d >= $ini && $d <= $fi ) {
// 										$out[ $slug ][ $d ] = $hores;
// 								}
// 						}
// 				}
// 				return $out;
// 		}








/* ---------------------------------------------------------------------------
 * FUNCIO NOVA: carregar directament BD per a un rang dinàmic (frontend)
 * ------------------------------------------------------------------------ */
if ( ! function_exists( 'sportic_carregar_finestra_bd' ) ) {
	 /**
	  * Torna dades + bloquejos entre ($data_base - $dies_enrere)
	  * i     ($data_base + $dies_endavant)
	  *
	  * @param string $data_base      YYYY-mm-dd
	  * @param int    $dies_endavant  dies futurs   (defecte 7)
	  * @param int    $dies_enrere    dies passats  (defecte 6)
	  * @return array mateix format que sportic_carregar_tot_com_array()
	  */
function sportic_carregar_finestra_bd( $data_base, $dies_endavant = 7, $dies_enrere = 6 ) {
			global $wpdb;
			$ini = date('Y-m-d', strtotime("-$dies_enrere days", strtotime($data_base)));
			$fi  = date('Y-m-d', strtotime("+$dies_endavant days", strtotime($data_base)));
		
			$t_prog  = $wpdb->prefix . 'sportic_programacio';
			$t_lock  = $wpdb->prefix . (defined('SPORTIC_LOCK_TABLE') ? SPORTIC_LOCK_TABLE : 'sportic_bloqueig');
			
			$piscines = sportic_unfile_get_pool_labels_sorted();
			$slugs    = array_keys($piscines);
			$ret      = array_fill_keys($slugs, []);
		
			$sql = $wpdb->prepare("SELECT piscina_slug, dia_data, hores_serial FROM $t_prog WHERE dia_data BETWEEN %s AND %s", $ini, $fi);
			$rows = $wpdb->get_results($sql, ARRAY_A);
			foreach ($rows as $r) {
				if (!in_array($r['piscina_slug'], $slugs, true)) continue;
				$hores = @maybe_unserialize($r['hores_serial']);
				if (!is_array($hores)) $hores = sportic_unfile_crear_programacio_default($r['piscina_slug']);
				$ret[$r['piscina_slug']][$r['dia_data']] = $hores;
			}
		
			$period = new DatePeriod(new DateTime($ini), new DateInterval('P1D'), (new DateTime($fi))->modify('+1 day'));
			foreach ($slugs as $slug) {
				foreach ($period as $date_obj) {
					$d = $date_obj->format('Y-m-d');
					if (!isset($ret[$slug][$d])) {
						$ret[$slug][$d] = sportic_unfile_crear_programacio_default($slug);
					}
				}
			}
		
			// NOTA: Eliminat el PAS 1 d'esdeveniments recurrents i excepcions.
		
			// PAS 2: Apliquem bloquejos manuals (sense canvis)
			$locks = $wpdb->get_results($wpdb->prepare("SELECT piscina_slug, dia_data, hora, carril_index FROM $t_lock WHERE dia_data BETWEEN %s AND %s", $ini, $fi), ARRAY_A);
			$map = [];
			foreach ($locks as $l) $map[$l['piscina_slug']][$l['dia_data']][$l['hora']][intval($l['carril_index'])] = true;
			
			foreach ($ret as $slug => &$dies) {
				if (!isset($map[$slug])) continue;
				foreach ($dies as $d => &$hores) {
					if (!isset($map[$slug][$d])) continue;
					foreach ($hores as $h => &$carrils) {
						if (!isset($map[$slug][$d][$h])) continue;
						foreach ($carrils as $idx => &$val) {
							if (isset($map[$slug][$d][$h][$idx]) && strpos($val, '!') !== 0) {
								$val = '!' . $val;
							}
						}
						unset($val);
					}
					unset($carrils);
				}
				unset($hores);
			}
			unset($dies);
			
			return $ret;
		}
	   }


 



/**
 * ========================================================================
 * INICI NOVES FUNCIONS PER A LA GENERACIÓ DE PDF DE PLANTILLES (v7 - Final)
 * ========================================================================
 */

/**
 * Funció auxiliar per renderitzar un bloc de dia buit (placeholder)
 * que manté l'alçada per a la maquetació DIN A3.
 */
if ( ! function_exists('_sportic_render_placeholder_day_dina3_v2') ) {
	 function _sportic_render_placeholder_day_dina3_v2($day_label) {
		 ob_start();
		 ?>
		 <div class="sw_day" style="min-height: 2000px; visibility: hidden;">
			 <!-- Aquest bloc és invisible però manté l'espai per a la maquetació -->
			 <h3 style="margin-bottom:28px;font-size:7rem;font-weight:bold;text-align:center;border-bottom:2px solid #eee;">
				 <?php echo esc_html($day_label); ?>
			 </h3>
		 </div>
		 <?php
		 return ob_get_clean();
	 }
 }

add_action('wp_ajax_sportic_get_template_for_dina3_pdf_v7', 'sportic_ajax_get_template_for_dina3_pdf_v7');
 function sportic_ajax_get_template_for_dina3_pdf_v7() {
	 check_ajax_referer('sportic_get_template_pdf_nonce', 'nonce');
	 if (!current_user_can('manage_options')) {
		 wp_send_json_error('No tens permisos.');
	 }
 
	 $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : '';
	 $all_templates = sportic_unfile_get_plantilles();
	 if (empty($template_id) || !isset($all_templates[$template_id])) {
		 wp_send_json_error('Plantilla no vàlida.');
	 }
 
	 $template = $all_templates[$template_id];
	 $template_type = $template['type'] ?? 'day';
	 $template_data = $template['data'] ?? [];
	 $template_name = $template['name'] ?? 'plantilla';
 
	 if (empty($template_data)) {
		 wp_send_json_error('La plantilla no té dades.');
	 }
 
	 $piscines = sportic_unfile_get_pool_labels_sorted();
	 $leg = [
		 'l' => ['color' => '#ffffff', 'title' => 'Lliure (l)'],
		 'o' => ['color' => '#ff9999', 'title' => 'Ocupat (o)'],
		 'b' => ['color' => '#b9b9b9', 'title' => 'Bloquejat (b)'],
	 ];
	 $pers = get_option('sportic_unfile_custom_letters', array());
	 if (is_array($pers)) {
		 foreach ($pers as $ci) {
			 if (!isset($ci['letter'])) continue;
			 $let = strtolower(trim($ci['letter']));
			 if (in_array($let, ['l','o','b'])) continue;
			 $leg[$let] = ['color' => $ci['color'] ?? '#dddddd', 'title' => ($ci['title'] ?? 'Personalitzada').' ('.$let.')'];
		 }
	 }
 
	 $html_dias = [];
	 $dias_semana_labels = ['Dilluns', 'Dimarts', 'Dimecres', 'Dijous', 'Divendres', 'Dissabte', 'Diumenge'];
	 
	 $main_pdf_title = '';
	 $show_individual_day_title = false; // Per defecte, no mostrem títol de dia
 
	 for ($i = 0; $i < 7; $i++) {
		 $fake_date = "2024-01-0" . ($i + 1);
		 $dades_del_dia_a_renderitzar = [];
		 $piscines_a_renderitzar_avui = array_keys($piscines);
		 
		 $render_content = false;
 
		 if ($template_type === 'week') {
			 $main_pdf_title = 'Plantilla Setmanal';
			 $show_individual_day_title = true; // <-- CANVI CLAU: Només activem el títol aquí
			 $render_content = true;
			 foreach ($piscines as $slug => $pool_info) {
				 $dades_del_dia_a_renderitzar[$slug][$fake_date] = $template_data[$i][$slug] ?? sportic_unfile_crear_programacio_default($slug);
			 }
		 } elseif ($template_type === 'day') {
			 $main_pdf_title = 'Plantilla Diària';
			 if ($i === 0) {
				 $render_content = true;
				 foreach ($piscines as $slug => $pool_info) {
					 $dades_del_dia_a_renderitzar[$slug][$fake_date] = $template_data[$slug] ?? sportic_unfile_crear_programacio_default($slug);
				 }
			 }
		 } elseif ($template_type === 'single') {
			 $main_pdf_title = 'Plantilla de Pavelló Individual';
			 if ($i === 0) {
				 $render_content = true;
				 $pool_slug_single = $template['piscina'] ?? null;
				 $piscines_a_renderitzar_avui = $pool_slug_single ? [$pool_slug_single] : [];
				 if ($pool_slug_single) {
					 $dades_del_dia_a_renderitzar[$pool_slug_single][$fake_date] = $template_data;
				 }
			 }
		 }
 
		 if ($render_content) {
			 // <-- CANVI CLAU: Passem el nou paràmetre -->
			 $html_dias[] = _sportic_day_html_dina3_for_pdf($fake_date, $dades_del_dia_a_renderitzar, $piscines, $leg, $piscines_a_renderitzar_avui, $show_individual_day_title);
		 } else {
			 $html_dias[] = _sportic_render_placeholder_day_dina3_v2($dias_semana_labels[$i]);
		 }
	 }
 
	 $logo_url = plugin_dir_url(__FILE__) . 'imatges/logo2.jpg';
	 $logo3_url = plugin_dir_url(__FILE__) . 'imatges/logo3.jpg';
 
	 ob_start();
	 ?>
	 <style>
		 .pdf-h3 { margin-bottom:28px !important; font-size:7rem !important; font-weight:bold !important; text-align:center !important; border-bottom:2px solid #eee !important; }
		 .pdf-h4 { margin:0 0 20px !important; text-align:center !important; font-size:1.8rem !important; white-space:nowrap !important; overflow:hidden !important; text-overflow:ellipsis !important; }
		 .pdf-pool-row { display:flex !important; gap:38px !important; flex-wrap:nowrap !important; justify-content:flex-start !important; }
		 .pdf-pool-block { display:inline-block !important; padding:12px 0 !important; }
		 .pdf-table { border-collapse:collapse !important; margin:0 auto !important; table-layout:fixed !important; }
		 .pdf-table thead tr { background:#d8d8d8 !important; font-size: 2.0rem !important; }
		 .pdf-th { border:2px solid #555 !important; padding:10px !important; width:70px !important; }
		 .pdf-th:first-child { width:110px !important; }
		 .pdf-td { border:2px solid #555 !important; padding:10px !important; text-align:center !important; position:relative !important; }
		 .pdf-hour-cell { font-size: 1.8rem !important; width:110px !important; }
		 .pdf-data-cell { font-size: 1.6rem !important; width:70px !important; }
	 </style>
 
	 <div style="padding: 40px 80px;">
		 <div style="position:relative;">
			 <img src="<?php echo esc_url($logo_url); ?>" class="logo2-dina3" style="bottom:750px !important;" alt="Logo2"/>
			 <img src="<?php echo esc_url($logo3_url); ?>" class="logo3-dina3" alt="Logo3"/>
			 
			 <h2 style="font-size: 8rem; text-align: center; margin-bottom: 40px; font-weight: bold;"><?php echo esc_html($main_pdf_title); ?></h2>
 
			 <div class="dina3-row">
				 <div><?php echo $html_dias[0]; ?></div>
				 <div><?php echo $html_dias[1]; ?></div>
				 <div><?php echo $html_dias[2]; ?></div>
			 </div>
			 <div class="dina3-row">
				 <div><?php echo $html_dias[3]; ?></div>
				 <div><?php echo $html_dias[4]; ?></div>
				 <div><?php echo $html_dias[5]; ?></div>
			 </div>
			 <div class="dina3-row">
				 <div><?php echo $html_dias[6]; ?></div>
				 <div></div><div></div>
			 </div>
 
			 <div style="margin-top:40px;border-top:5px solid #aaa;padding-top:40px;text-align:center;">
				 <h4 style="font-size:4rem;margin-bottom:48px;font-weight:bold;">Llegenda</h4>
				 <div style="display:inline-flex;gap:70px 60px;flex-wrap:wrap;justify-content:center;">
				 <?php foreach ($leg as $l => $inf): ?>
					 <div style="display:flex;align-items:center;gap:24px;font-size:5rem;">
					 <span style="width:75px;height:75px;background:<?php echo esc_attr($inf['color']); ?>;border:<?php echo $l === 'l' ? '3' : '2'; ?>px solid #<?php echo $l === 'l' ? '777' : 'bbb'; ?>;"></span>
					 <?php echo esc_html($inf['title']); ?>
					 </div>
				 <?php endforeach; ?>
				 </div>
			 </div>
		 </div>
	 </div>
 
	 <?php
	 $final_html = ob_get_clean();
 
	 wp_send_json_success([
		 'html' => $final_html,
		 'filename' => sanitize_file_name($template_name) . '-DINA3.jpg'
	 ]);
 }


/**
 * ========================================================================
 * FI FUNCIÓ MODIFICADA
 * ========================================================================
 */

/**
 * [NOVA - Per al PDF] Versió de _sportic_day_html_dina3 amb fonts més grans.
 * Aquesta funció NOMÉS serà cridada per la generació del PDF de plantilles,
 * garantint que no afecta cap altra part del web.
 */
if ( ! function_exists('_sportic_day_html_dina3_for_pdf') ) {
	 function _sportic_day_html_dina3_for_pdf($dia, $dades, $pisc, $leg, $pools_to_render_slugs = null, $show_day_title = true) {
		 
		 if ($pools_to_render_slugs === null) {
			 $pools_to_render_slugs = ['p12_20','p6','p4','infantil'];
		 }
 
		 $dies  = ['Diumenge','Dilluns','Dimarts','Dimecres','Dijous','Divendres','Dissabte'];
 
		 try { $o = new DateTime($dia); $nice = $o->format('d-m-Y'); $nom = $dies[(int)$o->format('w')]; }
		 catch (Exception $e) { $nice = 'Data invàlida'; $nom = ''; }
		 
		 if (!function_exists('_sportic_pdf_contrast_helper')) {
			 function _sportic_pdf_contrast_helper($hexcolor){
				 $r = hexdec(substr($hexcolor, 1, 2));
				 $g = hexdec(substr($hexcolor, 3, 2));
				 $b = hexdec(substr($hexcolor, 5, 2));
				 $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
				 return $luminance > 0.5 ? '#000000' : '#ffffff';
			 }
		 }
 
		 ob_start(); ?>
		 <div class="sw_day" data-day="<?php echo esc_attr($dia); ?>">
 
		   <?php // <-- INICI DEL CANVI: Només mostrem el títol si $show_day_title és true -->
		   if ($show_day_title): ?>
		   <h3 style="margin-bottom:28px !important; font-size:7rem !important; font-weight:bold !important; text-align:center !important; border-bottom:2px solid #eee !important;">
			 <?php echo esc_html(($nom ? $nom.', ' : '').$nice); ?>
		   </h3>
		   <?php endif; 
		   // <-- FI DEL CANVI --> ?>
 
		   <div style="display:flex !important; gap:38px !important; flex-wrap:nowrap !important; justify-content:flex-start !important;">
			 <?php foreach ($pools_to_render_slugs as $s):
			   if ( ! isset($pisc[$s]) ) continue;
			   $lab = $pisc[$s]['label'] ?? ucfirst($s);
			   $pr  = (isset($dades[$s][$dia]) && is_array($dades[$s][$dia]))
						 ? $dades[$s][$dia] : sportic_unfile_crear_programacio_default($s);
			   if ( ! $pr ) continue;
			   $hores = array_keys($pr); sort($hores);
			   
			   $cols = 0;
			   if (!empty($hores)) {
				 $first_hour_data = $pr[$hores[0]] ?? [];
				 if (is_array($first_hour_data)) {
					 $cols = count($first_hour_data);
				 }
			   }
			   if ($cols === 0) continue;
 
			   $w = _sportic_calcular_width_px_for_pdf($cols); ?>
			   <div style="display:inline-block !important; padding:12px 0 !important;">
				 <h4 style="margin:0 0 20px !important; text-align:center !important; font-size:1.8rem !important; white-space:nowrap !important; overflow:hidden !important; text-overflow:ellipsis !important;">
					 <?php echo esc_html($lab); ?>
				 </h4>
				 <div style="display:inline-block !important;">
				   <div style="min-width:<?php echo $w; ?>px !important;">
					 <table style="border-collapse:collapse !important; margin:0 auto !important; table-layout:fixed !important;">
					   <thead>
						 <tr style="background:#d8d8d8 !important; font-size: 2.0rem !important;">
						   <th style="border:2px solid #555 !important; padding:10px !important; width:110px !important;">Hora</th>
						   <?php for ($c=1;$c<=$cols;$c++): ?>
							 <th style="border:2px solid #555 !important; padding:10px !important; width:70px !important;"><?php echo $c; ?></th>
						   <?php endfor; ?>
						 </tr>
					   </thead>
					   <tbody>
						 <?php foreach ($hores as $h):
						   if ( ! sportic_unfile_is_time_in_open_range($h) ) continue;
						   echo '<tr>';
						   echo '<td class="sportic-hour-cell" style="border:2px solid #555 !important; padding:10px !important; text-align:center !important; width:110px !important; font-size: 1.8rem !important;">'.esc_html($h).'</td>';
						   
						   $carrils_del_dia = $pr[$h] ?? array_fill(0, $cols, 'l');
						   if (count($carrils_del_dia) < $cols) { $carrils_del_dia = array_pad($carrils_del_dia, $cols, 'l'); }
						   elseif (count($carrils_del_dia) > $cols) { $carrils_del_dia = array_slice($carrils_del_dia, 0, $cols); }
 
						   foreach ($carrils_del_dia as $v) {
							   $isRecurrent = false;
							   $valorBase = $v;
							   if (is_string($v) && strpos($v, '@') === 0) {
								   $isRecurrent = true;
								   $valorBase = substr($v, 1);
							   } elseif (is_string($v) && strpos($v, '!') === 0) {
								   $valorBase = substr($v, 1);
							   }
							   
							   $raw = strtolower(trim($valorBase));
							   $let = strpos($raw,':') !== false ? trim(explode(':',$raw)[0]) : $raw;
							   $bg  = $leg[$let]['color'] ?? $leg['l']['color'];
							   $tit = $leg[$let]['title'] ?? $leg['l']['title'];
							   
							   $text_color = _sportic_pdf_contrast_helper($bg);
 
							   $txt = '';
							   if (!in_array($let, ['l', 'b'])) {
								   $original_value = trim($valorBase);
								   if (strpos($original_value, ':') !== false) {
									   list($code_part, $desc_part) = explode(':', $original_value, 2);
									   $txt = strtoupper(trim($code_part)) . ':' . trim($desc_part);
								   } else {
									   $txt = strtoupper($original_value);
								   }
							   }
							   
							   echo '<td title="'.esc_attr($tit).'" style="border:2px solid #555 !important; padding:10px !important; text-align:center !important; background-color:'.$bg.' !important; color:'.$text_color.' !important; width:70px !important; position:relative !important; font-size: 1.6rem !important; font-weight: bold !important;">'.esc_html($txt).'';
 
							   if ($isRecurrent) {
								   echo '<i class="fas fa-sync-alt" style="position:absolute;bottom:2px;left:2px;font-size:12px;color:rgba(0,0,0,0.4);"></i>';
							   }
							   echo '</td>';
						   }
						   echo '</tr>';
						 endforeach; ?>
					   </tbody>
					 </table>
				   </div>
				 </div>
			   </div>
			 <?php endforeach; ?>
		   </div>
		 </div><?php
		 return ob_get_clean();
	 }
 }
 
 /**
  * ========================================================================
  * FI FUNCIÓ MODIFICADA
  * ========================================================================
  */

/**
 * [NOVA - Per al PDF] Versió de _sportic_render_placeholder_day_dina3_v2 amb fonts més grans.
 */
if ( ! function_exists('_sportic_render_placeholder_day_dina3_for_pdf') ) {
	function _sportic_render_placeholder_day_dina3_for_pdf($day_label) {
		ob_start();
		?>
		<div class="sw_day" style="min-height: 2000px; display: flex; flex-direction: column;">
			<h3 style="margin-bottom:28px;font-size:7rem;font-weight:bold;text-align:center;border-bottom:2px solid #eee;">
				<?php echo esc_html($day_label); ?>
			</h3>
			<div style="flex-grow: 1; display:flex; justify-content:center; align-items:center; color: #e0e0e0; font-size: 4rem;">
				(No aplicable)
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

/**
 * ========================================================================
 * FI FUNCIONS NOVES
 * ========================================================================
 */
 
 
 /**
  * ========================================================================
  * INICI NOVA FUNCIÓ - AFEGEIX-LA AL TEU CODI
  * Aquesta funció NOMÉS s'utilitzarà per al PDF de plantilles.
  * ========================================================================
  */
 if ( ! function_exists('_sportic_calcular_width_px_for_pdf') ) {
	 function _sportic_calcular_width_px_for_pdf($n) { 
		 // L'ample de la cel·la de l'hora és 110px.
		 // L'ample de cada cel·la de carril és 90px.
		 return 110 + 70*intval($n); 
	 }
 }


/* =========================================================================
 * INICI NOVA FUNCIÓ – AFEGEIX-LA AL TEU CODI
 * Aquesta funció s'activa només quan hi ha un conflicte.
 * =========================================================================*/
/**
 * Modifica la URL de redirecció en cas de conflicte.
 *
 * @param string $location URL de redirecció original.
 * @param int    $post_id  ID del post.
 * @return string          URL de redirecció modificada.
 */
function sportic_modify_redirect_on_conflict( $location, $post_id ) {
	// Traiem el missatge d'èxit per defecte de WordPress (ex: "?message=1")
	$location = remove_query_arg( 'message', $location );
	// Afegim el nostre paràmetre per saber que hi ha hagut un error
	$location = add_query_arg( 'sportic_conflict', '1', $location );
	return $location;
}

// ================================================================
// INICI: AFEGIR FONTS PERSONALITZADES ALS TÍTOLS I CONTINGUTS
// ================================================================

add_action('admin_head', 'sportic_afegir_estils_personalitzats');

function sportic_afegir_estils_personalitzats() {
	// Comprovem que estem a la pàgina principal del plugin per no carregar-ho a tot arreu
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sportic-onefile-menu' ) {
		return;
	}
	?>
	<!-- 1. Importem les fonts amb TOTS els gruixos necessaris -->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@1,300;1,400;1,600&family=Space+Grotesk:wght@500&display=swap" rel="stylesheet">

	<!-- 2. Apliquem els estils -->
	<style>
		/* --- Font Barlow Condensed per als Títols Principals I PESTANYES DE NAVEGACIÓ --- */
		.sportic-column-header h2,
		#tab-llegenda,
		#tab-plant,
		#sportic-cal-title-clickable,
		.sportic-secondary-tabs-wrapper .sportic-folder-tab {
			font-family: 'Barlow Condensed', sans-serif !important;
			font-weight: 400 !important;
			font-style: italic !important;
			font-size: 2em !important;
		}
		
		.sportic-main-tabs-wrapper .nav-tab { font-family: 'Barlow Condensed', sans-serif !important; font-weight: 400 !important; font-style: italic !important; font-size: 1.3em !important; }

		/* --- Font Space Grotesk per al Calendari i la Llegenda --- */
		.sportic-calendari td.cal-day a span,
		.sportic-calendari .cal-table thead th {
			font-family: 'Space Grotesk', sans-serif !important;
			font-weight: 500 !important;
			font-size: 1em;
		}

		.sportic-legend-core {
			font-family: 'Space Grotesk', sans-serif !important;
			font-weight: 500 !important;
		}

		.sportic-legend-core h3 {
			font-size: 1.05rem !important;
		}
		
		/* --- Font Space Grotesk per a BOTONS D'ACCIÓ I TÍTOLS DE TAULA --- */
		.sportic-action-btn-save,
		.sportic-toolbar .button,
		.week-header,
		.sportic-dia-content h3 {
			font-family: 'Space Grotesk', sans-serif !important;
			font-weight: 500 !important;
			font-style: normal !important;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		
		div#sportic-cal-title-clickable {
			font-size: 1.8em !important;
		}
		
		a.nav-tab.sportic-folder-tab.sportic-main-tab {
			font-size: 1.55em !important;
			font-weight: 400 !important;
		}
		
		button.sportic-folder-tab.sportic-secondary-tab {
			font-size: 1.4em !important;
		}
		
		.sportic-tab-content .week-header {
			font-size: 1.2em !important;
		}
		
		.sportic-dia-content h3 {
			font-weight: normal !important;
			font-size: 1.2em !important;
		}
		
	</style>
	<?php
}

// ================================================================
// FI: AFEGIR FONTS PERSONALITZADES
// ================================================================


/******************************************************************
 * FUNCIÓ PER GESTIONAR LA PETICIÓ AJAX DE LES SETMANES (CORREGIDA)
 ******************************************************************/
add_action('wp_ajax_sportic_get_week_html', 'sportic_get_week_html_callback');
add_action('wp_ajax_nopriv_sportic_get_week_html', 'sportic_get_week_html_callback');

function sportic_get_week_html_callback() {
	// Validació de seguretat
	check_ajax_referer('sportic_week_nonce', 'nonce');

	// Recollir i netejar paràmetres
	$week_start_str = isset($_POST['week_start']) ? sanitize_text_field($_POST['week_start']) : current_time('Y-m-d');
	$team_code = isset($_POST['team_code']) ? sanitize_title($_POST['team_code']) : '';
	$shortcode_title = isset($_POST['shortcode_title']) ? sanitize_text_field($_POST['shortcode_title']) : '';

	// Assegurem que la data rebuda SEMPRE es converteixi al dilluns d'aquella setmana.
	try {
		$date_obj = new DateTime($week_start_str);
		$date_obj->modify('monday this week');
		$week_start_str = $date_obj->format('Y-m-d');
	} catch (Exception $e) {
		$date_obj = new DateTime(current_time('Y-m-d'));
		$date_obj->modify('monday this week');
		$week_start_str = $date_obj->format('Y-m-d');
	}

	if (empty($team_code)) {
		wp_send_json_error(['message' => 'Falta el codi de l\'equip.']);
	}

	$teams = sportic_get_custom_activities_indexed();
	if (!isset($teams[$team_code])) {
		wp_send_json_error(['message' => 'Equip no trobat.']);
	}
	
	$team = $teams[$team_code];
	$team_name  = $team['description'] ?? ucfirst($team_code);

	// Generar les dades per a la setmana sol·licitada
	$piscines = sportic_unfile_get_pool_labels_sorted();
	$schedule_map = sportic_carregar_finestra_bd($week_start_str, 6, 0);
	$sessions_by_day = sportic_find_and_build_sessions($schedule_map, $piscines, $team_name, $week_start_str, 7);

	$shortcode_options = get_option('sporttic_shortcode_options', ['hide_empty_days' => '0']);
	$hide_empty_days = isset($shortcode_options['hide_empty_days']) && $shortcode_options['hide_empty_days'] === '1';

	$days_to_display = $sessions_by_day;
	if ($hide_empty_days) {
		$days_to_display = array_filter($sessions_by_day, function($day_sessions) {
			return !empty($day_sessions);
		});
	}

	// Calcular dades per a la capçalera
	$total_sessions = array_sum(array_map('count', $sessions_by_day));
	
	$start_date_obj = new DateTime($week_start_str);
	$end_date_obj = (clone $start_date_obj)->modify('+6 days');
	$week_range_label = 'Setmana del ' . $start_date_obj->format('d/m') . ' al ' . $end_date_obj->format('d/m');

	// Generar l'HTML de la graella
	ob_start();
	$monthsCat = ['Gener','Febrer','Març','Abril','Maig','Juny','Juliol','Agost','Setembre','Octubre','Novembre','Desembre'];
	$daysCat   = ['Diumenge','Dilluns','Dimarts','Dimecres','Dijous','Divendres','Dissabte'];

	if (empty($days_to_display)) : ?>
		<article class="sportic-day-card">
			<div class="no-session">No hi ha entrenaments programats per a cap dia d'aquesta setmana.</div>
		</article>
	<?php else :
		foreach ($days_to_display as $date_key => $day_sessions):
			$dateObj = new DateTime($date_key);
			$day_name = $daysCat[(int)$dateObj->format('w')];
			$day_label = $dateObj->format('d') . ' de ' . ($monthsCat[(int)$dateObj->format('n') - 1] ?? '');
			?>
			<article class="sportic-day-card">
				<header class="sportic-day-card-header">
					<span class="day-name"><?php echo esc_html($day_name); ?></span>
					<span class="day-date"><?php echo esc_html($day_label); ?></span>
				</header>
				<div class="sportic-session-list">
					<?php if (!empty($day_sessions)): foreach ($day_sessions as $session): ?>
						<div class="sportic-session-block">
							<div class="sportic-session-header">
								<div class="sportic-session-header-info">
									<span class="pavilion"><?php echo esc_html($session['pavilion_label']); ?></span>
									<span class="time"><?php echo esc_html($session['overall_start'] . ' - ' . $session['overall_end']); ?></span>
								</div>
								<span class="sportic-session-duration"><?php echo esc_html(sportic_format_duration_label($session['duration_minutes'])); ?></span>
							</div>
							<div class="sportic-session-grid-wrapper">
								<table class="sportic-session-table">
									<thead>
										<tr>
											<th>Hora</th>
											<?php foreach($session['all_lanes_in_pavilion'] as $lane_label): ?>
												<th><div class="th-content-wrapper"><?php echo esc_html($lane_label); ?></div></th>
											<?php endforeach; ?>
										</tr>
									</thead>
									<tbody>
										<?php foreach($session['schedule_grid'] as $hora => $lanes_status): ?>
											<tr>
												<td class="time-col"><?php echo esc_html($hora); ?></td>
												<?php foreach($lanes_status as $is_occupied): ?>
													<td class="<?php echo $is_occupied ? 'occupied' : 'empty'; ?>">
														<?php if($is_occupied) echo '✓'; ?>
													</td>
												<?php endforeach; ?>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					<?php endforeach; else: ?>
						<div class="no-session">Sense entrenaments programats</div>
					<?php endif; ?>
				</div>
			</article>
		<?php endforeach;
	endif;

	// ========================================================================
	// INICI DEL CANVI: Lògica per afegir els elements fantasma (versió AJAX)
	// ========================================================================
	if ($hide_empty_days) {
		$visible_days_count = count($days_to_display);
		$grid_columns = 3;
		$placeholders_needed = ($visible_days_count > 0 && $visible_days_count % $grid_columns !== 0) ? $grid_columns - ($visible_days_count % $grid_columns) : 0;
		
		for ($i = 0; $i < $placeholders_needed; $i++) {
			echo '<div class="sportic-day-card-placeholder"></div>';
		}
	}
	// ========================================================================
	// FI DEL CANVI
	// ========================================================================
	
	$grid_html = ob_get_clean();

	// Enviar la resposta
	wp_send_json_success([
		'html'          => $grid_html,
		'week_label'    => $week_range_label,
		'session_count' => $total_sessions,
	]);
}


/******************************************************************
 * NOU ENDPOINT AJAX SEGUR PER AL MAPA SVG
 * Aquest codi és independent i no afecta la resta del plugin.
 * Utilitza les funcions internes de SportTic per retornar dades completes.
 ******************************************************************/

// Registra la nova acció AJAX per a usuaris connectats i visitants
add_action('wp_ajax_sportic_get_svg_data', 'sportic_svg_data_handler');
add_action('wp_ajax_nopriv_sportic_get_svg_data', 'sportic_svg_data_handler');

function sportic_svg_data_handler() {
	// Comprovar que rebem els paràmetres necessaris
	if (!isset($_POST['piscina']) || !isset($_POST['dia'])) {
		wp_send_json_error('Paràmetres invàlids.', 400);
		return;
	}

	$piscina_slug = sanitize_text_field($_POST['piscina']);
	$dia_str = sanitize_text_field($_POST['dia']);

	// Validar el format de la data
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia_str)) {
		wp_send_json_error('Format de data invàlid.', 400);
		return;
	}

	// Comprovar que la funció principal del teu plugin existeix
	if (function_exists('sportic_carregar_dades_per_dia_i_piscina')) {
		
		// AQUESTA ÉS LA CLAU: Cridem la funció interna que retorna les dades completes,
		// sense simplificar-les a 'l', 'o', 'b'.
		$dades_completes = sportic_carregar_dades_per_dia_i_piscina($piscina_slug, $dia_str);
		
		// Retornem les dades completes en format JSON, perfecte per a JavaScript.
		wp_send_json_success($dades_completes);

	} else {
		wp_send_json_error('La funció principal del plugin SportTic no està disponible.', 500);
	}
}



/******************************************************************
 * ENDPOINT AJAX (VERSIÓ 3.1 - ESTRATÈGIA SIMPLIFICADA)
 * PHP només agrupa. El filtrat per hora es delega COMPLETAMENT al JavaScript.
 ******************************************************************/

add_action('wp_ajax_sportic_get_all_upcoming_events', 'sportic_upcoming_events_handler');
add_action('wp_ajax_nopriv_sportic_get_all_upcoming_events', 'sportic_upcoming_events_handler');

if (!function_exists('sportic_upcoming_events_handler')) {
	function sportic_upcoming_events_handler() {
		if (!function_exists('sportic_carregar_finestra_bd') || !function_exists('sportic_unfile_get_pool_labels_sorted')) {
			wp_send_json_error('Funcions necessàries del plugin SportTic no disponibles.', 500);
			return;
		}

		$wp_timezone = new DateTimeZone(wp_timezone_string());
		$now_datetime = new DateTime('now', $wp_timezone);
		
		$data_avui = $now_datetime->format('Y-m-d');
		$data_dema = (clone $now_datetime)->modify('+1 day')->format('Y-m-d');
		
		$pavilions_config = sportic_unfile_get_pool_labels_sorted();
		$schedule_window = sportic_carregar_finestra_bd($data_avui, 1, 0);
		$target_slug = 'pavello_tmr';

		$all_blocks = [];
		if (isset($schedule_window[$target_slug])) {
			foreach ([$data_avui, $data_dema] as $data_actual) {
				if (!isset($schedule_window[$target_slug][$data_actual])) continue;
				
				foreach ($schedule_window[$target_slug][$data_actual] as $hora => $carrils) {
					if (!is_array($carrils)) continue;
					$datetime_iso = (new DateTime($data_actual . ' ' . $hora, $wp_timezone))->format(DateTime::ISO8601);
					$teams_at_this_hour = [];
					foreach ($carrils as $index_carril => $valor_raw) {
						$valor_net = is_string($valor_raw) ? preg_replace('/^[@!]/', '', trim($valor_raw)) : '';
						if (empty($valor_net) || strtolower($valor_net) === 'l' || strtolower($valor_net) === 'b') continue;
						$teams_at_this_hour[$valor_net][] = $index_carril;
					}
					
					foreach ($teams_at_this_hour as $team_name => $lanes) {
						sort($lanes);
						$all_blocks[] = [
							'start_iso' => $datetime_iso,
							'team' => $team_name,
							'pavilion_slug' => $target_slug,
							'lanes_key' => implode(',', $lanes),
							'lanes' => $lanes
						];
					}
				}
			}
		}

		usort($all_blocks, function($a, $b) {
			$comp = strcmp($a['team'], $b['team']);
			if ($comp !== 0) return $comp;
			$comp = strcmp($a['lanes_key'], $b['lanes_key']);
			if ($comp !== 0) return $comp;
			return strcmp($a['start_iso'], $b['start_iso']);
		});

		$sessions_agrupades = [];
		if (!empty($all_blocks)) {
			$current_session = null;
			foreach ($all_blocks as $bloc) {
				$end_of_current_session = $current_session ? $current_session['end'] : null;
				if ($current_session && $current_session['team'] === $bloc['team'] && $current_session['lanes_key'] === $bloc['lanes_key'] && $bloc['start_iso'] === $end_of_current_session) {
					$current_session['end'] = (new DateTime($bloc['start_iso'], $wp_timezone))->modify('+15 minutes')->format(DateTime::ISO8601);
				} else {
					if ($current_session !== null) $sessions_agrupades[] = $current_session;
					$current_session = [
						'team' => $bloc['team'],
						'pavilion_slug' => $bloc['pavilion_slug'],
						'lanes_key' => $bloc['lanes_key'],
						'lane_indexes'  => $bloc['lanes'],
						'start' => $bloc['start_iso'],
						'end' => (new DateTime($bloc['start_iso'], $wp_timezone))->modify('+15 minutes')->format(DateTime::ISO8601)
					];
				}
			}
			if ($current_session !== null) $sessions_agrupades[] = $current_session;
		}
		
		$events_finals = [];
		foreach ($sessions_agrupades as $event) {
			$slug = $event['pavilion_slug'];
			$indexes = $event['lane_indexes'];
			$lane_labels_config = $pavilions_config[$slug]['lane_labels'] ?? [];
			$nom_pista = 'Desconegut';
			if ($slug === 'pavello_tmr') {
				$is_1a = in_array(0, $indexes); $is_1b = in_array(1, $indexes);
				$is_2a = in_array(2, $indexes); $is_2b = in_array(3, $indexes);
				if ($is_1a && $is_1b && $is_2a && $is_2b) $nom_pista = "Pista Central";
				elseif ($is_1a && $is_1b) $nom_pista = "Pista 1 Sencera";
				elseif ($is_2a && $is_2b) $nom_pista = "Pista 2 Sencera";
				else {
					$pistes_ocupades = array_intersect_key($lane_labels_config, array_flip($indexes));
					$nom_pista = implode(', ', $pistes_ocupades);
				}
			}
			$events_finals[] = [
				'team' => $event['team'],
				'pavilion_name' => $pavilions_config[$slug]['label'] ?? $slug,
				'lane_label' => $nom_pista,
				'start' => $event['start'],
				'end' => $event['end']
			];
		}
		
		// **** CANVI CLAU: JA NO FILTREM PER TEMPS AQUÍ ****
		// Simplement ordenem i enviem la llista COMPLETA d'avui i demà.
		usort($events_finals, function($a, $b) {
			return strcmp($a['start'], $b['start']);
		});

		wp_send_json_success(array_values($events_finals));
	}
}


/**
 * ========================================================================
 * NOU ENDPOINT API V2: SUPER-OPTIMITZAT I FLEXIBLE
 * Afegeix aquest bloc complet al teu plugin principal 'SportTic'.
 * ========================================================================
 */

// Registrem la nostra nova ruta a la API REST de WordPress.
add_action('rest_api_init', 'sportic_register_schedule_api_routes');

/**
 * Registra la ruta 'sportic/v2/schedule' per a consultes de programació.
 * Aquesta funció s'executa quan WordPress inicialitza la API REST.
 */
function sportic_register_schedule_api_routes() {
	register_rest_route('sportic/v2', '/schedule', [
		'methods'  => 'GET',
		'callback' => 'sportic_get_schedule_api_callback',
		'args'     => [
			'lloc' => [
				'description'       => 'El slug del lloc del qual es volen obtenir les dades.',
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			],
			'pavellons' => [
				'description'       => '(Opcional) Llista de slugs de pavellons separats per coma. Si no es proveeix, es retornen tots els pavellons del lloc.',
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'start_date' => [
				'description'       => 'Data d\'inici del rang en format YYYY-MM-DD.',
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => function($param) {
					return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $param);
				}
			],
			'end_date' => [
				'description'       => '(Opcional) Data de fi del rang en format YYYY-MM-DD. Si no es proveeix, s\'utilitza la mateixa que start_date (consulta d\'un sol dia).',
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => function($param) {
					return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $param);
				}
			],
		],
		// NOTA DE SEGURETAT: Per a un entorn de producció públic, hauries d'implementar una validació més robusta,
		// com una API Key o una comprovació d'origen (CORS). De moment, permet l'accés a tothom.
		'permission_callback' => '__return_true',
	]);
}

/**
 * Funció callback que gestiona la petició a la API REST 'sportic/v2/schedule'.
 *
 * @param WP_REST_Request $request Objecte de la petició REST.
 * @return WP_REST_Response|WP_Error Resposta amb les dades o un error.
 */
function sportic_get_schedule_api_callback($request) {
	// 1. Validar que les funcions de dependència existeixen.
	if (!function_exists('sportllocs_get_pavellons_by_lloc') || !function_exists('sportic_carregar_finestra_bd')) {
		return new WP_Error('plugin_dependency_error', 'Les funcions necessàries dels plugins de configuració o SportTic no estan disponibles.', ['status' => 500]);
	}

	// 2. Obtenir i validar paràmetres.
	$lloc_slug = $request['lloc'];
	$pavellons_param = $request['pavellons'];
	$start_date = $request['start_date'];
	$end_date = $request['end_date'] ?? $start_date; // Si no hi ha data final, és un sol dia.

	// Comprovem que les dates són lògiques.
	if (strtotime($end_date) < strtotime($start_date)) {
		return new WP_Error('invalid_date_range', 'La data de fi no pot ser anterior a la data d\'inici.', ['status' => 400]);
	}
	
	// 3. Obtenir la llista de pavellons vàlids per al lloc sol·licitat.
	$all_pavellons_in_lloc = sportllocs_get_pavellons_by_lloc($lloc_slug);
	if (empty($all_pavellons_in_lloc)) {
		return new WP_Error('no_pavilions_found', "El lloc '{$lloc_slug}' no existeix o no té pavellons associats.", ['status' => 404]);
	}

	$pavellons_a_consultar = [];
	if (!empty($pavellons_param)) {
		// L'usuari ha demanat pavellons específics. Els filtrem.
		$slugs_demanats = array_map('trim', explode(',', $pavellons_param));
		foreach ($slugs_demanats as $slug) {
			if (isset($all_pavellons_in_lloc[sanitize_key($slug)])) {
				$pavellons_a_consultar[sanitize_key($slug)] = $all_pavellons_in_lloc[sanitize_key($slug)];
			}
		}
	} else {
		// L'usuari no ha especificat pavellons, per tant els volem tots per a aquest lloc.
		$pavellons_a_consultar = $all_pavellons_in_lloc;
	}

	if (empty($pavellons_a_consultar)) {
		return new WP_Error('no_matching_pavilions', 'Cap dels pavellons sol·licitats pertany al lloc especificat.', ['status' => 404]);
	}

	// 4. Càrrega optimitzada de dades utilitzant la funció existent.
	$diff_dies = (new DateTime($start_date))->diff(new DateTime($end_date))->days;
	$schedule_window = sportic_carregar_finestra_bd($start_date, $diff_dies, 0);

	// 5. Construir la resposta final, filtrant només les dades que ens interessen.
	$response_data = [];
	foreach ($pavellons_a_consultar as $pav_slug => $pav_info) {
		$dates_per_pavello = [];
		$current_date = new DateTime($start_date);
		$end_date_obj = new DateTime($end_date);

		while ($current_date <= $end_date_obj) {
			$date_str = $current_date->format('Y-m-d');
			
			// Obtenim les dades del dia, ja processades (amb recurrents i excepcions)
			$day_schedule_raw = $schedule_window[$pav_slug][$date_str] ?? sportic_unfile_crear_programacio_default($pav_slug);
			
			// Netegem els prefixos '!' i '@' per al frontend
			$day_schedule_clean = [];
			foreach ($day_schedule_raw as $hora => $carrils) {
				$day_schedule_clean[$hora] = array_map(function($valor) {
					return is_string($valor) ? preg_replace('/^[@!]/', '', $valor) : 'l';
				}, $carrils);
			}
			
			$dates_per_pavello[$date_str] = $day_schedule_clean;
			$current_date->modify('+1 day');
		}

		$response_data[] = [
			'slug' => $pav_slug,
			'nom' => $pav_info['label'],
			'pistes' => $pav_info['lanes'],
			'noms_pistes' => $pav_info['lane_labels'],
			'horaris' => $dates_per_pavello
		];
	}

	return new WP_REST_Response($response_data, 200);
}