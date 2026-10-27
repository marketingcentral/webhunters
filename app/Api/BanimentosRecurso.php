<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Response;
use App\Repo\AdminPlayersRepo;
use App\Repo\ApiGestaoRepo;
use App\Repo\BanRepo;

/**
 * /api/v1/bans — histórico de punições, aplicar e revogar.
 *
 * ESTE É O ÚNICO CAMINHO DA API PARA PUNIR. `PATCH /players/{id}` recusa escrever
 * `status: banned` de propósito (ver JogadoresRecurso): punição sem linha em `bans` é uma
 * conta bloqueada sem motivo, sem prazo e sem autor — e o AccountGuard do jogo a
 * destrancaria na requisição seguinte, ao procurar a punição vigente e não achar nenhuma.
 *
 * `DELETE /bans/{id}` REVOGA, não apaga. O verbo HTTP diz "tire isto de vigor", e é o que
 * acontece: `revoked_at`, `revoked_by` e o motivo são gravados e a conta é recalculada. A
 * linha continua na tabela porque ela é a prova de que a punição existiu — e a tela do painel
 * a mostra como revogada. Apagar o histórico é o que nem o dono faz por aqui.
 *
 * Toda escrita usa App\Repo\BanRepo, que é o mesmo caminho da tela /admin/banimentos: é ele
 * que grava a punição e sincroniza `users.status` NA MESMA TRANSAÇÃO. Um ban gravado com a
 * conta ainda liberada seria pior do que ban nenhum.
 */
final class BanimentosRecurso extends Recurso
{
    public function nome(): string
    {
        return 'bans';
    }

    /** @return list<array{0:string, 1:string, 2:string, 3:string, 4?:string}> */
    public function rotas(): array
    {
        return [
            ['GET',    '',     'listar',  'Lista punições (todas, não só as vigentes).', '?user_id=&state=ativo&kind=temp&limit=50'],
            ['GET',    '{id}', 'obter',   'Uma punição pelo id.',                        ''],
            ['POST',   '',     'criar',   'Aplica uma punição e bloqueia a conta.',      ''],
            ['DELETE', '{id}', 'revogar', 'Revoga a punição e devolve a conta ao jogo.', ''],
        ];
    }

    protected function listar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        [$limite, $deslocamento] = $this->pagina($req);

        $r = ApiGestaoRepo::banimentos([
            'user_id' => (int)$req->consultaInt('user_id', 0, 0, 999999999),
            'state'   => $req->consulta('state'),
            'kind'    => $req->consulta('kind'),
        ], $limite, $deslocamento);

        return Resposta::ok($r['linhas'], Resposta::paginacao($r['total'], $limite, $deslocamento));
    }

    protected function obter(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $ban = ApiGestaoRepo::banimento($this->id($params));
        return $ban === null ? $this->naoEncontrado('Punição não encontrada.') : Resposta::ok($ban);
    }

    /**
     * Aplica a punição.
     *
     * Campos: user_id (obrigatório), kind (temp|perma|suspensao), reason (obrigatório),
     * expires_at (AAAA-MM-DD HH:MM:SS ou AAAA-MM-DD; exigido nos tipos com prazo).
     *
     * O MOTIVO É OBRIGATÓRIO e isso não é burocracia: é o texto que o jogador lê quando tenta
     * entrar (AccountGuard::mensagem) e é a única defesa do operador quando a punição é
     * contestada. Um ban sem motivo vira, semanas depois, uma decisão que ninguém consegue
     * justificar nem reverter com segurança.
     */
    protected function criar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $corpo = $req->corpo();
        $erros = [];

        $userId = (int)($corpo['user_id'] ?? 0);
        $conta  = $userId > 0 ? AdminPlayersRepo::conta($userId) : null;
        if ($conta === null) {
            $erros['user_id'] = 'Informe o id de uma conta existente.';
        } elseif ((string)$conta['role'] === 'owner') {
            // Punir o dono pela API seria o jeito mais rápido de trancar o jogo por fora.
            $erros['user_id'] = 'A conta do dono do jogo não pode ser punida pela API.';
        }

        $tipo = strtolower(trim((string)($corpo['kind'] ?? 'temp')));
        if (!isset(BanRepo::TIPOS[$tipo])) {
            $erros['kind'] = 'Tipo aceito: ' . implode(', ', array_keys(BanRepo::TIPOS)) . '.';
        }

        $motivo = trim((string)($corpo['reason'] ?? ''));
        if ($motivo === '') {
            $erros['reason'] = 'O motivo é obrigatório: é ele que o jogador lê ao ser barrado.';
        } elseif (mb_strlen($motivo) > 1000) {
            $erros['reason'] = 'O motivo não pode passar de 1000 caracteres.';
        }

        $expira = null;
        if ($erros === [] && BanRepo::TIPOS[$tipo]['prazo']) {
            $expira = self::prazo((string)($corpo['expires_at'] ?? ''));
            if ($expira === null) {
                $erros['expires_at'] = 'Tipo "' . $tipo . '" exige prazo futuro em AAAA-MM-DD ou AAAA-MM-DD HH:MM:SS.';
            }
        }

        if ($erros !== []) {
            return Resposta::erro('entrada_invalida', '', $erros);
        }

        // Punição já vigente: aplicar outra por cima empilharia duas verdades sobre a mesma
        // conta, e a mensagem que o jogador veria dependeria de qual delas o SELECT pegasse.
        if (AdminPlayersRepo::banAtivo($userId) !== null) {
            return Resposta::erro('conflito', 'Esta conta já tem punição vigente. Revogue a atual antes de aplicar outra.');
        }

        // admin_id NULO: quem puniu foi uma chave, não uma pessoa. Atribuir a punição ao
        // administrador que criou a chave, meses atrás, poria na prova o nome de alguém que
        // não estava lá. A identidade da chave vai para a trilha de auditoria, logo abaixo.
        $banId = BanRepo::aplicar($userId, null, $tipo, $motivo, $expira);

        $ctx->auditar(
            'ban_aplicado',
            'bans',
            $banId,
            sprintf('Punição %s aplicada a %s pela API.', $tipo, (string)$conta['username']),
            ['user_id' => $userId, 'kind' => $tipo, 'expires_at' => $expira, 'reason' => mb_substr($motivo, 0, 180)]
        );

        return Resposta::ok(ApiGestaoRepo::banimento($banId) ?? ['id' => $banId], [], 201)
            ->withHeader('Location', url(ltrim(Requisicao::PREFIXO, '/') . '/bans/' . $banId));
    }

    protected function revogar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $id  = $this->id($params);
        $ban = ApiGestaoRepo::banimento($id);
        if ($ban === null) {
            return $this->naoEncontrado('Punição não encontrada.');
        }

        $motivo = trim((string)($req->corpo()['reason'] ?? ''));
        if ($motivo === '') {
            $erro = 'Informe `reason`: revogar sem motivo deixa a punição e o perdão igualmente inexplicáveis.';
            return Resposta::erro('entrada_invalida', '', ['reason' => $erro]);
        }

        // false = já estava revogada. A condição mora no UPDATE do repo, então duas chamadas
        // simultâneas não sobrescrevem a assinatura de quem chegou primeiro.
        if (!BanRepo::revogar($id, null, mb_substr($motivo, 0, 1000))) {
            return Resposta::erro('conflito', 'Esta punição já estava revogada.');
        }

        $ctx->auditar(
            'ban_revogado',
            'bans',
            $id,
            sprintf('Punição #%d de %s revogada pela API.', $id, (string)$ban['username']),
            ['user_id' => $ban['user_id'], 'reason' => mb_substr($motivo, 0, 180)]
        );

        return Resposta::ok(ApiGestaoRepo::banimento($id) ?? []);
    }

    /**
     * Prazo de punição: aceita dia (vira o fim do dia) ou data e hora. Devolve null quando
     * não é data válida OU quando já passou — um ban que nasce vencido é um ban que não pune.
     */
    private static function prazo(string $bruto): ?string
    {
        $bruto = trim($bruto);
        if ($bruto === '') {
            return null;
        }

        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $bruto);
        if ($d !== false && $d->format('Y-m-d') === $bruto) {
            $d = $d->setTime(23, 59, 59);
        } else {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $bruto);
            if ($d === false) {
                return null;
            }
        }
        return $d->getTimestamp() > time() ? $d->format('Y-m-d H:i:s') : null;
    }
}
