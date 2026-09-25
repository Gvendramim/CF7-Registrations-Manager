<?php
/**
 * Sistema de Presença (Chamada).
 *
 * Reaproveita as inscrições já existentes (campo `interests`) em vez de
 * criar um cadastro de alunos separado: para fazer a chamada de um
 * programa, basta buscar quais inscrições têm aquele programa
 * selecionado.
 *
 * Cada registro de presença é identificado de forma única pela
 * combinação (inscrição + programa + data) - uma restrição de unicidade
 * no banco de dados garante que salvar a mesma chamada duas vezes nunca
 * cria linhas duplicadas, apenas atualiza a existente.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Attendance
 *
 * Responsabilidade única: ler e gravar registros de presença.
 */
class Attendance {

	/**
	 * Status possíveis de uma linha de presença. "not_marked" nunca é
	 * gravado no banco - é apenas o valor "virtual" usado quando uma
	 * criança do programa ainda não tem nenhum registro para aquela
	 * data, para deixar isso visualmente claro ao administrador sem
	 * presumir falta.
	 *
	 * @var array<string,string>
	 */
	const STATUSES = array(
		'present' => 'Present',
		'absent'  => 'Absent',
		'late'    => 'Late',
		'excused' => 'Excused',
	);

	/**
	 * Retorna a lista de status com seus rótulos amigáveis, incluindo o
	 * pseudo-status "not_marked" (usado apenas para exibição).
	 *
	 * @return array<string,string>
	 */
	public static function get_statuses_with_not_marked() {
		return array_merge( array( 'not_marked' => __( 'Not Marked', 'music-club-registrations' ) ), self::STATUSES );
	}

	/**
	 * Monta a lista de presença ("roster") de um programa em uma data
	 * específica: todas as inscrições que têm aquele programa
	 * selecionado, cada uma com seu status de presença atual para a data
	 * informada (ou "not_marked" se ainda não houver registro).
	 *
	 * @param string $program Nome exato do programa/interesse.
	 * @param string $date    Data no formato Y-m-d.
	 * @return array<int,array{registration_id:int,child_name:string,child_age:?int,child_class:string,registration_number:string,status:string,notes:string}>
	 */
	public static function get_roster( $program, $date ) {
		global $wpdb;

		$program = trim( (string) $program );
		$date    = self::sanitize_date( $date );

		if ( '' === $program || ! $date ) {
			return array();
		}

		$reg_table = Database::table_name();
		$att_table = self::table_name();

		// Busca todas as inscrições que têm este programa selecionado
		// (campo `interests`, armazenado separado por vírgulas) - a mesma
		// lógica de correspondência usada em Database::rename_interest_everywhere().
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, registration_number, child_name, child_age, child_class, interests FROM {$reg_table}
				WHERE interests != '' AND (interests = %s OR interests LIKE %s OR interests LIKE %s OR interests LIKE %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$program,
				$wpdb->esc_like( $program . ', ' ) . '%',
				'%' . $wpdb->esc_like( ', ' . $program . ', ' ) . '%',
				'%' . $wpdb->esc_like( ', ' . $program )
			),
			ARRAY_A
		);

		$roster = array();

		foreach ( $candidates ?: array() as $row ) {
			// Confirma a correspondência exata (evita falso positivo de
			// LIKE com programas cujo nome é substring de outro).
			if ( ! in_array( $program, mcr_interests_to_array( $row['interests'] ), true ) ) {
				continue;
			}

			$roster[ (int) $row['id'] ] = array(
				'registration_id'     => (int) $row['id'],
				'registration_number' => $row['registration_number'],
				'child_name'          => $row['child_name'],
				'child_age'           => isset( $row['child_age'] ) && '' !== $row['child_age'] ? (int) $row['child_age'] : null,
				'child_class'         => $row['child_class'],
				'status'              => 'not_marked',
				'notes'               => '',
			);
		}

		if ( empty( $roster ) ) {
			return array();
		}

		$existing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT registration_id, status, notes FROM {$att_table} WHERE program = %s AND attendance_date = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$program,
				$date
			),
			ARRAY_A
		);

		foreach ( $existing ?: array() as $row ) {
			$id = (int) $row['registration_id'];

			if ( isset( $roster[ $id ] ) ) {
				$roster[ $id ]['status'] = $row['status'];
				$roster[ $id ]['notes']  = $row['notes'];
			}
		}

		// Ordena por nome da criança, para uma chamada previsível de ler.
		uasort( $roster, function ( $a, $b ) {
			return strcasecmp( $a['child_name'], $b['child_name'] );
		} );

		return array_values( $roster );
	}

	/**
	 * Retorna a data da sessão mais recente já registrada para um
	 * programa, anterior à data informada - usada pelo botão "Copy from
	 * Last Session".
	 *
	 * @param string $program      Nome do programa.
	 * @param string $before_date  Data de referência (Y-m-d) - a busca considera apenas sessões anteriores a ela.
	 * @return string|null Data no formato Y-m-d, ou null se não houver sessão anterior.
	 */
	public static function get_last_session_date( $program, $before_date ) {
		global $wpdb;

		$program     = trim( (string) $program );
		$before_date = self::sanitize_date( $before_date );

		if ( '' === $program || ! $before_date ) {
			return null;
		}

		$table = self::table_name();

		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(attendance_date) FROM {$table} WHERE program = %s AND attendance_date < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$program,
				$before_date
			)
		);

		return $date ?: null;
	}

	/**
	 * Retorna o mapa de presença (status + observações) de uma sessão
	 * específica (programa + data), indexado por ID da inscrição - usado
	 * para pré-preencher a chamada atual a partir da sessão anterior
	 * ("Copy from Last Session").
	 *
	 * @param string $program Nome do programa.
	 * @param string $date    Data da sessão (Y-m-d).
	 * @return array<int,array{status:string,notes:string}>
	 */
	public static function get_session_map( $program, $date ) {
		global $wpdb;

		$program = trim( (string) $program );
		$date    = self::sanitize_date( $date );

		if ( '' === $program || ! $date ) {
			return array();
		}

		$table = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT registration_id, status, notes FROM {$table} WHERE program = %s AND attendance_date = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$program,
				$date
			),
			ARRAY_A
		);

		$map = array();

		foreach ( $rows ?: array() as $row ) {
			$map[ (int) $row['registration_id'] ] = array(
				'status' => $row['status'],
				'notes'  => $row['notes'],
			);
		}

		return $map;
	}

	/**
	 * Calcula, para um conjunto de inscrições de um mesmo programa,
	 * quantas faltas ("absent") CONSECUTIVAS cada uma acumula até o
	 * momento (olhando para trás a partir da sessão mais recente já
	 * registrada) - usado para destacar visualmente crianças que faltam
	 * repetidamente, sem exigir nenhuma consulta por criança
	 * individualmente.
	 *
	 * A contagem para assim que encontra qualquer status diferente de
	 * "absent" (Present, Late ou Excused zeram a sequência).
	 *
	 * @param string        $program          Nome do programa.
	 * @param array<int,int> $registration_ids IDs das inscrições a considerar.
	 * @return array<int,int> Mapa registration_id => número de faltas consecutivas.
	 */
	public static function get_consecutive_absences_map( $program, array $registration_ids ) {
		global $wpdb;

		$program = trim( (string) $program );

		if ( '' === $program || empty( $registration_ids ) ) {
			return array();
		}

		$table = self::table_name();

		$placeholders = implode( ', ', array_fill( 0, count( $registration_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT registration_id, attendance_date, status FROM {$table}
				WHERE program = %s AND registration_id IN ({$placeholders})
				ORDER BY registration_id ASC, attendance_date DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $program ), array_values( $registration_ids ) )
			),
			ARRAY_A
		);

		$by_registration = array();

		foreach ( $rows ?: array() as $row ) {
			$by_registration[ (int) $row['registration_id'] ][] = $row['status'];
		}

		$streaks = array();

		foreach ( $by_registration as $registration_id => $statuses ) {
			$streak = 0;

			foreach ( $statuses as $status ) {
				if ( 'absent' !== $status ) {
					break;
				}

				++$streak;
			}

			$streaks[ $registration_id ] = $streak;
		}

		return $streaks;
	}

	/**
	 * Salva a chamada de um programa/data inteira de uma vez: para cada
	 * criança marcada, insere um novo registro ou ATUALIZA o existente
	 * (nunca duplica), graças à restrição de unicidade
	 * (registration_id + program + attendance_date).
	 *
	 * Crianças com status "not_marked" são ignoradas (nenhuma linha é
	 * criada) - assim o professor pode marcar apenas parte da turma numa
	 * primeira passada e completar depois, sem criar registros vazios.
	 *
	 * @param string $program Nome do programa.
	 * @param string $date    Data no formato Y-m-d.
	 * @param array  $entries Mapa registration_id => array{status, notes}.
	 * @param int    $user_id ID do usuário que fez a chamada.
	 * @return int Número de registros de presença gravados.
	 */
	public static function save_attendance( $program, $date, array $entries, $user_id = 0 ) {
		global $wpdb;

		$program = trim( (string) $program );
		$date    = self::sanitize_date( $date );

		if ( '' === $program || ! $date ) {
			return 0;
		}

		$table = self::table_name();
		$saved = 0;

		foreach ( $entries as $registration_id => $entry ) {
			$registration_id = absint( $registration_id );
			$status          = isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'not_marked';
			$notes           = isset( $entry['notes'] ) ? sanitize_text_field( $entry['notes'] ) : '';

			if ( ! $registration_id || ! array_key_exists( $status, self::STATUSES ) ) {
				// "not_marked" (ou qualquer valor inválido) nunca é
				// gravado - representa apenas "ainda não preenchido".
				continue;
			}

			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE registration_id = %d AND program = %s AND attendance_date = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$registration_id,
					$program,
					$date
				)
			);

			if ( $existing_id ) {
				$result = $wpdb->update(
					$table,
					array(
						'status'     => $status,
						'notes'      => $notes,
						'marked_by'  => $user_id,
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => absint( $existing_id ) ),
					array( '%s', '%s', '%d', '%s' ),
					array( '%d' )
				);
			} else {
				$result = $wpdb->insert(
					$table,
					array(
						'registration_id' => $registration_id,
						'program'         => $program,
						'attendance_date' => $date,
						'status'          => $status,
						'notes'           => $notes,
						'marked_by'       => $user_id,
						'created_at'      => current_time( 'mysql' ),
						'updated_at'      => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
				);
			}

			// IMPORTANTE: só conta como salvo se o WordPress confirmar que
			// a gravação realmente aconteceu. Sem esta checagem, uma
			// falha no banco de dados (ex: tabela ausente, tipo de coluna
			// incompatível) passava despercebida - a tela mostrava "N
			// aluno(s) marcado(s)" mesmo quando nada foi de fato gravado.
			if ( false === $result ) {
				Logger::error(
					'attendance',
					sprintf(
						'Failed to save attendance for registration #%d ("%s" on %s): %s',
						$registration_id,
						$program,
						$date,
						$wpdb->last_error ? $wpdb->last_error : __( 'unknown database error', 'music-club-registrations' )
					)
				);

				continue;
			}

			++$saved;
		}

		return $saved;
	}

	/**
	 * Retorna o histórico de presença de uma inscrição específica, do
	 * mais recente para o mais antigo. Usado na seção "Attendance
	 * History" da tela de detalhes.
	 *
	 * @param int $registration_id ID da inscrição.
	 * @param int $limit           Número máximo de registros (padrão: 50).
	 * @return array<int,array>
	 */
	public static function get_history_for_registration( $registration_id, $limit = 50 ) {
		global $wpdb;

		$table = self::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE registration_id = %d ORDER BY attendance_date DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $registration_id ),
				absint( $limit )
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Calcula indicadores gerais de presença (opcionalmente filtrados por
	 * programa), para os cartões do Dashboard. A taxa de presença
	 * considera apenas registros efetivamente marcados - "not_marked"
	 * nunca é gravado no banco, então não pode distorcer o cálculo.
	 *
	 * @param string $program Programa específico, ou '' para todos.
	 * @return array{total: int, present: int, absent: int, late: int, excused: int, rate: float}
	 */
	public static function get_stats( $program = '' ) {
		global $wpdb;

		$table = self::table_name();
		$where = '';
		$args  = array();

		if ( '' !== $program ) {
			$where  = ' WHERE program = %s';
			$args[] = $program;
		}

		$sql = "SELECT status, COUNT(*) as total FROM {$table}{$where} GROUP BY status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$counts = array(
			'present' => 0,
			'absent'  => 0,
			'late'    => 0,
			'excused' => 0,
		);

		foreach ( $rows ?: array() as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}

		$total = array_sum( $counts );

		return array_merge(
			$counts,
			array(
				'total' => $total,
				'rate'  => $total > 0 ? ( $counts['present'] / $total ) * 100 : 0.0,
			)
		);
	}

	/**
	 * Retorna a taxa de presença por programa, para o gráfico "Attendance
	 * by Program" do Dashboard.
	 *
	 * @return array<int,array{program:string,rate:float,total:int}>
	 */
	public static function get_stats_by_program() {
		global $wpdb;

		$table = self::table_name();

		$programs = $wpdb->get_col( "SELECT DISTINCT program FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$result = array();

		foreach ( $programs ?: array() as $program ) {
			$stats = self::get_stats( $program );

			$result[] = array(
				'program' => $program,
				'rate'    => $stats['rate'],
				'total'   => $stats['total'],
			);
		}

		usort(
			$result,
			function ( $a, $b ) {
				return strcasecmp( $a['program'], $b['program'] );
			}
		);

		return $result;
	}

	/**
	 * Lê e sanitiza os dados de chamada enviados por um formulário (tanto
	 * a tela do admin quanto a página pública via shortcode usam este
	 * mesmo método, para que as duas telas nunca divirjam na forma de
	 * interpretar o que foi enviado).
	 *
	 * @param mixed $raw Valor bruto de $_POST['attendance'] (já com wp_unslash()).
	 * @return array{entries: array<int,array{status:string,notes:string}>, attempted: int}
	 */
	public static function parse_submitted_entries( $raw ) {
		$entries   = array();
		$attempted = 0;

		if ( ! is_array( $raw ) ) {
			return array(
				'entries'   => $entries,
				'attempted' => $attempted,
			);
		}

		foreach ( $raw as $registration_id => $entry ) {
			$registration_id = absint( $registration_id );

			if ( ! $registration_id || ! is_array( $entry ) ) {
				continue;
			}

			$status = isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'not_marked';

			$entries[ $registration_id ] = array(
				'status' => $status,
				'notes'  => isset( $entry['notes'] ) ? sanitize_text_field( $entry['notes'] ) : '',
			);

			if ( array_key_exists( $status, self::STATUSES ) ) {
				++$attempted;
			}
		}

		return array(
			'entries'   => $entries,
			'attempted' => $attempted,
		);
	}

	/**
	 * Retorna as chamadas já feitas, agrupadas por sessão (programa +
	 * data), opcionalmente filtradas por programa e intervalo de datas.
	 * Usado pela aba "History" tanto no admin quanto na página pública.
	 *
	 * @param string $program   Programa específico, ou '' para todos.
	 * @param string $date_from Data inicial (Y-m-d), ou '' para não limitar.
	 * @param string $date_to   Data final (Y-m-d), ou '' para não limitar.
	 * @return array<int,array{program:string,attendance_date:string,present:int,absent:int,late:int,excused:int,total:int}>
	 */
	public static function get_history_sessions( $program = '', $date_from = '', $date_to = '' ) {
		global $wpdb;

		$table = self::table_name();
		$where = array();
		$args  = array();

		if ( '' !== (string) $program ) {
			$where[] = 'program = %s';
			$args[]  = $program;
		}

		if ( '' !== (string) $date_from && self::sanitize_date( $date_from ) ) {
			$where[] = 'attendance_date >= %s';
			$args[]  = $date_from;
		}

		if ( '' !== (string) $date_to && self::sanitize_date( $date_to ) ) {
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
	 * Valida e normaliza uma data recebida (formato Y-m-d).
	 *
	 * @param string $date Data bruta.
	 * @return string|false Data normalizada, ou false se inválida.
	 */
	private static function sanitize_date( $date ) {
		$date = sanitize_text_field( (string) $date );

		$parsed = \DateTime::createFromFormat( 'Y-m-d', $date );

		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			return false;
		}

		return $date;
	}

	/**
	 * Retorna o nome completo da tabela de presença (com prefixo do WP).
	 *
	 * @return string
	 */
	public static function table_name() {
		return Database::attendance_table_name();
	}
}
