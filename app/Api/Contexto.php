<?php declare(strict_types=1);

namespace App\Api;

use App\Repo\AdminRepo;

/**
 * Quem está falando com a API, e a caneta com que ele assina o que faz.
 *
 * TODA ESCRITA DA API PASSA POR `auditar()`. A trilha é a mesma `admin_audit_log` do painel,
 * de propósito: uma segunda trilha "só da API" dividiria a história do jogo em dois lugares,
 * e a primeira pergunta de qualquer investigação — "quem mudou isto?" — passaria a exigir
 * duas consultas e uma junção mental. O operador abre /admin/logs e vê a ordem real dos
 * acontecimentos, tenha ela vindo de um clique ou de um script.
 *
 * COMO A CHAVE APARECE NA TRILHA
 * ------------------------------
 * `admin_id` fica NULO. Não é omissão: a coluna significa "qual PESSOA fez", e atribuir a
 * ação ao administrador que criou a chave, meses atrás, seria escrever na prova o nome de
 * alguém que não estava lá. A identidade da chave viaja em três lugares que a tela já lê:
 *
 *   - a ação ganha o prefixo `api_`, então dá para separar as duas origens num filtro;
 *   - o resumo termina com "via chave <nome> (<prefixo>)", e o resumo é varrido pela busca
 *     livre de /admin/logs — procurar pelo prefixo devolve tudo o que aquela chave fez;
 *   - `meta.api_key` guarda id, prefixo e nome em campos separados, para quem for ler o JSON.
 */
final class Contexto
{
    /** @param array<string,mixed> $chave linha de `api_keys` já autenticada */
    public function __construct(
        public readonly array $chave,
        public readonly string $ip,
    ) {
    }

    public function chaveId(): int
    {
        return (int)($this->chave['id'] ?? 0);
    }

    public function prefixo(): string
    {
        return (string)($this->chave['prefix'] ?? '');
    }

    public function nomeDaChave(): string
    {
        return (string)($this->chave['name'] ?? '');
    }

    /** @return list<string> */
    public function escopos(): array
    {
        $e = $this->chave['scopes'] ?? [];
        return is_array($e) ? $e : [];
    }

    public function temEscopo(string $escopo): bool
    {
        return in_array($escopo, $this->escopos(), true);
    }

    /**
     * Uma linha na trilha de auditoria, assinada pela chave.
     *
     * @param array<string,mixed> $meta
     */
    public function auditar(string $acao, string $entidade, string|int|null $entidadeId, string $resumo, array $meta = []): void
    {
        $assinatura = sprintf('via chave %s (%s)', $this->nomeDaChave(), $this->prefixo());

        AdminRepo::registrar(
            null,                                   // ver o comentário do cabeçalho
            'api_' . $acao,
            $entidade,
            $entidadeId !== null ? (string)$entidadeId : null,
            trim($resumo) . ' — ' . $assinatura,
            $meta + ['api_key' => [
                'id'     => $this->chaveId(),
                'prefix' => $this->prefixo(),
                'name'   => $this->nomeDaChave(),
            ]],
            $this->ip
        );
    }
}
