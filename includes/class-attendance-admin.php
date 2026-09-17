<?php
/**
 * Telas administrativas do Sistema de Presença: "Take Attendance"
 * (fazer a chamada) e "History" (histórico de chamadas já feitas).
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Attendance_Admin
 */
class Attendance_Admin {

	/**
	 * Slug da tela de presença (única página, com abas internas "Take
	 * Attendance" e "History").
	 *
	 * @var string
	 */
	const ATTENDANCE_SLUG = 'music-club-attendance';

	/**
	 * Registra os hooks de admin-post desta tela.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_mcr_save_attendance', array( $this, 'handle_save_attendance' ) );
	}

	/**
	 * Renderiza a tela de presença, com abas "Take Attendance" e
	 * "History".
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'music-club-registrations' ) );
		}

		$active_tab = isset( $_GET['view'] ) && 'history' === $_GET['view'] ? 'history' : 'take';

		$programs = Database::get_distinct_interests();

		$selected_program = isset( $_GET['program'] ) ? sanitize_text_field( wp_unslash( $_GET['program'] ) ) : '';
		$selected_date     = isset( $_GET['attendance_date'] ) ? sanitize_text_field( wp_unslash( $_GET['attendance_date'] ) ) : gmdate( 'Y-m-d' );

		$roster                = array();
		$consecutive_absences  = array();
		$last_session_date     = null;
		$last_session_map      = array();

		if ( 'take' === $active_tab && $selected_program ) {
			$roster = Attendance::get_roster( $selected_program, $selected_date );

			if ( ! empty( $roster ) ) {
				$consecutive_absences = Attendance::get_consecutive_absences_map(
					$selected_program,
					wp_list_pluck( $roster, 'registration_id' )
				);

				// Dados para o botão "Copy from Last Session": a sessão
				// anterior mais recente deste mesmo programa, se houver.
				$last_session_date = Attendance::get_last_session_date( $selected_program, $selected_date );

				if ( $last_session_date ) {
					$last_session_map = Attendance::get_session_map( $selected_program, $last_session_date );
				}
			}
		}

		$saved_summary = get_transient( 'mcr_attendance_saved_' . get_current_user_id() );
		delete_transient( 'mcr_attendance_saved_' . get_current_user_id() );

		if ( 'history' === $active_tab ) {
			$history_program   = $selected_program;
			$history_date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
			$history_date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
			$history_rows      = $this->get_history_rows( $history_program, $history_date_from, $history_date_to );
		}

		require_once MCR_PLUGIN_DIR . 'includes/views/view-attendance.php';
	}

	/**
	 * Busca as chamadas já feitas para a aba "History", opcionalmente
	 * filtradas por programa e por um intervalo de datas - agrupadas por
	 * data+programa para exibir um resumo por sessão em vez de uma linha
	 * por criança.
	 *
	 * @param string $program   Programa específico, ou '' para todos.
	 * @param string $date_from Data inicial (Y-m-d), ou '' para não limitar.
	 * @param string $date_to   Data final (Y-m-d), ou '' para não limitar.
	 * @return array<int,array{program:string,attendance_date:string,present:int,absent:int,late:int,excused:int,total:int}>
	 */
	private function get_history_rows( $program, $date_from = '', $date_to = '' ) {
		global $wpdb;

		$table = Attendance::table_name();
		$where = array();
		$args  = array();

		if ( '' !== $program ) {
			$where[] = 'program = %s';
			$args[]  = $program;
		}

		if ( '' !== $date_from ) {
			$where[] = 'attendance_date >= %s';
			$args[]  = $date_from;
		}

		if ( '' !== $date_to ) {
			$where[] = 'attendance_date <= %s';
			$args[]  = $date_to;
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		$sql = "SELECT program, attendance_date, status, COUNT(*) as total
			FROM {$table}{$where_sql}
			GROUP BY program, attendance_date, status
			ORDER BY attendance_date DESC, program ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sessions = array();

		foreach ( $rows ?: array() as $row ) {
			$key = $row['program'] . '|' . $row['attendance_date'];

			if ( ! isset( $sessions[ $key ] ) ) {
				$sessions[ $key ] = array(
					'program'         => $row['program'],
					'attendance_date' => $row['attendance_date'],
					'present'         => 0,
					'absent'          => 0,
					'late'            => 0,
					'excused'         => 0,
					'total'           => 0,
				);
			}

			if ( isset( $sessions[ $key ][ $row['status'] ] ) ) {
				$sessions[ $key ][ $row['status'] ] = (int) $row['total'];
			}

			$sessions[ $key ]['total'] += (int) $row['total'];
		}

		return array_values( $sessions );
	}

	/**
	 * Processa o botão "Save Attendance" da tela "Take Attendance".
	 *
	 * @return void
	 */
	public function handle_save_attendance() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		check_admin_referer( 'mcr_save_attendance' );

		$program = isset( $_POST['program'] ) ? sanitize_text_field( wp_unslash( $_POST['program'] ) ) : '';
		$date    = isset( $_POST['attendance_date'] ) ? sanitize_text_field( wp_unslash( $_POST['attendance_date'] ) ) : '';

		$entries = array();

		if ( isset( $_POST['attendance'] ) && is_array( $_POST['attendance'] ) ) {
			foreach ( wp_unslash( $_POST['attendance'] ) as $registration_id => $entry ) {
				$entries[ absint( $registration_id ) ] = array(
					'status' => isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'not_marked',
					'notes'  => isset( $entry['notes'] ) ? sanitize_text_field( $entry['notes'] ) : '',
				);
			}
		}

		$attempted = 0;
		foreach ( $entries as $entry ) {
			if ( isset( $entry['status'] ) && 'not_marked' !== $entry['status'] ) {
				++$attempted;
			}
		}

		$saved = Attendance::save_attendance( $program, $date, $entries, get_current_user_id() );

		Logger::info(
			'attendance',
			sprintf( 'Attendance saved for "%s" on %s (%d student(s) marked).', $program, $date, $saved ),
			get_current_user_id()
		);

		set_transient(
			'mcr_attendance_saved_' . get_current_user_id(),
			array(
				'saved'     => $saved,
				'attempted' => $attempted,
			),
			60
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::ATTENDANCE_SLUG,
					'program'         => $program,
					'attendance_date' => $date,
					'saved'           => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
