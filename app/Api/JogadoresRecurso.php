<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Response;
use App\Repo\AdminPlayersRepo;
use App\Repo\ApiGestaoRepo;

/**
 * /api/v1/players — contas, papel, situação e progresso.
 *
 * DUAS TRAVAS QUE NÃO SÃO NEGOCIÁVEIS
 *
 * 1. O papel `owner` não existe para a API — nem como valor a gravar, nem como conta a
 *    alterar. Uma chave vazada não pode criar um dono do jogo nem rebaixar o que existe. Essa
 *    é a única promoção que muda quem manda no produto, e ela continua sendo um ato humano,
 *    feito no painel por alguém que entrou com senha.
 *
 * 2. Nada aqui apaga conta. A API tem `players:write`, e mesmo assim `DELETE /players/{id}`
 *    não existe: apagar uma conta arrasta partidas, compras e punições — isto é, apaga a
 *    prova do que aconteceu no mundo. A tela do painel já trata isso como caso especial, com
 *    conferência de referências; um endpoint de API seria o caminho curto para o estrago que
 *    a tela evita. Para tirar alguém do jogo existe `status`, que é reversível.
 *
 * AVISO HONESTO SOBRE `players:write`: com este escopo a chave PODE promover uma conta comum
 * a `admin`, e administrador entra no painel. Conceder `players:write` é, na prática,
 * conceder a capacidade de criar um administrador — a tela /admin/api diz isso em voz alta na
 * hora de marcar a caixa, para a escolha ser consciente.
 */
final class JogadoresRecurso extends Recurso
{
    /** Papéis que a API pode GRAVAR. `owner` fica fora — ver o cabeçalho. */
    private const PAPEIS_GRAVAVEIS = ['player', 'admin'];

    public function nome(): string
    {
        return 'players';
    }

    /** @return list<array{0:string, 1:string, 2:string, 3:string, 4?:string}> */
    public function rotas(): array
    {
        return [
            ['GET',   '',            'listar',    'Lista contas (sem e-mail: use /players/{id}).', '?q=&role=player&status=active&limit=50&offset=0'],
            ['GET',   '{id}',        'obter',     'Uma conta, com ficha de jogador e punição vigente.', ''],
            ['PATCH', '{id}',        'atualizar', 'Altera papel (player|admin) e/ou situação.',    ''],
            ['GET',   '{id}/progress', 'progresso', 'Progresso: temporada, missões e conquistas.', ''],
        ];
    }

    protected function listar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        [$limite, $deslocamento] = $this->pagina($req);

        $r = ApiGestaoRepo::jogadores([
            'q'      => $req->consulta('q'),
            'role'   => $req->consulta('role'),
            'status' => $req->consulta('status'),
        ], $limite, $deslocamento);

        return Resposta::ok($r['linhas'], Resposta::paginacao($r['total'], $limite, $deslocamento));
    }

    protected function obter(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $conta = ApiGestaoRepo::jogador($this->id($params));
        return $conta === null ? $this->naoEncontrado('Conta não encontrada.') : Resposta::ok($conta);
    }

    protected function progresso(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $p = ApiGestaoRepo::progresso($this->id($params));
        return $p === null ? $this->naoEncontrado('Conta não encontrada.') : Resposta::ok($p);
    }

    /**
     * Altera papel e/ou situação. Alteração parcial: o que não vem no corpo não é tocado.
     *
     * A situação é escrita DIRETAMENTE aqui só quando não há punição envolvida. Banir com
     * motivo e prazo é outra operação — POST /bans —, porque um `status: banned` solto
     * deixaria a conta bloqueada sem nenhuma linha em `bans` explicando por quê, e o próprio
     * AccountGuard do jogo destrancaria a conta na requisição seguinte ao não achar punição
     * vigente. A API recusa esse caminho e diz para onde ir.
     */
    protected function atualizar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $id = $this->id($params);
        $conta = AdminPlayersRepo::conta($id);
        if ($conta === null) {
            return $this->naoEncontrado('Conta não encontrada.');
        }
        if ((string)$conta['role'] === 'owner') {
            return Resposta::erro('nao_permitido', 'A conta do dono do jogo não pode ser alterada pela API.');
        }

        $corpo  = $req->corpo();
        $erros  = [];
        $papel  = (string)$conta['role'];
        $estado = (string)$conta['status'];

        if (array_key_exists('role', $corpo)) {
            $novo = strtolower(trim((string)$corpo['role']));
            if (!in_array($novo, self::PAPEIS_GRAVAVEIS, true)) {
                $erros['role'] = 'Papel aceito: ' . implode(' ou ', self::PAPEIS_GRAVAVEIS) . '.';
            } else {
                $papel = $novo;
            }
        }

        if (array_key_exists('status', $corpo)) {
            $novo = strtolower(trim((string)$corpo['status']));
            if (!in_array($novo, AdminPlayersRepo::SITUACOES, true)) {
                $erros['status'] = 'Situação aceita: ' . implode(', ', AdminPlayersRepo::SITUACOES) . '.';
            } elseif ($novo !== 'active') {
                $erros['status'] = 'Para punir uma conta use POST /api/v1/bans, que grava motivo e prazo. '
                    . 'Um status de punição sem punição registrada seria desfeito pelo próprio jogo.';
            } else {
                $estado = $novo;
            }
        }

        if ($erros !== []) {
            return Resposta::erro('entrada_invalida', '', $erros);
        }
        if ($papel === (string)$conta['role'] && $estado === (string)$conta['status']) {
            // Nada mudou: não suja a trilha com "alterou de admin para admin".
            return Resposta::ok(ApiGestaoRepo::jogador($id) ?? []);
        }

        // Voltar para `active` com punição vigente exigiria revogar o ban junto — e revogar é
        // uma operação com motivo, que mora em DELETE /bans/{id}. Recusar aqui evita a conta
        // ficar `active` com um ban vivo, estado em que o AccountGuard a barraria de novo.
        if ($estado === 'active' && (string)$conta['status'] !== 'active' && AdminPlayersRepo::banAtivo($id) !== null) {
            return Resposta::erro(
                'conflito',
                'Esta conta tem punição vigente. Revogue por DELETE /api/v1/bans/{id} — assim a situação é recalculada com registro do motivo.'
            );
        }

        AdminPlayersRepo::atualizarConta($id, $papel, $estado);
        $ctx->auditar(
            'jogador_alterado',
            'users',
            $id,
            sprintf(
                'Conta %s: papel %s -> %s, situação %s -> %s, pela API.',
                (string)$conta['username'],
                (string)$conta['role'],
                $papel,
                (string)$conta['status'],
                $estado
            ),
            ['de' => ['role' => $conta['role'], 'status' => $conta['status']], 'para' => ['role' => $papel, 'status' => $estado]]
        );

        return Resposta::ok(ApiGestaoRepo::jogador($id) ?? []);
    }
}
