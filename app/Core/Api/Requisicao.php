<?php declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Request;

/**
 * A requisição vista PELA API — o que App\Core\Request não tem por que saber.
 *
 * Três coisas que só existem aqui:
 *
 * 1. CORPO EM JSON. `Request` lê $_POST, que o PHP só preenche para formulário
 *    (application/x-www-form-urlencoded ou multipart). Um cliente de API manda JSON, e para
 *    esse o $_POST chega VAZIO — sem esta classe, todo POST da API gravaria campos em branco
 *    sem reclamar. O corpo é lido de php://input uma única vez e guardado.
 *
 * 2. A CHAVE, só do cabeçalho `Authorization: Bearer <chave>`. Nunca da querystring: a URL
 *    inteira vai para o log do servidor web, para o histórico do navegador, para o cabeçalho
 *    Referer e para qualquer proxy no caminho — uma credencial ali está vazada por
 *    construção, e revogá-la depois não apaga onde ela já ficou escrita.
 *
 * 3. SEGMENTOS DA ROTA. O despachante precisa do caminho DEPOIS de /api/v1 já partido em
 *    pedaços, e precisa dele mesmo quando o pedaço não é um `{param}` que o Router aceitaria.
 *
 * O caminho já vem sem o prefixo da instalação (WAMP serve em /extracthunters/), porque
 * Request::path() resolve isso antes.
 */
final class Requisicao
{
    /** Prefixo público da API. Muda a versão inteira quando mudar. */
    public const PREFIXO = '/api/v1';

    /** Teto do corpo aceito. Acima disso é 413 — nenhum recurso desta API recebe 1 MiB. */
    public const CORPO_MAX_BYTES = 262144;   // 256 KiB

    /** @var list<string> Segmentos depois de /api/v1, já sem vazios. */
    private array $segmentos;

    /** @var array<string,mixed>|null Corpo decodificado; null enquanto não foi lido. */
    private ?array $corpo = null;

    /** Erro de leitura do corpo ('' quando não houve). Guardado para virar 400 uma vez só. */
    private string $erroCorpo = '';

    private bool $corpoLido = false;

    private function __construct(
        private readonly Request $base,
        private readonly string $metodo,
        private readonly string $caminho,
    ) {
        $resto = substr($caminho, strlen(self::PREFIXO));
        $this->segmentos = array_values(array_filter(explode('/', trim($resto, '/')), static fn(string $s): bool => $s !== ''));
    }

    /**
     * Esta requisição é uma chamada de /api/v1? Decidido a partir de $_SERVER, ANTES de
     * existir um objeto Request.
     *
     * Existe por causa de um único chamador: app/bootstrap.php, que precisa saber se abre
     * sessão. Uma API servidor-a-servidor não usa sessão para nada, e abrir uma a cada
     * chamada deixaria um arquivo de sessão no disco por requisição — num cliente que chama
     * em laço, isso é lixo acumulando por nada, além de um Set-Cookie inútil em toda
     * resposta.
     *
     * A detecção é ESTREITA de propósito: só o caminho exato sob /api/v1. Se ela errasse
     * para mais, uma página do jogo perderia a sessão do jogador — então o casamento é com o
     * prefixo seguido de fim de string ou barra, nunca com "contém".
     */
    public static function ehChamada(): bool
    {
        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
        $path = rawurldecode($path);

        // O mesmo desconto de base do Request::fromGlobals(): no WAMP o site vive em
        // /extracthunters/, e sem isto o prefixo nunca casaria no desenvolvimento.
        $base = rtrim(\App\Core\Config::baseUrl(), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = preg_replace('#^/index\.php#', '', $path) ?? $path;
        $path = '/' . trim(preg_replace('#/{2,}#', '/', $path) ?? $path, '/');

        return $path === self::PREFIXO || str_starts_with($path, self::PREFIXO . '/');
    }

    public static function de(Request $base): self
    {
        // O método real, e não o normalizado: Request converte HEAD em GET (correto para
        // páginas), e aqui a diferença entre GET e HEAD não muda nada — mas PUT, PATCH e
        // DELETE precisam chegar inteiros, e esses o Request já preserva.
        return new self($base, $base->method(), $base->path());
    }

    public function metodo(): string
    {
        return $this->metodo;
    }

    public function caminho(): string
    {
        return $this->caminho;
    }

    public function ip(): string
    {
        return $this->base->ip();
    }

    /** @return list<string> */
    public function segmentos(): array
    {
        return $this->segmentos;
    }

    /** Segmento por posição (0 = recurso), ou '' quando não existe. */
    public function segmento(int $i): string
    {
        return $this->segmentos[$i] ?? '';
    }

    /** O recurso pedido: o primeiro segmento depois de /api/v1. */
    public function recurso(): string
    {
        return strtolower($this->segmento(0));
    }

    /** Muda estado? Decide qual verbo de escopo será exigido e se o corpo é lido. */
    public function escreve(): bool
    {
        return $this->metodo !== 'GET';
    }

    /**
     * A chave apresentada, ou '' quando não veio.
     *
     * Três fontes para o MESMO cabeçalho porque `Authorization` não chega igual em toda
     * instalação: o Apache com mod_php entrega em HTTP_AUTHORIZATION; atrás de CGI/FastCGI
     * (o caso do OpenLiteSpeed da produção) o cabeçalho é removido por padrão e só reaparece
     * como REDIRECT_HTTP_AUTHORIZATION quando o .htaccess o reinjeta — é por isso que existe
     * a regra `E=HTTP_AUTHORIZATION` no .htaccess da raiz. getallheaders() é a terceira
     * tentativa, para o caso de nenhuma das duas variáveis existir.
     */
    public function chaveApresentada(): string
    {
        $bruto = $this->base->header('Authorization')
            ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

        if (($bruto === null || $bruto === '') && function_exists('getallheaders')) {
            foreach (getallheaders() as $nome => $valor) {
                if (strcasecmp((string)$nome, 'Authorization') === 0) {
                    $bruto = (string)$valor;
                    break;
                }
            }
        }

        $bruto = trim((string)$bruto);
        if ($bruto === '') {
            return '';
        }
        // Só `Bearer`. Aceitar a chave crua sem o esquema pareceria gentileza e só serviria
        // para um cliente mal configurado mandar o valor de outro cabeçalho aqui sem perceber.
        if (!preg_match('/^Bearer\s+(\S+)$/i', $bruto, $m)) {
            return '';
        }
        return $m[1];
    }

    /** Veio algum `Authorization`, ainda que ilegível? Separa "não mandou" de "mandou errado". */
    public function temAutorizacao(): bool
    {
        $bruto = $this->base->header('Authorization') ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return trim((string)$bruto) !== '';
    }

    /**
     * Valor da querystring, sempre string podada. Não decodifica nada: quem precisa de número
     * usa consultaInt().
     */
    public function consulta(string $nome, string $padrao = ''): string
    {
        $v = $this->base->query($nome);
        return $v === null ? $padrao : $v;
    }

    /** Inteiro da querystring, já preso entre $min e $max (valor ilegível vira o padrão). */
    public function consultaInt(string $nome, int $padrao, int $min, int $max): int
    {
        $bruto = $this->consulta($nome);
        if ($bruto === '' || !preg_match('/^-?\d{1,9}$/', $bruto)) {
            return $padrao;
        }
        return max($min, min($max, (int)$bruto));
    }

    /**
     * O corpo já decodificado. Devolve [] quando não há corpo; erroCorpo() explica quando o
     * que veio não era JSON de objeto.
     *
     * @return array<string,mixed>
     */
    public function corpo(): array
    {
        if ($this->corpoLido) {
            return $this->corpo ?? [];
        }
        $this->corpoLido = true;
        $this->corpo = [];

        $bruto = (string)file_get_contents('php://input');
        if ($bruto === '') {
            // Formulário: o PHP já consumiu php://input e encheu o $_POST. Aceito porque um
            // `curl -d` de teste é a primeira coisa que alguém faz — e porque não custa nada.
            //
            // $_POST direto, e não Request::all(), de propósito: `all()` junta corpo e
            // querystring, e misturar os dois deixaria alterar um campo de escrita pela URL.
            // Esta classe é a borda da API — é o lugar onde ler a superglobal é o trabalho.
            /** @var array<string,mixed> $post */
            $post = $_POST;
            unset($post['_csrf']);
            return $this->corpo = $post;
        }

        if (strlen($bruto) > self::CORPO_MAX_BYTES) {
            $this->erroCorpo = 'corpo_grande';
            return [];
        }

        $dados = json_decode($bruto, true);
        if (!is_array($dados)) {
            $this->erroCorpo = 'json_invalido';
            return [];
        }
        // Lista no lugar de objeto: nenhum endpoint desta API recebe array na raiz, e aceitar
        // silenciosamente devolveria "campo obrigatório ausente" no lugar da causa real.
        if (array_is_list($dados) && $dados !== []) {
            $this->erroCorpo = 'json_invalido';
            return [];
        }
        /** @var array<string,mixed> $dados */
        return $this->corpo = $dados;
    }

    /** '' | 'json_invalido' | 'corpo_grande' — consultado depois de corpo(). */
    public function erroCorpo(): string
    {
        $this->corpo();
        return $this->erroCorpo;
    }
}
