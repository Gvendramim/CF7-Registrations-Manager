/**
 * Comportamento da tela "Take Attendance": transforma o <select> de
 * status de cada aluno em um grupo de botões (P/A/L/E) mais rápido de
 * usar, mantém um contador ao vivo, e oferece os atalhos "Mark all as
 * Present" e "Copy from Last Session" - tudo sincronizado com os
 * elementos reais do formulário (os <select> escondidos), para que o
 * envio continue funcionando exatamente como antes.
 *
 * @package Music_Club_Registrations
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( '#mcr-attendance-form' );

		if ( ! $form.length ) {
			return;
		}

		var formIsSubmitting = false;
		var formIsDirty = false;

		/**
		 * Marca o formulário como "alterado", para o aviso de saída sem
		 * salvar (beforeunload) saber que há algo a perder.
		 */
		function markDirty() {
			formIsDirty = true;
		}

		/**
		 * Aplica visualmente um status a uma linha: atualiza o <select>
		 * escondido (o que realmente é enviado no formulário), destaca o
		 * botão correspondente, e re-pinta a cor de fundo da linha.
		 *
		 * @param {jQuery} $row   A linha (<tr>) do aluno.
		 * @param {string} status Um dos status válidos ('present', 'absent', 'late', 'excused', 'not_marked').
		 */
		function applyStatus( $row, status ) {
			var $select = $row.find( '.mcr-attendance-status-select' );

			$select.val( status );

			$row
				.find( '.mcr-status-btn' )
				.removeClass( 'is-active' )
				.filter( '[data-status="' + status + '"]' )
				.addClass( 'is-active' );

			$row.attr(
				'class',
				$row
					.attr( 'class' )
					.replace( /mcr-attendance-row-\S+/g, '' )
					.trim() + ' mcr-attendance-row-' + status
			);
		}

		/**
		 * Recalcula e exibe o contador ao vivo (Present/Absent/Late/
		 * Excused/Not Marked), lendo o valor atual de cada <select>
		 * escondido.
		 */
		function updateCounter() {
			var counts = { present: 0, absent: 0, late: 0, excused: 0, not_marked: 0 };

			$form.find( '.mcr-attendance-status-select' ).each( function () {
				var value = $( this ).val();
				if ( Object.prototype.hasOwnProperty.call( counts, value ) ) {
					counts[ value ]++;
				}
			} );

			$( '#mcr-attendance-counter' ).html(
				'🟢 ' + counts.present + ' &nbsp; ' +
				'🔴 ' + counts.absent + ' &nbsp; ' +
				'🟡 ' + counts.late + ' &nbsp; ' +
				'🔵 ' + counts.excused + ' &nbsp; ' +
				'<span class="mcr-counter-not-marked">⚪ ' + counts.not_marked + ' ' +
					( window.MCRAttendanceData && window.MCRAttendanceData.notMarkedLabel ? window.MCRAttendanceData.notMarkedLabel : 'Not Marked' ) +
				'</span>'
			);
		}

		// Inicializa o estado visual de cada linha a partir do valor já
		// salvo no <select> (ex: ao reabrir uma chamada já feita).
		$form.find( '.mcr-attendance-row' ).each( function () {
			var $row = $( this );
			var current = $row.find( '.mcr-attendance-status-select' ).val();
			applyStatus( $row, current || 'not_marked' );
		} );

		updateCounter();

		// Clique num botão de status: aplica e marca como alterado.
		$form.on( 'click', '.mcr-status-btn', function () {
			var $row = $( this ).closest( '.mcr-attendance-row' );
			applyStatus( $row, $( this ).data( 'status' ) );
			updateCounter();
			markDirty();
		} );

		// Campo de observações também conta como alteração.
		$form.on( 'input', '.mcr-attendance-notes', markDirty );

		// "Mark all as Present": aplica a todas as linhas de uma vez.
		$( '#mcr-mark-all-present' ).on( 'click', function () {
			$form.find( '.mcr-attendance-row' ).each( function () {
				applyStatus( $( this ), 'present' );
			} );
			updateCounter();
			markDirty();
		} );

		// "Copy from Last Session": aplica o status e a observação salvos
		// na sessão anterior mais recente deste mesmo programa, para cada
		// aluno que já existia naquela sessão. Alunos novos (que não
		// estavam na sessão anterior) permanecem como estavam.
		$( '#mcr-copy-last-session' ).on( 'click', function () {
			var lastSessionMap = {};

			try {
				lastSessionMap = JSON.parse( $( this ).attr( 'data-last-session-map' ) || '{}' );
			} catch ( e ) {
				lastSessionMap = {};
			}

			$form.find( '.mcr-attendance-row' ).each( function () {
				var $row = $( this );
				var registrationId = $row.data( 'registration-id' );
				var previous = lastSessionMap[ registrationId ];

				if ( ! previous ) {
					return;
				}

				applyStatus( $row, previous.status );
				$row.find( '.mcr-attendance-notes' ).val( previous.notes || '' );
			} );

			updateCounter();
			markDirty();
		} );

		// Aviso ao sair da página com alterações não salvas - nunca
		// dispara ao realmente enviar o formulário (Save Attendance).
		$form.on( 'submit', function () {
			formIsSubmitting = true;
		} );

		$( window ).on( 'beforeunload', function ( e ) {
			if ( formIsDirty && ! formIsSubmitting ) {
				var message = $form.data( 'confirm-leave' );
				e.preventDefault();
				e.returnValue = message;
				return message;
			}
		} );
	} );
} )( jQuery );
