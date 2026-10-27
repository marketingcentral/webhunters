<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Response;
use App\Repo\AdminSettingsRepo;

/**
 * /api/v1/settings — os parâmetros do jogo de `admin_settings`, ler e escrever.
 *
 * POR QUE A CHAVE NÃO VAI NA URL
 * ------------------------------
 * O roteador deste projeto aceita um único alfabeto em `{param}`: [a-z0-9-]. As chaves de
 * configuração usam sublinhado (`maintenance_enabled`, `squad_max`), que não casa — uma rota
 * /settings/{chave} devolveria 404 na metade das chaves, e "funciona para algumas chaves" é
 * pior do que não existir. Traduzir hífen em sublinhado seria mágica escondida, que quebra no
 * dia em que nascer uma chave com hífen de verdade.
 *
 * Então a leitura de uma chave é `GET /settings?key=squad_max` e a escrita é um PUT na
 * coleção, com o corpo `{"values": {"squad_max": "4", "xp_per_level": "12000"}}`.
 *
 * E ISSO SAIU MELHOR DO QUE O ACIDENTE. Configuração quase nunca muda sozinha: subir a
 * capacidade de uma região sem subir as vagas de Hunter deixa o mundo incoerente por alguns
 * segundos. Com o PUT na coleção, o conjunto é validado INTEIRO antes de qualquer gravação —
 * ou tudo entra, ou nada entra e a resposta diz campo a campo o que estava errado. Numa rota
 * por chave, o cliente teria de emendar três chamadas e conviver com o estado do meio.
 *
 * O QUE A API NÃO FAZ AQUI: criar chave nova e apagar chave. As chaves existentes são lidas
 * pelo CÓDIGO do jogo (AdminSettingsRepo::CHAVES_DO_CODIGO); inventar uma pela API criaria
 * uma linha que nada lê, e apagar uma delas faria o jogo cair no padrão do código sem
 * ninguém entender por quê. Criar e remover continua sendo tarefa da tela, que mostra o
 * tipo, o grupo e o aviso.
 */
final class ConfiguracoesRecurso extends Recurso
{
    public function nome(): string
    {
        return 'settings';
    }

    /** @return list<array{0:string, 1:string, 2:string, 3:string, 4?:string}> */
    public function rotas(): array
    {
        return [
            ['GET', '', 'listar', 'Lista os parâmetros (use ?key= para um só, ?grupo= para um grupo).', '?grupo=mundo'],
            ['PUT', '', 'gravar', 'Grava vários parâmetros de uma vez: {"values":{"chave":"valor"}}.', ''],
        ];
    }

    protected function listar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $chave = $req->consulta('key');
        $grupo = $req->consulta('grupo');

        if ($chave !== '') {
            $linha = AdminSettingsRepo::porChave($chave);
            return $linha === null
                ? $this->naoEncontrado('Não existe parâmetro com esta chave.')
                : Resposta::ok(self::saida($linha));
        }

        $linhas = AdminSettingsRepo::todas();
        if ($grupo !== '') {
            $linhas = array_values(array_filter($linhas, static fn(array $l): bool => (string)$l['grupo'] === $grupo));
        }

        return Resposta::ok(array_map(self::saida(...), $linhas), ['total' => count($linhas)]);
    }

    /**
     * Grava um conjunto de parâmetros. Tudo ou nada.
     *
     * A validação usa AdminSettingsRepo::validar() com as regras de app/Data/admin-config.php
     * — as MESMAS que a tela aplica. Uma segunda tabela de faixas aqui deixaria a API aceitar
     * o que o formulário recusa, e o jogo passaria a receber pela porta lateral o valor que a
     * porta da frente barra.
     */
    protected function gravar(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $corpo  = $req->corpo();
        $pedido = $corpo['values'] ?? null;
        if (!is_array($pedido) || $pedido === []) {
            return Resposta::erro('entrada_invalida', '', [
                'values' => 'Envie um objeto `values` com pelo menos uma chave: {"values":{"squad_max":"4"}}.',
            ]);
        }

        $regras = (array)(data_mock('admin-config')['chaves'] ?? []);
        $erros  = [];
        $prontos = [];

        // PRIMEIRA PASSADA: valida tudo. Nada é gravado enquanto houver um erro sequer —
        // é isto que faz do conjunto uma operação só.
        foreach ($pedido as $chave => $bruto) {
            $chave = (string)$chave;
            $linha = AdminSettingsRepo::porChave($chave);
            if ($linha === null) {
                $erros[$chave] = 'Não existe parâmetro com esta chave. A API não cria chave nova.';
                continue;
            }
            if (is_array($bruto) && (string)$linha['kind'] !== 'json') {
                $erros[$chave] = 'Esperava um valor simples, não um objeto.';
                continue;
            }

            $texto = is_array($bruto)
                ? (string)json_encode($bruto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (is_bool($bruto) ? ($bruto ? '1' : '0') : (string)$bruto);

            $v = AdminSettingsRepo::validar((string)$linha['kind'], $texto, (array)($regras[$chave] ?? []));
            if (!$v['ok']) {
                $erros[$chave] = $v['erro'];
                continue;
            }
            $prontos[$chave] = ['valor' => $v['valor'], 'antes' => $linha['value'], 'kind' => $linha['kind']];
        }

        if ($erros !== []) {
            return Resposta::erro('entrada_invalida', '', $erros);
        }

        // SEGUNDA PASSADA: grava. `gravar()` devolve false quando o valor não mudou — e esse
        // caso NÃO vira linha de auditoria: "alterou de 4 para 4" é ruído que, repetido por
        // um script que salva tudo a cada minuto, soterra a trilha de verdade.
        $mudadas = [];
        foreach ($prontos as $chave => $p) {
            if (AdminSettingsRepo::gravar($chave, $p['valor'], 0)) {
                $mudadas[$chave] = ['from' => $p['antes'], 'to' => $p['valor']];
            }
        }

        if ($mudadas !== []) {
            $ctx->auditar(
                'config_alterada',
                'admin_settings',
                implode(',', array_slice(array_keys($mudadas), 0, 5)),
                sprintf('Alterou %d parâmetro(s) pela API: %s.', count($mudadas), implode(', ', array_keys($mudadas))),
                ['mudancas' => $mudadas]
            );
        }

        return Resposta::ok([
            'changed'   => array_keys($mudadas),
            'unchanged' => array_values(array_diff(array_keys($prontos), array_keys($mudadas))),
            'values'    => array_map(self::saida(...), array_values(array_filter(
                AdminSettingsRepo::todas(),
                static fn(array $l): bool => isset($prontos[(string)$l['key']])
            ))),
        ]);
    }

    /**
     * Linha do banco -> objeto da API.
     *
     * `value` sai como TEXTO cru e `typed` como o valor já convertido para o tipo declarado.
     * Os dois, porque são para usos diferentes: quem vai reescrever a chave precisa do texto
     * exato que está gravado; quem vai USAR o parâmetro quer o booleano ou o inteiro.
     *
     * @param array<string,mixed> $l
     * @return array<string,mixed>
     */
    private static function saida(array $l): array
    {
        $bruto = $l['value'] === null ? null : (string)$l['value'];
        $kind  = (string)$l['kind'];

        return [
            'key'        => (string)$l['key'],
            'label'      => (string)$l['label'],
            'group'      => (string)$l['grupo'],
            'kind'       => $kind,
            'value'      => $bruto,
            'typed'      => $bruto === null ? null : match ($kind) {
                'int'   => is_numeric($bruto) ? (int)$bruto : null,
                'bool'  => $bruto === '1',
                'json'  => json_decode($bruto, true),
                default => $bruto,
            },
            'updated_at' => (string)$l['updated_at'],
            'updated_by' => $l['updated_por'] === null ? null : (string)$l['updated_por'],
        ];
    }
}
