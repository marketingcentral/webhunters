<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;

/**
 * RECEBIMENTO DE IMAGEM DO PAINEL — POST /admin/upload (JSON).
 *
 * Único endereço por onde arquivo entra no servidor vindo do /admin. As telas de missões,
 * mapas e eventos guardam no banco um CAMINHO relativo a /media (ex.: "uploads/mapas/x.jpg");
 * este controller grava o arquivo e devolve exatamente esse caminho para o campo de texto.
 *
 * POR QUE JSON E NÃO POST-REDIRECT-GET COMO O RESTO DO PAINEL
 * ----------------------------------------------------------
 * As telas do painel são formulários grandes de uma rota só. Se o envio da imagem fosse um
 * POST normal, o operador perderia tudo o que já digitou para subir uma foto. O envio é
 * assíncrono, à parte do formulário: o arquivo sobe, o caminho volta e cai no <input>, e o
 * formulário só é gravado quando o operador clicar em salvar — nada muda no banco aqui.
 * Por isso TODA saída deste arquivo é JSON, inclusive as recusas: um fetch que recebe o HTML
 * da tela de erro não tem como mostrar o motivo ao operador.
 *
 * O QUE É VERIFICADO, E POR QUE NESSA ORDEM
 * ----------------------------------------
 * 1. sessão de admin  -> ninguém escreve no disco sem estar logado no painel;
 * 2. CSRF             -> senão qualquer página aberta noutra aba poderia plantar arquivo aqui;
 * 3. entidade na lista-> a pasta de destino NUNCA vem do cliente como texto livre;
 * 4. erro do PHP      -> arquivo maior que o php.ini chega vazio, com código, não com conteúdo;
 * 5. is_uploaded_file -> garante que o caminho temporário veio mesmo de um upload HTTP;
 * 6. tamanho          -> antes de abrir o conteúdo, para não gastar memória com lixo;
 * 7. CONTEÚDO REAL    -> getimagesize() + finfo. O Content-Type e a extensão que o cliente
 *    mandou são ignorados de propósito: os dois são texto escolhido por quem envia. Um .php
 *    renomeado para .jpg passa pelos dois e morre aqui, porque getimagesize() não acha
 *    cabeçalho de imagem nenhum.
 *
 * A extensão gravada em disco é DEDUZIDA do tipo detectado, nunca copiada do nome do cliente —
 * é o que impede "foto.php.jpg" e "foto.jpg.php" de existirem na pasta.
 *
 * DEFESA FINAL: media/uploads/ não executa PHP (ver garantirPastaSegura() e o .htaccess da
 * raiz). Validar conteúdo é bom, mas a pasta que não executa é o que transforma um eventual
 * furo de validação em um arquivo inerte em vez de um shell.
 */
final class UploadController extends AdminController
{
    /**
     * Pastas permitidas dentro de media/uploads/. Lista fechada: a entidade decide o caminho
     * no disco, então texto livre aqui seria o cliente escolhendo onde gravar.
     * O valor é o nome da pasta — sem barra, sem ponto, sem maiúscula.
     */
    private const ENTIDADES = ['missoes', 'conquistas', 'mapas', 'eventos', 'noticias', 'itens', 'loja'];

    /**
     * Teto de 2 MiB. Dois motivos concretos:
     *  - é o que o php.ini desta instalação aceita (upload_max_filesize=2M). Prometer 5 MB na
     *    interface e o PHP cortar em 2 dá "arquivo vazio" sem explicação nenhuma;
     *  - a maior arte que o jogo usa hoje é um fundo 1920x1080: em JPEG q80 dá ~400 KB e em
     *    PNG com transparência ~1,2 MB. 2 MiB deixa folga de sobra sem virar depósito.
     * O valor real aplicado é o MENOR entre este teto e o do php.ini (ver tetoBytes()).
     */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** Dimensões aceitas. O mínimo pega arquivo-isca de 1x1; o máximo pega imagem de câmera. */
    private const MIN_LADO = 16;
    private const MAX_LADO = 8000;

    /**
     * Tipos de BITMAP aceitos: constante do getimagesize() => [extensão gravada, mime esperado].
     * GIF está fora porque nada no jogo usa.
     */
    private const TIPOS = [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    /**
     * SVG tem caminho PRÓPRIO, e não uma linha em TIPOS, porque ele não é bitmap: getimagesize()
     * não o lê, não há dimensão em pixels para conferir, e o risco dele é de outra natureza.
     *
     * POR QUE ELE AGORA ENTRA
     * Um ícone de mapa é vetor por natureza: escala em qualquer zoom e pesa poucos KB. Recusar
     * SVG obrigava a exportar PNG de cada ícone em três tamanhos, e é exatamente o formato em
     * que os ícones chegam prontos.
     *
     * POR QUE ELE CONTINUA SENDO TRATADO COMO PERIGOSO
     * SVG é XML e pode carregar `<script>`, `on*=`, `javascript:` e referência externa. Três
     * camadas respondem a isso, nesta ordem:
     *   1. RECUSA, não limpeza: qualquer um desses itens reprova o arquivo inteiro. Limpar
     *      significa adivinhar a intenção de quem enviou; recusar devolve o problema para quem
     *      pode consertá-lo, e não deixa passar a variante que o meu filtro não conhecia.
     *   2. O arquivo é sempre desenhado dentro de <img src="...">, e SVG em <img> é
     *      script-desabilitado por TODO navegador — nada roda nem que passasse pela camada 1.
     *   3. media/uploads/ não executa nada (ver garantirPastaSegura()).
     *
     * Consequência aceita: em <img> o SVG NÃO herda `currentColor`, então arte enviada leva a
     * própria cor. Para ícone que precisa mudar de cor com o marcador existe a origem `glyph`.
     */
    private const SVG_EXT = 'svg';

    /** Padrões que REPROVAM um SVG. Lista de recusa explícita — ver o docblock de SVG_EXT. */
    private const SVG_PROIBIDO = [
        '~<\s*script~i'                      => 'contém <script>',
        '~<\s*foreignObject~i'               => 'contém <foreignObject>',
        '~<\s*(iframe|embed|object|audio|video)~i' => 'contém elemento de mídia externa',
        '~<!\s*ENTITY~i'                     => 'declara ENTITY (risco de XXE)',
        '~<!\s*DOCTYPE[^>]*\[~i'             => 'declara DTD interna',
        '~\son[a-z]+\s*=~i'                  => 'tem atributo de evento (onload, onclick…)',
        '~(href|src|xlink:href)\s*=\s*["\']?\s*(javascript|data|vbscript):~i' => 'aponta para javascript:/data:',
        '~(href|src|xlink:href)\s*=\s*["\']?\s*(https?:)?//~i' => 'referencia arquivo de fora do servidor',
    ];

    /** Nome do campo de arquivo no formulário. */
    private const CAMPO = 'arquivo';

    // ============================================================================== ação

    /** @param array<string, string> $params */
    public function enviar(Request $request, array $params = []): Response
    {
        /* A guarda da base devolve um REDIRECT (302 para /admin/login) quando não há sessão.
           Redirect é a resposta certa para uma tela; para um fetch, é uma resposta 200 com o
           HTML do login e o operador só veria "erro desconhecido". Então aqui a guarda é lida
           e traduzida para JSON. */
        if ($this->exigirAdmin() !== null) {
            return $this->falha(401, 'Sessão do painel expirada. Recarregue a página e entre de novo.');
        }

        try {
            Security::checkCsrf($request);
        } catch (\Throwable) {
            return $this->falha(419, 'Token de sessão inválido ou expirado. Recarregue a página.');
        }

        $entidade = strtolower(trim((string)($request->input('entidade') ?? '')));
        if (!in_array($entidade, self::ENTIDADES, true)) {
            return $this->falha(422, 'Destino de upload desconhecido: "' . $entidade . '".');
        }

        /* $_FILES direto: Request é um objeto de leitura de $_GET/$_POST e não modela upload.
           Ler aqui, num único ponto, é mais honesto que espalhar superglobal pelo projeto. */
        $arquivo = $_FILES[self::CAMPO] ?? null;
        if (!is_array($arquivo) || is_array($arquivo['name'] ?? null)) {
            // is_array($arquivo['name']) = enviaram name="arquivo[]". Um por vez, sempre.
            return $this->falha(422, 'Nenhum arquivo recebido. Escolha uma imagem e tente de novo.');
        }

        $erroPhp = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erroPhp !== UPLOAD_ERR_OK) {
            return $this->falha(422, $this->mensagemErroPhp($erroPhp));
        }

        $tmp = (string)($arquivo['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            // Sem isto, um valor forjado em tmp_name faria o move_uploaded_file apontar para
            // um arquivo qualquer do servidor. A checagem é barata e fecha a porta.
            return $this->falha(400, 'Upload inválido: o arquivo não chegou por uma requisição de envio.');
        }

        $bytes = (int)($arquivo['size'] ?? 0);
        $teto  = $this->tetoBytes();
        if ($bytes <= 0) {
            return $this->falha(422, 'O arquivo chegou vazio (0 byte).');
        }
        if ($bytes > $teto) {
            return $this->falha(413, sprintf(
                'Imagem de %s — o limite é %s. Reduza a resolução ou salve em JPEG.',
                $this->humano($bytes),
                $this->humano($teto)
            ));
        }

        // ---- conteúdo de verdade -------------------------------------------------------
        $info = @getimagesize($tmp);

        /* SVG sai do caminho comum aqui: getimagesize() não o lê e não há pixel para medir.
           O que vale para ele é a leitura do XML — ver o docblock de SVG_EXT. */
        $ehSvg = !is_array($info) && $this->pareceSvg($tmp);
        if ($ehSvg) {
            $recusa = $this->recusarSvg($tmp);
            if ($recusa !== null) {
                return $this->falha(422, 'SVG recusado: ' . $recusa . '. Envie um vetor sem script nem referência externa.');
            }
            $ext  = self::SVG_EXT;
            $larg = 0;
            $alt  = 0;
            $mimeReal = 'image/svg+xml';
        } else {
            if (!is_array($info) || !isset($info[2], $info[0], $info[1])) {
                return $this->falha(422, 'O arquivo enviado não é uma imagem (nenhum cabeçalho de imagem foi encontrado nele).');
            }

            $tipo = (int)$info[2];
            if (!isset(self::TIPOS[$tipo])) {
                return $this->falha(422, 'Formato não aceito. Envie SVG, PNG, WEBP ou JPG.');
            }
            [$ext, $mimeEsperado] = self::TIPOS[$tipo];

        /* finfo confere o MESMO arquivo por outro caminho (números mágicos da libmagic).
           Concordar com o getimagesize() é o que fecha o caso: um arquivo híbrido, montado
           para um dos dois enxergar imagem e o outro não, é recusado. */
            $mimeReal = $this->mimeReal($tmp);
            if ($mimeReal !== $mimeEsperado) {
                return $this->falha(422, sprintf(
                    'O conteúdo do arquivo (%s) não corresponde a uma imagem %s válida.',
                    $mimeReal !== '' ? $mimeReal : 'desconhecido',
                    strtoupper($ext)
                ));
            }

            $larg = (int)$info[0];
            $alt  = (int)$info[1];
            if ($larg < self::MIN_LADO || $alt < self::MIN_LADO) {
                return $this->falha(422, sprintf('Imagem pequena demais (%dx%d). O mínimo é %dx%d.', $larg, $alt, self::MIN_LADO, self::MIN_LADO));
            }
            if ($larg > self::MAX_LADO || $alt > self::MAX_LADO) {
                return $this->falha(422, sprintf('Imagem grande demais (%dx%d). O máximo é %d px de lado.', $larg, $alt, self::MAX_LADO));
            }
        }

        // ---- destino -------------------------------------------------------------------
        $pasta = Config::root() . '/media/uploads/' . $entidade;
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            return $this->falha(500, 'Não foi possível criar a pasta de destino no servidor.');
        }
        $this->garantirPastaSegura(Config::root() . '/media/uploads');

        $nome    = $this->nomeUnico($pasta, (string)($arquivo['name'] ?? ''), $ext);
        $destino = $pasta . '/' . $nome;

        if (!@move_uploaded_file($tmp, $destino)) {
            return $this->falha(500, 'Falha ao gravar o arquivo no servidor. Verifique a permissão de media/uploads/.');
        }
        // 0644: o servidor web lê, ninguém executa. (No Windows o chmod é ignorado; em
        // produção, Linux, é o que impede o bit de execução de vir junto do umask.)
        @chmod($destino, 0644);

        $caminho = 'uploads/' . $entidade . '/' . $nome;

        $this->auditar(
            'upload_imagem',
            $entidade,
            null,
            'Imagem enviada: ' . $caminho,
            [
                'arquivo'  => $caminho,
                'bytes'    => $bytes,
                'mime'     => $mimeReal,
                // vetor não tem dimensão em pixel; o log diz isso em vez de mentir "0x0"
                'dimensao' => $larg > 0 ? $larg . 'x' . $alt : 'vetor',
                // Guardado só para a trilha de auditoria; nunca usado para montar caminho.
                'original' => mb_substr((string)($arquivo['name'] ?? ''), 0, 120),
            ]
        );

        return Response::json([
            'ok'       => true,
            'caminho'  => $caminho,           // é isto que vai para o <input> e para o banco
            'url'      => media($caminho),    // só para a pré-visualização
            'bytes'    => $bytes,
            'tamanho'  => $this->humano($bytes),
            'largura'  => $larg,
            'altura'   => $alt,
        ]);
    }

    // =========================================================================== apoio

    /** Resposta de recusa. Sempre JSON, sempre com motivo legível para o operador. */
    private function falha(int $status, string $mensagem): Response
    {
        // A recusa também é auditada quando há sessão: tentativa repetida de subir arquivo
        // recusado é sinal, não ruído. Sem sessão (401) não há quem assinar, então passa.
        if ($status !== 401) {
            try {
                $this->auditar('upload_recusado', 'sistema', null, 'Upload recusado: ' . $mensagem, ['status' => $status]);
            } catch (\Throwable) {
                // auditoria nunca pode transformar uma recusa clara em erro 500.
            }
        }
        return Response::json(['ok' => false, 'erro' => $mensagem], $status);
    }

    /** Teto real: o menor entre a regra do projeto e o que o php.ini deixa entrar. */
    private function tetoBytes(): int
    {
        $ini = min(
            $this->iniBytes('upload_max_filesize'),
            $this->iniBytes('post_max_size')
        );
        return $ini > 0 ? min(self::MAX_BYTES, $ini) : self::MAX_BYTES;
    }

    /** Converte "2M"/"8M"/"512K" do php.ini em bytes (0 quando ilimitado). */
    private function iniBytes(string $chave): int
    {
        $v = trim((string)ini_get($chave));
        if ($v === '' || $v === '0' || $v === '-1') {
            return 0;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g'     => $n * 1024 * 1024 * 1024,
            'm'     => $n * 1024 * 1024,
            'k'     => $n * 1024,
            default => $n,
        };
    }

    /** Mensagem de cada código do PHP — o operador precisa saber o que fazer, não o número. */
    private function mensagemErroPhp(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'Arquivo acima do limite do servidor (%s). Reduza a resolução ou salve em JPEG.',
                $this->humano($this->tetoBytes())
            ),
            UPLOAD_ERR_PARTIAL    => 'O envio foi interrompido no meio. Tente de novo.',
            UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo foi escolhido.',
            UPLOAD_ERR_NO_TMP_DIR => 'O servidor está sem pasta temporária para uploads (upload_tmp_dir).',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o arquivo temporário.',
            UPLOAD_ERR_EXTENSION  => 'Uma extensão do PHP bloqueou este envio.',
            default               => 'Falha desconhecida no envio (código ' . $codigo . ').',
        };
    }

    /**
     * O arquivo se anuncia como SVG? Só uma triagem barata para desviar do caminho de bitmap —
     * quem decide se ele ENTRA é recusarSvg(). Lê apenas o começo: um SVG legítimo declara o
     * elemento raiz nos primeiros bytes, e ler o arquivo inteiro aqui seria pagar duas vezes.
     */
    private function pareceSvg(string $tmp): bool
    {
        $inicio = (string)@file_get_contents($tmp, false, null, 0, 1024);
        return $inicio !== '' && preg_match('~<\s*svg[\s>]~i', $inicio) === 1;
    }

    /**
     * Motivo da recusa, ou null quando o vetor pode entrar.
     *
     * RECUSA, e não limpeza: ver o docblock de SVG_EXT. Limpar exigiria adivinhar a intenção de
     * quem enviou e deixaria passar a variante que esta lista não conhece; recusar devolve o
     * arquivo para quem pode consertá-lo.
     */
    private function recusarSvg(string $tmp): ?string
    {
        $bruto = (string)@file_get_contents($tmp);
        if ($bruto === '') {
            return 'o arquivo chegou vazio';
        }

        /* Entidades HTML escondem "<script" como "&lt;script": conferir também a forma
           decodificada fecha essa porta sem precisar de uma segunda lista de padrões. */
        $texto = html_entity_decode($bruto, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (self::SVG_PROIBIDO as $padrao => $motivo) {
            if (preg_match($padrao, $bruto) === 1 || preg_match($padrao, $texto) === 1) {
                return $motivo;
            }
        }

        /* Último passo: tem de ser XML bem formado com <svg> na raiz. Um arquivo que o parser
           recusa não é um vetor — é outra coisa com uma tag <svg> no meio. LIBXML_NONET corta
           busca de entidade pela rede, redundância proposital com o filtro de ENTITY acima. */
        $anterior = libxml_use_internal_errors(true);
        $doc  = new \DOMDocument();
        $lido = $doc->loadXML($bruto, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if (!$lido || $doc->documentElement === null) {
            return 'não é um XML bem formado';
        }
        if (strtolower($doc->documentElement->nodeName) !== 'svg') {
            return 'o elemento raiz não é <svg>';
        }
        return null;
    }

    /** MIME lido do CONTEÚDO. String vazia quando a fileinfo não está disponível. */
    private function mimeReal(string $caminho): string
    {
        if (!function_exists('finfo_open')) {
            return '';
        }
        $f = @finfo_open(FILEINFO_MIME_TYPE);
        if ($f === false) {
            return '';
        }
        $mime = @finfo_file($f, $caminho);
        finfo_close($f);
        return is_string($mime) ? strtolower($mime) : '';
    }

    /**
     * Nome final: pedaço legível do nome original + sufixo aleatório + extensão detectada.
     *
     * O pedaço legível existe só para o operador reconhecer o arquivo na pasta; ele passa
     * por um filtro que só deixa [a-z0-9-] e corta em 48 caracteres, então nada do nome do
     * cliente sobrevive como caminho (sem ".", sem "/", sem "\", sem ".."). Se não sobrar
     * nada legível, o nome vira só "img". O sufixo de 8 hex resolve a colisão e evita que
     * alguém adivinhe o endereço de um arquivo que ainda não foi publicado.
     */
    private function nomeUnico(string $pasta, string $nomeCliente, string $ext): string
    {
        $base = (string)pathinfo($nomeCliente, PATHINFO_FILENAME);
        /* Navegador manda o nome em UTF-8, mas nem todo cliente manda (ferramenta de linha de
           comando num terminal Windows manda Latin-1). Sem normalizar, o "ã" em Latin-1 é um
           byte só, inválido em UTF-8, e o mb_* trabalharia em cima de lixo. */
        if (!mb_check_encoding($base, 'UTF-8')) {
            $base = mb_convert_encoding($base, 'UTF-8', 'Windows-1252');
        }
        $base = mb_strtolower($base);
        // Acento vira a letra sem acento ANTES do filtro — senão "missão.jpg" viraria
        // "miss-o.jpg", porque o filtro abaixo é byte a byte e o "ã" são dois bytes.
        $base = strtr($base, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','è'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
        ]);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        $base = $base === '' ? 'img' : mb_substr($base, 0, 48);

        do {
            $nome = $base . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        } while (file_exists($pasta . '/' . $nome));

        return $nome;
    }

    /**
     * Garante que media/uploads/ não execute PHP.
     *
     * POR QUE ISSO É NECESSÁRIO: media/ é servida DIRETAMENTE pelo servidor — o .htaccess da
     * raiz manda tudo que existe no disco (`RewriteCond %{REQUEST_FILENAME} -f`) ser entregue
     * sem passar pelo index.php. Ou seja, um arquivo .php que conseguisse aterrissar aqui não
     * seria "uma imagem quebrada": seria código rodando com as permissões do site. A validação
     * de conteúdo já recusa isso na entrada; esta pasta sem execução é a segunda tranca, para
     * o caso de a primeira falhar (bug futuro, biblioteca trocada, extensão nova aceita sem
     * pensar).
     *
     * A REGRA DE VERDADE está no .htaccess da RAIZ, como RewriteRule — é a única forma que o
     * OpenLiteSpeed da produção honra (ele ignora <FilesMatch> e php_flag, conforme já medido
     * e documentado no topo daquele arquivo). Este .htaccess local é REFORÇO para Apache e
     * é escrito uma única vez, quando a pasta nasce.
     */
    private function garantirPastaSegura(string $raizUploads): void
    {
        $guarda = $raizUploads . '/.htaccess';
        if (is_file($guarda)) {
            return;
        }
        @file_put_contents($guarda, <<<'HTACCESS'
        # media/uploads/ guarda arquivo enviado por operador do painel. NADA aqui pode ser
        # executado: esta pasta e servida direto pelo servidor web (ver .htaccess da raiz),
        # entao um .php aqui rodaria como codigo do site. Reforco para Apache — a regra que
        # vale em producao (OpenLiteSpeed) e o RewriteRule de media/ no .htaccess da raiz.
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteRule \.(php\d?|phtml|phps|phar|pht|cgi|pl|py|rb|sh|exe|html?|svg|xml)$ - [F,L]
        </IfModule>
        <FilesMatch "\.(php\d?|phtml|phps|phar|pht|cgi|pl|py|rb|sh|exe|html?|svg|xml)$">
            Require all denied
        </FilesMatch>
        <IfModule mod_php.c>
            php_flag engine off
        </IfModule>
        Options -ExecCGI -Indexes
        AddType text/plain .php .phtml .phar .cgi .pl .py
        HTACCESS);
    }

    /** "1,3 MB" — para a mensagem de erro dizer o tamanho na língua do operador. */
    private function humano(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return str_replace('.', ',', (string)round($bytes / 1048576, 1)) . ' MB';
        }
        return max(1, (int)round($bytes / 1024)) . ' KB';
    }
}
