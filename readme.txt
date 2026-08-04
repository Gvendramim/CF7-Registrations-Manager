=== Music Club Registrations ===
Contributors: Gabriel Vendramim
Tags: contact form 7, registrations, forms, database, export, dashboard, rest api
Requires PHP: 8.1

Captura e gerencia automaticamente as inscrições enviadas por qualquer formulário do Contact Form 7 escolhido pelo administrador — sem nenhum ID de formulário ou nome de campo fixo no código.

== Description ==

Music Club Registrations é um plugin totalmente reutilizável: através da tela de Settings, o administrador escolhe qual formulário do Contact Form 7 deve ser monitorado e mapeia os campos desse formulário para os slots internos do plugin (Nome do aluno, Nome do responsável, Email, Telefone, Turma, Programa e Mensagem). Nenhum ID de formulário ou nome de campo permanece fixo no código-fonte, o que torna o plugin adequado para qualquer projeto ou cliente, bastando reconfigurar a tela de Settings.

Principais recursos:

* **Formulário e campos 100% configuráveis** — selecione qualquer formulário do Contact Form 7 e mapeie seus campos pela tela de Settings.
* **Dashboard** com indicadores (total, hoje, semana, mês, por status) e gráficos Chart.js (linha do tempo e distribuição por status).
* **Captura automática e segura** dos envios do formulário configurado, com sanitização e validação completas.
* **Detecção de duplicidade** configurável (ativar/desativar e intervalo em minutos).
* **Numeração sequencial automática** (MC-000001, MC-000002, ...).
* **Painel administrativo** com listagem (WP_List_Table), busca, filtros, ordenação e ações em massa.
* **Tela de detalhes** com histórico completo de alterações, status e observações internas (nunca exibidas no frontend).
* **Sistema de logs interno** (erros de banco, falhas de API, exportações, alterações de status, erros inesperados) com tela de consulta e limpeza.
* **Exportação para CSV e Excel (.xlsx)** com seleção de colunas e escopo (Todos / Filtrados / Selecionados).
* **API REST própria** (`/wp-json/cf7-registrations/v1/...`), registrada automaticamente na inicialização do plugin, protegida por chave de API (gerada automaticamente) ou autenticação padrão do WordPress.
* Totalmente internacionalizado (i18n) e seguindo os padrões de codificação do WordPress.

== Installation ==

1. Envie a pasta `music-club-registrations` para o diretório `/wp-content/plugins/`.
2. Ative o plugin através do menu "Plugins" no WordPress.
3. Certifique-se de que o Contact Form 7 esteja instalado e ativo.
4. Acesse "Music Club" > "Settings" e selecione o formulário do Contact Form 7 que deseja monitorar. Clique em "Load Fields" e mapeie cada campo do seu formulário para os slots internos do plugin.
5. (Opcional) Para habilitar a exportação em Excel (.xlsx), execute `composer install` dentro da pasta do plugin para instalar a biblioteca PhpSpreadsheet. Sem essa etapa, a exportação em CSV continua funcionando normalmente.
6. Acompanhe as inscrições em "Music Club" > "Dashboard" e "Music Club" > "Registrations".

== Frequently Asked Questions ==

= O plugin funciona com qualquer formulário do Contact Form 7? =

Sim. Nenhum ID de formulário fica fixo no código — o formulário monitorado é escolhido na tela de Settings, e o plugin permanece inativo até que um formulário seja selecionado.

= Como funciona o mapeamento de campos? =

Na tela de Settings, após selecionar o formulário e clicar em "Load Fields", o plugin lista automaticamente os campos existentes nesse formulário. Basta escolher, para cada slot interno (Nome do aluno, Responsável, Email, etc.), qual campo do seu formulário corresponde a ele.

= Onde os dados são armazenados? =

Em uma tabela própria (`wp_music_club_registrations`), criada automaticamente na ativação do plugin via `dbDelta()`.

= A API REST exige configuração manual? =

Não. Os endpoints são registrados automaticamente assim que o plugin é ativado. Uma chave de API também é gerada automaticamente e pode ser consultada ou regenerada na tela de Settings.

= Os dados são apagados se eu desinstalar o plugin? =

Por padrão, não. A remoção só ocorre se a opção "Remove Data" for explicitamente habilitada na tela de Settings.

== Changelog ==

= 2.0.0 =
* Remoção de qualquer ID de formulário ou nome de campo fixo no código.
* Nova tela de Settings: formulário monitorado, mapeamento de campos, status padrão, regras de duplicidade, chave de API, remoção de dados e ativação da exportação em Excel.
* Novo Dashboard com indicadores e gráficos Chart.js.
* Novo sistema interno de logs, com tela de consulta e limpeza.
* Exportação CSV/Excel agora permite selecionar colunas.
* API REST reescrita sob o namespace `cf7-registrations/v1`, com autenticação por chave de API.

= 1.0.0 =
* Versão inicial do plugin.
