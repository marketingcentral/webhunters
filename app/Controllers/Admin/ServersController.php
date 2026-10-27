<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\ServersRepo;

/**
 * Tela SERVIDORES do painel: /admin/servidores.
 *
 * Governa `server_regions`, `server_services` e `maintenance_windows` — as três tabelas que
 * alimentam o bloco "Status dos Servidores" do dashboard, o painel de status da tela /suporte
 * do jogo e o aviso de manutenção da caixa de mensagens do jogador.
 *
 * DUAS DECISÕES DE DESENHO QUE EXPLICAM O RESTO DO ARQUIVO:
 *
 * 1. Três tabelas, UMA rota de escrita. O roteador oferece exatamente `salvar` e `excluir`
 *    (app/routes.php é ponto de conflito e não pode ser editado por esta tela), então o campo
 *    `tipo` (regiao|servico|janela) discrimina o destino e é validado contra um `match` — nunca
 *    concatenado em SQL. O mesmo vale para `acao`, que separa a gravação do formulário da
 *    troca rápida de estado feita no botão da própria linha.
 *
 * 2. Erro de validação NÃO redireciona. Um POST inválido renderiza a mesma tela, de volta com
 *    o que foi digitado e com a mensagem embaixo do campo culpado. Redirecionar com flash
 *    (que é o caminho do SUCESSO, para o F5 não regravar) obrigaria a carregar o formulário
 *    inteiro na sessão e perderia a marcação por campo — que é justamente o que torna o erro
 *    corrigível sem adivinhação.
 *
 * A confirmação de exclusão é uma etapa de verdade, não um `confirm()` de JavaScript: o link
 * da linha leva a `?excluir=tipo:id`, a tela mostra o que vai sumir e só então existe um POST.
 * Com JavaScript desligado o comportamento é o mesmo, e — importante para uma tela que mexe no
 * que o jogo inteiro mostra — a região referenciada por janelas é BLOQUEADA nesta etapa, com o
 * motivo escrito, em vez de sumir e deixar a agenda apontando para o nada.
 */
final class ServersController extends AdminController
{
    /** Os três blocos da tela. É esta lista que valida o campo `tipo` de todo POST. */
    private const TIPOS = ['regiao', 'servico', 'janela'];

    /** Rótulo de cada tipo para flash, auditoria e título do formulário. */
    private const NOME = ['regiao' => 'região', 'servico' => 'serviço', 'janela' => 'janela de manutenção'];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        return $this->tela($request);
    }

    /**
     * Cria ou atualiza uma das três entidades — e também a troca rápida de estado.
     *
     * @param array<string, string> $params
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $tipo = (string)$request->input('tipo', '');
        if (!in_array($tipo, self::TIPOS, true)) {
            flash('error', 'Pedido inválido: tipo de registro desconhecido.');
            return $this->redirect('admin/servidores');
        }

        if ((string)$request->input('acao', '') === 'estado') {
            return $this->trocarEstado($request, $tipo);
        }

        $id  = max(0, (int)$request->input('id', '0'));
        $val = match ($tipo) {
            'regiao'  => $this->validarRegiao($request, $id),
            'servico' => $this->validarServico($request, $id),
            default   => $this->validarJanela($request, $id),
        };

        if ($val['erros'] !== []) {
            // Volta para a MESMA tela, com o formulário aberto no que foi digitado. Sem
            // redirect: é o único jeito de a mensagem chegar colada no campo errado.
            flash('error', 'Corrija os campos destacados para gravar.');
            return $this->tela($request, [
                'form_tipo'  => $tipo,
                'form_id'    => $id,
                'form_dados' => $val['bruto'],
                'form_erros' => $val['erros'],
            ]);
        }

        $novo  = $id <= 0;
        $antes = $novo ? null : $this->carregar($tipo, $id);
        if (!$novo && $antes === null) {
            flash('error', 'O registro que você tentou editar não existe mais.');
            return $this->redirect('admin/servidores');
        }

        $gravado = match ($tipo) {
            'regiao'  => ServersRepo::salvarRegiao($val['dados'], $id),
            'servico' => ServersRepo::salvarServico($val['dados'], $id),
            default   => ServersRepo::salvarJanela($val['dados'], $id),
        };

        // Renomear a região é uma migração pequena, não uma edição de texto: o rótulo é a chave
        // que as janelas guardam, e deixá-las para trás criaria agenda órfã silenciosamente.
        $renomeadas = 0;
        if ($tipo === 'regiao' && $antes !== null && (string)$antes['label'] !== (string)$val['dados']['label']) {
            $renomeadas = ServersRepo::renomearRegiaoNasJanelas((string)$antes['label'], (string)$val['dados']['label']);
        }

        $this->auditar(
            $novo ? 'servidores_criar' : 'servidores_editar',
            'servidores_' . $tipo,
            $gravado,
            ($novo ? 'Criou ' : 'Alterou ') . self::NOME[$tipo] . ' ' . $this->rotuloDe($tipo, $val['dados']),
            ['tipo' => $tipo, 'campos' => $this->diff($antes, $val['dados']), 'janelas_renomeadas' => $renomeadas]
        );

        $msg = ($novo ? 'Criada' : 'Salva') . ': ' . self::NOME[$tipo] . ' ' . $this->rotuloDe($tipo, $val['dados']) . '.';
        if ($renomeadas > 0) {
            $msg .= ' ' . $renomeadas . ' janela(s) acompanharam o novo rótulo.';
        }
        flash('ok', $msg);

        return $this->redirect('admin/servidores', $this->filtrosDe($request));
    }

    /**
     * Apaga uma das três entidades, depois da etapa de confirmação (`?excluir=tipo:id`).
     *
     * @param array<string, string> $params
     */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $tipo = (string)$request->input('tipo', '');
        $id   = max(0, (int)$request->input('id', '0'));
        if (!in_array($tipo, self::TIPOS, true) || $id <= 0) {
            flash('error', 'Pedido inválido: nada foi apagado.');
            return $this->redirect('admin/servidores');
        }

        $linha = $this->carregar($tipo, $id);
        if ($linha === null) {
            flash('error', 'Este registro já havia sido apagado.');
            return $this->redirect('admin/servidores');
        }

        // O bloqueio é conferido de novo AQUI, e não só na tela de confirmação: entre abrir a
        // confirmação e clicar, outro operador pode ter criado a janela que amarra a região.
        if (($motivo = $this->motivoDeBloqueio($tipo, $linha)) !== null) {
            flash('error', $motivo);
            return $this->redirect('admin/servidores');
        }

        if (!ServersRepo::excluir($tipo, $id)) {
            flash('error', 'Não foi possível apagar o registro.');
            return $this->redirect('admin/servidores');
        }

        $this->auditar(
            'servidores_excluir',
            'servidores_' . $tipo,
            $id,
            'Excluiu ' . self::NOME[$tipo] . ' ' . $this->rotuloCru($tipo, $linha),
            ['tipo' => $tipo, 'apagado' => $this->resumirLinha($tipo, $linha)]
        );

        flash('ok', 'Excluída: ' . self::NOME[$tipo] . ' ' . $this->rotuloCru($tipo, $linha) . '.');
        return $this->redirect('admin/servidores', $this->filtrosDe($request));
    }

    /* =====================================================================================
       MONTAGEM DA TELA
       ===================================================================================== */

    /**
     * A tela inteira. Serve tanto o GET quanto o POST que voltou com erro — por isso os
     * sobrescritos entram por parâmetro, e não por sessão.
     *
     * @param array<string,mixed> $over
     */
    private function tela(Request $request, array $over = []): Response
    {
        $filtro = $this->filtrosDe($request);

        $regioes  = ServersRepo::regioes([
            'q'      => $filtro['q'] ?? '',
            'estado' => $filtro['estado'] ?? '',
            'ord'    => $filtro['ord'] ?? '',
            'dir'    => $filtro['dir'] ?? 'asc',
        ]);
        $servicos = ServersRepo::servicos([
            'q'      => $filtro['q'] ?? '',
            'estado' => $filtro['estado'] ?? '',
            'ord'    => $filtro['sord'] ?? '',
            'dir'    => $filtro['sdir'] ?? 'asc',
        ]);
        $janelas  = ServersRepo::janelas([
            'q'        => $filtro['q'] ?? '',
            'situacao' => $filtro['situacao'] ?? '',
            'pagina'   => (int)($filtro['jp'] ?? 1),
        ]);

        $resumo = ServersRepo::resumo();

        // Formulário: o POST com erro manda o seu; o GET abre pela URL (?novo= / ?editar=).
        $form = $over !== []
            ? [
                'tipo'  => (string)$over['form_tipo'],
                'id'    => (int)$over['form_id'],
                'dados' => (array)$over['form_dados'],
                'erros' => (array)$over['form_erros'],
            ]
            : $this->formularioDaUrl($request);

        $exclusao = $this->exclusaoDaUrl($request);

        // Sem botões colados ao título: em 1366×768 a topbar já leva título, subtítulo, três
        // indicadores e as ferramentas da conta — três ações a mais empurravam o bloco do
        // administrador para fora da tela. Criar região, serviço e janela mora no cabeçalho do
        // cartão de cada um, que é onde o operador está olhando quando decide criar.
        $page = $this->adminPage('servidores', 'Servidores', 'Regiões, serviços e janelas de manutenção');

        return $this->render($page['slug'], $page, [
            'cromo'        => data_mock('admin-servidores'),
            'admin_conta'  => $this->admin(),

            'resumo'       => $resumo,
            'regioes'      => $regioes,
            'servicos'     => $servicos,
            'janelas'      => $janelas,

            'filtro'       => $filtro,
            'estados'      => ServersRepo::ESTADOS,
            'situacoes'    => ServersRepo::SITUACOES,
            'rotulos_reg'  => ServersRepo::rotulosRegioes(),
            'noticias'     => ServersRepo::noticias(),

            'form'         => $form,
            'exclusao'     => $exclusao,

            'admin_kpis'   => $this->kpisDaTopbar($resumo),
            'admin_alerts' => $this->alertas($regioes, $servicos, $resumo),
        ]);
    }

    /**
     * Filtros lidos da URL, já saneados. É a MESMA função usada para remontar a URL depois de
     * gravar — é o que faz o operador voltar para a lista filtrada em que estava, e não para
     * o começo de tudo.
     *
     * @return array<string,string>
     */
    private function filtrosDe(Request $request): array
    {
        $f = [];

        $q = trim((string)($request->query('q') ?? $request->input('f_q', '') ?? ''));
        if ($q !== '') {
            $f['q'] = mb_substr($q, 0, 60);
        }

        $estado = (string)($request->query('estado') ?? $request->input('f_estado', '') ?? '');
        if (isset(ServersRepo::ESTADOS[$estado])) {
            $f['estado'] = $estado;
        }

        $situacao = (string)($request->query('situacao') ?? $request->input('f_situacao', '') ?? '');
        if (isset(ServersRepo::SITUACOES[$situacao])) {
            $f['situacao'] = $situacao;
        }

        foreach (['ord' => 'dir', 'sord' => 'sdir'] as $col => $dir) {
            $v = preg_replace('/[^a-z]/', '', strtolower((string)($request->query($col) ?? '')));
            if ($v !== null && $v !== '') {
                $f[$col] = $v;
                $f[$dir] = strtolower((string)($request->query($dir) ?? '')) === 'desc' ? 'desc' : 'asc';
            }
        }

        $jp = (int)($request->query('jp') ?? $request->input('f_jp', '0') ?? 0);
        if ($jp > 1) {
            $f['jp'] = (string)$jp;
        }

        return $f;
    }

    /**
     * `?novo=regiao` e `?editar=regiao:3` — o formulário mora na URL, e não em estado de
     * sessão: o operador pode abrir a edição em outra aba, e voltar não ressuscita um
     * formulário fantasma.
     *
     * @return array{tipo:string,id:int,dados:array<string,mixed>,erros:array<string,string>}|null
     */
    private function formularioDaUrl(Request $request): ?array
    {
        $novo = (string)($request->query('novo') ?? '');
        if (in_array($novo, self::TIPOS, true)) {
            return ['tipo' => $novo, 'id' => 0, 'dados' => $this->padroes($novo), 'erros' => []];
        }

        [$tipo, $id] = $this->tipoId((string)($request->query('editar') ?? ''));
        if ($tipo === null) {
            return null;
        }

        $linha = $this->carregar($tipo, $id);
        if ($linha === null) {
            flash('error', 'O registro que você quis editar não existe mais.');
            return null;
        }

        return ['tipo' => $tipo, 'id' => $id, 'dados' => $this->paraFormulario($tipo, $linha), 'erros' => []];
    }

    /**
     * `?excluir=janela:7` — a etapa de confirmação, já com o motivo do bloqueio quando existe.
     *
     * @return array{tipo:string,id:int,linha:array<string,mixed>,rotulo:string,detalhes:array<string,string>,bloqueio:?string}|null
     */
    private function exclusaoDaUrl(Request $request): ?array
    {
        [$tipo, $id] = $this->tipoId((string)($request->query('excluir') ?? ''));
        if ($tipo === null) {
            return null;
        }
        $linha = $this->carregar($tipo, $id);
        if ($linha === null) {
            return null;
        }

        return [
            'tipo'     => $tipo,
            'id'       => $id,
            'linha'    => $linha,
            'rotulo'   => $this->rotuloCru($tipo, $linha),
            'detalhes' => $this->resumirLinha($tipo, $linha),
            'bloqueio' => $this->motivoDeBloqueio($tipo, $linha),
        ];
    }

    /** "regiao:3" -> ['regiao', 3]; qualquer outra coisa -> [null, 0]. */
    private function tipoId(string $ref): array
    {
        $partes = explode(':', $ref, 2);
        $tipo   = $partes[0] ?? '';
        $id     = (int)($partes[1] ?? 0);
        return in_array($tipo, self::TIPOS, true) && $id > 0 ? [$tipo, $id] : [null, 0];
    }

    /** @return array<string,mixed>|null */
    private function carregar(string $tipo, int $id): ?array
    {
        return match ($tipo) {
            'regiao'  => ServersRepo::regiao($id),
            'servico' => ServersRepo::servico($id),
            'janela'  => ServersRepo::janela($id),
            default   => null,
        };
    }

    /**
     * O motivo pelo qual este registro NÃO pode ser apagado, ou null quando pode.
     *
     * Hoje só a região tem amarra: as janelas guardam o RÓTULO dela, não o id, então apagar a
     * região deixaria a agenda apontando para um texto que não existe mais — e a caixa de
     * mensagens do jogo continuaria anunciando manutenção de uma região fantasma.
     */
    private function motivoDeBloqueio(string $tipo, array $linha): ?string
    {
        if ($tipo !== 'regiao') {
            return null;
        }
        $label = (string)($linha['label'] ?? '');
        $n     = ServersRepo::janelasDaRegiao($label);
        if ($n === 0) {
            return null;
        }
        $cromo = data_mock('admin-servidores');
        return sprintf((string)($cromo['excluir']['bloqueio'] ?? '%d janela(s) usam "%s".'), $n, $label);
    }

    /* =====================================================================================
       VALIDAÇÃO — servidor, campo a campo
       ===================================================================================== */

    /**
     * @return array{dados:array<string,mixed>, bruto:array<string,string>, erros:array<string,string>}
     */
    private function validarRegiao(Request $request, int $id): array
    {
        $bruto = [
            'label'      => trim((string)$request->input('label', '')),
            'slug'       => trim((string)$request->input('slug', '')),
            'ping_ms'    => trim((string)$request->input('ping_ms', '')),
            'players'    => trim((string)$request->input('players', '')),
            'state'      => (string)$request->input('state', 'ok'),
            'sort_order' => trim((string)$request->input('sort_order', '0')),
        ];
        $erros = [];

        $label = $bruto['label'];
        if ($label === '') {
            $erros['label'] = 'Informe o nome da região.';
        } elseif (mb_strlen($label) > 48) {
            $erros['label'] = 'No máximo 48 caracteres.';
        } elseif (ServersRepo::rotuloOcupado($label, $id)) {
            $erros['label'] = 'Já existe outra região com este nome — e o nome é a chave das janelas.';
        }

        // Slug em branco não é erro: sai do rótulo. Erro é o rótulo não render slug nenhum
        // (um nome só de símbolos), porque a coluna é UNIQUE e NOT NULL.
        $slug = $bruto['slug'] !== '' ? mb_strtolower($bruto['slug']) : ServersRepo::slugificar($label);
        if ($slug === '') {
            $erros['slug'] = 'Não foi possível gerar um identificador a partir do nome. Escreva um.';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $slug)) {
            $erros['slug'] = 'Use de 2 a 24 caracteres: letras minúsculas, números e hífen.';
        } elseif (ServersRepo::slugOcupado('regiao', $slug, $id)) {
            $erros['slug'] = 'Este identificador já pertence a outra região.';
        }

        $ping = $this->inteiro($bruto['ping_ms'], 0, 65535);
        if ($ping === null) {
            $erros['ping_ms'] = 'Um número inteiro de 0 a 65535.';
        }

        $players = $this->inteiro($bruto['players'], 0, 4294967295);
        if ($players === null) {
            $erros['players'] = 'Um número inteiro de 0 para cima.';
        }

        if (!isset(ServersRepo::ESTADOS[$bruto['state']])) {
            $erros['state'] = 'Escolha um dos estados da lista.';
        }

        $ordem = $this->inteiro($bruto['sort_order'] === '' ? '0' : $bruto['sort_order'], 0, 999);
        if ($ordem === null) {
            $erros['sort_order'] = 'Um número inteiro de 0 a 999.';
        }

        return [
            'bruto' => $bruto,
            'erros' => $erros,
            'dados' => [
                'label'      => $label,
                'slug'       => $slug,
                'ping_ms'    => $ping ?? 0,
                'players'    => $players ?? 0,
                'state'      => $bruto['state'],
                'sort_order' => $ordem ?? 0,
            ],
        ];
    }

    /**
     * @return array{dados:array<string,mixed>, bruto:array<string,string>, erros:array<string,string>}
     */
    private function validarServico(Request $request, int $id): array
    {
        $bruto = [
            'label'           => trim((string)$request->input('label', '')),
            'slug'            => trim((string)$request->input('slug', '')),
            'state'           => (string)$request->input('state', 'ok'),
            'status_label'    => trim((string)$request->input('status_label', '')),
            'short_label'     => trim((string)$request->input('short_label', '')),
            'maint_scope'     => trim((string)$request->input('maint_scope', '')),
            'maint_starts_at' => trim((string)$request->input('maint_starts_at', '')),
            'maint_minutes'   => trim((string)$request->input('maint_minutes', '')),
            'sort_order'      => trim((string)$request->input('sort_order', '0')),
        ];
        $erros = [];

        if ($bruto['label'] === '') {
            $erros['label'] = 'Informe o nome do serviço.';
        } elseif (mb_strlen($bruto['label']) > 48) {
            $erros['label'] = 'No máximo 48 caracteres.';
        }

        $slug = $bruto['slug'] !== '' ? mb_strtolower($bruto['slug']) : ServersRepo::slugificar($bruto['label']);
        if ($slug === '') {
            $erros['slug'] = 'Não foi possível gerar um identificador a partir do nome. Escreva um.';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/', $slug)) {
            $erros['slug'] = 'Use de 2 a 24 caracteres: letras minúsculas, números e hífen.';
        } elseif (ServersRepo::slugOcupado('servico', $slug, $id)) {
            $erros['slug'] = 'Este identificador já pertence a outro serviço.';
        }

        if (!isset(ServersRepo::ESTADOS[$bruto['state']])) {
            $erros['state'] = 'Escolha um dos estados da lista.';
        }

        if ($bruto['status_label'] === '') {
            $erros['status_label'] = 'Informe a frase que o jogador lê.';
        } elseif (mb_strlen($bruto['status_label']) > 32) {
            $erros['status_label'] = 'No máximo 32 caracteres.';
        }

        if ($bruto['short_label'] === '') {
            $erros['short_label'] = 'Informe a versão curta.';
        } elseif (mb_strlen($bruto['short_label']) > 24) {
            $erros['short_label'] = 'No máximo 24 caracteres.';
        }

        if (mb_strlen($bruto['maint_scope']) > 48) {
            $erros['maint_scope'] = 'No máximo 48 caracteres.';
        }

        $inicio = $this->dataHora($bruto['maint_starts_at']);
        if ($bruto['maint_starts_at'] !== '' && $inicio === null) {
            $erros['maint_starts_at'] = 'Data e hora inválidas.';
        }

        $minutos = $bruto['maint_minutes'] === '' ? null : $this->inteiro($bruto['maint_minutes'], 1, 1440);
        if ($bruto['maint_minutes'] !== '' && $minutos === null) {
            $erros['maint_minutes'] = 'Duração de 1 a 1440 minutos.';
        }

        // O par início+duração é o que vira contagem regressiva (SupportRepo::maintenance).
        // Metade do par não conta nada e ainda esconde do operador que a tela do jogador não
        // vai mostrar coisa alguma.
        if ($inicio !== null && $minutos === null && !isset($erros['maint_minutes'])) {
            $erros['maint_minutes'] = 'Com horário marcado, a duração é obrigatória.';
        }
        if ($inicio === null && $minutos !== null && !isset($erros['maint_starts_at'])) {
            $erros['maint_starts_at'] = 'Com duração informada, o horário é obrigatório.';
        }
        // Serviço operacional com janela marcada é uma contradição visível: a tela do jogo
        // ignora o horário (só lê `state <> 'ok'`) e o painel diria "operacional" ao lado de
        // uma manutenção agendada.
        if ($bruto['state'] === 'ok' && ($inicio !== null || $minutos !== null)) {
            $erros['maint_starts_at'] = 'Serviço operacional não anuncia janela. Mude o estado ou limpe o horário.';
        }

        $ordem = $this->inteiro($bruto['sort_order'] === '' ? '0' : $bruto['sort_order'], 0, 999);
        if ($ordem === null) {
            $erros['sort_order'] = 'Um número inteiro de 0 a 999.';
        }

        return [
            'bruto' => $bruto,
            'erros' => $erros,
            'dados' => [
                'label'           => $bruto['label'],
                'slug'            => $slug,
                'state'           => $bruto['state'],
                'status_label'    => $bruto['status_label'],
                'short_label'     => $bruto['short_label'],
                'maint_scope'     => $bruto['maint_scope'] !== '' ? $bruto['maint_scope'] : null,
                'maint_starts_at' => $inicio,
                'maint_minutes'   => $minutos,
                'sort_order'      => $ordem ?? 0,
            ],
        ];
    }

    /**
     * @return array{dados:array<string,mixed>, bruto:array<string,string>, erros:array<string,string>}
     */
    private function validarJanela(Request $request, int $id): array
    {
        $bruto = [
            'starts_at' => trim((string)$request->input('starts_at', '')),
            'ends_at'   => trim((string)$request->input('ends_at', '')),
            'region'    => trim((string)$request->input('region', '')),
            'note'      => trim((string)$request->input('note', '')),
            'news_slug' => trim((string)$request->input('news_slug', '')),
        ];
        $erros = [];

        $ini = $this->dataHora($bruto['starts_at']);
        $fim = $this->dataHora($bruto['ends_at']);
        if ($ini === null) {
            $erros['starts_at'] = 'Informe início válido.';
        }
        if ($fim === null) {
            $erros['ends_at'] = 'Informe fim válido.';
        }
        if ($ini !== null && $fim !== null) {
            $dur = strtotime($fim) - strtotime($ini);
            if ($dur <= 0) {
                $erros['ends_at'] = 'O fim tem de ser depois do início.';
            } elseif ($dur > 30 * 86400) {
                $erros['ends_at'] = 'Janela de mais de 30 dias: confira as datas.';
            }
        }

        // O rótulo vem de um <select> montado com as regiões existentes. Conferir de novo aqui
        // não é paranoia: o POST não é obrigado a vir do formulário, e um rótulo digitado à mão
        // é exatamente como nasce uma janela órfã.
        $regioes = ServersRepo::rotulosRegioes();
        if ($bruto['region'] === '') {
            $erros['region'] = 'Escolha a região.';
        } elseif (!in_array($bruto['region'], $regioes, true)) {
            $erros['region'] = $regioes === []
                ? 'Cadastre uma região antes de agendar manutenção.'
                : 'Esta região não existe. Escolha uma da lista.';
        }

        // Só faz sentido procurar choque quando as três coisas que definem a janela já são
        // válidas — senão o operador levaria dois erros pelo mesmo engano.
        if ($ini !== null && $fim !== null && $erros === []) {
            $choque = ServersRepo::janelaSobreposta($bruto['region'], $ini, $fim, $id);
            if ($choque !== null) {
                $erros['starts_at'] = 'Já existe janela nesta região de '
                    . date('d/m H:i', (int)strtotime((string)$choque['starts_at'])) . ' a '
                    . date('d/m H:i', (int)strtotime((string)$choque['ends_at'])) . '.';
            }
        }

        if (mb_strlen($bruto['note']) > 240) {
            $erros['note'] = 'No máximo 240 caracteres.';
        }

        if ($bruto['news_slug'] !== '') {
            $slugs = array_column(ServersRepo::noticias(), 'slug');
            if (!in_array($bruto['news_slug'], $slugs, true)) {
                $erros['news_slug'] = 'Notícia inexistente. Escolha uma da lista (ou deixe em branco).';
            }
        }

        return [
            'bruto' => $bruto,
            'erros' => $erros,
            'dados' => [
                'starts_at' => $ini,
                'ends_at'   => $fim,
                'region'    => $bruto['region'],
                'note'      => $bruto['note'] !== '' ? $bruto['note'] : null,
                'news_slug' => $bruto['news_slug'] !== '' ? $bruto['news_slug'] : null,
            ],
        ];
    }

    /** Troca só o `state` da linha, pelo botão da tabela. */
    private function trocarEstado(Request $request, string $tipo): Response
    {
        $id    = max(0, (int)$request->input('id', '0'));
        $state = (string)$request->input('state', '');
        $linha = $this->carregar($tipo, $id);

        if ($tipo === 'janela' || $linha === null || !isset(ServersRepo::ESTADOS[$state])) {
            flash('error', 'Não foi possível trocar o estado.');
            return $this->redirect('admin/servidores');
        }

        $antes = (string)$linha['state'];
        if ($antes === $state) {
            // Sem gravação não há o que auditar — e um flash de sucesso mentiria sobre mudança.
            flash('info', (string)$linha['label'] . ' já estava em "' . ServersRepo::ESTADOS[$state] . '".');
            return $this->redirect('admin/servidores', $this->filtrosDe($request));
        }

        ServersRepo::trocarEstado($tipo, $id, $state);
        $this->auditar(
            'servidores_estado',
            'servidores_' . $tipo,
            $id,
            self::NOME[$tipo] . ' "' . (string)$linha['label'] . '": ' . ServersRepo::ESTADOS[$antes] . ' -> ' . ServersRepo::ESTADOS[$state],
            ['tipo' => $tipo, 'de' => $antes, 'para' => $state]
        );

        flash('ok', (string)$linha['label'] . ' agora está em "' . ServersRepo::ESTADOS[$state] . '".');
        return $this->redirect('admin/servidores', $this->filtrosDe($request));
    }

    /* =====================================================================================
       APOIO
       ===================================================================================== */

    /** Inteiro dentro da faixa, ou null quando o texto não é um inteiro válido. */
    private function inteiro(string $v, int $min, int $max): ?int
    {
        if ($v === '' || !preg_match('/^-?\d{1,12}$/', $v)) {
            return null;
        }
        $n = (int)$v;
        return $n >= $min && $n <= $max ? $n : null;
    }

    /**
     * "2026-09-12T17:00" (o que <input type="datetime-local"> manda) -> "2026-09-12 17:00:00".
     * Também aceita o formato do banco, para o POST que volta de um formulário reexibido.
     */
    private function dataHora(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        $v = str_replace('T', ' ', $v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(:\d{2})?$/', $v, $m)) {
            return null;
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59) {
            return null;
        }
        return sprintf('%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4], $m[5]);
    }

    /** Valores iniciais de um formulário novo. @return array<string,string> */
    private function padroes(string $tipo): array
    {
        return match ($tipo) {
            'regiao'  => ['label' => '', 'slug' => '', 'ping_ms' => '0', 'players' => '0', 'state' => 'ok', 'sort_order' => '0'],
            'servico' => ['label' => '', 'slug' => '', 'state' => 'ok', 'status_label' => 'OPERACIONAL',
                          'short_label' => 'ESTÁVEL', 'maint_scope' => '', 'maint_starts_at' => '',
                          'maint_minutes' => '', 'sort_order' => '0'],
            default   => ['starts_at' => date('Y-m-d\TH:i', strtotime('tomorrow 17:00')),
                          'ends_at'   => date('Y-m-d\TH:i', strtotime('tomorrow 19:00')),
                          'region'    => (string)(ServersRepo::rotulosRegioes()[0] ?? ''),
                          'note'      => '', 'news_slug' => ''],
        };
    }

    /**
     * Linha do banco -> campos do formulário (os DATETIME viram o formato do input).
     * @return array<string,string>
     */
    private function paraFormulario(string $tipo, array $l): array
    {
        $local = static fn(?string $v): string => $v === null || $v === '' ? '' : date('Y-m-d\TH:i', (int)strtotime($v));

        return match ($tipo) {
            'regiao' => [
                'label' => (string)$l['label'], 'slug' => (string)$l['slug'],
                'ping_ms' => (string)$l['ping_ms'], 'players' => (string)$l['players'],
                'state' => (string)$l['state'], 'sort_order' => (string)$l['sort_order'],
            ],
            'servico' => [
                'label' => (string)$l['label'], 'slug' => (string)$l['slug'], 'state' => (string)$l['state'],
                'status_label' => (string)$l['status_label'], 'short_label' => (string)$l['short_label'],
                'maint_scope' => (string)($l['maint_scope'] ?? ''),
                'maint_starts_at' => $local($l['maint_starts_at'] ?? null),
                'maint_minutes' => (string)($l['maint_minutes'] ?? ''),
                'sort_order' => (string)$l['sort_order'],
            ],
            default => [
                'starts_at' => $local((string)$l['starts_at']), 'ends_at' => $local((string)$l['ends_at']),
                'region' => (string)$l['region'], 'note' => (string)($l['note'] ?? ''),
                'news_slug' => (string)($l['news_slug'] ?? ''),
            ],
        };
    }

    /** Como a entidade é chamada no flash e na auditoria, a partir dos dados JÁ validados. */
    private function rotuloDe(string $tipo, array $d): string
    {
        return $tipo === 'janela'
            ? '"' . (string)$d['region'] . '" ' . date('d/m/Y H:i', (int)strtotime((string)$d['starts_at']))
            : '"' . (string)$d['label'] . '"';
    }

    /** O mesmo, a partir de uma linha crua do banco. */
    private function rotuloCru(string $tipo, array $l): string
    {
        return $tipo === 'janela'
            ? '"' . (string)$l['region'] . '" ' . date('d/m/Y H:i', (int)strtotime((string)$l['starts_at']))
            : '"' . (string)$l['label'] . '"';
    }

    /**
     * O que foi apagado, em texto — vai para a tela de confirmação E para o `meta` da
     * auditoria. Uma exclusão sem cópia do conteúdo é um log que não prova nada.
     *
     * @return array<string,string>
     */
    private function resumirLinha(string $tipo, array $l): array
    {
        return match ($tipo) {
            'regiao' => [
                'Identificador' => (string)$l['slug'],
                'Ping'          => (string)$l['ping_ms'] . ' ms',
                'Jogadores'     => num((int)$l['players']),
                'Estado'        => ServersRepo::ESTADOS[(string)$l['state']] ?? (string)$l['state'],
            ],
            'servico' => [
                'Identificador' => (string)$l['slug'],
                'Estado'        => ServersRepo::ESTADOS[(string)$l['state']] ?? (string)$l['state'],
                'Rótulo'        => (string)$l['status_label'],
                'Escopo'        => (string)($l['maint_scope'] ?? '') ?: '—',
            ],
            default => [
                'Região' => (string)$l['region'],
                'Início' => date('d/m/Y H:i', (int)strtotime((string)$l['starts_at'])),
                'Fim'    => date('d/m/Y H:i', (int)strtotime((string)$l['ends_at'])),
                'Nota'   => (string)($l['note'] ?? '') ?: '—',
            ],
        };
    }

    /**
     * Só os campos que MUDARAM, para o `meta` da auditoria. Registrar o formulário inteiro em
     * toda edição faz o log crescer sem dizer o que aconteceu.
     *
     * @return array<string,array{de:mixed,para:mixed}>
     */
    private function diff(?array $antes, array $depois): array
    {
        if ($antes === null) {
            return [];
        }
        $out = [];
        foreach ($depois as $campo => $novo) {
            if (!array_key_exists($campo, $antes)) {
                continue;
            }
            $velho = $antes[$campo];
            if ((string)$velho !== (string)$novo) {
                $out[$campo] = ['de' => $velho, 'para' => $novo];
            }
        }
        return $out;
    }

    /**
     * Os indicadores da topbar desta tela. Não repetem os do dashboard: aqui o assunto é
     * infraestrutura, então os três são regiões, serviços e a próxima parada programada.
     *
     * @param array<string,mixed> $r
     * @return list<array<string,mixed>>
     */
    private function kpisDaTopbar(array $r): array
    {
        $prox = $r['proxima'] ?? null;

        return [
            [
                'icon'  => 'public',
                'label' => 'Regiões online',
                'value' => $r['regioes'] > 0 ? $r['regioes_ok'] . '/' . $r['regioes'] : null,
                'tone'  => $r['regioes'] > 0 && $r['regioes_ok'] === $r['regioes'] ? 'lime' : 'amber',
            ],
            [
                'icon'  => 'dns',
                'label' => 'Serviços no ar',
                'value' => $r['servicos'] > 0 ? $r['servicos_ok'] . '/' . $r['servicos'] : null,
                'tone'  => $r['servicos'] > 0 && $r['servicos_ok'] === $r['servicos'] ? 'cyan' : 'amber',
            ],
            [
                'icon'  => 'schedule',
                'label' => $prox !== null && $prox['ativa'] ? 'Janela ativa' : 'Próxima janela',
                'value' => $prox === null ? null : date('d/m H:i', (int)$prox['inicio']),
                'sub'   => $prox === null ? null : $prox['regiao'],
                'tone'  => $prox !== null && $prox['ativa'] ? 'red' : 'amber',
            ],
        ];
    }

    /**
     * O sino desta tela. Só fato do banco: serviço degradado, região fora do padrão e a janela
     * que já começou. Nada disso é decorativo — sem ocorrência, o sino fica mudo.
     *
     * @param list<array<string,mixed>> $regioes
     * @param list<array<string,mixed>> $servicos
     * @param array<string,mixed> $resumo
     * @return list<array<string,mixed>>
     */
    private function alertas(array $regioes, array $servicos, array $resumo): array
    {
        $out = [];

        foreach ($servicos as $s) {
            if ($s['state'] === 'ok') {
                continue;
            }
            $out[] = [
                'icon' => $s['state'] === 'down' ? 'error' : 'build',
                'tone' => $s['state'] === 'down' ? 'red' : 'amber',
                'text' => $s['label'] . ': ' . $s['rotulo'],
                'meta' => $s['escopo'],
            ];
        }

        foreach ($regioes as $r) {
            if ($r['estado'] === 'online') {
                continue;
            }
            $out[] = [
                'icon' => $r['estado'] === 'fora' ? 'error' : 'public',
                'tone' => $r['estado'] === 'fora' ? 'red' : 'amber',
                'text' => $r['label'] . ': ' . $r['estado_rotulo'],
                'meta' => $r['nota'],
            ];
        }

        $prox = $resumo['proxima'] ?? null;
        if ($prox !== null && $prox['ativa']) {
            $out[] = [
                'icon' => 'schedule',
                'tone' => 'cyan',
                'text' => 'Janela em andamento em ' . $prox['regiao'],
                'meta' => 'Termina ' . date('d/m H:i', (int)$prox['fim']),
            ];
        }

        return $out;
    }
}
