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
		return Attendance::get_history_sessions( $program, $date_from, $date_to );
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

		$parsed    = Attendance::parse_submitted_entries( isset( $_POST['attendance'] ) ? wp_unslash( $_POST['attendance'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizado dentro do parser.
		$entries   = $parsed['entries'];
		$attempted = $parsed['attempted'];

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
