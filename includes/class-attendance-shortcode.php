<?php
/**
 * Shortcode [mcr_attendance]
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Attendance_Shortcode
 */
class Attendance_Shortcode {

	/**
	 * Ação usada no admin-post.php para o envio do formulário público.
	 * Tem um nome próprio (diferente do usado pela tela de admin) para
	 * manter os dois fluxos completamente independentes.
	 *
	 * @var string
	 */
	const SAVE_ACTION = 'mcr_save_attendance_public';

	/**
	 * Registra o shortcode, o handler de salvamento (disponível também
	 * para visitantes não autenticados, via o sufixo "_nopriv_") e o
	 * enfileiramento condicional dos assets.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( 'mcr_attendance', array( $this, 'render_shortcode' ) );

		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_attendance' ) );
		add_action( 'admin_post_nopriv_' . self::SAVE_ACTION, array( $this, 'handle_save_attendance' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Renderiza o shortcode.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		$post_id = get_the_ID();

		// Trava principal: enquanto a senha da página não for informada,
		// o WordPress deve mostrar apenas o formulário de senha - nunca a
		// lista de presença.
		if ( $post_id && post_password_required( $post_id ) ) {
			return get_the_password_form( $post_id );
		}

		if ( ! $post_id ) {
			// Sem um post real por trás (ex: shortcode usado fora de uma
			// página, como em um widget), não há como aplicar a proteção
			// por senha - por segurança, a ferramenta não é exibida nesse
			// cenário.
			return '<p>' . esc_html__( 'This tool must be placed on a real WordPress Page (not a widget or template) so that its password protection can be enforced.', 'music-club-registrations' ) . '</p>';
		}

		$programs = Database::get_distinct_interests();

		$active_tab = isset( $_GET['mcr_view'] ) && 'history' === $_GET['mcr_view'] ? 'history' : 'take'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navegação simples de leitura, sem efeito colateral.

		$selected_program = isset( $_GET['mcr_program'] ) ? sanitize_text_field( wp_unslash( $_GET['mcr_program'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_date     = isset( $_GET['mcr_date'] ) ? sanitize_text_field( wp_unslash( $_GET['mcr_date'] ) ) : gmdate( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$roster               = array();
		$consecutive_absences = array();
		$last_session_date    = null;
		$last_session_map     = array();

		if ( 'take' === $active_tab && $selected_program ) {
			$roster = Attendance::get_roster( $selected_program, $selected_date );

			if ( ! empty( $roster ) ) {
				$consecutive_absences = Attendance::get_consecutive_absences_map(
					$selected_program,
					wp_list_pluck( $roster, 'registration_id' )
				);

				$last_session_date = Attendance::get_last_session_date( $selected_program, $selected_date );

				if ( $last_session_date ) {
					$last_session_map = Attendance::get_session_map( $selected_program, $last_session_date );
				}
			}
		}

		$saved_summary = null;

		// O resultado do último salvamento chega por um transient
		// próprio por página (não por usuário, já que visitantes da
		// página pública normalmente não estão autenticados no WordPress).
		$pending_result = get_transient( 'mcr_attendance_public_saved_' . $post_id );

		if ( false !== $pending_result ) {
			$saved_summary = $pending_result;
			delete_transient( 'mcr_attendance_public_saved_' . $post_id );
		}

		if ( 'history' === $active_tab ) {
			$history_program   = $selected_program;
			$history_date_from = isset( $_GET['mcr_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['mcr_date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$history_date_to   = isset( $_GET['mcr_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['mcr_date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$history_rows      = Attendance::get_history_sessions( $history_program, $history_date_from, $history_date_to );
		}

		ob_start();
		require MCR_PLUGIN_DIR . 'includes/views/view-attendance-frontend.php';
		return ob_get_clean();
	}

	/**
	 * Processa o envio do formulário "Save Attendance" a partir da
	 * página pública.
	 *
	 * @return void
	 */
	public function handle_save_attendance() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		// Reconfirma a senha da página aqui também: sem isso, alguém
		// poderia contornar completamente a proteção enviando o
		// formulário direto para admin-post.php, sem nunca ter passado
		// pela tela de senha da página.
		if ( ! $post_id || post_password_required( $post_id ) ) {
			wp_die( esc_html__( 'This page is password protected. Please go back and enter the password first.', 'music-club-registrations' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		$program = isset( $_POST['program'] ) ? sanitize_text_field( wp_unslash( $_POST['program'] ) ) : '';
		$date    = isset( $_POST['attendance_date'] ) ? sanitize_text_field( wp_unslash( $_POST['attendance_date'] ) ) : '';

		$parsed    = Attendance::parse_submitted_entries( isset( $_POST['attendance'] ) ? wp_unslash( $_POST['attendance'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizado dentro do parser.
		$entries   = $parsed['entries'];
		$attempted = $parsed['attempted'];

		// Sem um usuário do WordPress autenticado por trás (o acesso é
		// pela senha da página, não por login), o autor de cada alteração
		// fica registrado de forma genérica no histórico.
		$saved = Attendance::save_attendance( $program, $date, $entries, 0 );

		Logger::info(
			'attendance',
			sprintf( 'Attendance saved for "%s" on %s via public page (%d student(s) marked).', $program, $date, $saved )
		);

		set_transient(
			'mcr_attendance_public_saved_' . $post_id,
			array(
				'saved'     => $saved,
				'attempted' => $attempted,
			),
			60
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'mcr_program' => $program,
					'mcr_date'    => $date,
				),
				get_permalink( $post_id )
			)
		);
		exit;
	}

	/**
	 * Monta a URL de uma aba da tela pública de Attendance. É um método
	 * público (não uma função solta no arquivo de view) justamente para
	 * que o template possa ser incluído mais de uma vez com segurança,
	 * caso o shortcode apareça duas vezes na mesma página - uma função
	 * declarada dentro do próprio template quebraria nesse cenário
	 * ("Cannot redeclare function").
	 *
	 * @param string $tab     Slug da aba.
	 * @param int    $post_id ID da página.
	 * @return string
	 */
	public static function tab_url( $tab, $post_id ) {
		return add_query_arg( array( 'mcr_view' => $tab ), get_permalink( $post_id ) );
	}

	/**
	 * Carrega o CSS/JS necessário apenas nas páginas que realmente usam o
	 * shortcode.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || ! has_shortcode( $post->post_content, 'mcr_attendance' ) ) {
			return;
		}

		// Reaproveita o mesmo CSS/JS já usados na tela de admin (as
		// classes .mcr-attendance-*/.mcr-status-* são inteiramente
		// autônomas, não dependem de nenhum estilo do wp-admin) - e soma
		// um pequeno CSS complementar só com o que vem nativamente do
		// admin do WordPress (botões, abas, tabela, avisos), que não
		// existe fora do wp-admin.
		wp_enqueue_style( 'mcr-admin', MCR_PLUGIN_URL . 'assets/css/admin.css', array(), MCR_VERSION );
		wp_enqueue_style( 'mcr-attendance-frontend', MCR_PLUGIN_URL . 'assets/css/attendance-frontend.css', array( 'mcr-admin' ), MCR_VERSION );

		wp_enqueue_script(
			'mcr-attendance',
			MCR_PLUGIN_URL . 'assets/js/attendance.js',
			array( 'jquery' ),
			MCR_VERSION,
			true
		);

		wp_localize_script(
			'mcr-attendance',
			'MCRAttendanceData',
			array(
				'notMarkedLabel' => __( 'Not Marked', 'music-club-registrations' ),
			)
		);
	}
}
