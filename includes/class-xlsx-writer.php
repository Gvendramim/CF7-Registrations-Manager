<?php
/**
 * Gerador nativo de arquivos .xlsx, sem nenhuma dependência externa
 * (Composer, PhpSpreadsheet, etc). Utiliza apenas a extensão ZipArchive,
 * que acompanha a imensa maioria das instalações de PHP.
 *
 * O formato .xlsx é, na prática, um arquivo ZIP contendo alguns XMLs bem
 * definidos pela especificação OOXML (Office Open XML). Esta classe gera
 * a estrutura mínima necessária para uma planilha simples: uma aba, uma
 * linha de cabeçalho em negrito e linhas de dados, com colunas
 * auto-dimensionadas.
 *
 * Isso garante que a exportação em Excel funcione imediatamente após a
 * ativação do plugin, sem exigir Composer, SSH ou instalação manual de
 * bibliotecas.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Xlsx_Writer
 *
 * Responsabilidade única: converter um conjunto de linhas em um arquivo
 * .xlsx válido, sem dependências externas.
 */
class Xlsx_Writer {

	/**
	 * Cabeçalhos da planilha.
	 *
	 * @var array<int,string>
	 */
	private $headers = array();

	/**
	 * Linhas de dados da planilha.
	 *
	 * @var array<int,array<int,string>>
	 */
	private $rows = array();

	/**
	 * Nome da aba (worksheet).
	 *
	 * @var string
	 */
	private $sheet_name = 'Sheet1';

	/**
	 * Define os cabeçalhos (primeira linha, em negrito).
	 *
	 * @param array<int,string> $headers Lista de cabeçalhos.
	 * @return void
	 */
	public function set_headers( array $headers ) {
		$this->headers = array_values( $headers );
	}

	/**
	 * Adiciona uma linha de dados.
	 *
	 * @param array<int,string> $row Valores da linha, na mesma ordem dos cabeçalhos.
	 * @return void
	 */
	public function add_row( array $row ) {
		$this->rows[] = array_values( $row );
	}

	/**
	 * Define o nome da aba da planilha.
	 *
	 * @param string $name Nome da aba.
	 * @return void
	 */
	public function set_sheet_name( $name ) {
		$this->sheet_name = substr( sanitize_text_field( $name ), 0, 31 );
	}

	/**
	 * Verifica se o ambiente é capaz de gerar arquivos .xlsx nativamente
	 * (ou seja, se a extensão ZipArchive está disponível).
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Gera o arquivo .xlsx e grava no caminho informado.
	 *
	 * @param string $filepath Caminho absoluto de destino.
	 * @return bool True em caso de sucesso.
	 */
	public function save( $filepath ) {
		if ( ! self::is_supported() ) {
			return false;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$zip->addEmptyDir( '_rels' );
		$zip->addEmptyDir( 'docProps' );
		$zip->addEmptyDir( 'xl' );
		$zip->addEmptyDir( 'xl/_rels' );
		$zip->addEmptyDir( 'xl/worksheets' );

		$zip->addFromString( '[Content_Types].xml', $this->build_content_types_xml() );
		$zip->addFromString( '_rels/.rels', $this->build_rels_xml() );
		$zip->addFromString( 'docProps/core.xml', $this->build_core_xml() );
		$zip->addFromString( 'docProps/app.xml', $this->build_app_xml() );
		$zip->addFromString( 'xl/workbook.xml', $this->build_workbook_xml() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->build_workbook_rels_xml() );
		$zip->addFromString( 'xl/styles.xml', $this->build_styles_xml() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $this->build_sheet_xml() );

		return $zip->close();
	}

	/**
	 * Monta o XML "[Content_Types].xml", exigido pela especificação OOXML.
	 *
	 * @return string
	 */
	private function build_content_types_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
			. '</Types>';
	}

	/**
	 * Monta o XML "_rels/.rels" (relacionamentos do pacote raiz).
	 *
	 * @return string
	 */
	private function build_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Monta metadados básicos do documento (docProps/core.xml).
	 *
	 * @return string
	 */
	private function build_core_xml() {
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<dc:creator>CF7 Registrations Manager</dc:creator>'
			. '<cp:lastModifiedBy>CF7 Registrations Manager</cp:lastModifiedBy>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . esc_html( $now ) . '</dcterms:created>'
			. '<dcterms:modified xsi:type="dcterms:W3CDTF">' . esc_html( $now ) . '</dcterms:modified>'
			. '</cp:coreProperties>';
	}

	/**
	 * Monta metadados da aplicação (docProps/app.xml).
	 *
	 * @return string
	 */
	private function build_app_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
			. '<Application>CF7 Registrations Manager</Application>'
			. '</Properties>';
	}

	/**
	 * Monta o XML do workbook (xl/workbook.xml).
	 *
	 * @return string
	 */
	private function build_workbook_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="' . esc_html( $this->sheet_name ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	/**
	 * Monta os relacionamentos do workbook (xl/_rels/workbook.xml.rels).
	 *
	 * @return string
	 */
	private function build_workbook_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Monta uma folha de estilos mínima, com um estilo de negrito (índice 1)
	 * usado no cabeçalho.
	 *
	 * @return string
	 */
	private function build_styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0"/></cellStyleXfs>'
			. '<cellXfs count="2"><xf numFmtId="0" fontId="0" xfId="0"/><xf numFmtId="0" fontId="1" xfId="0" applyFont="1"/></cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	/**
	 * Calcula o comprimento de uma string de forma segura, mesmo em
	 * ambientes sem a extensão mbstring instalada (evita erro fatal).
	 *
	 * @param string $value Valor a medir.
	 * @return int
	 */
	private function safe_strlen( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value );
		}

		return strlen( $value );
	}

	/**
	 * Monta o elemento `<c>` (célula) para um valor de linha, usando o
	 * tipo numérico nativo do Excel quando o valor é um número puro
	 * (ex: idade da criança) - permitindo ordenação, filtros e cálculos
	 * corretos no Excel - e texto (inline string) em todos os outros
	 * casos, incluindo números com formatação especial (ex: telefones,
	 * que devem permanecer texto para preservar zeros à esquerda e sinais
	 * de "+").
	 *
	 * @param string $ref   Referência da célula (ex: "B2").
	 * @param mixed  $value Valor bruto da célula.
	 * @return string
	 */
	private function build_cell_xml( $ref, $value ) {
		if ( $this->is_plain_number( $value ) ) {
			return '<c r="' . $ref . '"><v>' . $this->escape_xml( $value ) . '</v></c>';
		}

		return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $this->escape_xml( $value ) . '</t></is></c>';
	}

	/**
	 * Verifica se um valor deve ser exportado como número nativo do Excel
	 * (em vez de texto). Apenas números inteiros ou decimais "puros" (sem
	 * zeros à esquerda, sinais de mais, parênteses, etc. - características
	 * comuns de campos como telefone) qualificam.
	 *
	 * @param mixed $value Valor bruto.
	 * @return bool
	 */
	private function is_plain_number( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return true;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		// Exclui números com zero à esquerda (ex: "007"), pois normalmente
		// representam códigos/identificadores, não quantidades.
		if ( preg_match( '/^0[0-9]/', $value ) ) {
			return false;
		}

		return (bool) preg_match( '/^-?[0-9]+(\.[0-9]+)?$/', $value );
	}

	/**
	 * Converte um índice de coluna (0-indexed) para a referência de coluna
	 * do Excel (A, B, ..., Z, AA, AB, ...).
	 *
	 * @param int $index Índice da coluna, começando em 0.
	 * @return string
	 */
	private function column_letter( $index ) {
		$letter = '';
		$index++;

		while ( $index > 0 ) {
			$remainder = ( $index - 1 ) % 26;
			$letter    = chr( 65 + $remainder ) . $letter;
			$index     = (int) ( ( $index - $remainder ) / 26 );
		}

		return $letter;
	}

	/**
	 * Monta a folha de dados (xl/worksheets/sheet1.xml), incluindo
	 * cabeçalho em negrito, linhas de dados e larguras de coluna
	 * aproximadas com base no maior valor de cada coluna.
	 *
	 * @return string
	 */
	private function build_sheet_xml() {
		$col_count = count( $this->headers );

		$cols_xml = '<cols>';
		for ( $c = 0; $c < $col_count; $c++ ) {
			$max_len = $this->safe_strlen( (string) ( $this->headers[ $c ] ?? '' ) );
			foreach ( $this->rows as $row ) {
				$len = $this->safe_strlen( (string) ( $row[ $c ] ?? '' ) );
				if ( $len > $max_len ) {
					$max_len = $len;
				}
			}
			$width      = min( 60, max( 10, $max_len + 2 ) );
			$cols_xml  .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . $width . '" customWidth="1"/>';
		}
		$cols_xml .= '</cols>';

		$rows_xml = '';

		// Linha de cabeçalho (estilo em negrito: s="1").
		$rows_xml .= '<row r="1">';
		foreach ( $this->headers as $c => $value ) {
			$ref       = $this->column_letter( $c ) . '1';
			$rows_xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t xml:space="preserve">' . $this->escape_xml( $value ) . '</t></is></c>';
		}
		$rows_xml .= '</row>';

		// Linhas de dados.
		$row_index = 2;
		foreach ( $this->rows as $row ) {
			$rows_xml .= '<row r="' . $row_index . '">';
			foreach ( $row as $c => $value ) {
				$ref       = $this->column_letter( $c ) . $row_index;
				$rows_xml .= $this->build_cell_xml( $ref, $value );
			}
			$rows_xml .= '</row>';
			++$row_index;
		}

		$dimension = 'A1:' . $this->column_letter( max( 0, $col_count - 1 ) ) . max( 1, $row_index - 1 );

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<dimension ref="' . $dimension . '"/>'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. $cols_xml
			. '<sheetData>' . $rows_xml . '</sheetData>'
			. '</worksheet>';
	}

	/**
	 * Escapa um valor para uso seguro dentro de um nó XML.
	 *
	 * @param string $value Valor bruto.
	 * @return string
	 */
	private function escape_xml( $value ) {
		$value = (string) $value;
		$value = str_replace(
			array( '&', '<', '>', '"', "'" ),
			array( '&amp;', '&lt;', '&gt;', '&quot;', '&apos;' ),
			$value
		);

		// Remove caracteres de controle inválidos em XML 1.0.
		return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
	}
}
