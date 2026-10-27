<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class ErrorsController extends Controller
{
    private const TEXT = [
        // 403 nasceu com o painel administrativo: quem está logado no jogo e bate numa rota
        // /admin precisa ver "não é seu", e não o 404 genérico que fingia que a rota não existe.
        403 => ['ACESSO NEGADO',      'Esta área é restrita. Sua conta não tem permissão para acessá-la.'],
        404 => ['ÁREA NÃO MAPEADA',   'A coordenada que você tentou acessar não existe ou foi evacuada. Verifique a rota e tente novamente.'],
        405 => ['MANOBRA INVÁLIDA',   'Este ponto não aceita esse tipo de requisição. Volte e use os controles da interface.'],
        419 => ['SESSÃO EXPIRADA',    'Seu token de segurança expirou ou é inválido. Recarregue a página e envie o formulário novamente.'],
        500 => ['FALHA NO SISTEMA',   'Ocorreu um erro interno. Nossa equipe já foi notificada. Tente novamente em instantes.'],
        // 503 é o modo manutenção de /admin/configuracoes (App\Core\Maintenance). O texto
        // abaixo é só a moldura: a frase que o jogador lê vem da chave `maintenance_message`,
        // escrita no painel, e chega como o "detalhe" da HttpException.
        503 => ['MANUTENÇÃO EM CURSO', 'O jogo está fora do ar para manutenção. Tente novamente em instantes.'],
    ];

    /** @param array<string, string> $params */
    public function notFound(Request $request, array $params = []): Response
    {
        return $this->errorPage(404, $request);
    }

    public function fromException(HttpException $e, Request $request): Response
    {
        $status = $e->status();
        if (!isset(self::TEXT[$status])) {
            $status = $status >= 500 ? 500 : 404;
        }
        // 419 e 503 são os dois status cuja mensagem é escrita para ser lida pelo usuário
        // (token expirado; aviso de manutenção do painel). Nos demais, a mensagem interna
        // fica no log — ela descreve o defeito, não o que a pessoa deve fazer.
        $detail   = in_array($status, [419, 503], true) && $e->getMessage() !== '' ? $e->getMessage() : null;
        $response = $this->errorPage($status, $request, $detail);
        foreach ($e->headers() as $k => $v) {
            $response = $response->withHeader($k, $v);
        }
        return $response;
    }

    public function serverError(\Throwable $e, Request $request): Response
    {
        $detail = Config::debug()
            ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine())
            : null;
        try {
            return $this->errorPage(500, $request, $detail);
        } catch (\Throwable) {
            // Layout itself is broken: fall back to a bare, still-escaped page.
            $body = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>500 · Extract Hunters</title>'
                . '<style nonce="' . e(nonce()) . '">body{background:#070907;color:#e8ede6;font:16px/1.5 system-ui;display:grid;place-items:center;min-height:100vh;margin:0}'
                . 'main{max-width:36rem;padding:2rem;border:1px solid rgba(155,224,74,.35)}h1{color:#9be04a;letter-spacing:.06em}</style></head>'
                . '<body><main><h1>FALHA NO SISTEMA</h1><p>' . e(self::TEXT[500][1]) . '</p>'
                . ($detail ? '<pre>' . e($detail) . '</pre>' : '')
                . '<p><a href="' . e(url('inicio')) . '" style="color:#9be04a">Voltar ao início</a></p></main></body></html>';
            return Response::html($body, 500);
        }
    }

    private function errorPage(int $status, Request $request, ?string $detail = null): Response
    {
        [$title, $message] = self::TEXT[$status];

        // app/Data/404.php pode sobrescrever os textos por status e a lista de atalhos.
        $mock  = data_mock('404');
        $texts = is_array($mock['codes'][$status] ?? null) ? $mock['codes'][$status] : [];
        $title   = trim((string)($texts['title'] ?? '')) ?: $title;
        $message = trim((string)($texts['message'] ?? '')) ?: $message;

        $links = [];
        foreach ((array)($mock['links'] ?? []) as $link) {
            if (!is_array($link) || trim((string)($link['label'] ?? '')) === '') {
                continue;
            }
            $links[] = [
                'label' => (string)$link['label'],
                'href'  => url((string)($link['href'] ?? 'inicio')),
                'icon'  => (string)($link['icon'] ?? 'chevron_right'),
            ];
        }
        if ($links === []) {
            $links = [
                ['label' => 'VOLTAR AO INÍCIO', 'href' => url('inicio'),  'icon' => 'flag'],
                ['label' => 'JOGAR',            'href' => url('jogar'),   'icon' => 'sports_esports'],
                ['label' => 'SUPORTE',          'href' => url('suporte'), 'icon' => 'headset_mic'],
            ];
        }

        $page = $this->page('404', (string)$status . ' · ' . $title, [
            'slug'  => '404',
            'nav'   => '',
            'back'  => ['label' => 'VOLTAR', 'href' => url('inicio')],
            'three' => ['atmosphere' => true],
        ]);
        $data = array_replace($mock, [
            'code'    => $status,
            'title'   => $title,
            'message' => $message,
            'status'  => (string)($texts['status'] ?? ''),
            'accent'  => (string)($texts['accent'] ?? 'lime'),
            'detail'  => $detail,
            'path'    => $request->path(),
            'links'   => $links,
        ]);
        return $this->render('_404', $page, $data, 'auth', $status);
    }
}
