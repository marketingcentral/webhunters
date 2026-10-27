/**
 * Telas /noticias e /noticias/{slug} — o App\Controllers\NewsController do PHP.
 * Conteúdo do banco (CatalogRepo.news); rótulos de apresentação sem coluna no banco
 * (tag_cls, lead, author, read, highlights, pos) vêm de src/data/noticias.json, casados por slug.
 */
import { dataMock, url } from '../core/helpers';
import { HttpException } from '../core/http-exception';
import { atom, lerDataSp, meiaNoite, proximoDiaSemana } from '../core/relogio';
import type { AppRequest } from '../core/request';
import type { AppResponse } from '../core/response';
import type { Params } from '../core/router';
import type { Dict } from '../core/view';
import * as CatalogRepo from '../repo/catalog-repo';
import { noticias } from '../views/pages/noticias';
import { Controller } from './controller';

/**
 * app/Data/noticias.php calcula a agenda com strtotime() a cada requisição; o JSON exportado
 * guardou o instante do export. Refaz as duas contas no fuso de SP:
 * 'next tuesday 03:00' e '+2 days 21:00'.
 */
function dadosNoticias(): Dict {
  const d: Dict = dataMock('noticias');
  const agenda = (d.agenda ?? []) as Dict[];
  if (agenda[0]) agenda[0].at = atom(meiaNoite(2) + 21 * 3600000);
  if (agenda[1]) agenda[1].at = atom(proximoDiaSemana(2, 3));
  return d;
}

/** date('d/m/Y', strtotime($published)). published é DATE, sem fuso a converter. */
function dataBr(pub: string): string {
  if (pub === '' || Number.isNaN(lerDataSp(pub))) return '';
  const [a, m, dd] = pub.slice(0, 10).split('-');
  return `${dd}/${m}/${a}`;
}

export class NewsController extends Controller {
  async index(_req: AppRequest, _p: Params): Promise<AppResponse> {
    const data = dadosNoticias();
    data.items = await this.newsItems();
    data.article = null;
    const page = this.page('noticias', 'Notícias');
    return this.render(noticias, page, data);
  }

  /** GET /noticias/{slug}: mesma view com o artigo preenchido; slug desconhecido = 404. */
  async show(_req: AppRequest, params: Params): Promise<AppResponse> {
    const data = dadosNoticias();
    data.items = await this.newsItems();
    const slug = params.slug ?? '';
    const article = (data.items as Dict[]).find((it) => (it.slug ?? null) === slug) ?? null;
    if (article === null) throw new HttpException(404, 'Notícia não encontrada');
    data.article = article;
    const page = this.page('noticias', String(article.title), { back: { label: 'VOLTAR', href: url('noticias') } });
    return this.render(noticias, page, data);
  }

  /** Notícias do banco mapeadas para as chaves da view; banco fora do ar = mock estático. */
  private async newsItems(): Promise<Dict[]> {
    const mock: Dict = dataMock('noticias');
    const overlay: Record<string, Dict> = {};
    for (const it of (mock.items ?? []) as Dict[]) {
      if (it.slug !== undefined && it.slug !== null) overlay[String(it.slug)] = it;
    }

    let rows: Dict[];
    try {
      rows = await CatalogRepo.news();
    } catch (e) {
      console.error('[extracthunters] noticias:', e);
      return (mock.items ?? []) as Dict[];
    }

    return rows.map((n) => {
      const slug = String(n.slug ?? '');
      const o = overlay[slug] ?? {};
      const pub = String(n.published ?? '');
      const exc = String(n.excerpt ?? '');
      const dt = dataBr(pub);
      return {
        slug,
        title: String(n.title ?? o.title ?? ''),
        tag: String(n.category ?? o.tag ?? ''),
        tag_cls: String(o.tag_cls ?? 'lime'),
        summary: exc !== '' ? exc : String(o.summary ?? ''),
        lead: String(o.lead ?? (exc !== '' ? exc : o.summary ?? '')),
        date: dt !== '' ? dt : String(o.date ?? ''),
        image: String(n.image ?? o.image ?? 'news/news-atualizacao.jpg'),
        pos: String(o.pos ?? '50% 45%'),
        author: String(o.author ?? ''),
        read: Math.trunc(Number(o.read ?? 3) || 0),
        body: Array.isArray(n.body) ? n.body : Array.isArray(o.body) ? o.body : [],
        highlights: Array.isArray(o.highlights) ? o.highlights : [],
      };
    });
  }
}
