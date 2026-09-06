<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repo\CatalogRepo;

final class NewsController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('noticias');
        $data['items']   = $this->newsItems();
        $data['article'] = null;
        $page = $this->page('noticias', 'Notícias');
        return $this->render('noticias', $page, $data);
    }

    /** GET /noticias/{slug}: same view with $data['article'] filled; unknown slug = 404. */
    public function show(Request $request, array $params = []): Response
    {
        $data = data_mock('noticias');
        $data['items'] = $this->newsItems();
        $slug = $params['slug'] ?? '';
        $article = null;
        foreach ($data['items'] as $item) {
            if (($item['slug'] ?? null) === $slug) {
                $article = $item;
                break;
            }
        }
        if ($article === null) {
            throw new HttpException(404, 'Notícia não encontrada');
        }
        $data['article'] = $article;
        $page = $this->page('noticias', $article['title'], ['back' => ['label' => 'VOLTAR', 'href' => url('noticias')]]);
        return $this->render('noticias', $page, $data);
    }

    /**
     * Notícias do banco (CatalogRepo::news() = catálogo, iguais para todos), mapeadas para as
     * chaves que a view consome. O conteúdo (title, tag=category, summary=excerpt, body[], image,
     * date=published) vem do banco; os rótulos de layout sem coluna no banco (tag_cls, lead,
     * author, read, highlights, pos) vêm do overlay de apresentação em app/Data/noticias.php,
     * casados por slug. Banco indisponível: cai para o mock estático (HUD segue navegável).
     *
     * @return list<array<string,mixed>>
     */
    private function newsItems(): array
    {
        $mock = data_mock('noticias');

        /** @var array<string,array<string,mixed>> $overlay */
        $overlay = [];
        foreach ((array)($mock['items'] ?? []) as $it) {
            if (isset($it['slug'])) {
                $overlay[(string)$it['slug']] = (array)$it;
            }
        }

        try {
            $rows = CatalogRepo::news();
        } catch (\Throwable) {
            return array_values((array)($mock['items'] ?? []));
        }

        $items = [];
        foreach ($rows as $n) {
            $slug = (string)($n['slug'] ?? '');
            $o    = $overlay[$slug] ?? [];
            $pub  = (string)($n['published'] ?? '');
            $ts   = $pub !== '' ? strtotime($pub) : false;
            $exc  = (string)($n['excerpt'] ?? '');

            $items[] = [
                'slug'       => $slug,
                'title'      => (string)($n['title'] ?? ($o['title'] ?? '')),
                'tag'        => (string)($n['category'] ?? ($o['tag'] ?? '')),
                'tag_cls'    => (string)($o['tag_cls'] ?? 'lime'),
                'summary'    => $exc !== '' ? $exc : (string)($o['summary'] ?? ''),
                'lead'       => (string)($o['lead'] ?? ($exc !== '' ? $exc : ($o['summary'] ?? ''))),
                'date'       => $ts !== false ? date('d/m/Y', $ts) : (string)($o['date'] ?? ''),
                'image'      => (string)($n['image'] ?? ($o['image'] ?? 'news/news-atualizacao.jpg')),
                'pos'        => (string)($o['pos'] ?? '50% 45%'),
                'author'     => (string)($o['author'] ?? ''),
                'read'       => (int)($o['read'] ?? 3),
                'body'       => array_values((array)($n['body'] ?? ($o['body'] ?? []))),
                'highlights' => array_values((array)($o['highlights'] ?? [])),
            ];
        }
        return $items;
    }
}
